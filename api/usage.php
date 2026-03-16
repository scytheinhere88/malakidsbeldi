<?php
require_once dirname(__DIR__).'/config.php';
require_once dirname(__DIR__).'/includes/CSRFMiddleware.php';
require_once dirname(__DIR__).'/includes/Analytics.php';
require_once dirname(__DIR__).'/includes/AuditLogger.php';

header('Content-Type: application/json');
startSession();

// Allow analytics tracking without authentication for client-side tracking
$input = json_decode(file_get_contents('php://input'), true);
$isAnalyticsTracking = !empty($input['feature']);

if(!$isAnalyticsTracking && !isLoggedIn()){
  echo json_encode(['ok'=>false,'msg'=>'Not authenticated']);
  exit;
}

if(!$isAnalyticsTracking){
  CSRFMiddleware::require();
}

$user=currentUser();
$action=$_POST['action']??$_GET['action']??'';

if($action==='check'){
  echo json_encode(['ok'=>true,'quota'=>getUserQuota($user['id']),'plan'=>$user['plan']]);exit;
}

// Pre-flight quota reservation — called BEFORE client-side processing begins.
// Returns a signed token that must be presented on the 'log' call.
// This is the authoritative server-side quota gate; JS QUOTA_LIMIT is display-only.
if($action==='reserve'){
  $rows=(int)($_POST['csv_rows']??0);
  $jobType=trim($_POST['job_type']??'bulk_replace');
  if($rows<=0){echo json_encode(['ok'=>false,'msg'=>'Invalid row count']);exit;}

  $pdo=db();
  try {
    // Lock user row to prevent concurrent over-reservations
    $pdo->beginTransaction();
    $pdo->prepare("SELECT id FROM users WHERE id=? FOR UPDATE")->execute([$user['id']]);

    $quota=getUserQuota($user['id'],false);
    if(!$quota['unlimited']&&$quota['remaining']<$rows){
      $pdo->rollBack();
      echo json_encode(['ok'=>false,'msg'=>'Quota exceeded. Remaining: '.$quota['remaining'].' rows.','quota'=>$quota]);exit;
    }
    $pdo->commit();

    // Issue a signed reservation token (HMAC-SHA256 with server secret + session id)
    $secret=defined('APP_SALT')?APP_SALT:(DB_PASS.DB_NAME);
    $payload=json_encode(['uid'=>$user['id'],'rows'=>$rows,'jt'=>$jobType,'ts'=>time()]);
    $sig=hash_hmac('sha256',$payload,$secret);
    $token=base64_encode($payload).'.'.$sig;
    $_SESSION['quota_token']=$token;

    echo json_encode(['ok'=>true,'token'=>$token,'quota'=>$quota]);
  } catch(Exception $e){
    if($pdo->inTransaction()) $pdo->rollBack();
    error_log("Quota reserve error: ".$e->getMessage());
    echo json_encode(['ok'=>false,'msg'=>'Server error. Please try again.']);
  }
  exit;
}

if($action==='log'){
  $rows=(int)($_POST['csv_rows']??0);
  $files=(int)($_POST['files_updated']??0);
  $name=trim($_POST['job_name']??'');
  $jobType=trim($_POST['job_type']??'bulk_replace');
  $token=trim($_POST['quota_token']??'');
  if($rows<=0){echo json_encode(['ok'=>false,'msg'=>'Invalid rows count']);exit;}

  // Verify the reservation token issued by 'reserve' action
  $secret=defined('APP_SALT')?APP_SALT:(DB_PASS.DB_NAME);
  $tokenValid=false;
  $reservedRows=0;
  if(!empty($token)&&!empty($_SESSION['quota_token'])&&hash_equals($_SESSION['quota_token'],$token)){
    $parts=explode('.',$token,2);
    if(count($parts)===2){
      $payload=base64_decode($parts[0],true);
      $sig=$parts[1];
      $expectedSig=hash_hmac('sha256',$payload,$secret);
      if(hash_equals($expectedSig,$sig)){
        $data=json_decode($payload,true);
        $tokenAge=time()-($data['ts']??0);
        if(($data['uid']??0)===$user['id']&&$tokenAge<7200){
          $tokenValid=true;
          $reservedRows=(int)($data['rows']??0);
          $jobType=$data['jt']??$jobType;
        }
      }
    }
  }

  if($tokenValid){
    // Token-based path: rows must match reservation (no more than reserved)
    if($rows>$reservedRows){
      echo json_encode(['ok'=>false,'msg'=>'Row count exceeds reservation. Refresh and try again.']);exit;
    }
    unset($_SESSION['quota_token']);
  } else {
    // Fallback path for legacy callers: re-validate server-side
    $quota=getUserQuota($user['id']);
    if(!$quota['unlimited']&&$quota['remaining']<$rows){
      echo json_encode(['ok'=>false,'msg'=>'Quota exceeded. Remaining: '.$quota['remaining'].' rows.','quota'=>$quota]);exit;
    }
  }

  db()->prepare("INSERT INTO usage_log(user_id,csv_rows,files_updated,job_type,job_name,created_at)VALUES(?,?,?,?,?,NOW())")
    ->execute([$user['id'],$rows,$files,$jobType,$name?:null]);

  echo json_encode(['ok'=>true,'quota'=>getUserQuota($user['id'],false)]);exit;
}

// Analytics tracking from client-side (ZIP Manager, etc)
if($isAnalyticsTracking){
  try {
    $analytics = new Analytics(db());
    $auditLogger = new AuditLogger(db());

    $feature = $input['feature'] ?? '';
    $operation = $input['operation'] ?? '';
    $data = $input['data'] ?? [];

    if($user){
      $auditLogger->setUserId($user['id']);
    }

    // Track analytics event
    $analytics->trackEvent(
      $operation,
      $feature,
      $user['id'] ?? null,
      $data
    );

    // Audit log for ZIP operations
    if($feature === 'zip_manager'){
      $auditLogger->log(
        $operation,
        'zip_manager',
        $data['failed'] > 0 ? 'partial' : 'success',
        [
          'target_type' => 'zip_operation',
          'request_data' => [
            'total' => $data['total'] ?? 0,
            'success' => $data['success'] ?? 0,
            'skipped' => $data['skipped'] ?? 0,
            'failed' => $data['failed'] ?? 0,
            'duration' => $data['duration'] ?? 0,
            'mode' => $data['mode'] ?? null
          ]
        ]
      );
    }

    echo json_encode(['ok'=>true]);
    exit;
  } catch(Exception $e){
    error_log("Analytics tracking error: " . $e->getMessage());
    echo json_encode(['ok'=>false,'msg'=>'Tracking failed']);
    exit;
  }
}

echo json_encode(['ok'=>false,'msg'=>'Unknown action']);
