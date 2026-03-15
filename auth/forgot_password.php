<?php
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/EmailSystem.php';
require_once dirname(__DIR__).'/includes/AuditLogger.php';
startSession();
if(isLoggedIn()){header('Location:'.APP_URL.'/dashboard/');exit;}

$err='';$suc='';
$auditLogger = new AuditLogger(db());
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!csrf_verify()){
    $err='Security validation failed. Please refresh and try again.';
  } else {
    $email=strtolower(trim($_POST['email']??''));
    if(!$email){$err='Email required.';}
    elseif(!filter_var($email,FILTER_VALIDATE_EMAIL)){$err='Invalid email address.';}
    else{
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
<meta name="robots" content="noindex, nofollow"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Forgot Password — BulkReplace</title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<style>.auth-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:40px 20px;}.auth-card{background:var(--card);border:1px solid var(--border);border-top:2px solid var(--a1);border-radius:20px;padding:40px;width:100%;max-width:440px;box-shadow:0 24px 80px rgba(0,0,0,.5);animation:fadeUp .3s ease;}@keyframes fadeUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:none}}</style>
</head><body>
<div class="auth-wrap"><div class="auth-card">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:28px;">
    <img src="/img/logo.png" alt="BulkReplace" style="width:48px;height:48px;border-radius:12px;">
    <div><div style="font-size:22px;font-weight:800;color:#fff;">BulkReplace</div><div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);letter-spacing:3px;text-transform:uppercase;">Reset Password</div></div>
  </div>
  <?php if($err): ?><div class="err-box">⚠ <?= htmlspecialchars($err) ?></div><?php endif; ?>
  <?php if($suc): ?><div class="suc-box">✓ <?= htmlspecialchars($suc) ?></div><?php endif; ?>
  <form method="POST">
    <?= csrf_field() ?>
    <div class="form-field"><label class="form-label">Email Address</label><input type="email" name="email" placeholder="you@example.com" value="<?= htmlspecialchars($_POST['email']??'') ?>" required></div>
    <button type="submit" class="btn btn-amber" style="width:100%;justify-content:center;">Send Reset Link →</button>
  </form>
  <div style="text-align:center;margin-top:20px;font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);"><a href="/auth/login.php" style="color:var(--a1);text-decoration:none;">← Back to Login</a></div>
</div></div>
</body></html>
