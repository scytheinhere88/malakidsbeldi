<?php
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/TwoFactorAuth.php';
require_once dirname(__DIR__).'/includes/AuditLogger.php';
requireLogin();

$user = currentUser();
$twoFA = new TwoFactorAuth(db(), $user['id']);
$auditLogger = new AuditLogger(db());
$auditLogger->setUserId($user['id']);

$msg = '';
$err = '';
$showQR = false;
$qrUrl = '';
$backupCodes = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $err = 'Security validation failed.';
    } elseif (isset($_POST['enable_2fa'])) {
        $secret = $twoFA->generateSecret();
        $backupCodes = $twoFA->generateBackupCodes();

        $_SESSION['2fa_setup_secret'] = $secret;
        $_SESSION['2fa_setup_backup_codes'] = $backupCodes;

        $qrUrl = $twoFA->getQRCodeURL($user['email'], $secret);
        $showQR = true;
        $msg = 'Step 1: Scan the QR code below with your authenticator app, then verify the code.';
    } elseif (isset($_POST['confirm_2fa'])) {
        $code = $_POST['code'] ?? '';
        $secret = $_SESSION['2fa_setup_secret'] ?? null;
        $backupCodes = $_SESSION['2fa_setup_backup_codes'] ?? [];

        if (!$secret || empty($backupCodes)) {
            $err = 'Setup session expired. Please start over.';
        } elseif ($twoFA->verifyTOTP($code, $secret)) {
            if ($twoFA->enableTwoFactor($secret, $backupCodes)) {
                unset($_SESSION['2fa_setup_secret']);
                unset($_SESSION['2fa_setup_backup_codes']);
                $msg = '2FA has been activated successfully!';
                $auditLogger->log('2fa_enabled', 'security', 'success');
            } else {
                $err = 'Failed to activate 2FA. Please try again.';
            }
        } else {
            $qrUrl = $twoFA->getQRCodeURL($user['email'], $secret);
            $showQR = true;
            $err = 'Invalid verification code. Please try again.';
        }
    } elseif (isset($_POST['disable_2fa'])) {
        $code = $_POST['code'] ?? '';

        if ($twoFA->verifyTOTP($code) || $twoFA->verifyBackupCode($code)) {
            if ($twoFA->disableTwoFactor()) {
                $msg = '2FA has been disabled for your account.';
                $auditLogger->log('2fa_disabled', 'security', 'success');
            } else {
                $err = 'Failed to disable 2FA. Please try again.';
            }
        } else {
            $err = 'Invalid verification code.';
        }
    } elseif (isset($_POST['regenerate_backup_codes'])) {
        $code = $_POST['code'] ?? '';

        if ($twoFA->verifyTOTP($code) || $twoFA->verifyBackupCode($code)) {
            $newBackupCodes = $twoFA->generateBackupCodes();
            if ($twoFA->regenerateBackupCodes($newBackupCodes)) {
                $backupCodes = $newBackupCodes;
                $msg = 'New backup codes generated successfully! Save them now.';
                $auditLogger->log('2fa_backup_codes_regenerated', 'security', 'success');
            } else {
                $err = 'Failed to regenerate backup codes. Please try again.';
            }
        } else {
            $err = 'Invalid verification code.';
        }
    }
}

$twoFAEnabled = $twoFA->isEnabled();
$remainingBackupCodes = $twoFA->getRemainingBackupCodes();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Settings — Dashboard</title>
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/main.css">
    <style>
        .security-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
        }
        .qr-container {
            text-align: center;
            padding: 24px;
            background: #fff;
            border-radius: 12px;
            margin: 20px 0;
        }
        .backup-codes {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 12px;
            margin: 20px 0;
        }
        .backup-code {
            padding: 12px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 14px;
            text-align: center;
            font-weight: 600;
        }
    </style>
</head>
<body>
<div class="dash-layout">
<?php include '_sidebar.php'; ?>
<div class="dash-main">
    <div class="dash-topbar">
        <div>
            <h1 class="dash-page-title">🔐 Security Settings</h1>
            <p style="color:var(--muted);font-size:12px;margin-top:4px;">Manage your account security and two-factor authentication</p>
        </div>
    </div>
    <div class="dash-content">

    <?php if ($msg): ?>
        <div class="success-box" style="margin-bottom:20px;">✓ <?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <?php if ($err): ?>
        <div class="err-box" style="margin-bottom:20px;">⚠ <?= htmlspecialchars($err) ?></div>
    <?php endif; ?>

    <div class="security-card">
        <h2 style="margin-top:0;">Two-Factor Authentication (2FA)</h2>
        <p style="color:var(--muted);margin-bottom:24px;">
            Add an extra layer of security to your account by requiring a verification code from your phone.
        </p>

        <?php if ($twoFAEnabled): ?>
            <div style="padding:16px;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);border-radius:8px;margin-bottom:20px;">
                <div style="font-weight:600;color:#22c55e;margin-bottom:4px;">✓ 2FA is Active</div>
                <div style="font-size:13px;color:var(--muted);">
                    Your account is protected with two-factor authentication.
                    <?php if ($remainingBackupCodes > 0): ?>
                        You have <?= $remainingBackupCodes ?> backup code<?= $remainingBackupCodes > 1 ? 's' : '' ?> remaining.
                    <?php else: ?>
                        ⚠ You have no backup codes remaining! Generate new ones if needed.
                    <?php endif; ?>
                </div>
            </div>

            <div style="display:flex;gap:20px;flex-wrap:wrap;">
                <div style="flex:1;min-width:300px;">
                    <h3 style="margin-top:0;font-size:16px;">Regenerate Backup Codes</h3>
                    <p style="color:var(--muted);font-size:13px;margin-bottom:16px;">
                        Generate new backup codes. This will invalidate all existing backup codes.
                    </p>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <div class="form-field">
                            <label class="form-label">Enter verification code</label>
                            <input type="text" name="code" placeholder="000000" required pattern="[0-9]{6,8}" style="font-family:'JetBrains Mono',monospace;">
                        </div>
                        <button type="submit" name="regenerate_backup_codes" class="btn btn-amber">Regenerate Codes</button>
                    </form>
                </div>

                <div style="flex:1;min-width:300px;">
                    <h3 style="margin-top:0;font-size:16px;">Disable 2FA</h3>
                    <p style="color:var(--muted);font-size:13px;margin-bottom:16px;">
                        Turn off two-factor authentication for your account.
                    </p>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <div class="form-field">
                            <label class="form-label">Enter verification code</label>
                            <input type="text" name="code" placeholder="000000" required pattern="[0-9]{6,8}" style="font-family:'JetBrains Mono',monospace;">
                        </div>
                        <button type="submit" name="disable_2fa" class="btn btn-danger">Disable 2FA</button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div style="padding:16px;background:rgba(245,158,11,0.1);border:1px solid rgba(245,158,11,0.3);border-radius:8px;margin-bottom:20px;">
                <div style="font-weight:600;color:#f59e0b;margin-bottom:4px;">⚠ 2FA is Disabled</div>
                <div style="font-size:13px;color:var(--muted);">
                    Your account is only protected by your password. Enable 2FA for better security.
                </div>
            </div>

            <?php if ($showQR): ?>
                <div class="qr-container">
                    <h3 style="margin-top:0;color:#000;">Step 1: Scan this QR Code</h3>
                    <div style="margin:20px 0;">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= urlencode($qrUrl) ?>"
                             alt="2FA QR Code"
                             style="border:2px solid #000;border-radius:8px;">
                    </div>
                    <p style="color:#666;font-size:13px;margin-bottom:0;">
                        Use Google Authenticator, Authy, or any TOTP app
                    </p>
                </div>

                <?php if (!empty($_SESSION['2fa_setup_backup_codes']) || !empty($backupCodes)): ?>
                    <?php
                    $displayCodes = !empty($backupCodes) ? $backupCodes : $_SESSION['2fa_setup_backup_codes'];
                    ?>
                    <div style="padding:20px;background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:8px;margin:20px 0;">
                        <h3 style="margin-top:0;color:#ef4444;">🔑 Your Backup Codes (Save These!)</h3>
                        <p style="font-size:13px;color:var(--muted);margin-bottom:16px;">
                            <strong>IMPORTANT:</strong> Copy these codes now! Each code can only be used once if you lose access to your authenticator app.
                        </p>
                        <div class="backup-codes">
                            <?php foreach ($displayCodes as $code): ?>
                                <div class="backup-code"><?= htmlspecialchars($code) ?></div>
                            <?php endforeach; ?>
                        </div>
                        <button onclick="printBackupCodes()" class="btn btn-ghost" style="margin-top:12px;">
                            🖨 Print Codes
                        </button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['2fa_setup_secret'])): ?>
                    <div style="margin-bottom:16px;padding:12px;background:rgba(59,130,246,0.1);border:1px solid rgba(59,130,246,0.3);border-radius:6px;">
                        <div style="font-weight:600;color:#3b82f6;margin-bottom:8px;">Can't scan the QR code?</div>
                        <div style="font-size:13px;color:var(--muted);margin-bottom:8px;">
                            Enter this key manually in your authenticator app:
                        </div>
                        <div style="padding:12px;background:rgba(255,255,255,0.9);border-radius:6px;font-family:'JetBrains Mono',monospace;font-size:14px;word-break:break-all;text-align:center;font-weight:600;color:#000;">
                            <?= htmlspecialchars($_SESSION['2fa_setup_secret']) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <div style="padding:20px;background:rgba(240,165,0,0.1);border:1px solid rgba(240,165,0,0.3);border-radius:8px;margin:20px 0;">
                    <h3 style="margin-top:0;color:var(--a1);">Step 2: Verify Your Code</h3>
                    <p style="font-size:13px;color:var(--muted);margin-bottom:16px;">
                        Enter the 6-digit code from your authenticator app to complete setup
                    </p>

                    <form method="POST">
                        <?= csrf_field() ?>
                        <div class="form-field">
                            <input type="text" name="code" placeholder="000000" required pattern="[0-9]{6}"
                                   style="font-family:'JetBrains Mono',monospace;font-size:20px;text-align:center;letter-spacing:4px;"
                                   maxlength="6" autocomplete="off">
                        </div>
                        <button type="submit" name="confirm_2fa" class="btn btn-amber">Verify & Activate 2FA</button>
                    </form>
                </div>
            <?php else: ?>
                <form method="POST">
                    <?= csrf_field() ?>
                    <button type="submit" name="enable_2fa" class="btn btn-amber">Enable 2FA</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="security-card">
        <h2 style="margin-top:0;">Security Tips</h2>
        <ul style="color:var(--muted);line-height:2;">
            <li>Use a strong, unique password for your account</li>
            <li>Never share your password or 2FA codes with anyone</li>
            <li>Keep your backup codes in a secure location</li>
            <li>Log out from shared or public devices</li>
            <li>Regularly review your account activity</li>
        </ul>
    </div>
    </div>
</div>
</div>

<script>
function printBackupCodes() {
    const codes = <?= !empty($backupCodes) ? json_encode($backupCodes) : json_encode($_SESSION['2fa_setup_backup_codes'] ?? []) ?>;
    const printWindow = window.open('', '', 'width=600,height=400');
    printWindow.document.write('<html><head><title>2FA Backup Codes</title>');
    printWindow.document.write('<style>body{font-family:monospace;padding:40px;}h1{font-size:20px;}.code{padding:8px;margin:4px;border:1px solid #ccc;}</style>');
    printWindow.document.write('</head><body>');
    printWindow.document.write('<h1>Two-Factor Authentication Backup Codes</h1>');
    printWindow.document.write('<p>Account: <?= htmlspecialchars($user['email']) ?></p>');
    printWindow.document.write('<p>Generated: <?= date('Y-m-d H:i:s') ?></p>');
    printWindow.document.write('<p style="color:red;font-weight:bold;">Store these codes securely. Each can only be used once.</p>');
    codes.forEach(code => {
        printWindow.document.write('<div class="code">' + code + '</div>');
    });
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.print();
}
</script>
</body>
</html>
