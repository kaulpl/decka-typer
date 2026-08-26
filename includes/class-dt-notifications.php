<?php
if (!defined('ABSPATH')) exit;

class DT_Notifications {
    private const META = 'dt_notification_preferences';
    private const CRON = 'dt_notification_reminders';
    private const ENDPOINT_VERSION = '2';

    public static function register(): void {
        add_action(self::CRON, [__CLASS__, 'cron']);
        add_action('init', [__CLASS__, 'endpoints']);
        add_action('template_redirect', [__CLASS__, 'serve_endpoint'], -100);
        add_action('init', [__CLASS__, 'maybe_flush_rewrite_rules'], 99);
        add_filter('redirect_canonical', [__CLASS__, 'disable_endpoint_redirect'], 10, 2);
        add_action('wp_head', [__CLASS__, 'head'], 3);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue'], 50);
        if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + 300, 'dt_custom_sync', self::CRON);
    }

    public static function defaults(): array {
        return ['push'=>0,'email'=>0,'standard'=>1,'schedule_changes'=>1,'postponed'=>1,'incomplete'=>1,'reminder_6h'=>1,'reminder_3d'=>1];
    }

    public static function preferences(int $uid): array {
        return wp_parse_args((array)get_user_meta($uid,self::META,true),self::defaults());
    }

    public static function save_preferences(int $uid, array $input): array {
        $out=[];
        foreach (array_keys(self::defaults()) as $key) $out[$key]=empty($input[$key])?0:1;
        update_user_meta($uid,self::META,$out);
        return $out;
    }

    public static function push_ready(): bool {
        return defined('DT_ONESIGNAL_APP_ID') && trim((string)DT_ONESIGNAL_APP_ID)!==''
            && defined('DT_ONESIGNAL_REST_API_KEY') && trim((string)DT_ONESIGNAL_REST_API_KEY)!=='';
    }

    public static function endpoints(): void {
        add_rewrite_rule('^typkosza-manifest\.webmanifest$','index.php?dt_pwa_manifest=1','top');
        add_rewrite_rule('^OneSignalSDKWorker\.js$','index.php?dt_onesignal_worker=1','top');
        add_filter('query_vars',static function(array $vars): array {$vars[]='dt_pwa_manifest';$vars[]='dt_onesignal_worker';return $vars;});
    }

    public static function maybe_flush_rewrite_rules(): void {
        if ((string)get_option('dt_notification_endpoint_version', '') === self::ENDPOINT_VERSION) return;
        flush_rewrite_rules(false);
        update_option('dt_notification_endpoint_version', self::ENDPOINT_VERSION, false);
    }

    private static function request_path(): string {
        $requestUri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string)$_SERVER['REQUEST_URI']) : '';
        return (string)wp_parse_url($requestUri, PHP_URL_PATH);
    }

    private static function is_manifest_request(): bool {
        return (bool)get_query_var('dt_pwa_manifest') || self::request_path() === wp_make_link_relative(home_url('/typkosza-manifest.webmanifest'));
    }

    private static function is_worker_request(): bool {
        return (bool)get_query_var('dt_onesignal_worker') || self::request_path() === wp_make_link_relative(home_url('/OneSignalSDKWorker.js'));
    }

    public static function disable_endpoint_redirect($redirect, $requested) {
        if (self::is_manifest_request() || self::is_worker_request()) return false;
        return $redirect;
    }

    public static function serve_endpoint(): void {
        if (self::is_manifest_request()) {
            status_header(200);
            nocache_headers();
            header('Content-Type: application/manifest+json; charset=utf-8');
            $scope = trailingslashit(wp_make_link_relative(home_url('/')));
            $icon = class_exists('DT_Brand') ? DT_Brand::mark_url() : DT_URL.'assets/img/typujkosza-mark.png';
            echo wp_json_encode([
                'id'=>$scope,
                'name'=>'TypujKosza.pl',
                'short_name'=>'TypujKosza',
                'start_url'=>$scope,
                'scope'=>$scope,
                'display'=>'standalone',
                'background_color'=>'#f5f7fb',
                'theme_color'=>'#071f43',
                'icons'=>[
                    ['src'=>$icon,'sizes'=>'any','type'=>'image/png','purpose'=>'any'],
                    ['src'=>DT_URL.'assets/img/typujkosza-pwa-512.png','sizes'=>'512x512','type'=>'image/png','purpose'=>'maskable'],
                ],
            ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (self::is_worker_request()) {
            status_header(200);
            nocache_headers();
            header('Content-Type: application/javascript; charset=utf-8');
            header('Service-Worker-Allowed: /');
            header('X-Content-Type-Options: nosniff');
            echo "importScripts('https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.sw.js');";
            exit;
        }
    }

    public static function head(): void {
        $icon = class_exists('DT_Brand') ? DT_Brand::mark_url() : DT_URL.'assets/img/typujkosza-mark.png';
        echo '<link rel="manifest" href="'.esc_url(home_url('/typkosza-manifest.webmanifest')).'">' . "\n";
        echo '<link rel="apple-touch-icon" href="'.esc_url($icon).'">' . "\n";
        echo '<meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="TypujKosza">' . "\n";
    }

    public static function enqueue(): void {
        if (!is_user_logged_in() || !class_exists('DT_Frontend') || !DT_Frontend::is_typer_page()) return;
        wp_enqueue_style('dt-notifications',DT_URL.'assets/css/notifications.css',['dt-user-settings'],DT_VERSION);
        if (self::push_ready()) wp_enqueue_script('dt-onesignal','https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js',[],null,false);
        wp_enqueue_script('dt-notifications',DT_URL.'assets/js/notifications.js',[],DT_VERSION,true);
        wp_localize_script('dt-notifications','DeckaTyperNotifications',[
            'userId'=>get_current_user_id(),'pushReady'=>self::push_ready(),
            'appId'=>self::push_ready()?(string)DT_ONESIGNAL_APP_ID:'',
            'workerPath'=>wp_make_link_relative(home_url('/OneSignalSDKWorker.js')),
            'workerScope'=>trailingslashit(wp_make_link_relative(home_url('/'))),'homeUrl'=>home_url('/'),
        ]);
    }

    public static function schedule_changed(int $matchId, int $roundId, ?string $old, ?string $new, array $userIds): void {
        global $wpdb;
        $wpdb->insert(DT_DB::table('schedule_changes'),['match_id'=>$matchId,'round_id'=>$roundId,'old_starts_at'=>$old,'new_starts_at'=>$new,'reset_count'=>count($userIds),'detected_at'=>current_time('mysql')]);
        foreach (array_unique(array_map('intval',$userIds)) as $uid) {
            if (!$uid) continue;
            $prefs=self::preferences($uid);
            $title='Zmiana terminu meczu';
            $message='Termin wytypowanego meczu został zmieniony. Twój typ wyzerowano — wybierz zwycięzcę ponownie.';
            $eventKey='schedule-'.$matchId.'-'.md5((string)$new);
            self::send_channel($uid,'inapp','schedule_change',$eventKey,$title,$message,$roundId,$matchId);
            if (!empty($prefs['schedule_changes']) || !empty($prefs['postponed'])) {
                if (!empty($prefs['email'])) self::send_channel($uid,'email','schedule_change',$eventKey,$title,$message,$roundId,$matchId);
                if (!empty($prefs['push'])) self::send_channel($uid,'push','schedule_change',$eventKey,$title,$message,$roundId,$matchId);
            }
        }
    }

    public static function cron(): void {
        global $wpdb;
        $now=current_time('mysql');
        foreach ([['key'=>'3d','seconds'=>3*DAY_IN_SECONDS],['key'=>'6h','seconds'=>6*HOUR_IN_SECONDS]] as $window) {
            $to=wp_date('Y-m-d H:i:s',current_time('timestamp')+$window['seconds'],wp_timezone());
            $from=wp_date('Y-m-d H:i:s',current_time('timestamp')+$window['seconds']-90*MINUTE_IN_SECONDS,wp_timezone());
            $matches=$wpdb->get_results($wpdb->prepare(
                "SELECT m.id,m.round_id,m.starts_at,r.league_key,r.group_key,r.title FROM ".DT_DB::table('matches')." m JOIN ".DT_DB::table('rounds')." r ON r.id=m.round_id WHERE r.status='open' AND m.start_time_known=1 AND m.starts_at>%s AND m.starts_at<=%s",$from,$to
            ));
            foreach ((array)$matches as $match) self::remind_match($match,(string)$window['key']);
        }
        DT_Logger::log('notification_cron','Sprawdzono harmonogram przypomnień.',['at'=>$now],'info');
    }

    private static function remind_match(object $match,string $window): void {
        global $wpdb;
        $users=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT u.ID FROM {$wpdb->users} u WHERE EXISTS(SELECT 1 FROM ".DT_DB::table('predictions')." p WHERE p.user_id=u.ID) OR EXISTS(SELECT 1 FROM ".DT_DB::table('round_submissions')." s WHERE s.user_id=u.ID) OR EXISTS(SELECT 1 FROM {$wpdb->usermeta} um WHERE um.user_id=u.ID AND um.meta_key=%s)",self::META));
        foreach ((array)$users as $uid) {
            $uid=(int)$uid;$prefs=self::preferences($uid);
            if (empty($prefs['standard']) || empty($prefs['incomplete']) || empty($prefs['reminder_'.$window])) continue;
            $has=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".DT_DB::table('predictions')." WHERE user_id=%d AND match_id=%d",$uid,(int)$match->id));
            if ($has) continue;
            $remaining=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".DT_DB::table('matches')." m LEFT JOIN ".DT_DB::table('predictions')." p ON p.match_id=m.id AND p.user_id=%d WHERE m.round_id=%d AND m.starts_at>%s AND p.id IS NULL",$uid,(int)$match->round_id,current_time('mysql')));
            if (!$remaining) continue;
            $league=strtoupper((string)$match->league_key).((string)$match->group_key!==''?' grupa '.strtoupper((string)$match->group_key):'');
            self::deliver($uid,'incomplete','reminder-'.$window.'-'.(int)$match->id.'-'.md5((string)$match->starts_at),'Uzupełnij typy · '.$league,'Pozostało '.number_format_i18n($remaining).' meczów do wytypowania w kolejce „'.(string)$match->title.'”.',(int)$match->round_id,(int)$match->id);
        }
    }

    public static function deliver(int $uid,string $type,string $eventKey,string $title,string $message,int $roundId=0,int $matchId=0): void {
        $prefs=self::preferences($uid);
        self::send_channel($uid,'inapp',$type,$eventKey,$title,$message,$roundId,$matchId);
        if (!empty($prefs['email'])) self::send_channel($uid,'email',$type,$eventKey,$title,$message,$roundId,$matchId);
        if (!empty($prefs['push'])) self::send_channel($uid,'push',$type,$eventKey,$title,$message,$roundId,$matchId);
    }

    public static function send_admin_test(int $uid): array {
        global $wpdb;
        $eventKey='admin-test-'.$uid.'-'.wp_generate_uuid4();
        $title='Test powiadomień TypujKosza.pl';
        $message='Jeśli widzisz tę wiadomość, kanał powiadomień działa poprawnie. Artur melduje gotowość!';
        $channels=['inapp','email'];
        if (self::push_ready()) $channels[]='push';
        foreach ($channels as $channel) self::send_channel($uid,$channel,'admin_test',$eventKey,$title,$message,0,0);

        $rows=$wpdb->get_results($wpdb->prepare(
            'SELECT channel,status FROM '.DT_DB::table('notifications').' WHERE user_id=%d AND event_key=%s ORDER BY id ASC',
            $uid,
            $eventKey
        ),ARRAY_A);
        return is_array($rows)?$rows:[];
    }

    private static function send_channel(int $uid,string $channel,string $type,string $eventKey,string $title,string $message,int $roundId,int $matchId): void {
        global $wpdb;
        $table=DT_DB::table('notifications');$now=current_time('mysql');
        $inserted=$wpdb->insert($table,['user_id'=>$uid,'channel'=>$channel,'event_key'=>$eventKey,'event_type'=>$type,'title'=>$title,'message'=>$message,'round_id'=>$roundId?:null,'match_id'=>$matchId?:null,'status'=>'queued','created_at'=>$now]);
        if (!$inserted) return;
        $id=(int)$wpdb->insert_id;$ok=false;$response='';
        if ($channel==='inapp') {
            $ok=true;$response='Wiadomość zapisana w aplikacji';
        } elseif ($channel==='email') {
            $user=get_userdata($uid);$ok=$user?(bool)wp_mail((string)$user->user_email,$title,$message."\n\n".home_url('/')):false;
            $response=$ok?'wp_mail accepted':'wp_mail failed';
        } elseif (self::push_ready()) {
            $request=wp_remote_post('https://api.onesignal.com/notifications',[ 'timeout'=>15,'headers'=>['Authorization'=>'Key '.(string)DT_ONESIGNAL_REST_API_KEY,'Content-Type'=>'application/json'],'body'=>wp_json_encode(['app_id'=>(string)DT_ONESIGNAL_APP_ID,'target_channel'=>'push','include_aliases'=>['external_id'=>[(string)$uid]],'headings'=>['pl'=>$title,'en'=>$title],'contents'=>['pl'=>$message,'en'=>$message],'url'=>home_url('/')]) ]);
            $ok=!is_wp_error($request)&&wp_remote_retrieve_response_code($request)>=200&&wp_remote_retrieve_response_code($request)<300;
            $response=is_wp_error($request)?$request->get_error_message():(string)wp_remote_retrieve_body($request);
        } else $response='OneSignal nie jest skonfigurowany';
        $excerpt=function_exists('mb_substr')?mb_substr($response,0,1000):substr($response,0,1000);
        $wpdb->update($table,['status'=>$ok?'sent':'failed','provider_response'=>$excerpt,'sent_at'=>$ok?$now:null],['id'=>$id]);
    }
}
