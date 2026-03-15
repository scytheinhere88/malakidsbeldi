<?php
require_once dirname(__DIR__).'/config.php';
requireAdmin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Deliverability Check</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Courier New', monospace;
            background: #0a0a0a;
            color: #999;
            padding: 40px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #fff; margin-bottom: 30px; }
        .section {
            background: #1a1a1a;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 20px;
            border-left: 4px solid #28a745;
        }
        .section h2 { color: #28a745; margin-bottom: 15px; font-size: 18px; }
        .info-row {
            display: flex;
            padding: 10px;
            border-bottom: 1px solid #2a2a2a;
        }
        .info-row:last-child { border-bottom: none; }
        .info-row label {
            flex: 0 0 200px;
            color: #666;
            font-weight: bold;
        }
        .info-row value { color: #fff; }
        .status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
        .status.ok { background: #28a745; color: white; }
        .status.warning { background: #ffc107; color: black; }
        .status.error { background: #dc3545; color: white; }
        .fix-box {
            background: #2a2a2a;
            padding: 20px;
            border-radius: 4px;
            margin-top: 15px;
            border-left: 4px solid #ffc107;
        }
        .fix-box h3 { color: #ffc107; margin-bottom: 10px; font-size: 16px; }
        .fix-box pre {
            background: #1a1a1a;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            color: #0f0;
            margin: 10px 0;
        }
        .fix-box p { line-height: 1.6; margin: 10px 0; }
        .nav { margin-bottom: 30px; }
        .nav a {
            color: #28a745;
            text-decoration: none;
            padding: 10px 20px;
            background: #1a1a1a;
            border-radius: 4px;
            display: inline-block;
        }
        .nav a:hover { background: #2a2a2a; }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav">
            <a href="index.php">← Back to Dashboard</a>
            <a href="email_setup.php">Email Setup</a>
        </div>

        <h1>📧 Email Deliverability Check</h1>

        <?php
        $domain = parse_url($_ENV['APP_URL'] ?? 'https://bulkreplacetool.com', PHP_URL_HOST);
        $emailFrom = $_ENV['EMAIL_FROM'] ?? 'noreply@bulkreplacetool.com';
        $emailDomain = substr(strrchr($emailFrom, "@"), 1);
        $smtpUser = $_ENV['SMTP_USER'] ?? '';
        $smtpDomain = substr(strrchr($smtpUser, "@"), 1);
        ?>

        <!-- Configuration Summary -->
        <div class="section">
            <h2>📋 Current Configuration</h2>
            <div class="info-row">
                <label>App Domain:</label>
                <value><?= htmlspecialchars($domain) ?></value>
            </div>
            <div class="info-row">
                <label>Email From:</label>
                <value><?= htmlspecialchars($emailFrom) ?></value>
            </div>
            <div class="info-row">
                <label>Email Domain:</label>
                <value><?= htmlspecialchars($emailDomain) ?></value>
            </div>
            <div class="info-row">
                <label>SMTP User:</label>
                <value><?= htmlspecialchars($smtpUser) ?></value>
            </div>
            <div class="info-row">
                <label>SMTP Domain:</label>
                <value><?= htmlspecialchars($smtpDomain) ?></value>
            </div>
        </div>

        <!-- Issue Detection -->
        <div class="section">
            <h2>⚠️ Deliverability Issues</h2>
            <?php
            $issues = [];

            if ($emailDomain !== $smtpDomain) {
                $issues[] = [
                    'level' => 'error',
                    'title' => 'Domain Mismatch',
                    'description' => "You're sending from <strong>$emailFrom</strong> but authenticating with <strong>$smtpUser</strong>",
                    'impact' => 'Gmail will mark your emails as suspicious or send to spam because the From domain doesn\'t match the authenticated sender.',
                    'fix' => "Change EMAIL_FROM to use the same domain as your SMTP user: <strong>noreply@$smtpDomain</strong>"
                ];
            }

            $spfRecord = @dns_get_record($emailDomain, DNS_TXT);
            $hasSPF = false;
            if ($spfRecord) {
                foreach ($spfRecord as $record) {
                    if (isset($record['txt']) && strpos($record['txt'], 'v=spf1') === 0) {
                        $hasSPF = true;
                        break;
                    }
                }
            }

            if (!$hasSPF) {
                $issues[] = [
                    'level' => 'warning',
                    'title' => 'Missing SPF Record',
                    'description' => "No SPF record found for <strong>$emailDomain</strong>",
                    'impact' => 'Email providers cannot verify that Gmail is authorized to send emails on behalf of your domain.',
                    'fix' => 'Add SPF record to your DNS settings'
                ];
            }

            if (empty($issues)) {
                echo '<div class="info-row"><span class="status ok">✅ No major issues detected</span></div>';
            } else {
                foreach ($issues as $i => $issue) {
                    $statusClass = $issue['level'] === 'error' ? 'error' : 'warning';
                    echo '<div class="info-row" style="flex-direction: column; align-items: flex-start; border-bottom: 1px solid #333; padding: 20px 10px;">';
                    echo '<div style="margin-bottom: 10px;"><span class="status ' . $statusClass . '">' . strtoupper($issue['level']) . '</span> <strong style="color: #fff; margin-left: 10px;">' . $issue['title'] . '</strong></div>';
                    echo '<p style="margin: 10px 0; line-height: 1.6;">' . $issue['description'] . '</p>';
                    echo '<p style="margin: 10px 0; color: #ffc107;"><strong>Impact:</strong> ' . $issue['impact'] . '</p>';
                    echo '<p style="margin: 10px 0; color: #28a745;"><strong>Fix:</strong> ' . $issue['fix'] . '</p>';
                    echo '</div>';
                }
            }
            ?>
        </div>

        <!-- Quick Fix -->
        <?php if ($emailDomain !== $smtpDomain): ?>
        <div class="section" style="border-left-color: #ffc107;">
            <h2>🔧 Quick Fix - Update Email From Address</h2>
            <p style="margin-bottom: 15px;">The easiest solution is to change your FROM email to match your Gmail account:</p>

            <form method="POST" style="margin-top: 20px;">
                <div class="info-row" style="flex-direction: column; border: none;">
                    <label style="margin-bottom: 10px;">New Email From:</label>
                    <input type="text" name="new_email_from" value="noreply@<?= htmlspecialchars($smtpDomain) ?>"
                           style="background: #0a0a0a; border: 1px solid #333; color: #fff; padding: 10px; border-radius: 4px; width: 100%; margin-bottom: 10px;">
                    <button type="submit" name="update_email_from"
                            style="background: #28a745; color: white; border: none; padding: 12px 24px; border-radius: 4px; cursor: pointer; font-weight: bold;">
                        Update EMAIL_FROM
                    </button>
                </div>
            </form>

            <?php
            if (isset($_POST['update_email_from'])) {
                $newEmailFrom = trim($_POST['new_email_from']);
                $envFile = dirname(__DIR__) . '/.env';
                $envContent = file_get_contents($envFile);

                if (strpos($envContent, 'EMAIL_FROM=') !== false) {
                    $envContent = preg_replace('/EMAIL_FROM=.*/', "EMAIL_FROM=$newEmailFrom", $envContent);
                    if (file_put_contents($envFile, $envContent)) {
                        echo '<div style="margin-top: 20px; padding: 15px; background: #28a745; color: white; border-radius: 4px;">';
                        echo '✅ EMAIL_FROM updated to: ' . htmlspecialchars($newEmailFrom);
                        echo '<br><br><a href="?" style="color: white; text-decoration: underline;">Refresh page</a> to see changes';
                        echo '</div>';
                    } else {
                        echo '<div style="margin-top: 20px; padding: 15px; background: #dc3545; color: white; border-radius: 4px;">';
                        echo '❌ Failed to update .env file. Check file permissions.';
                        echo '</div>';
                    }
                }
            }
            ?>
        </div>
        <?php endif; ?>

        <!-- SPF Setup Guide -->
        <div class="section">
            <h2>🔐 SPF Record Setup (Optional but Recommended)</h2>
            <p style="margin-bottom: 15px;">Add this TXT record to your DNS settings at your domain registrar:</p>

            <div class="fix-box">
                <h3>DNS TXT Record</h3>
                <div class="info-row" style="border: none;">
                    <label>Type:</label>
                    <value>TXT</value>
                </div>
                <div class="info-row" style="border: none;">
                    <label>Name/Host:</label>
                    <value>@ (or <?= htmlspecialchars($emailDomain) ?>)</value>
                </div>
                <div class="info-row" style="border: none;">
                    <label>Value:</label>
                    <value><pre style="margin: 0;">v=spf1 include:_spf.google.com ~all</pre></value>
                </div>
                <p style="margin-top: 15px; color: #999;">
                    This tells email providers that Gmail is authorized to send emails on behalf of <?= htmlspecialchars($emailDomain) ?>
                </p>
            </div>
        </div>

        <!-- Test Results -->
        <div class="section">
            <h2>🧪 Testing Recommendations</h2>
            <div class="info-row" style="flex-direction: column; border: none;">
                <ol style="margin-left: 20px; line-height: 2;">
                    <li>Update EMAIL_FROM to match your SMTP user domain</li>
                    <li>Send test email via <a href="email_setup.php" style="color: #28a745;">Email Setup page</a></li>
                    <li>Check inbox AND spam folder</li>
                    <li>Wait 1-2 minutes for delivery</li>
                    <li>If still in spam, set up SPF record</li>
                </ol>
            </div>
        </div>
    </div>
</body>
</html>
