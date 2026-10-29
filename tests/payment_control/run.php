<?php
/**
 * Payment-control tests. Run: php tests/payment_control/run.php
 * Runs the REAL service code on SQLite via SqliteMysqlShim (no MySQL needed).
 * Limits: no real row-lock concurrency, no ENUM enforcement, no PesaJet network.
 */
define('APP_URL', 'http://test.local'); define('APP_CURRENCY', 'UGX');
define('PESAJET_API_KEY', 'test'); define('PESAJET_BASE_URL', 'http://127.0.0.1:9'); define('PESAJET_WEBHOOK_SECRET', 'whsec');
define('PAYMENT_ENVIRONMENT', 'testing'); define('PESAJET_DEBUG', false);
foreach (['RETURN','SUCCESS','FAILURE','CANCEL','CALLBACK','WEBHOOK'] as $k) define("PAYMENT_{$k}_URL", APP_URL.'/x');
ini_set('error_log', '/dev/null');
$root = dirname(__DIR__, 2);
require __DIR__ . '/SqliteMysqlShim.php';
require "$root/includes/functions.php"; require "$root/includes/PesaJetService.php";
require "$root/includes/inventory_service.php"; require "$root/includes/payment_service.php";
$GLOBALS['mails'] = []; $GLOBALS['notes'] = [];
function user_allows_notification($d,$u,$t,$c){return true;}
function create_in_app_notification($d,$u,$t,$m,$ty='system',$o=null,$l=null,$c='in_app'){$GLOBALS['notes'][]=[$ty,$o];return 1;}
function log_notification_delivery(...$a){}
function handle_order_status_change($d,$o,$s){$GLOBALS['mails'][]=[$o,$s];}

$pass=0;$fail=0;
function t(string $name,bool $ok,string $extra=''){global $pass,$fail;$ok?$pass++:$fail++;echo ($ok?'PASS':'FAIL')." $name".($extra&&!$ok?" ($extra)":'')."\n";}
function mk(): PDO { $db=new SqliteMysqlShim('sqlite::memory:'); $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
  $db->raw('PRAGMA recursive_triggers=OFF');
  foreach (array_filter(array_map('trim', explode(";\n", file_get_contents(__DIR__.'/schema.sql')))) as $s) { $db->raw($s); }
  $db->raw("INSERT INTO users(full_name) VALUES('Cust')"); $db->raw("INSERT INTO admins(full_name,email,role) VALUES('Admin','a','admin')");
  $db->raw("INSERT INTO admins(full_name,email,role,is_active) VALUES('Gone','b','admin',0)");
  $db->raw("INSERT INTO products(name,stock_quantity) VALUES('Tomato',8)"); return $db; }
function order(PDO $db,string $pstatus='pending',string $ref='MM-1',?string $txn='TXN1',float $amt=5000): array {
  static $n=0;$n++; $db->exec("INSERT INTO orders(order_number,user_id,customer_name,customer_phone,grand_total) VALUES('KT-$n',1,'C','077',$amt)");
  $oid=(int)$db->lastInsertId(); $db->exec("INSERT INTO order_items(order_id,product_id,product_name,quantity) VALUES($oid,1,'Tomato',2)");
  $db->exec("UPDATE products SET stock_quantity=stock_quantity-2 WHERE product_id=1");
  $db->prepare("INSERT INTO payments(order_id,user_id,amount,provider,phone_number,transaction_reference,status,pesajet_transaction_id) VALUES(?,1,?,'mtn','+256771234567',?,?,?)")->execute([$oid,$amt,"$ref-$n",$pstatus,$txn?"$txn-$n":null]);
  return [$oid,(int)$db->lastInsertId()]; }
function stock(PDO $db){return (float)$db->query("SELECT stock_quantity s FROM products")->fetch()['s'];}
function pay(PDO $db,int $p){return $db->query("SELECT * FROM payments WHERE payment_id=$p")->fetch();}
function ordr(PDO $db,int $o){return $db->query("SELECT * FROM orders WHERE order_id=$o")->fetch();}
function cnt(PDO $db,string $sql){return (int)$db->query($sql)->fetch()['c'];}
class Mock { public $r; function __construct($r){$this->r=$r;} function getPayment($t){return $this->r;} }
function prov($st,$amt=5000,$extra=[]){return ['ok'=>true,'data'=>array_merge(['status'=>$st,'amount'=>$amt,'currency'=>'UGX'],$extra)];}

// 2. provider confirms paid (webhook path)
$db=mk(); [$o,$p]=order($db); $r=katale_apply_payment_status($db,pay($db,$p),'paid',['source'=>'webhook','remote_status'=>'COMPLETED','webhook_event'=>'payment.completed']);
t('2 provider paid: payment+order paid, provider-confirmed', $r && pay($db,$p)['status']==='paid' && ordr($db,$o)['payment_status']==='paid' && katale_payment_confirmation_type(pay($db,$p))['key']==='provider_confirmed');
// 5 duplicate callback
$before=count($GLOBALS['notes']); $r2=katale_apply_payment_status($db,pay($db,$p),'paid',['source'=>'webhook']);
t('5 duplicate callback: no second transition/notification', !$r2 && count($GLOBALS['notes'])===$before && cnt($db,"SELECT COUNT(*) c FROM payment_status_history WHERE new_status<>previous_status")===1);
// 3 failed, 12 stock once
$db=mk(); [$o,$p]=order($db); katale_apply_payment_status($db,pay($db,$p),'failed',['source'=>'webhook','failure_reason'=>'insufficient funds']); katale_apply_payment_status($db,pay($db,$p),'failed',['source'=>'webhook']);
t('3 provider failed: failed, reason stored', pay($db,$p)['status']==='failed' && pay($db,$p)['failure_reason']==='insufficient funds' && ordr($db,$o)['payment_status']==='failed');
t('12 stock restored exactly once', stock($db)===8.0 && cnt($db,"SELECT COUNT(*) c FROM inventory_logs WHERE change_qty>0")===1);
// 4 expiry
$db=mk(); [$o,$p]=order($db); katale_apply_payment_status($db,pay($db,$p),'expired',['source'=>'expiry_poll']);
t('4 expired: payment expired, stock restored, order payment stays retryable(pending)', pay($db,$p)['status']==='expired' && ordr($db,$o)['payment_status']==='pending' && stock($db)===8.0);
// 8 late success after timeout/expiry
katale_apply_payment_status($db,pay($db,$p),'paid',['source'=>'webhook']);
t('8 late paid after expiry: paid + order flagged for review (stock was released)', pay($db,$p)['status']==='paid' && (int)ordr($db,$o)['needs_admin_review']===1);
// 6 invalid signature
$body='{"event":"payment.completed"}'; $good=hash_hmac('sha256',$body,'whsec');
t('6 signature: valid accepted, invalid/empty rejected', PesaJetService::verifyWebhookSignature($body,$good) && !PesaJetService::verifyWebhookSignature($body,'bad') && !PesaJetService::verifyWebhookSignature($body,''));
// 7 provider unavailable on verify
$db=mk(); [$o,$p]=order($db); $r=katale_admin_verify_payment($db,$p,1,new Mock(['ok'=>false,'error_code'=>'TIMEOUT','data'=>null]));
t('7 provider unavailable: nothing changed, error reported', !$r['ok'] && $r['outcome']==='unreachable' && pay($db,$p)['status']==='pending');
// verify success / still pending / mismatch / no txn
$r=katale_admin_verify_payment($db,$p,1,new Mock(prov('PENDING'))); t('verify: pending stays pending', $r['ok'] && $r['outcome']==='still_pending' && pay($db,$p)['status']==='pending');
$r=katale_admin_verify_payment($db,$p,1,new Mock(prov('COMPLETED',9999))); t('verify: amount mismatch rejected + flagged', !$r['ok'] && $r['outcome']==='mismatch' && pay($db,$p)['status']==='pending' && (int)pay($db,$p)['needs_reconciliation']===1);
$r=katale_admin_verify_payment($db,$p,1,new Mock(prov('COMPLETED'))); t('verify: provider COMPLETED -> paid (provider-confirmed)', $r['outcome']==='applied' && pay($db,$p)['status']==='paid' && pay($db,$p)['verification_source']==='gateway');
[$o3,$p3]=order($db,'pending','MM',null); $r=katale_admin_verify_payment($db,$p3,1,new Mock(prov('COMPLETED'))); t('verify: no provider txn id -> clear message, unchanged', $r['outcome']==='no_transaction' && pay($db,$p3)['status']==='pending');
// 9/10 duplicate initiation guard & single row
$db=mk(); [$o,$p]=order($db,'processing'); $order=ordr($db,$o); $order['user_id']=1;
$r=katale_initiate_payment($db,$order,['provider'=>'mtn','phone'=>'0771234567']);
t('9/10 repeat/duplicate initiation within 2 min: suppressed, no new attempt, one payment row', $r['ok'] && !empty($r['duplicate']) && cnt($db,"SELECT COUNT(*) c FROM payments WHERE order_id=$o")===1 && (int)pay($db,$p)['attempt_count']===0);
// 11 admin decisions
$db=mk(); [$o,$p]=order($db); $long='MTN statement shows UGX 5,000 received';
$r=katale_admin_set_payment_status($db,$p,'paid',1,'short'); t('admin: note required (>=10 chars)', !$r['ok'] && pay($db,$p)['status']==='pending');
$r=katale_admin_set_payment_status($db,$p,'paid',2,$long); t('admin: deactivated admin refused server-side', !$r['ok'] && pay($db,$p)['status']==='pending');
$r=katale_admin_set_payment_status($db,$p,'paid',99,$long); t('admin: unknown admin refused', !$r['ok']);
$r=katale_admin_set_payment_status($db,$p,'paid',1,$long,['transaction_reference'=>'MTN123456']);
t('11 admin PAID without callback: admin-confirmed, order paid, audit+history', $r['ok'] && katale_payment_confirmation_type(pay($db,$p))['key']==='admin_confirmed' && ordr($db,$o)['payment_status']==='paid' && cnt($db,"SELECT COUNT(*) c FROM order_admin_actions WHERE action_type='payment_mark_paid' AND previous_payment_status='pending' AND new_payment_status='paid' AND admin_id=1 AND note<>''")===1 && cnt($db,"SELECT COUNT(*) c FROM payment_status_history WHERE source='admin_decision'")===1);
$r=katale_admin_set_payment_status($db,$p,'paid',1,$long); t('admin: second PAID refused (no double processing)', !$r['ok'] && !empty($r['already']) && cnt($db,"SELECT COUNT(*) c FROM order_admin_actions")===1);
// provider failed after admin paid -> conflict
katale_apply_payment_status($db,pay($db,$p),'failed',['source'=>'webhook','remote_status'=>'FAILED']);
t('provider FAILED after admin PAID: status kept, conflict flagged', pay($db,$p)['status']==='paid' && (int)pay($db,$p)['needs_reconciliation']===1);
// admin FAILED
$db=mk(); [$o,$p]=order($db); $r=katale_admin_set_payment_status($db,$p,'failed',1,'Customer never approved prompt');
t('admin FAILED: order cancelled, stock restored once, admin-marked failure', $r['ok'] && ordr($db,$o)['order_status']==='cancelled' && ordr($db,$o)['payment_status']==='failed' && stock($db)===8.0 && katale_payment_confirmation_type(pay($db,$p))['key']==='admin_failed');
// late provider success after admin failed
katale_apply_payment_status($db,pay($db,$p),'paid',['source'=>'webhook','remote_status'=>'COMPLETED']);
t('8b late provider PAID after admin FAILED: NOT overwritten, flagged for reconciliation', pay($db,$p)['status']==='failed' && (int)pay($db,$p)['needs_reconciliation']===1 && (int)ordr($db,$o)['needs_admin_review']===1 && stock($db)===8.0);
$r=katale_admin_set_payment_status($db,$p,'paid',1,$long); t('admin PAID on cancelled order needs acknowledgement', !$r['ok'] && !empty($r['needs_ack']) && pay($db,$p)['status']==='failed');
$r=katale_admin_set_payment_status($db,$p,'paid',1,$long,['acknowledge_override'=>true]);
t('admin reinstates: paid, order received, stock re-taken, flag resolved', $r['ok'] && ordr($db,$o)['order_status']==='received' && stock($db)===6.0 && (int)pay($db,$p)['needs_reconciliation']===0 && ordr($db,$o)['inventory_state']==='deducted');
// pending
$db=mk(); [$o,$p]=order($db,'failed'); $db->exec("UPDATE payments SET admin_decision=NULL"); 
$r=katale_admin_set_payment_status($db,$p,'pending',1,'Holding for MTN statement');
t('admin PENDING: unresolved, order not paid', $r['ok'] && pay($db,$p)['status']==='pending' && ordr($db,$o)['payment_status']==='pending');
$db->exec("UPDATE payments SET updated_at=datetime('now','-1 hour')"); $res=katale_process_due_payment_expirations($db);
t('admin PENDING hold excluded from auto-expiry', $res['checked']===0);
// resolve
$db=mk(); [$o,$p]=order($db); katale_admin_set_payment_status($db,$p,'failed',1,'Customer never approved'); katale_apply_payment_status($db,pay($db,$p),'paid',['source'=>'webhook']);
$r=katale_admin_resolve_reconciliation($db,$p,1,'Refunded customer manually'); t('resolve flag recorded in audit', $r['ok'] && (int)pay($db,$p)['needs_reconciliation']===0 && cnt($db,"SELECT COUNT(*) c FROM order_admin_actions WHERE action_type='resolve_reconciliation'")===1);
// atomicity: audit failure rolls back everything
$db=mk(); [$o,$p]=order($db); $db->raw('DROP TABLE order_admin_actions'); $r=katale_admin_set_payment_status($db,$p,'failed',1,'Customer never approved');
t('atomic: audit write failure rolls back payment, order and stock', !$r['ok'] && pay($db,$p)['status']==='pending' && ordr($db,$o)['order_status']==='received' && stock($db)===6.0);
echo "\n$pass passed, $fail failed\n"; exit($fail?1:0);
