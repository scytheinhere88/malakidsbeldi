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
<meta name="robots" content="noindex, nofollow">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Reset Password — BulkReplace</title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<style>
.auth-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:40px 20px;position:relative;z-index:1;}
.auth-card{background:var(--card);border:1px solid var(--border);border-top:2px solid var(--a1);border-radius:20px;padding:40px;width:100%;max-width:440px;box-shadow:0 24px 80px rgba(0,0,0,.5),0 0 0 1px rgba(240,165,0,.04);animation:fadeUp .3s ease;}
@keyframes fadeUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:none}}
.pw-strength-bar{height:4px;border-radius:4px;background:var(--border);margin-top:8px;overflow:hidden;}
.pw-strength-fill{height:100%;border-radius:4px;transition:width .3s ease,background .3s ease;width:0%;}
.pw-strength-label{font-family:'JetBrains Mono',monospace;font-size:9px;margin-top:5px;letter-spacing:.5px;transition:color .3s;}
.pw-req{display:grid;grid-template-columns:1fr 1fr;gap:4px;margin-top:10px;}
.pw-req-item{font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);display:flex;gap:5px;align-items:center;transition:color .2s;padding:2px 0;}
.pw-req-item.pass{color:var(--ok);}
.pw-req-dot{width:5px;height:5px;border-radius:50%;background:var(--muted);flex-shrink:0;transition:background .2s;}
.pw-req-item.pass .pw-req-dot{background:var(--ok);}
.match-ind{font-family:'JetBrains Mono',monospace;font-size:10px;margin-top:6px;min-height:16px;display:flex;align-items:center;gap:5px;}
</style>
</head><body>
<div class="auth-wrap">
<div class="auth-card">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:28px;">
    <img src="/img/logo.png" alt="BulkReplace" style="width:48px;height:48px;border-radius:12px;">
    <div>
      <div style="font-size:22px;font-weight:800;color:#fff;">BulkReplace</div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);letter-spacing:3px;text-transform:uppercase;">New Password</div>
    </div>
  </div>

  <?php if($err): ?><div class="err-box">⚠ <?= htmlspecialchars($err) ?></div><?php endif; ?>

  <?php if($suc): ?>
  <div style="text-align:center;padding:8px 0 16px;">
    <div style="display:inline-flex;align-items:center;justify-content:center;width:64px;height:64px;background:rgba(0,230,118,.1);border:2px solid rgba(0,230,118,.25);border-radius:16px;margin-bottom:14px;font-size:30px;">🔓</div>
    <div style="font-size:18px;font-weight:800;color:#fff;margin-bottom:6px;">Password Updated!</div>
    <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);line-height:1.8;margin-bottom:12px;">All active sessions have been logged out for your security.</div>
  </div>
  <div class="suc-box">✓ Password reset successfully. You can now log in.</div>
  <a href="/auth/login.php" class="btn btn-amber" style="width:100%;justify-content:center;margin-top:12px;">Go to Login →</a>

  <?php elseif(!$token || !$validToken): ?>
  <div style="text-align:center;padding:8px 0 16px;">
    <div style="display:inline-flex;align-items:center;justify-content:center;width:64px;height:64px;background:rgba(255,69,96,.08);border:2px solid rgba(255,69,96,.2);border-radius:16px;margin-bottom:14px;font-size:30px;">⏰</div>
    <div style="font-size:18px;font-weight:800;color:#fff;margin-bottom:6px;">Link Expired</div>
    <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);line-height:1.8;">Reset links are valid for 1 hour only. Please request a new one.</div>
  </div>
  <a href="/auth/forgot_password.php" class="btn btn-amber" style="width:100%;justify-content:center;margin-top:16px;">Request New Link</a>

  <?php else: ?>
  <div style="font-size:18px;font-weight:800;color:#fff;margin-bottom:6px;">Create new password</div>
  <div style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);line-height:1.8;margin-bottom:20px;">
    Hi <strong style="color:var(--a1);"><?= htmlspecialchars($user['name']) ?></strong>, choose a strong new password for your account.
  </div>
  <form method="POST" id="rp-form">
    <?= csrf_field() ?>
    <div class="form-field">
      <label class="form-label">New Password</label>
      <input type="password" name="password" id="rp-pass" placeholder="Min. 8 chars + uppercase + number + symbol" required autocomplete="new-password">
      <div class="pw-strength-bar"><div class="pw-strength-fill" id="pw-fill"></div></div>
      <div class="pw-strength-label" id="pw-label" style="color:var(--muted);">Enter a password</div>
      <div class="pw-req">
        <div class="pw-req-item" id="req-len"><div class="pw-req-dot"></div>8+ characters</div>
        <div class="pw-req-item" id="req-upper"><div class="pw-req-dot"></div>Uppercase letter</div>
        <div class="pw-req-item" id="req-lower"><div class="pw-req-dot"></div>Lowercase letter</div>
        <div class="pw-req-item" id="req-num"><div class="pw-req-dot"></div>Number</div>
        <div class="pw-req-item" id="req-sym"><div class="pw-req-dot"></div>Special character</div>
      </div>
    </div>
    <div class="form-field">
      <label class="form-label">Confirm Password</label>
      <input type="password" name="password2" id="rp-pass2" placeholder="Repeat your new password" required autocomplete="new-password">
      <div class="match-ind" id="match-ind"></div>
    </div>
    <button type="submit" class="btn btn-amber" id="rp-btn" style="width:100%;justify-content:center;" disabled>
      <span id="rp-btn-text">Set New Password →</span>
    </button>
  </form>
  <?php endif; ?>

  <hr style="border:none;border-top:1px solid var(--border);margin:20px 0;">
  <div style="text-align:center;font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--muted);">
    <a href="/auth/login.php" style="color:var(--a1);text-decoration:none;">← Back to Login</a>
  </div>
</div>
</div>
<script>
(function(){
  const passEl = document.getElementById('rp-pass');
  const pass2El = document.getElementById('rp-pass2');
  const fill = document.getElementById('pw-fill');
  const label = document.getElementById('pw-label');
  const btn = document.getElementById('rp-btn');
  const matchInd = document.getElementById('match-ind');
  if(!passEl) return;

  const checks = {
    len:   { el: document.getElementById('req-len'),   fn: v => v.length >= 8 },
    upper: { el: document.getElementById('req-upper'), fn: v => /[A-Z]/.test(v) },
    lower: { el: document.getElementById('req-lower'), fn: v => /[a-z]/.test(v) },
    num:   { el: document.getElementById('req-num'),   fn: v => /[0-9]/.test(v) },
    sym:   { el: document.getElementById('req-sym'),   fn: v => /[^A-Za-z0-9]/.test(v) },
  };
  const strengthLevels = [
    { pct:'20%', color:'#ff4560', text:'Very Weak'   },
    { pct:'40%', color:'#ff8c00', text:'Weak'        },
    { pct:'60%', color:'#ffd740', text:'Fair'        },
    { pct:'80%', color:'#00d4aa', text:'Strong'      },
    { pct:'100%',color:'#00e676', text:'Very Strong' },
  ];

  function evaluate() {
    const v = passEl.value;
    let score = 0;
    for (const k in checks) {
      const ok = checks[k].fn(v);
      checks[k].el.classList.toggle('pass', ok);
      if (ok) score++;
    }
    if (!v) {
      fill.style.width = '0%';
      label.textContent = 'Enter a password';
      label.style.color = 'var(--muted)';
    } else {
      const s = strengthLevels[score - 1] || strengthLevels[0];
      fill.style.width = s.pct;
      fill.style.background = s.color;
      label.textContent = s.text;
      label.style.color = s.color;
    }
    validateMatch();
  }

  function validateMatch() {
    const v = passEl.value;
    const v2 = pass2El.value;
    const allPassed = Object.values(checks).every(c => c.fn(v));
    const matched = v && v2 && v === v2;
    if (!v2) {
      matchInd.innerHTML = '';
    } else if (matched) {
      matchInd.innerHTML = '<span style="color:var(--ok);">✓ Passwords match</span>';
    } else {
      matchInd.innerHTML = '<span style="color:var(--err);">✗ Does not match</span>';
    }
    btn.disabled = !(allPassed && matched);
  }

  passEl.addEventListener('input', evaluate);
  pass2El.addEventListener('input', validateMatch);

  document.getElementById('rp-form')?.addEventListener('submit', function() {
    btn.disabled = true;
    document.getElementById('rp-btn-text').innerHTML = '<span style="display:inline-block;width:13px;height:13px;border:2px solid rgba(0,0,0,.25);border-top-color:#000;border-radius:50%;animation:spin .6s linear infinite;"></span> Saving...';
  });
})();
</script>
<style>@keyframes spin{to{transform:rotate(360deg)}}</style>
</body></html>
