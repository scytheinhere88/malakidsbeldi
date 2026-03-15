<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

ss();

if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header('Location: '.APP_URL.'/admin/login.php');
    exit;
}

$success = '';
$error = '';
$testResult = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_smtp'])) {
        $envFile = __DIR__ . '/../.env';
        $envContent = file_get_contents($envFile);

        $smtpHost = trim($_POST['smtp_host']);
        $smtpPort = trim($_POST['smtp_port']);
        $smtpUser = trim($_POST['smtp_user']);
        $smtpPass = str_replace(' ', '', trim($_POST['smtp_pass']));
        $smtpEncryption = trim($_POST['smtp_encryption']);
        $emailFrom = trim($_POST['email_from']);
        $emailFromName = trim($_POST['email_from_name']);

        $needsQuote = function($value) {
            return strpos($value, ' ') !== false || strpos($value, '#') !== false || strpos($value, '"') !== false;
        };

        $escapeValue = function($value) use ($needsQuote) {
            $value = str_replace('\\', '\\\\', $value);
            $value = str_replace('"', '\\"', $value);
            return $needsQuote($value) ? '"' . $value . '"' : $value;
        };

        if (strpos($envContent, 'SMTP_HOST=') !== false) {
            $envContent = preg_replace('/SMTP_HOST=.*/', "SMTP_HOST=" . $escapeValue($smtpHost), $envContent);
            $envContent = preg_replace('/SMTP_PORT=.*/', "SMTP_PORT=$smtpPort", $envContent);
            $envContent = preg_replace('/SMTP_USER=.*/', "SMTP_USER=" . $escapeValue($smtpUser), $envContent);
            $envContent = preg_replace('/SMTP_PASS=.*/', "SMTP_PASS=" . $smtpPass, $envContent);
            $envContent = preg_replace('/SMTP_ENCRYPTION=.*/', "SMTP_ENCRYPTION=$smtpEncryption", $envContent);
            $envContent = preg_replace('/EMAIL_FROM=.*/', "EMAIL_FROM=$emailFrom", $envContent);

            if (strpos($envContent, 'EMAIL_FROM_NAME=') !== false) {
                $envContent = preg_replace('/EMAIL_FROM_NAME=.*/', "EMAIL_FROM_NAME=" . $escapeValue($emailFromName), $envContent);
            } else {
                $envContent .= "\nEMAIL_FROM_NAME=" . $escapeValue($emailFromName);
            }
        } else {
            $envContent .= "\n\n# ============================================\n";
            $envContent .= "# SMTP EMAIL CONFIGURATION\n";
            $envContent .= "# ============================================\n";
            $envContent .= "SMTP_HOST=" . $escapeValue($smtpHost) . "\n";
            $envContent .= "SMTP_PORT=$smtpPort\n";
            $envContent .= "SMTP_USER=" . $escapeValue($smtpUser) . "\n";
            $envContent .= "SMTP_PASS=$smtpPass\n";
            $envContent .= "SMTP_ENCRYPTION=$smtpEncryption\n";
            $envContent .= "EMAIL_FROM=$emailFrom\n";
            $envContent .= "EMAIL_FROM_NAME=" . $escapeValue($emailFromName) . "\n";
        }

        if (file_put_contents($envFile, $envContent)) {
            $_ENV['SMTP_HOST'] = $smtpHost;
            $_ENV['SMTP_PORT'] = $smtpPort;
            $_ENV['SMTP_USER'] = $smtpUser;
            $_ENV['SMTP_PASS'] = $smtpPass;
            $_ENV['SMTP_ENCRYPTION'] = $smtpEncryption;
            $_ENV['EMAIL_FROM'] = $emailFrom;
            $_ENV['EMAIL_FROM_NAME'] = $emailFromName;

            $success = 'SMTP settings saved successfully!';
        } else {
            $error = 'Failed to save settings. Check file permissions.';
        }
    }

    if (isset($_POST['test_email'])) {
        $testEmail = trim($_POST['test_email_address']);

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->SMTPDebug = 0;
            $mail->isSMTP();
            $mail->Host = getenv('SMTP_HOST') ?: ($_ENV['SMTP_HOST'] ?? 'smtp.gmail.com');
            $mail->SMTPAuth = true;
            $mail->Username = getenv('SMTP_USER') ?: ($_ENV['SMTP_USER'] ?? '');
            $mail->Password = getenv('SMTP_PASS') ?: ($_ENV['SMTP_PASS'] ?? '');
            $mail->SMTPSecure = getenv('SMTP_ENCRYPTION') ?: ($_ENV['SMTP_ENCRYPTION'] ?? 'tls');
            $mail->Port = (int)(getenv('SMTP_PORT') ?: ($_ENV['SMTP_PORT'] ?? 587));

            $fromEmail = getenv('EMAIL_FROM') ?: ($_ENV['EMAIL_FROM'] ?? 'noreply@bulkreplacetool.com');
            $fromName = getenv('EMAIL_FROM_NAME') ?: ($_ENV['EMAIL_FROM_NAME'] ?? 'BulkReplaceTool');

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($testEmail);
            $mail->isHTML(true);
            $mail->Subject = 'Test Email from BulkReplace - ' . date('Y-m-d H:i:s');
            $mail->Body = '
                <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
                    <div style="background: #28a745; padding: 30px; text-align: center; border-radius: 10px 10px 0 0;">
                        <h1 style="color: white; margin: 0;">✅ Email Success!</h1>
                    </div>
                    <div style="background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px;">
                        <p style="font-size: 16px; color: #333;">Your SMTP email is working!</p>
                        <div style="background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #28a745;">
                            <p><strong>Test Details:</strong></p>
                            <ul style="color: #666;">
                                <li>Sent: ' . date('Y-m-d H:i:s') . '</li>
                                <li>From: ' . htmlspecialchars($fromEmail) . '</li>
                                <li>To: ' . htmlspecialchars($testEmail) . '</li>
                            </ul>
                        </div>
                    </div>
                </div>
            ';
            $mail->AltBody = 'Email test successful! Sent at: ' . date('Y-m-d H:i:s');

            if ($mail->send()) {
                $testResult = "✅ Test email sent successfully to $testEmail! Check your inbox and spam folder.";
            } else {
                $testResult = "❌ Failed: " . $mail->ErrorInfo;
            }
        } catch (Exception $e) {
            $testResult = "❌ Error: " . $e->getMessage();
            if (isset($mail) && $mail->ErrorInfo) {
                $testResult .= "<br>Details: " . $mail->ErrorInfo;
            }
            error_log("Email test error: " . $e->getMessage() . " | " . ($mail->ErrorInfo ?? ''));
        }
    }
}

$smtpHost = getenv('SMTP_HOST') ?: ($_ENV['SMTP_HOST'] ?? 'smtp.gmail.com');
$smtpPort = getenv('SMTP_PORT') ?: ($_ENV['SMTP_PORT'] ?? '587');
$smtpUser = getenv('SMTP_USER') ?: ($_ENV['SMTP_USER'] ?? '');
$smtpPass = getenv('SMTP_PASS') ?: ($_ENV['SMTP_PASS'] ?? '');
$smtpEncryption = getenv('SMTP_ENCRYPTION') ?: ($_ENV['SMTP_ENCRYPTION'] ?? 'tls');
$emailFrom = getenv('EMAIL_FROM') ?: ($_ENV['EMAIL_FROM'] ?? 'noreply@bulkreplacetool.com');
$emailFromName = getenv('EMAIL_FROM_NAME') ?: ($_ENV['EMAIL_FROM_NAME'] ?? 'BulkReplaceTool');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Setup - Admin Panel</title>
    <link rel="stylesheet" href="../assets/main.css">
    <style>
        .setup-container {
            max-width: 900px;
            margin: 40px auto;
            padding: 20px;
        }
        .setup-card {
            background: white;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .setup-card h2 {
            margin-top: 0;
            color: #333;
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #555;
        }
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        .form-group small {
            color: #666;
            font-size: 12px;
        }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
        }
        .btn-primary {
            background: #007bff;
            color: white;
        }
        .btn-success {
            background: #28a745;
            color: white;
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
            text-decoration: none;
            display: inline-block;
        }
        .alert {
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }
        .alert-danger {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }
        .alert-info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }
        .instructions {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 4px;
            margin-top: 20px;
        }
        .instructions h3 {
            margin-top: 0;
            color: #333;
        }
        .instructions ol {
            padding-left: 20px;
        }
        .instructions li {
            margin-bottom: 10px;
            line-height: 1.6;
        }
        .instructions code {
            background: #e9ecef;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
        .test-section {
            border-top: 2px solid #eee;
            padding-top: 20px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <?php include '_sidebar.php'; ?>

    <div class="setup-container">
        <a href="index.php" class="btn btn-secondary" style="margin-bottom: 20px;">← Back to Dashboard</a>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($testResult): ?>
            <div class="alert alert-info"><?php echo $testResult; ?></div>
        <?php endif; ?>

        <div class="setup-card">
            <h2>📧 SMTP Email Configuration</h2>

            <form method="POST">
                <div class="form-group">
                    <label>SMTP Host</label>
                    <input type="text" name="smtp_host" value="<?php echo htmlspecialchars($smtpHost); ?>" required>
                    <small>For Gmail: smtp.gmail.com</small>
                </div>

                <div class="form-group">
                    <label>SMTP Port</label>
                    <input type="number" name="smtp_port" value="<?php echo htmlspecialchars($smtpPort); ?>" required>
                    <small>For Gmail TLS: 587 | For Gmail SSL: 465</small>
                </div>

                <div class="form-group">
                    <label>SMTP Username (Email)</label>
                    <input type="email" name="smtp_user" value="<?php echo htmlspecialchars($smtpUser); ?>" required>
                    <small>Your Gmail address (e.g., youremail@gmail.com)</small>
                </div>

                <div class="form-group">
                    <label>SMTP Password (App Password)</label>
                    <input type="password" name="smtp_pass" value="<?php echo htmlspecialchars($smtpPass); ?>" required>
                    <small>Use Gmail App Password, NOT your regular password!</small>
                </div>

                <div class="form-group">
                    <label>Encryption</label>
                    <select name="smtp_encryption" required>
                        <option value="tls" <?php echo $smtpEncryption === 'tls' ? 'selected' : ''; ?>>TLS (Recommended)</option>
                        <option value="ssl" <?php echo $smtpEncryption === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>From Email</label>
                    <input type="email" name="email_from" value="<?php echo htmlspecialchars($emailFrom); ?>" required>
                    <small>Email address shown as sender</small>
                </div>

                <div class="form-group">
                    <label>From Name</label>
                    <input type="text" name="email_from_name" value="<?php echo htmlspecialchars($emailFromName); ?>" required>
                    <small>Name shown as sender</small>
                </div>

                <button type="submit" name="save_smtp" class="btn btn-primary">💾 Save SMTP Settings</button>
            </form>

            <div class="test-section">
                <h3>🧪 Test Email</h3>
                <form method="POST">
                    <div class="form-group">
                        <label>Test Email Address</label>
                        <input type="email" name="test_email_address" placeholder="your@email.com" required>
                    </div>
                    <button type="submit" name="test_email" class="btn btn-success">📨 Send Test Email</button>
                </form>
            </div>
        </div>

        <div class="setup-card instructions">
            <h3>📖 How to Setup Gmail SMTP</h3>
            <ol>
                <li>
                    <strong>Enable 2-Step Verification</strong><br>
                    Go to <a href="https://myaccount.google.com/security" target="_blank">Google Account Security</a> → Enable 2-Step Verification
                </li>
                <li>
                    <strong>Generate App Password</strong><br>
                    Go to <a href="https://myaccount.google.com/apppasswords" target="_blank">App Passwords</a><br>
                    Select app: <code>Mail</code> | Select device: <code>Other (Custom name)</code><br>
                    Type: <code>BulkReplaceTool</code> → Click <strong>Generate</strong>
                </li>
                <li>
                    <strong>Copy the 16-character password</strong><br>
                    Example: <code>abcd efgh ijkl mnop</code> (remove spaces when pasting)
                </li>
                <li>
                    <strong>Fill the form above</strong><br>
                    SMTP Host: <code>smtp.gmail.com</code><br>
                    SMTP Port: <code>587</code><br>
                    SMTP User: Your Gmail address<br>
                    SMTP Password: The 16-character app password<br>
                    Encryption: <code>TLS</code>
                </li>
                <li>
                    <strong>Save and Test</strong><br>
                    Click "Save SMTP Settings" then send a test email!
                </li>
            </ol>

            <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border-radius: 4px; border: 1px solid #ffc107;">
                <strong>⚠️ Important Notes:</strong>
                <ul style="margin: 10px 0 0 0; padding-left: 20px;">
                    <li>Never use your regular Gmail password - always use App Password!</li>
                    <li>If you see "Less secure app access" - ignore it, use App Password instead</li>
                    <li>App Password only works if 2-Step Verification is enabled</li>
                    <li>Remove spaces from the 16-character password when pasting</li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>
