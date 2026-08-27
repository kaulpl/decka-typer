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
function get_user_meta($uid,$key,$single=true) { global $meta; return $key==='dt_notification_preferences'?$meta:[]; }
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
