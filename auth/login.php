<?php
require_once dirname(__DIR__).'/config.php';
startSession();

// Redirect if already logged in (check before loading other classes)
if(isLoggedIn() && !isset($_SESSION['temp_2fa_user'])){
    header('Location: /dashboard/');
    exit;
}

require_once dirname(__DIR__).'/includes/RateLimiter.php';
require_once dirname(__DIR__).'/includes/SecurityManager.php';
require_once dirname(__DIR__).'/includes/AuditLogger.php';
require_once dirname(__DIR__).'/includes/TwoFactorAuth.php';

if(isset($_GET['cancel_2fa'])){
    unset($_SESSION['temp_2fa_user']);
    header('Location: /auth/login.php');
    exit;
}

$err='';
$next = filter_var($_GET['next']??'/dashboard/', FILTER_SANITIZE_URL);
if(!$next || strpos($next,'/')!==0) $next='/dashboard/';

$rateLimiter = new RateLimiter(db());
$auditLogger = new AuditLogger(db());
$securityManager = new SecurityManager(db(), $auditLogger);

$showTwoFactorStep = false;
$tempUserId = null;

if(isset($_POST['verify_2fa']) && isset($_SESSION['temp_2fa_user'])){
    if(!csrf_verify()){
        $err='Security validation failed. Please refresh and try again.';
        $showTwoFactorStep = true;
        $tempUserId = $_SESSION['temp_2fa_user'];
    } else {
        $tempUserId = $_SESSION['temp_2fa_user'];
        $twoFA = new TwoFactorAuth(db(), $tempUserId);
        $code = trim($_POST['code'] ?? '');

        if(empty($code)){
            $err = 'Please enter a verification code.';
            $showTwoFactorStep = true;
        } elseif($twoFA->verifyTOTP($code) || $twoFA->verifyBackupCode($code)){
            $stmt = db()->prepare("SELECT * FROM users WHERE id=?");
            $stmt->execute([$tempUserId]);
            $u = $stmt->fetch();

            if(!$u){
                $err = 'User not found. Please login again.';
                unset($_SESSION['temp_2fa_user']);
                $showTwoFactorStep = false;
            } else {
                unset($_SESSION['temp_2fa_user']);

                $sessionId = $securityManager->createSession($u['id']);
                $_SESSION['uid'] = (int)$u['id'];
                $_SESSION['session_id'] = $sessionId;
                $_SESSION['lt'] = time();
                session_regenerate_id(true);

                $auditLogger->setUserId($u['id']);
                $auditLogger->logAuth('login_success', $u['email'], 'success', '2FA verified');

                session_write_close();
                header('Location:'.APP_URL.$next);
                exit;
            }
        } else {
            $err = 'Invalid 2FA code. Please try again.';
            $showTwoFactorStep = true;
            $auditLogger->setUserId($tempUserId);
            $auditLogger->logAuth('2fa_failed', 'user_id_'.$tempUserId, 'failed', 'Invalid 2FA code');
        }
    }
}

if($_SERVER['REQUEST_METHOD']==='POST' && !isset($_POST['verify_2fa'])){
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateCheck = $rateLimiter->check($ip, 'login', 10, 900);

    if(!csrf_verify()){
        $err='Security validation failed. Please refresh and try again.';
    } elseif(!$rateCheck['allowed']){
        if($rateCheck['reason'] === 'blocked'){
            $minutes = ceil($rateCheck['retry_after'] / 60);
            $err="Account temporarily locked. Try again in {$minutes} minutes.";
        } else {
            $err='Too many failed attempts. Please try again later.';
        }
    } else {
        $email = strtolower(trim($_POST['email']??''));
        $pass  = $_POST['password']??'';
        if(!$email||!$pass){
            $err='Please fill in all fields.';
        } else {
            if($securityManager->isLoginBlocked($email, $ip)){
                $err = 'Account temporarily blocked due to multiple failed login attempts. Please try again in 15 minutes.';
                $auditLogger->logAuth('login_blocked', $email, 'blocked', 'Multiple failed attempts');
            } else {
                try {
                    $s=db()->prepare("SELECT * FROM users WHERE email=?");
                    $s->execute([$email]);
                    $u=$s->fetch();
                    if(!$u){
                        $err='Invalid email or password.';
                        $securityManager->recordFailedLogin($email, $ip);
                        $auditLogger->logAuth('login_failed', $email, 'failed', 'Invalid credentials');
                    } elseif(!password_verify($pass,$u['password'])){
                        $err='Invalid email or password.';
                        $securityManager->recordFailedLogin($email, $ip);
                        $auditLogger->logAuth('login_failed', $email, 'failed', 'Invalid password');
                    } elseif(($u['status']??'active')==='suspended'){
                        $err='Account suspended. Contact support.';
                        $auditLogger->logAuth('login_failed', $email, 'failed', 'Account suspended');
                    } elseif($u['account_locked']??false){
                        $err='Account locked for security reasons. Please contact support.';
                        $auditLogger->logAuth('login_failed', $email, 'failed', 'Account locked');
                    } else {
                        $twoFA = new TwoFactorAuth(db(), $u['id']);
                        if($twoFA->isEnabled()){
                            $_SESSION['temp_2fa_user'] = $u['id'];
                            $showTwoFactorStep = true;
                            $tempUserId = $u['id'];
                        } else {
                            $rateLimiter->reset($ip, 'login');
                            $securityManager->clearFailedLoginAttempts($email, $ip);

                            $sessionId = $securityManager->createSession($u['id']);
                            $_SESSION['uid'] = (int)$u['id'];
                            $_SESSION['session_id'] = $sessionId;
                            $_SESSION['lt']  = time();

                            $auditLogger->setUserId($u['id']);
                            $auditLogger->logAuth('login_success', $email, 'success');

                            session_write_close();
                            header('Location:'.APP_URL.$next);
                            exit;
                        }
                    }
                } catch(Exception $e){
                    $err='Login error. Please try again.';
                    error_log("Login error: " . $e->getMessage());
                }
            }
        }
    }
}

if(isset($_SESSION['temp_2fa_user']) && !isset($_POST['verify_2fa'])){
    $showTwoFactorStep = true;
    $tempUserId = $_SESSION['temp_2fa_user'];
}
?><!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Sign In — BulkReplace</title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<style>
.auth-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:40px 20px;}
.auth-card{background:var(--card);border:1px solid var(--border);border-top:2px solid var(--a1);border-radius:20px;padding:40px;width:100%;max-width:420px;box-shadow:0 24px 80px rgba(0,0,0,.5);animation:fadeUp .3s ease;}
@keyframes fadeUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:none}}
</style>
</head><body>
<div class="auth-wrap"><div class="auth-card">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:28px;">
    <img src="/img/logo.png" alt="BulkReplace" style="width:48px;height:48px;border-radius:12px;">
    <div>
      <div style="font-size:22px;font-weight:800;color:#fff;">BulkReplace</div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);letter-spacing:3px;text-transform:uppercase;">Welcome back</div>
    </div>
  </div>
  <?php if($err): ?>
  <div class="err-box">⚠ <?= htmlspecialchars($err) ?></div>
  <?php endif; ?>
  <?php if(isset($_GET['msg'])&&$_GET['msg']==='logged_out'): ?>
  <div class="info-box">You have been logged out.</div>
  <?php endif; ?>

  <?php if($showTwoFactorStep): ?>
  <div style="margin-bottom:20px;padding:16px;background:rgba(59,130,246,0.1);border:1px solid rgba(59,130,246,0.3);border-radius:12px;font-size:13px;color:var(--muted);">
    <div style="font-weight:600;color:#60a5fa;margin-bottom:8px;">🔐 Two-Factor Authentication</div>
    <span id="instructionText">Enter the 6-digit code from your authenticator app.</span>
  </div>
  <form method="POST" autocomplete="off" id="verifyForm">
    <?= csrf_field() ?>
    <input type="hidden" name="verify_2fa" value="1">
    <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
    <div class="form-field">
      <label class="form-label" id="codeLabel">Authenticator Code</label>
      <input type="text" name="code" id="codeInput" placeholder="000000" required autocomplete="off" autofocus pattern="[0-9]{6,8}" maxlength="8" style="text-align:center;font-size:20px;letter-spacing:4px;font-family:'JetBrains Mono',monospace;">
    </div>
    <button type="submit" class="btn btn-amber" style="width:100%;justify-content:center;margin-top:4px;">Verify Code →</button>
  </form>
  <div style="text-align:center;margin-top:16px;">
    <button onclick="toggleBackupCode()" id="toggleBtn" style="background:none;border:none;color:var(--a1);text-decoration:underline;font-size:13px;cursor:pointer;padding:0;">Use Backup Code</button>
  </div>
  <div style="text-align:center;margin-top:12px;">
    <form method="GET" action="/auth/login.php" style="display:inline;">
      <button type="submit" name="cancel_2fa" value="1" style="background:none;border:none;color:var(--muted);text-decoration:none;font-size:12px;cursor:pointer;padding:0;">← Back to login</button>
    </form>
  </div>
  <script>
  let usingBackupCode = false;
  function toggleBackupCode() {
    usingBackupCode = !usingBackupCode;
    const codeLabel = document.getElementById('codeLabel');
    const codeInput = document.getElementById('codeInput');
    const toggleBtn = document.getElementById('toggleBtn');
    const instructionText = document.getElementById('instructionText');

    if (usingBackupCode) {
      codeLabel.textContent = 'Backup Code';
      codeInput.placeholder = '12345678';
      codeInput.maxLength = '8';
      codeInput.pattern = '[0-9]{8}';
      toggleBtn.textContent = 'Use Authenticator Code';
      instructionText.textContent = 'Enter one of your 8-digit backup codes.';
    } else {
      codeLabel.textContent = 'Authenticator Code';
      codeInput.placeholder = '000000';
      codeInput.maxLength = '6';
      codeInput.pattern = '[0-9]{6,8}';
      toggleBtn.textContent = 'Use Backup Code';
      instructionText.textContent = 'Enter the 6-digit code from your authenticator app.';
    }
    codeInput.value = '';
    codeInput.focus();
  }
  </script>
  <?php else: ?>
  <form method="POST" autocomplete="on">
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">
    <div class="form-field">
      <label class="form-label">Email</label>
      <input type="email" name="email" placeholder="you@example.com" value="<?= htmlspecialchars($_POST['email']??'') ?>" required autocomplete="email">
    </div>
    <div class="form-field" style="position:relative;">
      <label class="form-label">Password</label>
      <input type="password" name="password" id="pi" placeholder="••••••••" required autocomplete="current-password">
      <button type="button" onclick="togglePass()" style="position:absolute;right:12px;bottom:11px;background:none;border:none;color:var(--muted);cursor:pointer;font-size:15px;">👁</button>
    </div>
    <button type="submit" class="btn btn-amber" style="width:100%;justify-content:center;margin-top:4px;">Sign In →</button>
  </form>
  <div style="text-align:center;margin-top:12px;font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);">
    No account? <a href="/auth/register.php" style="color:var(--a1);text-decoration:none;">Create one free</a>
  </div>
  <?php endif; ?>
</div></div>
<script>function togglePass(){const i=document.getElementById('pi');i.type=i.type==='password'?'text':'password';}</script>
</body></html>
