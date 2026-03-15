<?php
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/EmailSystem.php';
require_once dirname(__DIR__).'/includes/AuditLogger.php';
require_once dirname(__DIR__).'/includes/RateLimiter.php';
startSession();
if(isLoggedIn()){header('Location:'.APP_URL.'/dashboard/');exit;}

$err='';$suc='';
$auditLogger = new AuditLogger(db());
if($_SERVER['REQUEST_METHOD']==='POST'){
  $fpIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
  $fpLimiter = new RateLimiter(db());
  $fpRateCheck = $fpLimiter->check($fpIp, 'forgot_password', 5, 900);
  if(!$fpRateCheck['allowed']){
    $retryMins = ceil(($fpRateCheck['retry_after'] ?? 900) / 60);
    $err = "Too many requests. Please try again in {$retryMins} minutes.";
    $auditLogger->log('password_reset_rate_limited', 'auth', 'failed', [
      'target_type' => 'ip',
      'target_id'   => $fpIp
    ]);
  } elseif(!csrf_verify()){
    $err='Security validation failed. Please refresh and try again.';
  } else {
    $email=strtolower(trim($_POST['email']??''));
    if(!$email){$err='Email required.';}
    elseif(!filter_var($email,FILTER_VALIDATE_EMAIL)){$err='Invalid email address.';}
    elseif(strlen($email)>254){$err='Invalid email address.';}
    else{
      $emailRateKey = 'forgot_email_' . md5($email);
      $emailRateCheck = $fpLimiter->check($emailRateKey, 'forgot_password_email', 3, 3600);
      if(!$emailRateCheck['allowed']){
        $suc='If that email exists in our system, a reset link has been sent. Please check your inbox (and spam folder).';
        $email='';
      }
    }
    if($email){
      try{
        $stmt=db()->prepare("SELECT id, name, email FROM users WHERE email=?");
        $stmt->execute([$email]);
        $user=$stmt->fetch(PDO::FETCH_ASSOC);

        if($user){
          $token=bin2hex(random_bytes(32));
          $expires=date('Y-m-d H:i:s', strtotime('+1 hour'));

          db()->prepare("UPDATE users SET reset_token=?, reset_token_expires=? WHERE id=?")
             ->execute([$token, $expires, $user['id']]);

          $emailSystem = new EmailSystem(db());
          $resetUrl = APP_URL . '/auth/reset_password.php?token=' . $token;

          $emailSystem->sendFromTemplate('password_reset', $user['email'], $user['name'], [
            'user_name' => $user['name'],
            'reset_url' => $resetUrl
          ], $user['id'], 2);

          $auditLogger->setUserId($user['id']);
          $auditLogger->log('password_reset_requested', 'auth', 'success', [
            'target_type' => 'user',
            'target_id' => $user['id'],
            'request_data' => [
              'email' => $email,
              'token_expires' => $expires
            ]
          ]);

          $suc='Password reset link has been sent to your email. Please check your inbox.';
        } else {
          $auditLogger->log('password_reset_failed', 'auth', 'failed', [
            'target_type' => 'user',
            'target_id' => $email,
            'error_message' => 'Email not found'
          ]);
          $suc='If that email exists, a password reset link has been sent.';
        }
      }catch(Exception $e){$err='Failed to process request. Please try again.';}
    }
  }
}
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="robots" content="noindex, nofollow">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Forgot Password — BulkReplace</title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<style>
.auth-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:40px 20px;position:relative;z-index:1;}
.auth-card{background:var(--card);border:1px solid var(--border);border-top:2px solid var(--a1);border-radius:20px;padding:40px;width:100%;max-width:440px;box-shadow:0 24px 80px rgba(0,0,0,.5),0 0 0 1px rgba(240,165,0,.04);animation:fadeUp .3s ease;}
@keyframes fadeUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:none}}
.page-title{font-size:20px;font-weight:800;color:#fff;margin-bottom:6px;}
.page-desc{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);line-height:1.9;margin-bottom:24px;}
.success-icon{display:inline-flex;align-items:center;justify-content:center;width:64px;height:64px;background:rgba(0,230,118,.1);border:2px solid rgba(0,230,118,.25);border-radius:16px;margin-bottom:16px;font-size:30px;}
.steps-hint{background:rgba(0,212,170,.04);border:1px dashed rgba(0,212,170,.2);border-radius:10px;padding:14px 16px;margin-top:16px;}
.steps-hint-title{font-family:'JetBrains Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:2px;color:var(--a2);margin-bottom:8px;}
.steps-hint-li{font-family:'JetBrains Mono',monospace;font-size:10px;color:var(--muted);display:flex;gap:8px;align-items:flex-start;padding:3px 0;line-height:1.7;}
.steps-hint-li::before{content:'→';color:var(--a2);flex-shrink:0;}
</style>
</head><body>
<div class="auth-wrap">
<div class="auth-card">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:28px;">
    <img src="/img/logo.png" alt="BulkReplace" style="width:48px;height:48px;border-radius:12px;">
    <div>
      <div style="font-size:22px;font-weight:800;color:#fff;">BulkReplace</div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);letter-spacing:3px;text-transform:uppercase;">Password Recovery</div>
    </div>
  </div>

  <?php if($err): ?>
  <div class="err-box">⚠ <?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <?php if($suc): ?>
  <div style="text-align:center;padding:8px 0 4px;">
    <div class="success-icon">📬</div>
    <div style="font-size:18px;font-weight:800;color:#fff;margin-bottom:8px;">Check your inbox</div>
  </div>
  <div class="suc-box">✓ <?= htmlspecialchars($suc) ?></div>
  <div class="steps-hint">
    <div class="steps-hint-title">Next steps</div>
    <div class="steps-hint-li">Open the email from BulkReplace</div>
    <div class="steps-hint-li">Click "Reset My Password" in the email</div>
    <div class="steps-hint-li">Create a new strong password</div>
    <div class="steps-hint-li">Check your spam/junk folder if not received</div>
  </div>
  <hr style="border:none;border-top:1px solid var(--border);margin:20px 0;">
  <div style="text-align:center;font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);">
    Didn't receive it? <a href="/auth/forgot_password.php" style="color:var(--a1);">Try again</a> &nbsp;·&nbsp; <a href="/auth/login.php" style="color:var(--a1);">Back to Login</a>
  </div>
  <?php else: ?>
  <div class="page-title">Forgot your password?</div>
  <div class="page-desc">Enter the email linked to your account and we'll send you a secure reset link valid for 1 hour.</div>
  <form method="POST" id="fp-form">
    <?= csrf_field() ?>
    <div class="form-field">
      <label class="form-label">Email Address</label>
      <input type="email" name="email" id="fp-email" placeholder="you@example.com" value="<?= htmlspecialchars($_POST['email']??'') ?>" required autocomplete="email" autofocus>
    </div>
    <button type="submit" class="btn btn-amber" id="fp-btn" style="width:100%;justify-content:center;">
      <span id="fp-btn-text">Send Reset Link →</span>
    </button>
  </form>
  <hr style="border:none;border-top:1px solid var(--border);margin:20px 0;">
  <div style="text-align:center;font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);">
    <a href="/auth/login.php" style="color:var(--a1);text-decoration:none;">← Back to Login</a>
  </div>
  <?php endif; ?>
</div>
</div>
<script>
document.getElementById('fp-form')?.addEventListener('submit', function() {
  const btn = document.getElementById('fp-btn');
  const txt = document.getElementById('fp-btn-text');
  btn.disabled = true;
  txt.innerHTML = '<span style="display:inline-block;width:13px;height:13px;border:2px solid rgba(0,0,0,.25);border-top-color:#000;border-radius:50%;animation:spin .6s linear infinite;"></span> Sending...';
});
</script>
<style>@keyframes spin{to{transform:rotate(360deg)}}</style>
</body></html>
