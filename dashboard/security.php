<?php
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/TwoFactorAuth.php';
require_once dirname(__DIR__).'/includes/AuditLogger.php';
require_once dirname(__DIR__).'/includes/SecurityManager.php';
requireLogin();

$user        = currentUser();
$twoFA       = new TwoFactorAuth(db(), $user['id']);
$auditLogger = new AuditLogger(db());
$auditLogger->setUserId($user['id']);
$secMgr      = new SecurityManager(db(), $auditLogger);

$msg = '';
$err = '';
$showQR      = false;
$qrUrl       = '';
$backupCodes = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $err = 'Security validation failed.';
    } elseif (isset($_POST['enable_2fa'])) {
        $secret      = $twoFA->generateSecret();
        $backupCodes = $twoFA->generateBackupCodes();

        $_SESSION['2fa_setup_secret']       = $secret;
        $_SESSION['2fa_setup_backup_codes'] = $backupCodes;

        $qrUrl   = $twoFA->getQRCodeURL($user['email'], $secret);
        $showQR  = true;
        $msg     = '';

    } elseif (isset($_POST['confirm_2fa'])) {
        $code        = $_POST['code'] ?? '';
        $secret      = $_SESSION['2fa_setup_secret'] ?? null;
        $backupCodes = $_SESSION['2fa_setup_backup_codes'] ?? [];

        if (!$secret || empty($backupCodes)) {
            $err = 'Setup session expired. Please start over.';
        } elseif ($twoFA->verifyTOTP($code, $secret)) {
            if ($twoFA->enableTwoFactor($secret, $backupCodes)) {
                unset($_SESSION['2fa_setup_secret'], $_SESSION['2fa_setup_backup_codes']);
                $msg = '2FA activated! Your account is now more secure.';
                $auditLogger->log('2fa_enabled', 'security', 'success');
            } else {
                $err = 'Failed to activate 2FA. Please try again.';
            }
        } else {
            $qrUrl  = $twoFA->getQRCodeURL($user['email'], $secret);
            $showQR = true;
            $err    = 'Invalid verification code. Please try again.';
        }

    } elseif (isset($_POST['disable_2fa'])) {
        $code = $_POST['code'] ?? '';
        if ($twoFA->verifyTOTP($code) || $twoFA->verifyBackupCode($code)) {
            if ($twoFA->disableTwoFactor()) {
                $msg = '2FA has been disabled for your account.';
                $auditLogger->log('2fa_disabled', 'security', 'success');
            } else {
                $err = 'Failed to disable 2FA.';
            }
        } else {
            $err = 'Invalid verification code.';
        }

    } elseif (isset($_POST['regenerate_backup_codes'])) {
        $code          = $_POST['code'] ?? '';
        $password      = $_POST['account_password'] ?? '';
        $remainingNow  = $twoFA->getRemainingBackupCodes();
        $verified      = false;

        if ($twoFA->verifyTOTP($code) || $twoFA->verifyBackupCode($code)) {
            $verified = true;
        } elseif ($remainingNow === 0 && !empty($password) && password_verify($password, $user['password'])) {
            $verified = true;
        }

        if ($verified) {
            $newBackupCodes = $twoFA->generateBackupCodes();
            if ($twoFA->regenerateBackupCodes($newBackupCodes)) {
                $backupCodes = $newBackupCodes;
                $msg = 'New backup codes generated! Save them in a safe place.';
                $auditLogger->log('2fa_backup_codes_regenerated', 'security', 'success');
            } else {
                $err = 'Failed to regenerate backup codes.';
            }
        } else {
            $err = 'Invalid verification code.';
        }

    } elseif (isset($_POST['change_password'])) {
        $currentPw  = $_POST['current_password'] ?? '';
        $newPw      = $_POST['new_password'] ?? '';
        $confirmPw  = $_POST['confirm_password'] ?? '';

        if (!password_verify($currentPw, $user['password'])) {
            $err = 'Current password is incorrect.';
        } elseif ($newPw !== $confirmPw) {
            $err = 'New passwords do not match.';
        } else {
            $strength = $secMgr->validatePasswordStrength($newPw);
            if (!$strength['valid']) {
                $err = implode(' ', $strength['errors']);
            } elseif (!$secMgr->checkPasswordHistory($user['id'], $newPw)) {
                $err = 'You cannot reuse one of your last 5 passwords.';
            } else {
                $hash = password_hash($newPw, PASSWORD_BCRYPT, ['cost' => 12]);
                db()->prepare("UPDATE users SET password=?, password_updated_at=NOW() WHERE id=?")->execute([$hash, $user['id']]);
                try {
                    db()->prepare("INSERT INTO password_history (user_id, password_hash) VALUES (?,?)")->execute([$user['id'], $hash]);
                } catch(Exception $e) {}
                $auditLogger->log('password_changed', 'security', 'success');
                $msg = 'Password changed successfully.';
            }
        }

    } elseif (isset($_POST['delete_account'])) {
        $confirmText = trim($_POST['confirm_text'] ?? '');
        $password    = $_POST['current_password'] ?? '';

        if ($confirmText !== 'DELETE MY ACCOUNT') {
            $err = 'Please type DELETE MY ACCOUNT exactly to confirm.';
        } elseif (!password_verify($password, $user['password'])) {
            $err = 'Incorrect password.';
        } else {
            $uid = $user['id'];
            $auditLogger->log('account_deletion_requested', 'security', 'success');
            db()->prepare("UPDATE users SET
                deleted_at=NOW(), status='deleted',
                email=CONCAT('deleted_', id, '_', UNIX_TIMESTAMP(), '@deleted.invalid'),
                name='Deleted User', password='',
                gumroad_license=NULL, reset_token=NULL
                WHERE id=?")->execute([$uid]);
            db()->prepare("DELETE FROM two_factor_auth WHERE user_id=?")->execute([$uid]);
            session_destroy();
            header('Location: '.APP_URL.'/auth/login.php?msg=account_deleted');
            exit;
        }
    }
}

$twoFAEnabled         = $twoFA->isEnabled();
$remainingBackupCodes = $twoFA->getRemainingBackupCodes();

$activeSessions = [];
try {
    $sesStmt = db()->prepare("SELECT session_id, ip_address, user_agent, created_at, last_active FROM user_sessions WHERE user_id=? AND expires_at > NOW() ORDER BY last_active DESC LIMIT 10");
    $sesStmt->execute([$user['id']]);
    $activeSessions = $sesStmt->fetchAll();
} catch(Exception $e) {}

$passwordUpdatedAt = $user['password_updated_at'] ?? null;
$daysSincePwChange = $passwordUpdatedAt ? floor((time() - strtotime($passwordUpdatedAt)) / 86400) : null;
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Security Settings — BulkReplace</title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/main.css">
<style>
.sec-card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:24px 28px;margin-bottom:20px;position:relative;overflow:hidden;}
.sec-card-title{font-family:'Syne',sans-serif;font-size:17px;font-weight:800;color:#fff;margin-bottom:6px;display:flex;align-items:center;gap:10px;}
.sec-card-sub{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);margin-bottom:20px;line-height:1.7;}

.twofa-status{display:flex;align-items:center;gap:10px;padding:14px 18px;border-radius:10px;margin-bottom:20px;}
.twofa-status.on{background:rgba(0,212,170,.08);border:1px solid rgba(0,212,170,.25);}
.twofa-status.off{background:rgba(240,165,0,.08);border:1px solid rgba(240,165,0,.2);}
.twofa-dot{width:10px;height:10px;border-radius:50%;}
.twofa-dot.on{background:var(--a2);box-shadow:0 0 8px var(--a2);}
.twofa-dot.off{background:var(--a1);box-shadow:0 0 8px var(--a1);}

.setup-steps{display:flex;gap:0;margin-bottom:24px;border-radius:10px;overflow:hidden;border:1px solid var(--border);}
.setup-step{flex:1;padding:12px 16px;background:var(--dim);text-align:center;position:relative;}
.setup-step.active{background:rgba(240,165,0,.1);border-bottom:2px solid var(--a1);}
.setup-step.done{background:rgba(0,212,170,.08);border-bottom:2px solid var(--a2);}
.setup-step-num{font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:4px;}
.setup-step-label{font-family:'Syne',sans-serif;font-size:12px;font-weight:700;color:var(--text);}

.qr-wrap{display:flex;justify-content:center;padding:20px;background:#fff;border-radius:12px;margin:16px 0;width:fit-content;margin-inline:auto;}

.backup-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;margin:16px 0;}
.backup-code-box{padding:12px 10px;background:var(--bg);border:1px solid var(--border);border-radius:8px;font-family:'JetBrains Mono',monospace;font-size:13px;text-align:center;font-weight:700;color:#fff;letter-spacing:1px;cursor:pointer;transition:border-color .15s;}
.backup-code-box:hover{border-color:var(--a1);}

.pw-strength-bar{height:4px;border-radius:2px;background:var(--dim);margin-top:6px;overflow:hidden;}
.pw-strength-fill{height:100%;border-radius:2px;transition:width .3s,background .3s;}
.pw-req-list{margin:10px 0;padding:0;display:grid;grid-template-columns:1fr 1fr;gap:4px;}
.pw-req{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);list-style:none;display:flex;align-items:center;gap:6px;padding:2px 0;}
.pw-req-dot{width:6px;height:6px;border-radius:50%;background:var(--border);flex-shrink:0;transition:background .2s;}
.pw-req.met .pw-req-dot{background:var(--a2);}
.pw-req.met{color:var(--a2);}

.session-row{display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid rgba(255,255,255,.04);}
.session-row:last-child{border-bottom:none;}
.session-icon{width:34px;height:34px;border-radius:8px;background:var(--dim);display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.session-info{flex:1;min-width:0;}
.session-ua{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.session-meta{font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);margin-top:2px;}
.session-badge{font-family:'JetBrains Mono',monospace;font-size:9px;font-weight:700;padding:3px 8px;border-radius:5px;text-transform:uppercase;}
.session-badge.current{background:rgba(0,212,170,.1);border:1px solid rgba(0,212,170,.3);color:var(--a2);}
.session-badge.other{background:var(--dim);border:1px solid var(--border);color:var(--muted);}

.danger-zone{border-radius:12px;padding:20px 24px;background:rgba(255,69,96,.04);border:1px solid rgba(255,69,96,.15);}
.danger-zone-title{font-family:'Syne',sans-serif;font-size:15px;font-weight:700;color:var(--err);margin-bottom:6px;}

.code-input-big{font-family:'JetBrains Mono',monospace;font-size:22px;text-align:center;letter-spacing:6px;padding:14px;border-radius:10px;width:100%;background:var(--dim);border:1.5px solid var(--border);color:#fff;transition:border-color .2s;box-sizing:border-box;}
.code-input-big:focus{outline:none;border-color:var(--a1);box-shadow:0 0 0 3px rgba(240,165,0,.1);}
</style>
</head>
<body>
<div class="dash-layout">
<?php include '_sidebar.php'; ?>
<div class="dash-main">
<div class="dash-topbar">
  <div class="dash-page-title">Security Settings</div>
</div>
<div class="dash-content">

<?php if($msg): ?><div class="suc-box" style="margin-bottom:20px;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if($err): ?><div class="err-box" style="margin-bottom:20px;"><?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- 2FA CARD -->
<div class="sec-card">
  <div class="sec-card-title">
    <span style="font-size:20px;">◈</span>
    Two-Factor Authentication
    <?php if($twoFAEnabled): ?>
      <span class="badge badge-ok" style="font-size:10px;">Enabled</span>
    <?php else: ?>
      <span class="badge badge-warn" style="font-size:10px;background:rgba(240,165,0,.1);border:1px solid rgba(240,165,0,.3);color:var(--a1);">Disabled</span>
    <?php endif; ?>
  </div>
  <div class="sec-card-sub">Add an extra layer of security by requiring a 6-digit code from your phone when signing in.</div>

  <div class="twofa-status <?= $twoFAEnabled ? 'on' : 'off' ?>">
    <div class="twofa-dot <?= $twoFAEnabled ? 'on' : 'off' ?>"></div>
    <div style="flex:1;">
      <div style="font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:<?= $twoFAEnabled ? 'var(--a2)' : 'var(--a1)' ?>;">
        <?= $twoFAEnabled ? '2FA is Active' : '2FA is Disabled' ?>
      </div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-top:2px;">
        <?php if($twoFAEnabled): ?>
          <?= $remainingBackupCodes ?> backup code<?= $remainingBackupCodes !== 1 ? 's' : '' ?> remaining
          <?php if($remainingBackupCodes === 0): ?>
            <span style="color:var(--err);"> — Generate new ones below!</span>
          <?php endif; ?>
        <?php else: ?>
          Your account is protected by password only
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if($twoFAEnabled): ?>
    <!-- 2FA MANAGEMENT WHEN ENABLED -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;flex-wrap:wrap;">
      <div>
        <div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:#fff;margin-bottom:12px;">Regenerate Backup Codes</div>
        <p style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-bottom:14px;line-height:1.8;">
          Generate new backup codes. All existing backup codes will be invalidated immediately.
        </p>
        <?php if(!empty($backupCodes)): ?>
        <div style="padding:12px;background:rgba(255,69,96,.06);border:1px solid rgba(255,69,96,.2);border-radius:8px;margin-bottom:14px;">
          <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--err);font-weight:700;margin-bottom:8px;">Save these codes now — they won't be shown again!</div>
          <div class="backup-grid">
            <?php foreach($backupCodes as $bc): ?>
            <div class="backup-code-box" onclick="copyCode(this)"><?= htmlspecialchars($bc) ?></div>
            <?php endforeach; ?>
          </div>
          <button onclick="printBackupCodes(<?= htmlspecialchars(json_encode($backupCodes)) ?>)" class="btn" style="font-size:10px;padding:6px 14px;background:var(--dim);border:1px solid var(--border);">Print Codes</button>
        </div>
        <?php endif; ?>
        <form method="POST">
          <?= csrf_field() ?>
          <div class="form-field" style="margin-bottom:12px;">
            <label class="form-label">TOTP or backup code</label>
            <input type="text" name="code" placeholder="000000" pattern="[0-9A-Za-z\-]{6,12}" style="font-family:'JetBrains Mono',monospace;" maxlength="12">
          </div>
          <?php if($remainingBackupCodes === 0): ?>
          <div class="form-field" style="margin-bottom:12px;">
            <label class="form-label">Or account password (no backup codes left)</label>
            <input type="password" name="account_password" autocomplete="current-password">
          </div>
          <?php endif; ?>
          <button type="submit" name="regenerate_backup_codes" class="btn btn-amber" style="font-size:12px;">Regenerate Codes</button>
        </form>
      </div>

      <div>
        <div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:#fff;margin-bottom:12px;">Disable 2FA</div>
        <p style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-bottom:14px;line-height:1.8;">
          Turn off two-factor authentication. You'll only need your password to log in after this.
        </p>
        <form method="POST">
          <?= csrf_field() ?>
          <div class="form-field" style="margin-bottom:12px;">
            <label class="form-label">Verification code to confirm</label>
            <input type="text" name="code" placeholder="000000" required pattern="[0-9]{6,8}" style="font-family:'JetBrains Mono',monospace;" maxlength="8">
          </div>
          <button type="submit" name="disable_2fa" class="btn" style="background:rgba(255,69,96,.1);border:1px solid rgba(255,69,96,.3);color:var(--err);font-size:12px;">Disable 2FA</button>
        </form>
      </div>
    </div>

  <?php else: ?>
    <!-- 2FA SETUP WIZARD -->
    <?php if($showQR): ?>
    <div class="setup-steps">
      <div class="setup-step done">
        <div class="setup-step-num">Step 1</div>
        <div class="setup-step-label" style="color:var(--a2);">Scan QR Code</div>
      </div>
      <div class="setup-step active">
        <div class="setup-step-num">Step 2</div>
        <div class="setup-step-label">Save Backup Codes</div>
      </div>
      <div class="setup-step">
        <div class="setup-step-num">Step 3</div>
        <div class="setup-step-label">Verify & Activate</div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start;">
      <div>
        <div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:#fff;margin-bottom:12px;">Scan with your authenticator app</div>
        <div class="qr-wrap">
          <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?= urlencode($qrUrl) ?>"
               alt="2FA QR Code" style="display:block;border-radius:4px;">
        </div>
        <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);text-align:center;margin-bottom:12px;">
          Works with Google Authenticator, Authy, 1Password, Bitwarden
        </div>

        <?php if(isset($_SESSION['2fa_setup_secret'])): ?>
        <details style="font-family:'JetBrains Mono',monospace;font-size:10px;">
          <summary style="cursor:pointer;color:var(--muted);margin-bottom:8px;">Can't scan the QR? Enter manually</summary>
          <div style="padding:10px 12px;background:var(--bg);border:1px solid var(--border);border-radius:8px;word-break:break-all;font-weight:700;color:var(--a1);letter-spacing:2px;text-align:center;margin-top:8px;">
            <?= htmlspecialchars($_SESSION['2fa_setup_secret']) ?>
          </div>
        </details>
        <?php endif; ?>
      </div>

      <div>
        <?php $displayCodes = !empty($backupCodes) ? $backupCodes : ($_SESSION['2fa_setup_backup_codes'] ?? []); ?>
        <?php if(!empty($displayCodes)): ?>
        <div style="padding:14px 16px;background:rgba(255,69,96,.05);border:1px solid rgba(255,69,96,.2);border-radius:10px;margin-bottom:16px;">
          <div style="font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:var(--err);margin-bottom:4px;">Save Your Backup Codes</div>
          <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-bottom:12px;line-height:1.7;">
            These are one-time use codes. If you lose your phone, use one to access your account.
          </div>
          <div class="backup-grid">
            <?php foreach($displayCodes as $bc): ?>
            <div class="backup-code-box" onclick="copyCode(this)"><?= htmlspecialchars($bc) ?></div>
            <?php endforeach; ?>
          </div>
          <div style="display:flex;gap:8px;margin-top:10px;">
            <button onclick="copyAllCodes(<?= htmlspecialchars(json_encode($displayCodes)) ?>)" class="btn" style="font-size:10px;padding:5px 12px;background:var(--dim);border:1px solid var(--border);">Copy All</button>
            <button onclick="printBackupCodes(<?= htmlspecialchars(json_encode($displayCodes)) ?>)" class="btn" style="font-size:10px;padding:5px 12px;background:var(--dim);border:1px solid var(--border);">Print</button>
          </div>
        </div>
        <?php endif; ?>

        <div style="font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:#fff;margin-bottom:10px;">Enter the code from your app</div>
        <form method="POST">
          <?= csrf_field() ?>
          <input class="code-input-big" type="text" name="code" placeholder="000000"
                 required pattern="[0-9]{6}" maxlength="6" autocomplete="off"
                 oninput="this.value=this.value.replace(/\D/g,'').slice(0,6)">
          <button type="submit" name="confirm_2fa" class="btn btn-amber" style="width:100%;margin-top:12px;">Verify & Activate 2FA</button>
        </form>
      </div>
    </div>

    <?php else: ?>
    <!-- INITIAL STATE - NOT ENABLED -->
    <div style="display:flex;align-items:center;gap:20px;padding:16px;background:var(--dim);border-radius:10px;flex-wrap:wrap;">
      <div style="flex:1;">
        <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);line-height:1.8;">
          After enabling 2FA, you'll scan a QR code with an app like <strong style="color:var(--text);">Google Authenticator</strong> or <strong style="color:var(--text);">Authy</strong>, then enter the 6-digit code to confirm.
        </div>
      </div>
      <form method="POST">
        <?= csrf_field() ?>
        <button type="submit" name="enable_2fa" class="btn btn-amber" style="white-space:nowrap;">Start 2FA Setup</button>
      </form>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- CHANGE PASSWORD CARD -->
<div class="sec-card">
  <div class="sec-card-title">
    <span style="font-size:20px;">◉</span>
    Change Password
    <?php if($daysSincePwChange !== null && $daysSincePwChange > 90): ?>
      <span class="badge badge-warn" style="font-size:10px;background:rgba(240,165,0,.1);border:1px solid rgba(240,165,0,.3);color:var(--a1);">Last changed <?= $daysSincePwChange ?>d ago</span>
    <?php endif; ?>
  </div>
  <div class="sec-card-sub">
    Minimum 8 characters with uppercase, lowercase, number, and special character.
    <?php if($passwordUpdatedAt): ?>Last changed: <?= date('M j, Y', strtotime($passwordUpdatedAt)) ?>.<?php endif; ?>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">
    <form method="POST" id="pwChangeForm">
      <?= csrf_field() ?>
      <div class="form-field" style="margin-bottom:14px;">
        <label class="form-label">Current Password</label>
        <input type="password" name="current_password" required autocomplete="current-password">
      </div>
      <div class="form-field" style="margin-bottom:6px;">
        <label class="form-label">New Password</label>
        <input type="password" name="new_password" id="newPw" required autocomplete="new-password" oninput="checkPwStrength(this.value)">
        <div class="pw-strength-bar"><div class="pw-strength-fill" id="pwStrFill" style="width:0%;"></div></div>
      </div>
      <ul class="pw-req-list" id="pwReqs">
        <li class="pw-req" id="req-len"><div class="pw-req-dot"></div>8+ characters</li>
        <li class="pw-req" id="req-upper"><div class="pw-req-dot"></div>Uppercase letter</li>
        <li class="pw-req" id="req-lower"><div class="pw-req-dot"></div>Lowercase letter</li>
        <li class="pw-req" id="req-num"><div class="pw-req-dot"></div>Number</li>
        <li class="pw-req" id="req-spec"><div class="pw-req-dot"></div>Special character</li>
        <li class="pw-req" id="req-match"><div class="pw-req-dot"></div>Passwords match</li>
      </ul>
      <div class="form-field" style="margin-bottom:16px;">
        <label class="form-label">Confirm New Password</label>
        <input type="password" name="confirm_password" id="confirmPw" required autocomplete="new-password" oninput="checkPwMatch()">
      </div>
      <button type="submit" name="change_password" class="btn btn-amber" id="pwChangeBtn" disabled>Change Password</button>
    </form>

    <div style="background:var(--dim);border-radius:10px;padding:16px;">
      <div style="font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:#fff;margin-bottom:14px;">Password Tips</div>
      <ul style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);line-height:2.2;padding-left:0;list-style:none;">
        <li>◎ Use a unique password not used elsewhere</li>
        <li>◎ Consider a passphrase (3+ random words)</li>
        <li>◎ A password manager can generate & store strong passwords</li>
        <li>◎ Never share your password with anyone</li>
        <li>◎ Change your password if you suspect a breach</li>
      </ul>
    </div>
  </div>
</div>

<!-- ACTIVE SESSIONS CARD -->
<div class="sec-card">
  <div class="sec-card-title">
    <span style="font-size:20px;">◫</span>
    Active Sessions
    <?php if(!empty($activeSessions)): ?>
    <span style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);font-weight:400;"><?= count($activeSessions) ?> session<?= count($activeSessions) !== 1 ? 's' : '' ?></span>
    <?php endif; ?>
  </div>
  <div class="sec-card-sub">All devices currently signed in to your account. Revoke any sessions you don't recognize.</div>

  <?php if(!empty($activeSessions)): ?>
  <div>
    <?php $currentSessId = session_id(); foreach($activeSessions as $s):
      $ua = $s['user_agent'] ?? 'Unknown device';
      $isMobile = preg_match('/Mobile|Android|iPhone/i', $ua);
      $isCurrentSession = ($s['session_id'] === $currentSessId);
      $deviceIcon = $isMobile ? '◱' : '▣';
    ?>
    <div class="session-row">
      <div class="session-icon"><?= $deviceIcon ?></div>
      <div class="session-info">
        <div class="session-ua"><?= htmlspecialchars(substr($ua, 0, 80)) ?></div>
        <div class="session-meta">
          <?= htmlspecialchars($s['ip_address'] ?? '—') ?>
          &bull; Active <?= ago($s['last_active'] ?? $s['created_at']) ?>
        </div>
      </div>
      <span class="session-badge <?= $isCurrentSession ? 'current' : 'other' ?>">
        <?= $isCurrentSession ? 'This device' : 'Other' ?>
      </span>
    </div>
    <?php endforeach; ?>
  </div>
  <div style="margin-top:16px;display:flex;gap:10px;">
    <a href="<?= APP_URL ?>/auth/logout.php?all=1" class="btn" style="font-size:11px;background:rgba(255,69,96,.08);border:1px solid rgba(255,69,96,.2);color:var(--err);">Sign out all other sessions</a>
  </div>
  <?php else: ?>
  <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);padding:20px;text-align:center;background:var(--dim);border-radius:10px;">
    Session tracking not available — no session data found.
  </div>
  <?php endif; ?>
</div>

<!-- DATA & PRIVACY CARD -->
<div class="sec-card">
  <div class="sec-card-title"><span style="font-size:20px;">◎</span> Data & Privacy</div>
  <div class="sec-card-sub">GDPR-compliant data access and account deletion. Your data belongs to you.</div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
    <div>
      <div style="font-family:'Syne',sans-serif;font-size:13px;font-weight:700;color:#fff;margin-bottom:8px;">Export Your Data</div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-bottom:14px;line-height:1.7;">Download a JSON file with all data we hold about your account — profile, usage history, licenses, and settings.</div>
      <a href="<?= APP_URL ?>/api/data_export_gdpr.php" class="btn" style="background:var(--dim);border:1px solid var(--border);">Download My Data</a>
    </div>
    <div class="danger-zone">
      <div class="danger-zone-title">Delete Account</div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);margin-bottom:14px;line-height:1.7;">Permanently anonymize your account and all associated data. This cannot be undone — your plan and usage history will be lost.</div>
      <button onclick="document.getElementById('deleteModal').style.display='flex'" class="btn" style="background:rgba(255,69,96,.1);border:1px solid rgba(255,69,96,.3);color:var(--err);font-size:12px;">Delete My Account</button>
    </div>
  </div>
</div>

<!-- DELETE ACCOUNT MODAL -->
<div id="deleteModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:1000;align-items:center;justify-content:center;padding:20px;backdrop-filter:blur(4px);">
  <div style="background:var(--card);border:1.5px solid rgba(255,69,96,.3);border-radius:16px;padding:28px 32px;max-width:440px;width:100%;box-shadow:0 24px 64px rgba(0,0,0,.5);">
    <div style="font-family:'Syne',sans-serif;font-size:18px;font-weight:800;color:var(--err);margin-bottom:12px;">Delete Account</div>
    <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);margin-bottom:20px;line-height:1.8;">
      This will permanently anonymize your account. All your plan data, usage history, licenses, and settings will be removed. <strong style="color:#fff;">This cannot be undone.</strong>
    </div>
    <form method="POST">
      <?= csrf_field() ?>
      <div class="form-field" style="margin-bottom:14px;">
        <label class="form-label">Current Password</label>
        <input type="password" name="current_password" required autocomplete="current-password">
      </div>
      <div class="form-field" style="margin-bottom:18px;">
        <label class="form-label">Type <code style="color:var(--err);">DELETE MY ACCOUNT</code> to confirm</label>
        <input type="text" name="confirm_text" placeholder="DELETE MY ACCOUNT" required autocomplete="off" style="font-family:'JetBrains Mono',monospace;">
      </div>
      <div style="display:flex;gap:10px;">
        <button type="button" onclick="document.getElementById('deleteModal').style.display='none'" class="btn" style="flex:1;background:var(--dim);border:1px solid var(--border);">Cancel</button>
        <button type="submit" name="delete_account" class="btn" style="flex:1;background:rgba(255,69,96,.15);border:1px solid rgba(255,69,96,.4);color:var(--err);">Delete Forever</button>
      </div>
    </form>
  </div>
</div>

</div>
</div>
</div>

<script>
function copyCode(el) {
    navigator.clipboard.writeText(el.textContent.trim()).then(() => {
        const orig = el.style.borderColor;
        el.style.borderColor = 'var(--a2)';
        el.style.color = 'var(--a2)';
        setTimeout(() => { el.style.borderColor = orig; el.style.color = '#fff'; }, 1200);
    });
}

function copyAllCodes(codes) {
    navigator.clipboard.writeText(codes.join('\n')).then(() => {
        if(window.toast) toast('Copied all backup codes!', 'ok');
    });
}

function printBackupCodes(codes) {
    const w = window.open('', '', 'width=600,height=500');
    w.document.write(`<html><head><title>Backup Codes — BulkReplace</title>
    <style>body{font-family:monospace;padding:40px;}.code{padding:8px 14px;margin:5px 0;border:1px solid #ccc;border-radius:4px;font-size:14px;font-weight:600;display:inline-block;}</style></head><body>
    <h2>2FA Backup Codes — BulkReplace</h2>
    <p>Account: <?= htmlspecialchars(addslashes($user['email'])) ?></p>
    <p>Generated: <?= date('Y-m-d H:i') ?></p>
    <p style="color:red;font-weight:bold;">Each code can only be used once. Store securely.</p>
    ${codes.map(c=>'<div class="code">'+c+'</div>').join('')}
    </body></html>`);
    w.document.close();
    w.print();
}

const pwReqs = {
    'req-len':   pw => pw.length >= 8,
    'req-upper': pw => /[A-Z]/.test(pw),
    'req-lower': pw => /[a-z]/.test(pw),
    'req-num':   pw => /[0-9]/.test(pw),
    'req-spec':  pw => /[^A-Za-z0-9]/.test(pw),
};

function checkPwStrength(pw) {
    const met = Object.values(pwReqs).filter(fn => fn(pw)).length;
    const pct = (met / 5) * 100;
    const colors = ['','#ef4444','#f59e0b','#f0a500','#00d4aa','#10b981'];
    const fill = document.getElementById('pwStrFill');
    fill.style.width = pct + '%';
    fill.style.background = colors[met] || '#6b7280';

    Object.entries(pwReqs).forEach(([id, fn]) => {
        const el = document.getElementById(id);
        if(el) el.classList.toggle('met', fn(pw));
    });
    checkPwMatch();
}

function checkPwMatch() {
    const pw = document.getElementById('newPw').value;
    const cp = document.getElementById('confirmPw').value;
    const el = document.getElementById('req-match');
    const allMet = Object.values(pwReqs).every(fn => fn(pw));
    const match  = pw === cp && cp.length > 0;
    if(el) el.classList.toggle('met', match);
    const btn = document.getElementById('pwChangeBtn');
    if(btn) btn.disabled = !(allMet && match);
}

document.addEventListener('keydown', e => {
    if(e.key === 'Escape') document.getElementById('deleteModal').style.display = 'none';
});
</script>
<script src="/assets/toast.js"></script>
</body>
</html>
