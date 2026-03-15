<?php
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/Analytics.php';
require_once dirname(__DIR__).'/includes/EmailSystem.php';
require_once dirname(__DIR__).'/includes/SecurityManager.php';
require_once dirname(__DIR__).'/includes/AuditLogger.php';
require_once dirname(__DIR__).'/includes/EnhancedRateLimiter.php';
require_once dirname(__DIR__).'/includes/SystemMonitor.php';
startSession();
if(isLoggedIn()){header('Location:'.APP_URL.'/dashboard/');exit;}

$analytics = new Analytics(db());
$analytics->trackEvent('page_view', 'registration', null);
$auditLogger = new AuditLogger(db());

$err='';$suc='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  $analytics->trackEvent('registration_started', 'user', null);

  $regIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
  $regMonitor = new SystemMonitor(db());
  $regLimiter = new EnhancedRateLimiter(db(), $regMonitor);
  $regRateCheck = $regLimiter->check($regIp, 'register', 'free', null);
  if(!$regRateCheck['allowed']){
    $err='Too many registration attempts. Please try again later.';
    $auditLogger->log('registration_rate_limited', 'auth', 'failed', [
      'target_type' => 'ip',
      'target_id'   => $regIp
    ]);
  } elseif(!csrf_verify()){
    $err='Security validation failed. Please refresh and try again.';
  } else {
    $name=trim($_POST['name']??'');
    $email=strtolower(trim($_POST['email']??''));
    $pass=$_POST['password']??'';
    $pass2=$_POST['password2']??'';

    if(!$name||!$email||!$pass){
      $err='All fields required.';
      $auditLogger->log('registration_failed', 'auth', 'failed', [
        'target_type' => 'user',
        'target_id' => $email,
        'error_message' => 'Missing required fields'
      ]);
    }
    elseif(!filter_var($email,FILTER_VALIDATE_EMAIL)){
      $err='Invalid email address.';
      $auditLogger->log('registration_failed', 'auth', 'failed', [
        'target_type' => 'user',
        'target_id' => $email,
        'error_message' => 'Invalid email format'
      ]);
    }
    elseif(strlen($pass)<12){
      $err='Password must be at least 12 characters long.';
      $auditLogger->log('registration_failed', 'auth', 'failed', [
        'target_type' => 'user',
        'target_id' => $email,
        'error_message' => 'Password too short'
      ]);
    }
    elseif($pass!==$pass2){
      $err='Passwords do not match.';
      $auditLogger->log('registration_failed', 'auth', 'failed', [
        'target_type' => 'user',
        'target_id' => $email,
        'error_message' => 'Password mismatch'
      ]);
    }
    else{
      $securityManager = new SecurityManager(db());
      $passwordValidation = $securityManager->validatePasswordStrength($pass);

      if(!$passwordValidation['valid']){
        $err = implode(' ', $passwordValidation['errors']);
        $auditLogger->log('registration_failed', 'auth', 'failed', [
          'target_type' => 'user',
          'target_id' => $email,
          'error_message' => 'Weak password: ' . $err
        ]);
      }
      else{
    try{
      $chk=db()->prepare("SELECT id FROM users WHERE email=?");$chk->execute([$email]);
      if($chk->fetch()){
        $err='Registration failed. Please check your information and try again.';
        $auditLogger->log('registration_failed', 'auth', 'failed', [
          'target_type' => 'user',
          'target_id' => $email,
          'error_message' => 'Email already exists'
        ]);
      }
      else{
        $hash=password_hash($pass,PASSWORD_BCRYPT,['cost'=>12]);
        db()->prepare("INSERT INTO users(name,email,password,plan,created_at)VALUES(?,?,?,'free',NOW())")->execute([$name,$email,$hash]);
        $uid=db()->lastInsertId();
        $analytics->trackEvent('registration_completed', 'user', $uid, ['plan' => 'free']);

        $auditLogger->setUserId($uid);
        $auditLogger->log('user_registered', 'auth', 'success', [
          'target_type' => 'user',
          'target_id' => $uid,
          'request_data' => [
            'name' => $name,
            'email' => $email,
            'plan' => 'free'
          ]
        ]);

        $emailSystem = new EmailSystem(db());
        $emailSystem->sendFromTemplate('welcome', $email, $name, [
          'user_name' => $name,
          'plan_name' => 'Free Plan'
        ], $uid, 3);

        $_SESSION['uid']=$uid;$_SESSION['lt']=time();
        header('Location:'.APP_URL.'/dashboard/');exit;
      }
    }catch(Exception $e){
      $err='Registration failed. Please try again.';
      $auditLogger->log('registration_failed', 'auth', 'error', [
        'target_type' => 'user',
        'target_id' => $email,
        'error_message' => 'Database error: ' . $e->getMessage()
      ]);
    }
      }
    }
  }
}
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="robots" content="index, follow"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Register — BulkReplace</title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<style>.auth-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:40px 20px;}.auth-card{background:var(--card);border:1px solid var(--border);border-top:2px solid var(--a1);border-radius:20px;padding:40px;width:100%;max-width:440px;box-shadow:0 24px 80px rgba(0,0,0,.5);animation:fadeUp .3s ease;}@keyframes fadeUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:none}}</style>
</head><body>
<div class="auth-wrap"><div class="auth-card">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:28px;">
    <img src="/img/logo.png" alt="BulkReplace" style="width:48px;height:48px;border-radius:12px;">
    <div><div style="font-size:22px;font-weight:800;color:#fff;">BulkReplace</div><div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);letter-spacing:3px;text-transform:uppercase;">Create Account</div></div>
  </div>
  <?php if($err): ?><div class="err-box">⚠ <?= htmlspecialchars($err) ?></div><?php endif; ?>
  <form method="POST">
    <?= csrf_field() ?>
    <div class="form-field"><label class="form-label">Full Name</label><input type="text" name="name" placeholder="Your name" value="<?= htmlspecialchars($_POST['name']??'') ?>" required></div>
    <div class="form-field"><label class="form-label">Email</label><input type="email" name="email" placeholder="you@example.com" value="<?= htmlspecialchars($_POST['email']??'') ?>" required></div>
    <div class="form-field">
      <label class="form-label">Password</label>
      <input type="password" name="password" id="password" placeholder="Min. 12 characters (A-Z, a-z, 0-9, special)" required>
      <div id="password-strength" style="margin-top:8px;height:4px;background:#333;border-radius:2px;overflow:hidden;">
        <div id="strength-bar" style="height:100%;width:0%;transition:all 0.3s;background:#666;"></div>
      </div>
      <div id="password-feedback" style="font-size:10px;margin-top:6px;color:var(--muted);"></div>
    </div>
    <div class="form-field"><label class="form-label">Confirm Password</label><input type="password" name="password2" placeholder="Repeat password" required></div>
    <div class="info-box" style="font-size:10px;">Free plan: 20 CSV rows. No credit card required.</div>
    <button type="submit" class="btn btn-amber" style="width:100%;justify-content:center;">Create Account →</button>
  </form>
  <div style="text-align:center;margin-top:20px;font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);">Already have an account? <a href="/auth/login.php" style="color:var(--a1);text-decoration:none;">Sign In</a></div>
</div></div>
<script>
const password = document.getElementById('password');
const strengthBar = document.getElementById('strength-bar');
const feedback = document.getElementById('password-feedback');

password.addEventListener('input', function() {
  const pass = this.value;
  let strength = 0;
  const checks = {
    length: pass.length >= 12,
    upper: /[A-Z]/.test(pass),
    lower: /[a-z]/.test(pass),
    number: /[0-9]/.test(pass),
    special: /[^A-Za-z0-9]/.test(pass)
  };

  strength = Object.values(checks).filter(Boolean).length;

  const colors = ['#ef4444', '#f59e0b', '#eab308', '#84cc16', '#22c55e'];
  const labels = ['Very Weak', 'Weak', 'Fair', 'Good', 'Strong'];
  const widths = [20, 40, 60, 80, 100];

  strengthBar.style.width = widths[strength - 1] + '%';
  strengthBar.style.background = colors[strength - 1] || '#666';
  feedback.textContent = pass.length > 0 ? labels[strength - 1] || 'Very Weak' : '';
  feedback.style.color = colors[strength - 1] || '#666';
});
</script>
</body></html>
