<?php
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/SecurityManager.php';
require_once dirname(__DIR__).'/includes/AuditLogger.php';
ss();

$auditLogger = new AuditLogger(db());
$securityManager = new SecurityManager(db(), $auditLogger);

if(!empty($_SESSION['is_admin'])){
    header('Location:'.APP_URL.'/admin/');
    exit;
}

$err = '';
$ipAddress = $securityManager->sanitizeInput($_SERVER['REMOTE_ADDR'] ?? 'unknown');

if(defined('ADMIN_IP_WHITELIST_ENABLED') && ADMIN_IP_WHITELIST_ENABLED === true){
    if(!$securityManager->isIPWhitelisted($ipAddress)){
        $auditLogger->log('admin_access_denied', 'security', 'failed', [
            'target_type' => 'admin_login',
            'error_message' => 'IP not whitelisted: ' . $ipAddress
        ]);
        http_response_code(403);
        die('<!DOCTYPE html><html><head><title>Access Denied</title><style>body{font-family:sans-serif;text-align:center;padding:100px;background:#0a0a0a;color:#999;}h1{color:#ff4560;}</style></head><body><h1>🚫 Access Denied</h1><p>Your IP address is not authorized to access the admin panel.</p></body></html>');
    }
}

if (!defined('ADMIN_CONFIGURED') || !ADMIN_CONFIGURED) {
    http_response_code(503);
    die('<!DOCTYPE html><html><head><title>Admin Unavailable</title><style>body{font-family:sans-serif;text-align:center;padding:100px;background:#0a0a0a;color:#999;}h1{color:#ff4560;}</style></head><body><h1>Admin Panel Unavailable</h1><p>Admin credentials are not configured. Set ADMIN_USERNAME and ADMIN_PASS_HASH in .env</p></body></html>');
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!csrf_verify()){
        $err = 'Security validation failed. Please refresh and try again.';
    } else {
        $u = trim($_POST['username']??'');
        $p = $_POST['password']??'';

        if($securityManager->isLoginBlocked('admin_' . $u, $ipAddress)){
            $err = 'Too many failed attempts. Access temporarily blocked.';
            $auditLogger->log('admin_login_blocked', 'security', 'blocked', [
                'target_type' => 'admin',
                'target_id' => $u
            ]);
        } elseif(!empty($u) && !empty($p) && $u===ADMIN_USERNAME && password_verify($p,ADMIN_PASS_HASH)){
            $securityManager->clearFailedLoginAttempts('admin_' . $u, $ipAddress);

            $_SESSION['is_admin'] = true;
            $_SESSION['admin_id'] = 1;
            $_SESSION['lt'] = time();

            $auditLogger->setAdminId(1);
            $auditLogger->log('admin_login_success', 'auth', 'success', [
                'target_type' => 'admin_panel'
            ]);

            session_write_close();
            header('Location:'.APP_URL.'/admin/');
            exit;
        } else {
            sleep(2);
            $err = 'Invalid admin credentials.';
            $securityManager->recordFailedLogin('admin_' . $u, $ipAddress);
            $auditLogger->log('admin_login_failed', 'auth', 'failed', [
                'target_type' => 'admin',
                'target_id' => $u,
                'error_message' => 'Invalid credentials'
            ]);
        }
    }
}
?><!DOCTYPE html><html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Admin Login — BulkReplace</title>
<link rel="icon" type="image/png" href="/img/logo.png">
<link rel="stylesheet" href="/assets/main.css">
<style>
.auth-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:40px 20px;}
.auth-card{background:var(--card);border:1px solid rgba(255,69,96,.2);border-top:2px solid var(--err);border-radius:20px;padding:40px;width:100%;max-width:380px;box-shadow:0 24px 80px rgba(0,0,0,.5);}
</style>
</head><body>
<div class="auth-wrap"><div class="auth-card">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:28px;">
    <div style="width:40px;height:40px;background:rgba(255,69,96,.15);border:1px solid rgba(255,69,96,.3);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;">🛡</div>
    <div>
      <div style="font-size:18px;font-weight:800;color:#fff;">Admin Panel</div>
      <div style="font-family:'JetBrains Mono',monospace;font-size:9px;color:var(--muted);letter-spacing:2px;text-transform:uppercase;">BulkReplace</div>
    </div>
  </div>
  <?php if($err): ?><div class="err-box">⚠ <?= htmlspecialchars($err) ?></div><?php endif; ?>
  <form method="POST" autocomplete="off">
    <?= csrf_field() ?>
    <div class="form-field">
      <label class="form-label">Username</label>
      <input type="text" name="username" placeholder="admin username" required autofocus autocomplete="off">
    </div>
    <div class="form-field">
      <label class="form-label">Password</label>
      <input type="password" name="password" placeholder="••••••••" required autocomplete="off">
    </div>
    <button type="submit" class="btn btn-danger" style="width:100%;justify-content:center;">🛡 Enter Admin Panel</button>
  </form>
</div></div>
</body></html>
