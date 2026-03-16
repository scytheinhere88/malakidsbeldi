<?php
// ============================================
// ERROR HANDLING
// ============================================
if (file_exists(__DIR__ . '/error_handler.php')) {
    require_once __DIR__ . '/error_handler.php';
}

// ============================================
// TIMEZONE CONFIGURATION
// ============================================
date_default_timezone_set('Asia/Jakarta');

// ============================================
// LOAD ENVIRONMENT VARIABLES
// ============================================
require_once __DIR__ . '/vendor/autoload.php';

try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (Exception $e) {
    error_log("Dotenv parse error: " . $e->getMessage());

    if (strpos($e->getMessage(), 'whitespace') !== false) {
        http_response_code(500);
        die('
            <html>
            <head>
                <title>.env Configuration Error</title>
                <style>
                    body { font-family: monospace; padding: 40px; background: #0a0a0a; color: #999; }
                    h1 { color: #ff4560; }
                    .error-box { background: #1a1a1a; padding: 20px; border-radius: 8px; border-left: 4px solid #ff4560; margin: 20px 0; }
                    pre { background: #111; padding: 15px; border-radius: 4px; overflow: auto; color: #0f0; }
                    .fix { background: #2a2a2a; padding: 15px; border-radius: 4px; margin: 10px 0; }
                </style>
            </head>
            <body>
                <h1>⚠️ .env Configuration Error</h1>
                <div class="error-box">
                    <p><strong>Problem:</strong> The .env file contains a value with unquoted whitespace.</p>
                    <p>This typically happens with passwords or API keys that contain spaces.</p>
                </div>

                <h2>How to Fix:</h2>
                <div class="fix">
                    <p><strong>Option 1:</strong> Wrap values containing spaces in double quotes</p>
                    <pre>SMTP_PASS="nqzt kewc yrxo wrsg"</pre>
                </div>

                <div class="fix">
                    <p><strong>Option 2:</strong> Remove spaces from the value</p>
                    <pre>SMTP_PASS=nqztkewcyrxowrsg</pre>
                </div>

                <div class="fix">
                    <p><strong>For Gmail App Password:</strong> Remove all spaces</p>
                    <pre># WRONG:
SMTP_PASS=nqzt kewc yrxo wrsg

# CORRECT:
SMTP_PASS=nqztkewcyrxowrsg</pre>
                </div>

                <p style="margin-top: 30px;">
                    <strong>Error Details:</strong><br>
                    <code style="color: #ff6b6b;">' . htmlspecialchars($e->getMessage()) . '</code>
                </p>
            </body>
            </html>
        ');
    }

    throw $e;
}

// ============================================
// DATABASE CONFIGURATION
// ============================================
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'bulk_bulkreplacetool');
define('DB_USER', $_ENV['DB_USER'] ?? 'bulk_bulkreplacetool');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');

// ============================================
// APP CONFIGURATION
// ============================================
define('APP_NAME', 'BulkReplace');
define('APP_URL',  'https://bulkreplacetool.com');
define('APP_SALT', $_ENV['APP_SALT'] ?? bin2hex(random_bytes(32)));
define('SUPPORT_TELEGRAM', '@scytheinhere');
define('SUPPORT_TELEGRAM_URL', 'https://t.me/scytheinhere');
define('SESSION_NAME', 'br_saas');
define('DEFAULT_LANG', 'en');

define('SESSION_LIFETIME',        86400);
define('SESSION_ADMIN_TIMEOUT',   1800);
define('SESSION_USER_TIMEOUT',    43200);
define('SESSION_SID_LENGTH',      48);
define('SESSION_SID_BITS',        6);

// Set FORCE_HTTPS=true in .env when running on HTTPS-only hosting
// Set TRUST_PROXY=true in .env when behind a trusted reverse proxy (Nginx, Cloudflare, etc.)
define('FORCE_HTTPS', filter_var($_ENV['FORCE_HTTPS'] ?? 'true', FILTER_VALIDATE_BOOLEAN));
define('TRUST_PROXY', filter_var($_ENV['TRUST_PROXY'] ?? 'false', FILTER_VALIDATE_BOOLEAN));

// ============================================
// SECURITY HEADERS — applied to ALL responses (after constants are defined)
// ============================================
if (file_exists(__DIR__ . '/includes/SecurityHeaders.php')) {
    require_once __DIR__ . '/includes/SecurityHeaders.php';
    if (!headers_sent()) {
        SecurityHeaders::apply();
    }
}

// ============================================
// PAYMENT GATEWAYS
// ============================================
define('GUMROAD_WEBHOOK_SECRET', $_ENV['GUMROAD_WEBHOOK_SECRET'] ?? '');
define('GUMROAD_PING_TOKEN',    $_ENV['GUMROAD_PING_TOKEN'] ?? '');
define('GUMROAD_ACCESS_TOKEN',  $_ENV['GUMROAD_ACCESS_TOKEN'] ?? '');

// ============================================
// ADMIN CREDENTIALS
// Both ADMIN_USERNAME and ADMIN_PASS_HASH must be set in .env
// To generate ADMIN_PASS_HASH: php -r "echo password_hash('yourpassword', PASSWORD_BCRYPT, ['cost'=>12]);"
// ============================================
$adminUsername = $_ENV['ADMIN_USERNAME'] ?? '';
$adminPassHash = $_ENV['ADMIN_PASS_HASH'] ?? '';
if (empty($adminUsername) || empty($adminPassHash)) {
    error_log('SECURITY: ADMIN_USERNAME and ADMIN_PASS_HASH must be set in .env. Admin panel is disabled.');
    define('ADMIN_USERNAME', '');
    define('ADMIN_PASS_HASH', '');
    define('ADMIN_CONFIGURED', false);
} else {
    define('ADMIN_USERNAME', $adminUsername);
    define('ADMIN_PASS_HASH', $adminPassHash);
    define('ADMIN_CONFIGURED', true);
}
unset($adminUsername, $adminPassHash);

// ============================================
// API KEYS
// ============================================
define('GOOGLE_PLACES_API_KEY', $_ENV['GOOGLE_PLACES_API_KEY'] ?? '');
define('CRON_AUTH_KEY', $_ENV['CRON_AUTH_KEY'] ?? 'change-this-key-in-production');

const PLAN_DATA = [
  'free'     => ['name'=>'Free',     'limit'=>20,   'rollover'=>false,'pm'=>0,    'pa'=>0,     'pl'=>0,     'color'=>'#454568','badge'=>'','has_addons'=>false,'gumroad_monthly'=>'','gumroad_annual'=>'','gumroad_lifetime'=>''],
  'pro'      => ['name'=>'Pro',      'limit'=>500,  'rollover'=>true, 'pm'=>19.9, 'pa'=>190.9, 'pl'=>0,     'color'=>'#f0a500','badge'=>'Popular','has_addons'=>false,'gumroad_monthly'=>'pro-monthly-plan','gumroad_annual'=>'pro-yearly-plan','gumroad_lifetime'=>''],
  'platinum' => ['name'=>'Platinum', 'limit'=>1500, 'rollover'=>true, 'pm'=>69.9, 'pa'=>671.9, 'pl'=>0,     'color'=>'#00d4aa','badge'=>'Best Value','has_addons'=>true,'gumroad_monthly'=>'platinum-monthly-plan','gumroad_annual'=>'platinum-yearly-plan','gumroad_lifetime'=>''],
  'lifetime' => ['name'=>'Lifetime', 'limit'=>-1,   'rollover'=>true, 'pm'=>0,    'pa'=>0,     'pl'=>469.9, 'color'=>'#c084fc','badge'=>'Forever','has_addons'=>true,'gumroad_monthly'=>'','gumroad_annual'=>'','gumroad_lifetime'=>'lifetime-access-plan'],
];

const ADDON_DATA = [
  // ── Individual Add-ons ──────────────────────────────────────────────
  'csv-generator-pro' => [
    'name'  => 'CSV Generator',
    'slug'  => 'csv-generator-pro',
    'price' => 19.9,
    'icon'  => '📊',
    'desc'  => 'Generate CSV data from domain lists — daerah, alamat, embed maps, email, telepon.',
    'gumroad_permalink' => 'csv-generator-addon',
  ],
  'zip-manager' => [
    'name'  => 'ZIP Manager',
    'slug'  => 'zip-manager',
    'price' => 19.9,
    'icon'  => '🗜️',
    'desc'  => 'Unzip, modify content inside ZIPs, and repackage — bulk ZIP operations.',
    'gumroad_permalink' => 'zip-manager-addon',
  ],
  'copy-rename' => [
    'name'  => 'Copy & Rename',
    'slug'  => 'copy-rename',
    'price' => 19.9,
    'icon'  => '📋',
    'desc'  => 'Bulk copy and rename files/folders with pattern-based rules.',
    'gumroad_permalink' => 'copy-rename-addon',
  ],
  // ── Autopilot (Lifetime-exclusive) ─────────────────────────────────
  'autopilot' => [
    'name'          => 'Autopilot',
    'slug'          => 'autopilot',
    'price'         => 99.9,
    'icon'          => '🤖',
    'desc'          => 'Pick folder + drop domains = 50 ZIPs ready. Full AI-powered pipeline, no manual steps.',
    'lifetime_only' => true,
    'gumroad_permalink' => 'ai-autopilot-bundle',
  ],
  // ── Full Bundle (all 3) ─────────────────────────────────────────────
  'premium-bundle' => [
    'name'     => 'Full Bundle',
    'slug'     => 'premium-bundle',
    'price'    => 49.9,
    'icon'     => '💎',
    'desc'     => 'CSV Generator + ZIP Manager + Copy & Rename — hemat vs beli satuan.',
    'includes' => ['csv-generator-pro','zip-manager','copy-rename'],
    'gumroad_permalink' => 'all-in-one-bundle',
  ],
];

// ============================================
// GUMROAD PRODUCT MAP — SINGLE SOURCE OF TRUTH
// Maps Gumroad permalink/product_id → plan config
// Used by: gumroad.php, activate_license.php, LicenseGenerator.php
// ============================================
const GUMROAD_PRODUCT_MAP = [
    // --- Permalinks (from Gumroad dashboard) ---
    'pro-monthly-plan'      => ['plan'=>'pro',      'cycle'=>'monthly',  'months'=>1,    'slug'=>'pro-monthly-plan'],
    'platinum-monthly-plan' => ['plan'=>'platinum',  'cycle'=>'monthly',  'months'=>1,    'slug'=>'platinum-monthly-plan'],
    'pro-yearly-plan'       => ['plan'=>'pro',      'cycle'=>'annual',   'months'=>12,   'slug'=>'pro-yearly-plan'],
    'platinum-yearly-plan'  => ['plan'=>'platinum',  'cycle'=>'annual',   'months'=>12,   'slug'=>'platinum-yearly-plan'],
    'lifetime-access-plan'  => ['plan'=>'lifetime',  'cycle'=>'lifetime', 'months'=>9999, 'slug'=>'lifetime-access-plan'],
    // --- Add-ons ---
    'csv-generator-addon'   => ['plan'=>'pro',      'cycle'=>'addon',    'months'=>0,    'slug'=>'csv-generator-addon',  'addon'=>'csv-generator-pro'],
    'zip-manager-addon'     => ['plan'=>'pro',      'cycle'=>'addon',    'months'=>0,    'slug'=>'zip-manager-addon',    'addon'=>'zip-manager'],
    'copy-rename-addon'     => ['plan'=>'pro',      'cycle'=>'addon',    'months'=>0,    'slug'=>'copy-rename-addon',    'addon'=>'copy-rename'],
    'ai-autopilot-bundle'   => ['plan'=>'pro',      'cycle'=>'addon',    'months'=>0,    'slug'=>'ai-autopilot-bundle',  'addon'=>'autopilot'],
    'all-in-one-bundle'     => ['plan'=>'platinum',  'cycle'=>'addon',    'months'=>3,    'slug'=>'all-in-one-bundle',    'addon'=>'premium-bundle'],
    // --- Typo alias (Gumroad permalink has missing 's') ---
    'lifetime-acces-plan'       => ['plan'=>'lifetime',  'cycle'=>'lifetime', 'months'=>9999, 'slug'=>'lifetime-access-plan'],
    // --- Legacy product names (fallback) ---
    'Pro Automation Plan'       => ['plan'=>'pro',     'cycle'=>'monthly',  'months'=>1,    'slug'=>'pro-monthly-plan'],
    'Platinum Agency Plan'      => ['plan'=>'platinum', 'cycle'=>'monthly',  'months'=>1,    'slug'=>'platinum-monthly-plan'],
    'Pro Automation Yearly'     => ['plan'=>'pro',     'cycle'=>'annual',   'months'=>12,   'slug'=>'pro-yearly-plan'],
    'Platinum Agency Yearly'    => ['plan'=>'platinum', 'cycle'=>'annual',   'months'=>12,   'slug'=>'platinum-yearly-plan'],
    'Lifetime Unlimited'        => ['plan'=>'lifetime', 'cycle'=>'lifetime', 'months'=>9999, 'slug'=>'lifetime-access-plan'],
];

// Gumroad product_id (hash) → slug mapping
// These are the internal Gumroad product IDs (from product URL/API)
const GUMROAD_PRODUCT_ID_MAP = [
    '5FemOQM3T5CTJcoxLBz9lA==' => 'pro-yearly-plan',
    '7nQs3PYRz6Wc_zSwZEKmpA==' => 'pro-monthly-plan',
    'QqX3oihPnbHi56uTL33xtw==' => 'platinum-yearly-plan',
    'IsVC3Fk2_BX3eoeUBsmMHQ==' => 'platinum-monthly-plan',
    'v7bddkeH5_4CZOF-rvdVYg==' => 'lifetime-access-plan',
    'Rv3lRIIKoziiUCsyZT_8xg==' => 'ai-autopilot-bundle',
    'n3naDS2BY26jmjBgz8iQkQ==' => 'csv-generator-addon',
    'RnSl8osTSdq8ObTYFGuZWw==' => 'zip-manager-addon',
    'qHSLgP8ikGyof7yc-PNU5Q==' => 'copy-rename-addon',
    'BWD85J4nF3sS9ggXMdtTqQ==' => 'all-in-one-bundle',
];

// Resolve product identifier (permalink or product_id) → config
function resolveGumroadProduct(string $identifier): ?array {
    // Direct match (permalink)
    if (isset(GUMROAD_PRODUCT_MAP[$identifier])) {
        return GUMROAD_PRODUCT_MAP[$identifier];
    }
    // Try as Gumroad product_id hash
    if (isset(GUMROAD_PRODUCT_ID_MAP[$identifier])) {
        $slug = GUMROAD_PRODUCT_ID_MAP[$identifier];
        return GUMROAD_PRODUCT_MAP[$slug] ?? null;
    }
    // Partial/substring match (legacy product names)
    foreach (GUMROAD_PRODUCT_MAP as $key => $config) {
        if (stripos($identifier, $key) !== false || stripos($key, $identifier) !== false) {
            return $config;
        }
    }
    return null;
}

// Helper: get all slugs unlocked by an addon purchase (bundle expands to 3)
function getAddonSlugs(string $purchasedSlug): array {
    $a = ADDON_DATA[$purchasedSlug] ?? null;
    if (!$a) return [];
    return $a['includes'] ?? [$purchasedSlug];
}

function db():PDO {
  static $p=null; if($p)return $p;
  try {
    $p=new PDO(
      "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
      DB_USER,
      DB_PASS,
      [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
  } catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    if (php_sapi_name() === 'cli') {
      fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
      exit(1);
    }
    http_response_code(503);
    $isJson = (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
           || (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false);
    if ($isJson) {
      header('Content-Type: application/json');
      die(json_encode(['success' => false, 'error' => 'Service temporarily unavailable. Please try again later.']));
    }
    die('Service temporarily unavailable. Please try again later.');
  }

  if(file_exists(__DIR__.'/includes/AutoMigration.php')){
    require_once __DIR__.'/includes/AutoMigration.php';
    $migration = new AutoMigration($p);
    $migration->runMigrations();
  }

  return $p;
}
function ss(){
  // Don't recreate session during logout!
  if(defined('IS_LOGOUT') && IS_LOGOUT) {
    return;
  }

  if(session_status()!==PHP_SESSION_NONE)return;

  // Prevent any output before session_start
  if(headers_sent($file, $line)){
    error_log("Headers already sent in $file on line $line before session_start()");
    return;
  }

  // SecurityHeaders::apply() is already called at config load time.
  // Only apply here for session cookie settings which require an active session context.
  if(class_exists('SecurityHeaders') && !headers_sent()){
    SecurityHeaders::apply();
  }

  // Force HTTPS detection from environment or trusted server vars only
  // Prevents header spoofing when behind a reverse proxy
  $https = (defined('FORCE_HTTPS') && FORCE_HTTPS === true)
    || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? 80) == 443)
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' && (defined('TRUST_PROXY') && TRUST_PROXY === true));
  $sessdir = $_ENV['SESSION_SAVE_PATH'] ?? '';

  if($sessdir) {
    if(!is_dir($sessdir) && !headers_sent()) {
      @mkdir($sessdir, 0700, true);
    }
    if(!is_dir($sessdir) || !is_writable($sessdir)) {
      $sessdir = '';
    }
  }

  if(!$sessdir) {
    $sessdir = sys_get_temp_dir();
  }

  // Set session configuration BEFORE session_start
  if(!headers_sent()){
    if(is_writable($sessdir)){
      ini_set('session.save_path', $sessdir);
    }
    ini_set('session.cookie_httponly',  1);
    ini_set('session.cookie_secure',    $https ? 1 : 0);
    ini_set('session.cookie_samesite',  'Strict');
    ini_set('session.use_strict_mode',  1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.gc_maxlifetime',   SESSION_LIFETIME);
    ini_set('session.sid_length',       SESSION_SID_LENGTH);
    ini_set('session.sid_bits_per_character', SESSION_SID_BITS);
    session_name(SESSION_NAME);
    if(PHP_VERSION_ID >= 70300){
      session_set_cookie_params(['lifetime'=>SESSION_LIFETIME,'path'=>'/','httponly'=>true,'secure'=>$https,'samesite'=>'Strict']);
    }
    session_start();
  }

  if(empty($_SESSION['_fingerprint'])){
    $_SESSION['_fingerprint']=hash('sha256',($_SERVER['HTTP_USER_AGENT']??'').'|'.($_SERVER['REMOTE_ADDR']??''));
  }else{
    $current=hash('sha256',($_SERVER['HTTP_USER_AGENT']??'').'|'.($_SERVER['REMOTE_ADDR']??''));
    if(!hash_equals($_SESSION['_fingerprint'],$current)){
      session_destroy();
      session_start();
      $_SESSION['_fingerprint']=$current;
    }
  }
}
function startSession(){ss();}
function isLoggedIn():bool{ss();if(empty($_SESSION['uid']))return false;if(time()-($_SESSION['lt']??0)>SESSION_USER_TIMEOUT){session_destroy();return false;}$_SESSION['lt']=time();return true;}
function requireLogin(): void {if(!isLoggedIn()){header('Location:'.APP_URL.'/auth/login.php?next='.urlencode($_SERVER['REQUEST_URI']));exit;}}
function isAdmin():bool{
  ss();
  if(empty($_SESSION['is_admin']))return false;
  $adminTimeout = SESSION_ADMIN_TIMEOUT;
  if(time()-($_SESSION['admin_lt']??0)>$adminTimeout){
    $_SESSION['is_admin']=false;
    unset($_SESSION['admin_lt'],$_SESSION['admin_id']);
    return false;
  }
  $_SESSION['admin_lt']=time();
  return true;
}
function requireAdmin(): void {
  ss();
  if(!isAdmin()){
    $expired = !empty($_SESSION['is_admin_was_set']);
    unset($_SESSION['is_admin_was_set']);
    $loc = APP_URL.'/admin/login.php'.($expired?'?msg=session_expired':'');
    header('Location:'.$loc);
    exit;
  }
}
function verifyCronAuth():bool{if(php_sapi_name()==='cli')return true;$key=$_GET['key']??$_POST['key']??'';return hash_equals(CRON_AUTH_KEY,$key);}
function requireCronAuth(): void {if(!verifyCronAuth()){http_response_code(401);header('Content-Type:application/json');echo json_encode(['success'=>false,'error'=>'Unauthorized']);exit;}}
function currentUser():?array{if(!isLoggedIn())return null;$s=db()->prepare("SELECT * FROM users WHERE id=?");$s->execute([$_SESSION['uid']]);return $s->fetch()?:null;}
function getPlan(string $k):array{return PLAN_DATA[$k]??PLAN_DATA['free'];}
function getUserQuota(int $uid, bool $useCache = true):array{
  if ($useCache && file_exists(__DIR__.'/includes/QueryCache.php')) {
    require_once __DIR__.'/includes/QueryCache.php';
    return QueryCache::remember("user_quota_{$uid}", function() use ($uid) {
      return getUserQuota($uid, false);
    }, 60);
  }

  try {
    $u=db()->prepare("SELECT plan,rollover_balance FROM users WHERE id=?");$u->execute([$uid]);$row=$u->fetch();
    if (!$row) {
      return ['used'=>0,'limit'=>20,'rollover'=>0,'remaining'=>20,'unlimited'=>false];
    }
    $plan=getPlan($row['plan']??'free');$rb=(int)($row['rollover_balance']??0);
    $us=db()->prepare("SELECT COALESCE(SUM(csv_rows),0) as t FROM usage_log WHERE user_id=? AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())");
    $us->execute([$uid]);$used=(int)$us->fetch()['t'];
    $lim=$plan['limit']===-1?PHP_INT_MAX:$plan['limit'];
    $total=$plan['limit']===-1?PHP_INT_MAX:($lim+$rb);
    return['used'=>$used,'limit'=>$plan['limit'],'rollover'=>$rb,'remaining'=>max(0,$total-$used),'unlimited'=>$plan['limit']===-1];
  } catch (Exception $e) {
    error_log("getUserQuota error: " . $e->getMessage());
    return ['used'=>0,'limit'=>20,'rollover'=>0,'remaining'=>20,'unlimited'=>false];
  }
}
function logUsage(int $uid,int $rows,int $files,string $jobType='bulk_replace',?string $jobName=null):void{db()->prepare("INSERT INTO usage_log(user_id,csv_rows,files_updated,job_type,job_name,created_at)VALUES(?,?,?,?,?,NOW())")->execute([$uid,$rows,$files,$jobType,$jobName]);}
function ago(string $dt):string{$d=time()-strtotime($dt);if($d<60)return'just now';if($d<3600)return floor($d/60).'m ago';if($d<86400)return floor($d/3600).'h ago';return floor($d/86400).'d ago';}

function getLang():string{ss();return $_SESSION['lang']??$_COOKIE['lang']??DEFAULT_LANG;}
function setLang(string $l): void {ss();$_SESSION['lang']=$l;setcookie('lang',$l,time()+31536000,'/');}
function t(string $k):string{static $t=null;if(!$t){$l=getLang();$l=preg_match('/^[a-z]{2}(_[a-z]{2,4})?$/i',$l)?$l:DEFAULT_LANG;$f=__DIR__.'/lang/'.$l.'.php';$t=file_exists($f)?require $f:require __DIR__.'/lang/'.DEFAULT_LANG.'.php';}return $t[$k]??$k;}

// Addons that are ALWAYS sold separately, even for has_addons plans
const ADDON_ALWAYS_SEPARATE = ['autopilot'];

function hasAddonAccess(int $uid,string $slug):bool{
  $u=currentUser();if(!$u)return false;
  $plan=getPlan($u['plan']??'free');
  // has_addons grants all addons EXCEPT ones explicitly sold separately (e.g. autopilot)
  if(($plan['has_addons']??false) && !in_array($slug, ADDON_ALWAYS_SEPARATE))return true;
  // Check direct ownership OR bundle ownership that includes this slug
  $bundleOwned = false;
  foreach(ADDON_DATA as $aSlug => $aData) {
    if(!empty($aData['includes']) && in_array($slug, $aData['includes'])) {
      $check=db()->prepare("SELECT COUNT(*) as c FROM user_addons ua LEFT JOIN addons a ON ua.addon_id=a.id WHERE ua.user_id=? AND (a.slug=? OR ua.addon_slug=?) AND ua.is_active=1");
      $check->execute([$uid,$aSlug,$aSlug]);
      if(($check->fetch()['c']??0)>0){ return true; }
    }
  }
  $s=db()->prepare("SELECT COUNT(*) as c FROM user_addons ua LEFT JOIN addons a ON ua.addon_id=a.id WHERE ua.user_id=? AND (a.slug=? OR ua.addon_slug=?) AND ua.is_active=1");
  $s->execute([$uid,$slug,$slug]);
  return (int)$s->fetch()['c']>0;
}
function getUserAddons(int $uid):array{
  $u=currentUser();if(!$u)return[];
  $plan=getPlan($u['plan']??'free');
  if($plan['has_addons']??false){
    // Return all addons except those sold separately
    return array_values(array_filter(array_keys(ADDON_DATA), fn($s)=>!in_array($s,ADDON_ALWAYS_SEPARATE)));
  }
  $s=db()->prepare("SELECT COALESCE(a.slug, ua.addon_slug) as slug FROM user_addons ua LEFT JOIN addons a ON ua.addon_id=a.id WHERE ua.user_id=? AND ua.is_active=1");
  $s->execute([$uid]);
  return array_column($s->fetchAll(),'slug');
}

// ============================================
// AI API CONFIGURATION
// ============================================
define('OPENAI_API_KEY',    $_ENV['OPENAI_API_KEY'] ?? '');
define('OPENAI_MODEL',      'gpt-4o-mini');
define('OPENAI_PARSE_BATCH', 20);
define('ANTHROPIC_API_KEY', $_ENV['ANTHROPIC_API_KEY'] ?? '');

// ============================================
// CSRF PROTECTION
// ============================================
function csrf_token(): string {
  ss();
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

function csrf_verify(): bool {
  ss();
  $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
  $session_token = $_SESSION['csrf_token'] ?? '';

  if (empty($token) || empty($session_token)) {
    return false;
  }

  return hash_equals($session_token, $token);
}

function csrf_field(): string {
  return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function require_csrf(): void {
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    http_response_code(403);
    die(json_encode(['error' => 'CSRF validation failed. Please refresh the page and try again.']));
  }
}

define('MAX_PAYLOAD_DEFAULT',    2 * 1024 * 1024);
define('MAX_PAYLOAD_CSV',       32 * 1024 * 1024);
define('MAX_PAYLOAD_ZIP',      256 * 1024 * 1024);

function enforce_payload_limit(int $maxBytes = MAX_PAYLOAD_DEFAULT): void {
  $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
  if ($contentLength > $maxBytes) {
    http_response_code(413);
    header('Content-Type: application/json');
    die(json_encode([
      'ok'    => false,
      'error' => 'Payload too large. Maximum allowed: ' . round($maxBytes / 1024 / 1024, 1) . ' MB.',
    ]));
  }
}

// ============================================
// DISTRIBUTED CRON LOCK (MySQL GET_LOCK)
// Prevents race conditions when cron runs twice simultaneously
// ============================================
function acquireCronLock(string $lockName, int $timeoutSeconds = 0): bool {
  try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT GET_LOCK(?, ?) AS locked");
    $stmt->execute([$lockName, $timeoutSeconds]);
    $result = $stmt->fetch();
    return (int)($result['locked'] ?? 0) === 1;
  } catch (Exception $e) {
    error_log("acquireCronLock failed for '{$lockName}': " . $e->getMessage());
    return false;
  }
}

function releaseCronLock(string $lockName): void {
  try {
    $pdo = db();
    $pdo->prepare("SELECT RELEASE_LOCK(?)")->execute([$lockName]);
  } catch (Exception $e) {
    error_log("releaseCronLock failed for '{$lockName}': " . $e->getMessage());
  }
}

function requireCronLock(string $lockName): void {
  if (!acquireCronLock($lockName, 0)) {
    error_log("Cron '{$lockName}' already running — skipped duplicate execution");
    if (php_sapi_name() !== 'cli') {
      header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'skipped' => true, 'reason' => 'Already running']);
    exit(0);
  }
  register_shutdown_function(function() use ($lockName) {
    releaseCronLock($lockName);
  });
}

// ============================================
// ATOMIC PROMO CODE REDEMPTION
// Uses SELECT ... FOR UPDATE to prevent race conditions when multiple requests
// try to redeem the same single-use promo code simultaneously.
// Returns the promo row on success, or null if invalid/exhausted/already used by this user.
// ============================================
function redeemPromoCode(string $code, int $userId): ?array {
  $pdo = db();
  try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
      SELECT * FROM promo_codes
      WHERE code = ?
        AND is_active = 1
        AND valid_from  <= NOW()
        AND valid_until >= NOW()
      FOR UPDATE
    ");
    $stmt->execute([$code]);
    $promo = $stmt->fetch();

    if (!$promo) {
      $pdo->rollBack();
      return null;
    }

    if ($promo['max_uses'] !== null && (int)$promo['current_uses'] >= (int)$promo['max_uses']) {
      $pdo->rollBack();
      return null;
    }

    // Check if this user already redeemed this code
    $check = $pdo->prepare("SELECT id FROM promo_redemptions WHERE promo_code_id = ? AND user_id = ? LIMIT 1");
    $check->execute([$promo['id'], $userId]);
    if ($check->fetch()) {
      $pdo->rollBack();
      return null;
    }

    // Record redemption and increment usage counter atomically
    $pdo->prepare("INSERT INTO promo_redemptions (promo_code_id, user_id, redeemed_at) VALUES (?, ?, NOW())")
      ->execute([$promo['id'], $userId]);
    $pdo->prepare("UPDATE promo_codes SET current_uses = current_uses + 1 WHERE id = ?")
      ->execute([$promo['id']]);

    $pdo->commit();
    return $promo;
  } catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("redeemPromoCode error: " . $e->getMessage());
    return null;
  }
}
