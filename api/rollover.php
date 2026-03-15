<?php
// Run this via cron on 1st of each month:
// 0 0 1 * * php /path/to/public_html/api/rollover.php
require_once dirname(__DIR__).'/config.php';

requireCronAuth();
requireCronLock('cron_rollover');

$month=date('Y-m',strtotime('-1 month'));
$users=db()->query("SELECT * FROM users WHERE plan IN('pro','platinum') AND deleted_at IS NULL")->fetchAll();
$processed=0;
foreach($users as $u){
  $plan_data=getPlan($u['plan']);
  $limit=$plan_data['limit'];
  $max_rollover=$limit*3;
  // Usage last month
  $ym=explode('-',$month);
  $used_stmt=db()->prepare("SELECT COALESCE(SUM(csv_rows),0) as t FROM usage_log WHERE user_id=? AND YEAR(created_at)=? AND MONTH(created_at)=?");
  $used_stmt->execute([$u['id'],$ym[0],$ym[1]]);
  $used=(int)$used_stmt->fetch()['t'];
  $leftover=max(0,$limit-$used);
  $cur_rollover=(int)($u['rollover_balance']??0);
  $new_rollover=min($max_rollover,$cur_rollover+$leftover);
  db()->prepare("UPDATE users SET rollover_balance=? WHERE id=?")->execute([$new_rollover,$u['id']]);
  db()->prepare("INSERT INTO rollover_log(user_id,month_year,leftover,rolled_over)VALUES(?,?,?,?)")->execute([$u['id'],$month,$leftover,$leftover]);
  $processed++;
}
echo json_encode(['ok'=>true,'processed'=>$processed,'month'=>$month]);
