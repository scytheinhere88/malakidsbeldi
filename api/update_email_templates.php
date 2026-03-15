<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

try {
    $pdo = db();
    $pdo->exec("SET NAMES utf8mb4");

    $appUrl = APP_URL ?? 'https://bulkreplace.com';
    $logoUrl = $appUrl . '/img/logo.png';

    $baseStyles = "body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica Neue, Arial, sans-serif; background: #0a0a0a; }
        .email-container { max-width: 600px; margin: 0 auto; background: #111111; }
        .email-header { background: linear-gradient(135deg, #ff4560 0%, #ff6b81 100%); padding: 40px 30px; text-align: center; }
        .logo { width: 64px; height: 64px; border-radius: 16px; margin-bottom: 16px; }
        .brand-name { font-size: 28px; font-weight: 900; color: #ffffff; margin: 0; letter-spacing: -0.5px; }
        .tagline { font-size: 11px; color: rgba(255,255,255,0.8); letter-spacing: 2px; text-transform: uppercase; margin-top: 8px; }
        .email-body { padding: 40px 30px; color: #e0e0e0; }
        .greeting { font-size: 24px; font-weight: 700; color: #ffffff; margin: 0 0 20px 0; }
        .content-text { font-size: 16px; line-height: 1.6; color: #b0b0b0; margin: 0 0 20px 0; }
        .highlight { color: #ff4560; font-weight: 600; }
        .info-box { background: rgba(255,69,96,0.1); border-left: 3px solid #ff4560; padding: 16px 20px; margin: 20px 0; border-radius: 6px; }
        .info-box-title { font-size: 14px; font-weight: 700; color: #ff4560; margin: 0 0 8px 0; text-transform: uppercase; letter-spacing: 1px; }
        .info-box-content { font-size: 14px; color: #e0e0e0; margin: 0; }
        .cta-button { display: inline-block; background: linear-gradient(135deg, #ff4560 0%, #ff6b81 100%); color: #ffffff !important; padding: 16px 32px; text-decoration: none; border-radius: 8px; font-weight: 700; font-size: 16px; margin: 20px 0; text-align: center; }
        .cta-button:hover { background: linear-gradient(135deg, #ff3550 0%, #ff5a71 100%); }
        .footer { background: #0a0a0a; padding: 30px; text-align: center; color: #666666; font-size: 13px; border-top: 1px solid #1a1a1a; }
        .footer-links { margin: 15px 0; }
        .footer-link { color: #ff4560; text-decoration: none; margin: 0 10px; }
        .divider { height: 1px; background: #1a1a1a; margin: 30px 0; }";

    $welcomeHtml = "<!DOCTYPE html>
<html>
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <style>{$baseStyles}</style>
</head>
<body>
    <div class=\"email-container\">
        <div class=\"email-header\">
            <img src=\"{$logoUrl}\" alt=\"BulkReplace\" class=\"logo\">
            <h1 class=\"brand-name\">BulkReplace</h1>
            <p class=\"tagline\">Find & Replace Made Easy</p>
        </div>
        <div class=\"email-body\">
            <h2 class=\"greeting\">Welcome aboard, {{user_name}}!</h2>
            <p class=\"content-text\">
                Thanks for joining <span class=\"highlight\">BulkReplace</span> — the most powerful CSV find & replace tool for professionals.
            </p>
            <p class=\"content-text\">
                Your account is ready to go and you are all set to start transforming your CSV files with ease.
            </p>
            <div class=\"info-box\">
                <div class=\"info-box-title\">Your Plan</div>
                <div class=\"info-box-content\">{{plan_name}}</div>
            </div>
            <a href=\"{{dashboard_url}}\" class=\"cta-button\">Go to Dashboard →</a>
            <div class=\"divider\"></div>
            <p class=\"content-text\" style=\"font-size: 14px;\">
                <strong>Need help getting started?</strong><br>
                Check out our tutorial or reach out to support anytime.
            </p>
        </div>
        <div class=\"footer\">
            <div class=\"footer-links\">
                <a href=\"{{app_url}}\" class=\"footer-link\">Home</a>
                <a href=\"{{dashboard_url}}\" class=\"footer-link\">Dashboard</a>
                <a href=\"{{app_url}}/landing/tutorial.php\" class=\"footer-link\">Tutorial</a>
            </div>
            <p>© 2024 BulkReplace. All rights reserved.</p>
        </div>
    </div>
</body>
</html>";

    $invoiceHtml = "<!DOCTYPE html>
<html>
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <style>{$baseStyles}</style>
</head>
<body>
    <div class=\"email-container\">
        <div class=\"email-header\">
            <img src=\"{$logoUrl}\" alt=\"BulkReplace\" class=\"logo\">
            <h1 class=\"brand-name\">BulkReplace</h1>
            <p class=\"tagline\">Payment Receipt</p>
        </div>
        <div class=\"email-body\">
            <h2 class=\"greeting\">Thanks for your purchase!</h2>
            <p class=\"content-text\">
                Your payment has been successfully processed. Here are your transaction details:
            </p>
            <div class=\"info-box\">
                <div class=\"info-box-title\">Transaction Details</div>
                <div class=\"info-box-content\">
                    <strong>Plan:</strong> {{plan_name}}<br>
                    <strong>Amount:</strong> {{amount}}<br>
                    <strong>Date:</strong> {{date}}<br>
                    <strong>Invoice ID:</strong> {{invoice_id}}
                </div>
            </div>
            <a href=\"{{billing_url}}\" class=\"cta-button\">View Billing Details →</a>
            <p class=\"content-text\" style=\"font-size: 14px; color: #808080;\">
                This is your official receipt. Please keep it for your records.
            </p>
        </div>
        <div class=\"footer\">
            <div class=\"footer-links\">
                <a href=\"{{app_url}}\" class=\"footer-link\">Home</a>
                <a href=\"{{billing_url}}\" class=\"footer-link\">Billing</a>
            </div>
            <p>© 2024 BulkReplace. All rights reserved.</p>
        </div>
    </div>
</body>
</html>";

    $passwordResetHtml = "<!DOCTYPE html>
<html>
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <style>{$baseStyles}</style>
</head>
<body>
    <div class=\"email-container\">
        <div class=\"email-header\">
            <img src=\"{$logoUrl}\" alt=\"BulkReplace\" class=\"logo\">
            <h1 class=\"brand-name\">BulkReplace</h1>
            <p class=\"tagline\">Password Reset Request</p>
        </div>
        <div class=\"email-body\">
            <h2 class=\"greeting\">Reset Your Password</h2>
            <p class=\"content-text\">
                We received a request to reset your password. Click the button below to create a new password:
            </p>
            <a href=\"{{reset_url}}\" class=\"cta-button\">Reset Password →</a>
            <p class=\"content-text\" style=\"font-size: 14px; color: #808080;\">
                This link will expire in 1 hour. If you did not request this reset, please ignore this email.
            </p>
            <div class=\"divider\"></div>
            <p class=\"content-text\" style=\"font-size: 12px; color: #666666;\">
                If the button does not work, copy and paste this link into your browser:<br>
                <span style=\"color: #ff4560;\">{{reset_url}}</span>
            </p>
        </div>
        <div class=\"footer\">
            <p>© 2024 BulkReplace. All rights reserved.</p>
        </div>
    </div>
</body>
</html>";

    $planExpiry7DaysHtml = "<!DOCTYPE html>
<html>
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <style>{$baseStyles}</style>
</head>
<body>
    <div class=\"email-container\">
        <div class=\"email-header\">
            <img src=\"{$logoUrl}\" alt=\"BulkReplace\" class=\"logo\">
            <h1 class=\"brand-name\">BulkReplace</h1>
            <p class=\"tagline\">Plan Expiry Notice</p>
        </div>
        <div class=\"email-body\">
            <h2 class=\"greeting\">Your Plan Expires Soon</h2>
            <p class=\"content-text\">
                Just a friendly reminder that your <span class=\"highlight\">{{plan_name}}</span> plan will expire in 7 days.
            </p>
            <div class=\"info-box\">
                <div class=\"info-box-title\">Expiry Date</div>
                <div class=\"info-box-content\">{{expiry_date}}</div>
            </div>
            <p class=\"content-text\">
                To continue enjoying uninterrupted access, renew your plan today.
            </p>
            <a href=\"{{billing_url}}\" class=\"cta-button\">Renew Now →</a>
        </div>
        <div class=\"footer\">
            <div class=\"footer-links\">
                <a href=\"{{billing_url}}\" class=\"footer-link\">Billing</a>
            </div>
            <p>© 2024 BulkReplace. All rights reserved.</p>
        </div>
    </div>
</body>
</html>";

    $planExpiry1DayHtml = "<!DOCTYPE html>
<html>
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <style>{$baseStyles}</style>
</head>
<body>
    <div class=\"email-container\">
        <div class=\"email-header\" style=\"background: linear-gradient(135deg, #ff4560 0%, #ff3030 100%);\">
            <img src=\"{$logoUrl}\" alt=\"BulkReplace\" class=\"logo\">
            <h1 class=\"brand-name\">BulkReplace</h1>
            <p class=\"tagline\">Urgent: Plan Expires Tomorrow</p>
        </div>
        <div class=\"email-body\">
            <h2 class=\"greeting\">Final Reminder!</h2>
            <p class=\"content-text\">
                Your <span class=\"highlight\">{{plan_name}}</span> plan expires <strong>tomorrow</strong>.
            </p>
            <div class=\"info-box\" style=\"border-color: #ff3030; background: rgba(255,48,48,0.15);\">
                <div class=\"info-box-title\" style=\"color: #ff3030;\">Expires</div>
                <div class=\"info-box-content\">{{expiry_date}}</div>
            </div>
            <p class=\"content-text\">
                Renew now to avoid any interruption in your service.
            </p>
            <a href=\"{{billing_url}}\" class=\"cta-button\">Renew Now →</a>
        </div>
        <div class=\"footer\">
            <p>© 2024 BulkReplace. All rights reserved.</p>
        </div>
    </div>
</body>
</html>";

    $planExpiredHtml = "<!DOCTYPE html>
<html>
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <style>{$baseStyles}</style>
</head>
<body>
    <div class=\"email-container\">
        <div class=\"email-header\" style=\"background: #1a1a1a;\">
            <img src=\"{$logoUrl}\" alt=\"BulkReplace\" class=\"logo\">
            <h1 class=\"brand-name\">BulkReplace</h1>
            <p class=\"tagline\">Plan Expired</p>
        </div>
        <div class=\"email-body\">
            <h2 class=\"greeting\">Your Plan Has Expired</h2>
            <p class=\"content-text\">
                Your <span class=\"highlight\">{{plan_name}}</span> plan has expired. You have been moved to the Free plan.
            </p>
            <p class=\"content-text\">
                Renew anytime to restore your premium features and continue where you left off.
            </p>
            <a href=\"{{billing_url}}\" class=\"cta-button\">Renew Plan →</a>
            <div class=\"divider\"></div>
            <p class=\"content-text\" style=\"font-size: 14px;\">
                Questions? Our support team is here to help.
            </p>
        </div>
        <div class=\"footer\">
            <p>© 2024 BulkReplace. All rights reserved.</p>
        </div>
    </div>
</body>
</html>";

    // Usage warning templates
    $usageWarning80Html = "<!DOCTYPE html>
<html>
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <style>{$baseStyles}</style>
</head>
<body>
    <div class=\"email-container\">
        <div class=\"email-header\" style=\"background: linear-gradient(135deg, #f59e0b 0%, #f97316 100%);\">
            <img src=\"{$logoUrl}\" alt=\"BulkReplace\" class=\"logo\">
            <h1 class=\"brand-name\">Usage Alert</h1>
            <p class=\"tagline\">80% Limit Reached</p>
        </div>
        <div class=\"email-body\">
            <h2 class=\"greeting\">Hey {{user_name}},</h2>
            <p class=\"content-text\">
                You've used <span class=\"highlight\">{{percent}}%</span> of your monthly CSV processing limit.
            </p>
            <div class=\"info-box\" style=\"background: rgba(245,158,11,0.1); border-left-color: #f59e0b;\">
                <p class=\"info-box-title\" style=\"color: #f59e0b;\">Usage Details</p>
                <p class=\"info-box-content\"><strong>Used:</strong> {{used_rows}} rows<br>
                <strong>Limit:</strong> {{max_rows}} rows<br>
                <strong>Remaining:</strong> {{remaining_rows}} rows</p>
            </div>
            <p class=\"content-text\">
                To ensure uninterrupted service, consider upgrading your plan.
            </p>
            <a href=\"{{upgrade_url}}\" class=\"cta-button\">Upgrade Plan</a>
        </div>
        <div class=\"footer\">
            <p>© 2024 BulkReplace. All rights reserved.</p>
        </div>
    </div>
</body>
</html>";

    $usageWarning90Html = "<!DOCTYPE html>
<html>
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <style>{$baseStyles}</style>
</head>
<body>
    <div class=\"email-container\">
        <div class=\"email-header\" style=\"background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);\">
            <img src=\"{$logoUrl}\" alt=\"BulkReplace\" class=\"logo\">
            <h1 class=\"brand-name\">Critical Alert</h1>
            <p class=\"tagline\">90% Limit Reached</p>
        </div>
        <div class=\"email-body\">
            <h2 class=\"greeting\">Urgent: {{user_name}}</h2>
            <p class=\"content-text\">
                You've used <span class=\"highlight\">{{percent}}%</span> of your monthly CSV processing limit. You're running low!
            </p>
            <div class=\"info-box\" style=\"background: rgba(239,68,68,0.1); border-left-color: #ef4444;\">
                <p class=\"info-box-title\" style=\"color: #ef4444;\">Usage Details</p>
                <p class=\"info-box-content\"><strong>Used:</strong> {{used_rows}} rows<br>
                <strong>Limit:</strong> {{max_rows}} rows<br>
                <strong>Remaining:</strong> Only {{remaining_rows}} rows left!</p>
            </div>
            <p class=\"content-text\">
                Once you reach your limit, you won't be able to process more files until next month or until you upgrade.
            </p>
            <a href=\"{{upgrade_url}}\" class=\"cta-button\">Upgrade Now</a>
        </div>
        <div class=\"footer\">
            <p>© 2024 BulkReplace. All rights reserved.</p>
        </div>
    </div>
</body>
</html>";

    $usageLimitReachedHtml = "<!DOCTYPE html>
<html>
<head>
    <meta charset=\"UTF-8\">
    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">
    <style>{$baseStyles}</style>
</head>
<body>
    <div class=\"email-container\">
        <div class=\"email-header\" style=\"background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);\">
            <img src=\"{$logoUrl}\" alt=\"BulkReplace\" class=\"logo\">
            <h1 class=\"brand-name\">Limit Reached</h1>
            <p class=\"tagline\">Processing Paused</p>
        </div>
        <div class=\"email-body\">
            <h2 class=\"greeting\">{{user_name}},</h2>
            <p class=\"content-text\">
                You've reached your monthly CSV processing limit of <span class=\"highlight\">{{max_rows}} rows</span>.
            </p>
            <div class=\"info-box\" style=\"background: rgba(220,38,38,0.1); border-left-color: #dc2626;\">
                <p class=\"info-box-title\" style=\"color: #dc2626;\">Limit Status</p>
                <p class=\"info-box-content\"><strong>Used:</strong> {{used_rows}} rows<br>
                <strong>Limit:</strong> {{max_rows}} rows<br>
                <strong>Status:</strong> Limit reached</p>
            </div>
            <p class=\"content-text\">
                <strong>What happens now?</strong><br>
                • Your current plan limit has been reached<br>
                • Upgrade to continue processing immediately<br>
                • Or wait until next month when your limit resets
            </p>
            <a href=\"{{upgrade_url}}\" class=\"cta-button\">Upgrade to Continue</a>
        </div>
        <div class=\"footer\">
            <p>© 2024 BulkReplace. All rights reserved.</p>
        </div>
    </div>
</body>
</html>";

    $stmt = $pdo->prepare("UPDATE email_templates SET body_html = ? WHERE template_key = ?");

    $templates = [
        ['welcome', $welcomeHtml],
        ['invoice', $invoiceHtml],
        ['password_reset', $passwordResetHtml],
        ['plan_expiry_7days', $planExpiry7DaysHtml],
        ['plan_expiry_1day', $planExpiry1DayHtml],
        ['plan_expired', $planExpiredHtml],
        ['usage_warning_80', $usageWarning80Html],
        ['usage_warning_90', $usageWarning90Html],
        ['usage_limit_reached', $usageLimitReachedHtml]
    ];

    foreach ($templates as $template) {
        $stmt->execute([$template[1], $template[0]]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Email templates updated with professional design and BulkReplace branding',
        'templates_updated' => count($templates),
        'includes_usage_warnings' => true
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
