<?php
// Lightweight regression tests; WordPress storage and sanitizers are stubbed.
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__);
$options=[];
function get_option($key,$fallback=[]) { global $options; return $options[$key]??$fallback; }
function update_option($key,$value,$autoload=false) { global $options; $options[$key]=$value; return true; }
function sanitize_text_field($value) { return trim(strip_tags(str_replace(["\r","\n"],' ',$value))); }
function sanitize_textarea_field($value) { return trim(strip_tags($value)); }
function wp_timezone() { return new DateTimeZone('Europe/Warsaw'); }
class WP_Error { public function __construct(public string $code,public string $message) {} }
require dirname(__DIR__).'/includes/class-dt-notifications.php';
function check($value,$message) { if (!$value) throw new RuntimeException($message); }
$input=DT_Notifications::template_defaults();
check(DT_Notifications::save_templates($input)===true,'Save defaults');
$values=['liga'=>'2LM','grupa'=>'grupa A','kolejka'=>'1. kolejka','mecz'=>'Decka – Goście','termin'=>'27.09.2026 18:00','pozostalo'=>3,'stary_termin'=>'26.09.2026 18:00','nowy_termin'=>'termin do ustalenia'];
foreach (array_keys($input) as $key) {
    $copy=DT_Notifications::render_template($key,$values);
    check(str_starts_with($copy['title'],'TypujKosza.pl - '),'Title prefix');
    check(!str_contains($copy['message'],'{'),'All placeholders resolved');
}
$input['reminder_3d']['message']="{kolejka}: {pozostalo}\n{mecz}";
$input['reminder_3d']['title']='TypujKosza.pl - {liga}';
check(DT_Notifications::save_templates($input)===true,'Custom copy saved');
$copy=DT_Notifications::render_template('reminder_3d',$values);
check($copy['title']==='TypujKosza.pl - 2LM','No duplicate branding');
check($copy['message']==="1. kolejka: 3\nDecka – Goście",'Custom values and newlines preserved');
$before=$options;
foreach (['{literowka}','{liga',''] as $invalid) {
    $bad=$input;$bad['reminder_3d']['message']=$invalid;
    check(DT_Notifications::save_templates($bad) instanceof WP_Error,'Reject invalid copy');
    check($options===$before,'Invalid input must not partially save');
}
$bad=$input;$bad['welcome']['message']='{mecz}';
check(DT_Notifications::save_templates($bad) instanceof WP_Error,'Reject variables unavailable to welcome');
$date=new ReflectionMethod(DT_Notifications::class,'template_date');
check($date->invoke(null,null)==='termin do ustalenia','Unknown date');
check($date->invoke(null,'2026-09-27 18:00:00')==='27.09.2026 18:00','Local date unchanged');
check(strlen(DT_Notifications::notification_title(str_repeat('a',300)))===190,'Database title limit');
echo "Notification templates: OK\n";

// Existing email preferences must no longer dispatch reminders.
$meta=['push'=>1,'email'=>1,'standard'=>1];
function get_user_meta($uid,$key,$single=true) { global $meta,$broadcastMeta; return $key==='dt_notification_preferences'?($broadcastMeta[$uid]??$meta):[]; }
function update_user_meta($uid,$key,$value) { global $meta; if ($key==='dt_notification_preferences') $meta=$value; }
function wp_parse_args($args,$defaults) { return array_merge($defaults,$args); }
function get_current_user_id() { return 7; }
class WP_REST_Request { public function __construct(private array $body) {} public function get_json_params() { return $this->body; } }
class WP_REST_Response { public function __construct(public array $data) {} }
check(!array_key_exists('email',DT_Notifications::preferences(7)),'Legacy email preference ignored');
DT_Notifications::disable_push(new WP_REST_Request(['push'=>false]));
check($meta['push']===0,'Push opt-out persisted');
DT_Notifications::register_push_subscription(new WP_REST_Request(['subscription_id'=>'new-device-1234','activate'=>false]));
check($meta['push']===0,'Passive subscription refresh cannot undo opt-out');
DT_Notifications::register_push_subscription(new WP_REST_Request(['subscription_id'=>'new-device-1234','activate'=>true]));
check($meta['push']===1,'Explicit activation enables Push');
check(!array_key_exists('email',$meta),'Email removed on preference update');
echo "Push preferences: OK\n";

// Broadcast tests use fake cron/database/HTTP only; no real recipients.
define('DT_ONESIGNAL_APP_ID','test-app');
define('DT_ONESIGNAL_REST_API_KEY','test-key');
$broadcastMeta=[];$scheduled=[];$http=[];$scheduleFail=false;
function get_users($args) { return range(1,12); }
function get_userdata($uid) { return $uid!==12; }
function wp_generate_uuid4() { return 'test-uuid'; }
function wp_schedule_single_event($time,$hook,$args,$errors=false) { global $scheduled,$scheduleFail; $scheduled[]=[$time,$hook,$args]; return !$scheduleFail; }
function current_time($format) { return '2026-08-27 12:00:00'; }
function home_url($path) { return 'https://example.test'.$path; }
function wp_json_encode($value) { return json_encode($value); }
function wp_remote_post($url,$args) { global $http; $http[]=json_decode($args['body'],true); return []; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code($value) { return 200; }
function wp_remote_retrieve_body($value) { return '{"id":"accepted-test"}'; }
class DT_DB { static function table($name) { return $name; } }
$wpdb=new class {
 public $insert_id=0; public $rows=[];
 function insert($table,$row) { $key=$row['user_id'].'/'.$row['event_key'].'/'.$row['channel']; if(isset($this->rows[$key]))return false; $this->rows[$key]=$row; $this->insert_id++;return 1; }
 function update($table,$values,$where) { return 1; }
};
foreach(range(1,12) as $uid)$broadcastMeta[$uid]=['push'=>$uid===2?0:1];
$result=DT_Notifications::queue_admin_test();
check($result===['queued'=>11,'failed'=>0],'All opted-in accounts queued');
check(count($scheduled)===3,'Broadcast split into small batches');
check(count($http)===0,'Admin action schedules without sending');
$broadcastMeta[3]=['push'=>0];
foreach($scheduled as $event)DT_Notifications::send_admin_test_batch(...$event[2]);
check(count($http)===9,'Skip opt-out at execution and deleted account');
foreach($wpdb->rows as $row)check($row['channel']==='push' && !in_array($row['user_id'],[2,3,12]),'Only consenting Push recipients');
foreach($http as $payload)check(str_starts_with($payload['headings']['pl'],'TypujKosza.pl - TEST - '),'Explicit test branding');
foreach($scheduled as $event)DT_Notifications::send_admin_test_batch(...$event[2]);
check(count($http)===9,'Same campaign cannot resend a processed recipient');
$scheduleFail=true;$result=DT_Notifications::queue_admin_test();
check($result===['queued'=>0,'failed'=>10],'Cron failures are not reported as queued');
foreach(range(1,12) as $uid)$broadcastMeta[$uid]=['push'=>0];
check(DT_Notifications::queue_admin_test()===['queued'=>0,'failed'=>0],'Empty audience does not enqueue');
echo "Broadcast Push: OK\n";
