<?php

class EmailSystem {
    private $pdo;
    private $fromEmail;
    private $fromName;
    private $appName;
    private $appUrl;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->fromEmail = getenv('EMAIL_FROM') ?: ($_ENV['EMAIL_FROM'] ?? (defined('APP_URL') ? 'noreply@bulkreplacetool.com' : 'noreply@yourdomain.com'));
        $this->fromName = getenv('EMAIL_FROM_NAME') ?: ($_ENV['EMAIL_FROM_NAME'] ?? (defined('APP_NAME') ? APP_NAME : 'Your App'));
        $this->appName = defined('APP_NAME') ? APP_NAME : ($_ENV['APP_NAME'] ?? 'Your App');
        $this->appUrl = defined('APP_URL') ? APP_URL : ($_ENV['APP_URL'] ?? 'https://yourdomain.com');
    }

    public function queueEmail($data) {
        $stmt = $this->pdo->prepare("
            INSERT INTO email_queue
            (user_id, to_email, to_name, from_email, from_name, reply_to,
             subject, body_html, body_text, template_key, priority, scheduled_at, metadata)
            VALUES
            (:user_id, :to_email, :to_name, :from_email, :from_name, :reply_to,
             :subject, :body_html, :body_text, :template_key, :priority, :scheduled_at, :metadata)
        ");

        return $stmt->execute([
            'user_id' => $data['user_id'] ?? null,
            'to_email' => $data['to_email'],
            'to_name' => $data['to_name'] ?? '',
            'from_email' => $data['from_email'] ?? $this->fromEmail,
            'from_name' => $data['from_name'] ?? $this->fromName,
            'reply_to' => $data['reply_to'] ?? null,
            'subject' => $data['subject'],
            'body_html' => $data['body_html'],
            'body_text' => $data['body_text'] ?? strip_tags($data['body_html']),
            'template_key' => $data['template_key'] ?? null,
            'priority' => $data['priority'] ?? 5,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'metadata' => isset($data['metadata']) ? json_encode($data['metadata']) : null
        ]);
    }

    public function sendFromTemplate($templateKey, $toEmail, $toName, $variables, $userId = null, $priority = 5, $metadata = null) {
        $stmt = $this->pdo->prepare("
            SELECT subject, body_html, body_text
            FROM email_templates
            WHERE template_key = :key AND is_active = 1
        ");
        $stmt->execute(['key' => $templateKey]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$template) {
            throw new Exception("Email template '{$templateKey}' not found");
        }

        if ($userId) {
            $prefStmt = $this->pdo->prepare("
                SELECT * FROM email_preferences WHERE user_id = :user_id
            ");
            $prefStmt->execute(['user_id' => $userId]);
            $prefs = $prefStmt->fetch(PDO::FETCH_ASSOC);

            if ($prefs && $prefs['unsubscribed_at']) {
                return false;
            }

            $prefMap = [
                'welcome' => 'welcome_emails',
                'invoice' => 'invoice_emails',
                'plan_expiry_7days' => 'plan_expiry_warnings',
                'plan_expiry_1day' => 'plan_expiry_warnings',
                'plan_expired' => 'plan_expiry_warnings'
            ];

            if (isset($prefMap[$templateKey]) && $prefs && !$prefs[$prefMap[$templateKey]]) {
                return false;
            }
        }

        $defaultVars = [
            'app_name' => $this->appName,
            'app_url' => $this->appUrl,
            'dashboard_url' => $this->appUrl . '/dashboard',
            'billing_url' => $this->appUrl . '/dashboard/billing.php'
        ];

        $variables = array_merge($defaultVars, $variables);

        $subject = $this->replaceVariables($template['subject'], $variables);
        $bodyHtml = $this->replaceVariables($template['body_html'], $variables);
        $bodyText = $this->replaceVariables($template['body_text'] ?? '', $variables);

        return $this->queueEmail([
            'user_id' => $userId,
            'to_email' => $toEmail,
            'to_name' => $toName,
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'template_key' => $templateKey,
            'priority' => $priority,
            'metadata' => $metadata
        ]);
    }

    private function replaceVariables($text, $variables) {
        // Safe URL/path keys that must NOT be HTML-escaped
        $urlKeys = ['app_url', 'dashboard_url', 'billing_url', 'reset_link', 'verify_link', 'unsubscribe_url'];

        foreach ($variables as $key => $value) {
            $safeValue = (string)$value;

            // HTML-escape all values except known safe URL variables to prevent injection
            if (!in_array($key, $urlKeys, true)) {
                $safeValue = htmlspecialchars($safeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } else {
                // For URL values, only allow http/https schemes and strip dangerous protocols
                if (!preg_match('/^https?:\/\//i', $safeValue)) {
                    $safeValue = htmlspecialchars($safeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }

            $text = str_replace('{{' . $key . '}}', $safeValue, $text);
        }
        return $text;
    }

    public function processQueue($limit = 50) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM email_queue
            WHERE status = 'pending'
            AND (scheduled_at IS NULL OR scheduled_at <= NOW())
            AND attempts < max_attempts
            ORDER BY priority ASC, created_at ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $sent = 0;
        $failed = 0;

        foreach ($emails as $email) {
            $this->updateQueueStatus($email['id'], 'sending');

            try {
                $success = $this->sendEmail($email);

                if ($success) {
                    $this->updateQueueStatus($email['id'], 'sent', date('Y-m-d H:i:s'));
                    $this->logEmail($email, 'sent');
                    $sent++;
                } else {
                    throw new Exception('Email sending failed');
                }
            } catch (Exception $e) {
                $attempts = $email['attempts'] + 1;
                $status = $attempts >= $email['max_attempts'] ? 'failed' : 'pending';

                $updateStmt = $this->pdo->prepare("
                    UPDATE email_queue
                    SET status = :status, attempts = :attempts, error_message = :error,
                        failed_at = IF(:status = 'failed', NOW(), failed_at)
                    WHERE id = :id
                ");
                $updateStmt->execute([
                    'status' => $status,
                    'attempts' => $attempts,
                    'error' => $e->getMessage(),
                    'id' => $email['id']
                ]);

                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'total' => count($emails)];
    }

    private function sendEmail($email) {
        if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            return $this->sendEmailPHPMailer($email);
        } else {
            return $this->sendEmailNative($email);
        }
    }

    private function sendEmailPHPMailer($email) {
        require_once __DIR__ . '/../vendor/autoload.php';

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = getenv('SMTP_HOST') ?: ($_ENV['SMTP_HOST'] ?? 'smtp.gmail.com');
            $mail->SMTPAuth = true;
            $mail->Username = getenv('SMTP_USER') ?: ($_ENV['SMTP_USER'] ?? '');
            $mail->Password = getenv('SMTP_PASS') ?: ($_ENV['SMTP_PASS'] ?? '');
            $mail->SMTPSecure = getenv('SMTP_ENCRYPTION') ?: ($_ENV['SMTP_ENCRYPTION'] ?? 'tls');
            $mail->Port = (int)(getenv('SMTP_PORT') ?: ($_ENV['SMTP_PORT'] ?? 587));

            $mail->setFrom($email['from_email'], $email['from_name']);
            $mail->addAddress($email['to_email'], $email['to_name']);

            if ($email['reply_to']) {
                $mail->addReplyTo($email['reply_to']);
            }

            $mail->isHTML(true);
            $mail->Subject = $email['subject'];
            $mail->Body = $email['body_html'];
            $mail->AltBody = $email['body_text'];

            $mail->SMTPDebug = 0;
            $mail->Debugoutput = function($str, $level) {
                error_log("PHPMailer [$level]: $str");
            };

            return $mail->send();
        } catch (\Exception $e) {
            error_log("PHPMailer Error: " . $e->getMessage());
            throw $e;
        }
    }

    private function sendEmailNative($email) {
        $headers = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8" . "\r\n";
        $headers .= "From: {$email['from_name']} <{$email['from_email']}>" . "\r\n";

        if ($email['reply_to']) {
            $headers .= "Reply-To: {$email['reply_to']}" . "\r\n";
        }

        $to = $email['to_name'] ? "{$email['to_name']} <{$email['to_email']}>" : $email['to_email'];

        return mail($to, $email['subject'], $email['body_html'], $headers);
    }

    private function updateQueueStatus($id, $status, $sentAt = null) {
        $sql = "UPDATE email_queue SET status = :status";
        $params = ['status' => $status, 'id' => $id];

        if ($sentAt) {
            $sql .= ", sent_at = :sent_at";
            $params['sent_at'] = $sentAt;
        }

        $sql .= " WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    private function logEmail($email, $status) {
        $stmt = $this->pdo->prepare("
            INSERT INTO email_logs
            (queue_id, user_id, to_email, subject, template_key, status, sent_at, metadata)
            VALUES
            (:queue_id, :user_id, :to_email, :subject, :template_key, :status, NOW(), :metadata)
        ");

        return $stmt->execute([
            'queue_id' => $email['id'],
            'user_id' => $email['user_id'],
            'to_email' => $email['to_email'],
            'subject' => $email['subject'],
            'template_key' => $email['template_key'],
            'status' => $status,
            'metadata' => $email['metadata']
        ]);
    }

    public function getQueueStats() {
        $stmt = $this->pdo->query("
            SELECT
                status,
                COUNT(*) as count,
                MIN(created_at) as oldest,
                MAX(created_at) as newest
            FROM email_queue
            GROUP BY status
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function cancelEmail($id) {
        $stmt = $this->pdo->prepare("
            UPDATE email_queue SET status = 'cancelled' WHERE id = :id AND status = 'pending'
        ");
        return $stmt->execute(['id' => $id]);
    }

    public function retryFailed($limit = 100) {
        $stmt = $this->pdo->prepare("
            UPDATE email_queue
            SET status = 'pending', attempts = 0, error_message = NULL
            WHERE status = 'failed'
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    public function sendTestEmail($toEmail) {
        $testData = [
            'to_email' => $toEmail,
            'to_name' => 'Test User',
            'from_email' => $this->fromEmail,
            'from_name' => $this->fromName,
            'subject' => 'Test Email from ' . $this->appName,
            'body_html' => '
                <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
                    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
                        <h1 style="color: white; margin: 0;">🎉 Email Setup Successful!</h1>
                    </div>
                    <div style="background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px;">
                        <p style="font-size: 16px; color: #333;">Congratulations! Your SMTP email configuration is working perfectly.</p>
                        <div style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #28a745;">
                            <h3 style="margin-top: 0; color: #28a745;">✅ Test Details:</h3>
                            <ul style="color: #666;">
                                <li><strong>Sent:</strong> ' . date('Y-m-d H:i:s') . '</li>
                                <li><strong>From:</strong> ' . $this->fromEmail . '</li>
                                <li><strong>App:</strong> ' . $this->appName . '</li>
                            </ul>
                        </div>
                        <p style="color: #666;">Your system can now send emails for:</p>
                        <ul style="color: #666;">
                            <li>Password reset requests</li>
                            <li>Welcome emails</li>
                            <li>Plan expiry warnings</li>
                            <li>Invoice notifications</li>
                            <li>2FA backup codes</li>
                        </ul>
                        <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
                        <p style="color: #999; font-size: 12px; text-align: center;">
                            This is a test email from ' . $this->appName . '<br>
                            If you received this, your email system is configured correctly!
                        </p>
                    </div>
                </div>
            ',
            'body_text' => 'Email configuration test successful! Sent at: ' . date('Y-m-d H:i:s')
        ];

        return $this->sendEmailPHPMailer($testData);
    }
}
