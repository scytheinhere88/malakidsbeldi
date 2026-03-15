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

if($action==='log'){
  $rows=(int)($_POST['csv_rows']??0);
  $files=(int)($_POST['files_updated']??0);
  $name=trim($_POST['job_name']??'');
  $jobType=trim($_POST['job_type']??'bulk_replace'); // bulk_replace, csv_generator, zip_manager, copy_rename, autopilot
  if($rows<=0){echo json_encode(['ok'=>false,'msg'=>'Invalid rows count']);exit;}

  $quota=getUserQuota($user['id']);
  if(!$quota['unlimited']&&$quota['remaining']<$rows){
    echo json_encode(['ok'=>false,'msg'=>'Quota exceeded. Remaining: '.$quota['remaining'].' rows.','quota'=>$quota]);exit;
  }

  // Log usage with job type
  db()->prepare("INSERT INTO usage_log(user_id,csv_rows,files_updated,job_type,job_name,created_at)VALUES(?,?,?,?,?,NOW())")
    ->execute([$user['id'],$rows,$files,$jobType,$name?:null]);

  echo json_encode(['ok'=>true,'quota'=>getUserQuota($user['id'])]);exit;
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
