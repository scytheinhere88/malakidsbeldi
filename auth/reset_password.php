<?php
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/SecurityManager.php';
require_once dirname(__DIR__).'/includes/AuditLogger.php';
startSession();
if(isLoggedIn()){header('Location:'.APP_URL.'/dashboard/');exit;}

$securityManager = new SecurityManager(db());
$auditLogger = new AuditLogger(db());

$token=$_GET['token']??'';
$err='';$suc='';
$validToken=false;

if($token){
  $stmt=db()->prepare("SELECT id, name, email FROM users WHERE reset_token=? AND reset_token_expires > NOW()");
  $stmt->execute([$token]);
  $user=$stmt->fetch(PDO::FETCH_ASSOC);
  if($user){$validToken=true;}
}

if($_SERVER['REQUEST_METHOD']==='POST' && $validToken){
  if(!csrf_verify()){
    $err='Security validation failed. Please refresh and try again.';
  } else {
    $pass=$_POST['password']??'';
    $pass2=$_POST['password2']??'';

    $validation = $securityManager->validatePasswordStrength($pass);

    if(!$validation['valid']){
      $err = implode(' ', $validation['errors']);
    }
    elseif($pass!==$pass2){$err='Passwords do not match.';}
    elseif(!$securityManager->checkPasswordHistory($user['id'], $pass)){
      $err='You cannot reuse a recent password. Please choose a different one.';
    }
    else{
      try{
        $hash=password_hash($pass,PASSWORD_BCRYPT);

        db()->prepare("UPDATE users SET password=?, password_updated_at=NOW(), reset_token=NULL, reset_token_expires=NULL WHERE reset_token=?")
           ->execute([$hash, $token]);

        $securityManager->addPasswordToHistory($user['id'], $hash);

        $securityManager->destroyAllUserSessions($user['id']);

        $auditLogger->setUserId($user['id']);
        $auditLogger->log('password_reset', 'security', 'success', [
          'target_type' => 'user',
          'target_id' => $user['id']
        ]);

        $suc='Password reset successful! All active sessions have been logged out for security. You can now login with your new password.';
        $validToken=false;
      }catch(Exception $e){
        $err='Failed to reset password. Please try again.';
        error_log("Password reset error: " . $e->getMessage());
      }
    }
  }
}
?><!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="robots" content="noindex, nofollow"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Reset Password — BulkReplace</title>
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
  <?php if($suc): ?><div class="suc-box">✓ <?= htmlspecialchars($suc) ?><br><a href="/auth/login.php" style="color:var(--a1);text-decoration:none;">Go to Login →</a></div><?php endif; ?>
  <?php if(!$token || !$validToken): ?>
    <div class="err-box">Invalid or expired reset link. Please request a new one.</div>
    <div style="text-align:center;margin-top:20px;"><a href="/auth/forgot_password.php" class="btn btn-amber">Request New Link</a></div>
  <?php else: ?>
  <form method="POST">
    <?= csrf_field() ?>
    <div class="form-field"><label class="form-label">New Password</label><input type="password" name="password" placeholder="Min. 6 characters" required></div>
    <div class="form-field"><label class="form-label">Confirm Password</label><input type="password" name="password2" placeholder="Repeat password" required></div>
    <button type="submit" class="btn btn-amber" style="width:100%;justify-content:center;">Reset Password →</button>
  </form>
  <?php endif; ?>
  <div style="text-align:center;margin-top:20px;font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);"><a href="/auth/login.php" style="color:var(--a1);text-decoration:none;">← Back to Login</a></div>
</div></div>
</body></html>
