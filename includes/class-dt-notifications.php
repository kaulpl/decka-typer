<?php
if (!defined('ABSPATH')) exit;

class DT_Notifications {
    private const META = 'dt_notification_preferences';
    private const SUBSCRIPTIONS_META = 'dt_onesignal_subscription_ids';
    private const CRON = 'dt_notification_reminders';
    private const ENDPOINT_VERSION = '3';

    public static function register(): void {
        add_action(self::CRON, [__CLASS__, 'cron']);
        add_action('rest_api_init', [__CLASS__, 'rest_routes']);
        add_action('init', [__CLASS__, 'endpoints']);
        add_action('template_redirect', [__CLASS__, 'serve_endpoint'], -100);
        add_action('init', [__CLASS__, 'maybe_flush_rewrite_rules'], 99);
        add_filter('redirect_canonical', [__CLASS__, 'disable_endpoint_redirect'], 10, 2);
        add_action('wp_head', [__CLASS__, 'head'], 3);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue'], 50);
        if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + 300, 'dt_custom_sync', self::CRON);
    }

    public static function defaults(): array {
        return ['push'=>0,'standard'=>1,'schedule_changes'=>1,'postponed'=>1,'incomplete'=>1,'reminder_6h'=>1,'reminder_3d'=>1];
    }

    public static function template_defaults(): array {
        return [
            'welcome'=>['label'=>'Powitanie po pierwszej zgodzie Push','title'=>'Powiadomienia włączone','message'=>'Dziękujemy! Będziemy przypominać Ci o typowaniu i zmianach terminów.','variables'=>[]],
            'schedule_change'=>['label'=>'Zmiana terminu meczu','title'=>'Zmiana terminu · {liga} {grupa}','message'=>'{mecz} — {kolejka}. Poprzedni termin: {stary_termin}. Nowy termin: {nowy_termin}. Twój typ wyzerowano — wybierz zwycięzcę ponownie.','variables'=>['liga','grupa','kolejka','mecz','stary_termin','nowy_termin']],
            'reminder_3d'=>['label'=>'Przypomnienie 3 dni przed meczem','title'=>'Uzupełnij typy · {liga} {grupa}','message'=>'Zbliża się mecz {mecz} ({termin}) w kolejce „{kolejka}”. Liczba meczów pozostałych do wytypowania: {pozostalo}. Nie przegap typowania!','variables'=>['liga','grupa','kolejka','mecz','termin','pozostalo']],
            'reminder_6h'=>['label'=>'Przypomnienie 6 godzin przed meczem','title'=>'Ostatnie godziny na typowanie · {liga} {grupa}','message'=>'Mecz {mecz} rozpocznie się {termin}. Kolejka: {kolejka}. Liczba meczów pozostałych do wytypowania: {pozostalo}. Uzupełnij swoje typy!','variables'=>['liga','grupa','kolejka','mecz','termin','pozostalo']],
            'test'=>['label'=>'Ręczny test powiadomień','title'=>'Test powiadomień','message'=>'To jest test powiadomień. Jeśli widzisz tę wiadomość, ten kanał działa poprawnie.','variables'=>[]],
        ];
    }

    public static function templates(): array {
        $saved=(array)get_option('dt_notification_templates',[]);
        $templates=self::template_defaults();
        foreach ($templates as $key=>&$template) {
            foreach (['title','message'] as $field) {
                if (isset($saved[$key][$field]) && is_string($saved[$key][$field]) && trim($saved[$key][$field])!=='') $template[$field]=$saved[$key][$field];
            }
        }
        unset($template);
        return $templates;
    }

    public static function save_templates(array $input): bool|WP_Error {
        $out=[];
        foreach (self::template_defaults() as $key=>$default) {
            foreach (['title','message'] as $field) {
                if (!isset($input[$key][$field]) || !is_string($input[$key][$field])) return new WP_Error('invalid_template','Brakuje tytułu lub treści szablonu.');
                $value=trim($field==='title'?sanitize_text_field($input[$key][$field]):sanitize_textarea_field($input[$key][$field]));
                if ($value==='') return new WP_Error('empty_template','Tytuł i treść nie mogą być puste.');
                preg_match_all('/\{([^{}]*)\}/u',$value,$matches);
                foreach ($matches[1] as $variable) {
                    if (!in_array($variable,$default['variables'],true)) return new WP_Error('invalid_variable','Nieznana zmienna {'.$variable.'} w szablonie „'.$default['label'].'”.');
                }
                if (preg_match('/[{}]/u',preg_replace('/\{[^{}]*\}/u','',$value))) return new WP_Error('invalid_variable','Sprawdź nawiasy zmiennych w szablonie „'.$default['label'].'”.');
                $out[$key][$field]=$value;
            }
        }
        update_option('dt_notification_templates',$out,false);
        return true;
    }

    public static function notification_title(string $title): string {
        $title=trim((string)preg_replace('/^(?:TypujKosza\.pl\s*[-–—]\s*)+/iu','',trim($title)));
        preg_match('/^.{0,190}/us','TypujKosza.pl - '.$title,$match);
        return $match[0];
    }

    public static function render_template(string $key,array $values=[]): array {
        $template=self::templates()[$key];
        $replace=[];
        foreach ($template['variables'] as $variable) $replace['{'.$variable.'}']=sanitize_text_field((string)($values[$variable]??'—'));
        return ['title'=>self::notification_title(strtr($template['title'],$replace)), 'message'=>strtr($template['message'],$replace)];
    }

    private static function template_context(int $roundId,int $matchId): array {
        global $wpdb;
        $round=$wpdb->get_row($wpdb->prepare('SELECT title,league_key,group_key FROM '.DT_DB::table('rounds').' WHERE id=%d',$roundId));
        $match=$wpdb->get_row($wpdb->prepare('SELECT m.starts_at,h.name home_name,a.name away_name FROM '.DT_DB::table('matches').' m LEFT JOIN '.DT_DB::table('teams').' h ON h.id=m.home_team_id LEFT JOIN '.DT_DB::table('teams').' a ON a.id=m.away_team_id WHERE m.id=%d',$matchId));
        return ['kolejka'=>$round->title??'najbliższa kolejka','liga'=>strtoupper((string)($round->league_key??'')), 'grupa'=>empty($round->group_key)?'':'grupa '.strtoupper((string)$round->group_key), 'mecz'=>$match?($match->home_name.' – '.$match->away_name):'mecz', 'termin'=>self::template_date($match->starts_at??null)];
    }

    private static function template_date(?string $date): string {
        if (!$date || $date==='0000-00-00 00:00:00') return 'termin do ustalenia';
        try { return (new DateTimeImmutable($date,wp_timezone()))->format('d.m.Y H:i'); }
        catch (Exception $e) { return 'termin do ustalenia'; }
    }

    public static function preferences(int $uid): array {
        $preferences=wp_parse_args((array)get_user_meta($uid,self::META,true),self::defaults());
        unset($preferences['email']);
        return $preferences;
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

    public static function rest_routes(): void {
        register_rest_route('decka-typer/v1', '/push-subscription', [
            'methods'=>'POST',
            'callback'=>[__CLASS__, 'register_push_subscription'],
            'permission_callback'=>static fn()=>is_user_logged_in(),
        ]);
        register_rest_route('decka-typer/v1', '/push-preference', ['methods'=>'POST','callback'=>[__CLASS__, 'disable_push'],'permission_callback'=>static fn()=>is_user_logged_in()]);
        register_rest_route('decka-typer/v1', '/push-test', [
            'methods'=>'POST',
            'callback'=>[__CLASS__, 'test_push_subscription'],
            'permission_callback'=>static fn()=>is_user_logged_in(),
        ]);
    }

    public static function disable_push(WP_REST_Request $request): WP_REST_Response|WP_Error {
        if (($request->get_json_params()['push']??null)!==false) return new WP_Error('invalid_preference','Ten endpoint służy do wyłączenia Push.',['status'=>422]);
        $uid=get_current_user_id();$preferences=self::preferences($uid);$preferences['push']=0;
        update_user_meta($uid,self::META,$preferences);
        return new WP_REST_Response(['ok'=>true,'push_enabled'=>false]);
    }

    public static function register_push_subscription(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $body=$request->get_json_params();
        $subscriptionId=sanitize_text_field((string)($body['subscription_id']??''));
        if (!preg_match('/^[A-Za-z0-9_-]{8,160}$/',$subscriptionId)) {
            return new WP_Error('invalid_push_subscription','OneSignal nie zwrócił poprawnego identyfikatora urządzenia.',['status'=>422]);
        }
        $uid=get_current_user_id();
        $stored=array_values(array_filter(array_map('sanitize_text_field',(array)get_user_meta($uid,self::SUBSCRIPTIONS_META,true))));
        $stored=array_values(array_unique(array_merge([$subscriptionId],$stored)));
        $stored=array_slice($stored,0,10);
        update_user_meta($uid,self::SUBSCRIPTIONS_META,$stored);
        $preferences=self::preferences($uid);
        // Passive refresh must never undo an explicit account opt-out.
        if (($body['activate']??false)===true) {
            $preferences['push']=1;
            update_user_meta($uid,self::META,$preferences);
        }
        return new WP_REST_Response(['ok'=>true,'registered_devices'=>count($stored),'push_enabled'=>!empty($preferences['push'])]);
    }

    public static function test_push_subscription(WP_REST_Request $request): WP_REST_Response|WP_Error {
        if (!self::push_ready()) return new WP_Error('push_not_configured','OneSignal nie jest skonfigurowany.',['status'=>503]);
        $body=$request->get_json_params();
        $subscriptionId=sanitize_text_field((string)($body['subscription_id']??''));
        $uid=get_current_user_id();
        if ($subscriptionId==='' || !in_array($subscriptionId,self::subscription_ids($uid),true)) return new WP_Error('push_subscription_not_registered','To urządzenie nie jest przypisane do zalogowanego konta. Włącz powiadomienia ponownie.',['status'=>422]);
        $delaySeconds=15;
        $copy=self::device_test_copy();
        $result=self::send_channel($uid,'push','device_test','device-test-'.$uid.'-'.wp_generate_uuid4(),$copy['title'],$copy['message'],(int)$copy['round_id'],0,[$subscriptionId],$delaySeconds);

        $result['deliver_in_seconds']=$delaySeconds;
        $result['message']=$result['ok']?'Test zaplanowany. Zamknij aplikację lub przejdź do ekranu głównego telefonu.':'OneSignal nie przyjął testu.';
        return new WP_REST_Response($result,$result['ok']?200:502);
    }

    private static function device_test_copy(): array {
        return array_merge(self::render_template('test'),['round_id'=>0]);
    }

    private static function subscription_ids(int $uid): array {
        $ids=array_values(array_filter(array_map('sanitize_text_field',(array)get_user_meta($uid,self::SUBSCRIPTIONS_META,true))));
        return array_values(array_filter($ids,static fn($id)=>preg_match('/^[A-Za-z0-9_-]{8,160}$/',(string)$id)));
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
            $iconSvg = DT_URL.'assets/img/app-icon.svg';
            $icon192 = DT_URL.'assets/img/app-icon-192.png';
            $icon512 = DT_URL.'assets/img/app-icon-512.png';
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
                    ['src'=>$iconSvg,'sizes'=>'any','type'=>'image/svg+xml','purpose'=>'any'],
                    ['src'=>$icon192,'sizes'=>'192x192','type'=>'image/png','purpose'=>'any'],
                    ['src'=>$icon512,'sizes'=>'512x512','type'=>'image/png','purpose'=>'any maskable'],
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
        $appleIcon = DT_URL.'assets/img/app-icon-180.png';
        echo '<link rel="manifest" href="'.esc_url(home_url('/typkosza-manifest.webmanifest')).'">' . "\n";
        echo '<link rel="apple-touch-icon" sizes="180x180" href="'.esc_url($appleIcon).'">' . "\n";
        echo '<meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="TypujKosza">' . "\n";
    }

    public static function enqueue(): void {
        if (!is_user_logged_in() || !class_exists('DT_Frontend') || !DT_Frontend::is_typer_page()) return;
        wp_enqueue_style('dt-notifications',DT_URL.'assets/css/notifications.css',['dt-user-settings'],DT_VERSION);
        if (self::push_ready()) wp_enqueue_script('dt-onesignal','https://cdn.onesignal.com/sdks/web/v16/OneSignalSDK.page.js',[],null,false);
        wp_enqueue_script('dt-notifications',DT_URL.'assets/js/notifications.js',[],DT_VERSION,true);
        wp_localize_script('dt-notifications','DeckaTyperNotifications',[
            'userId'=>get_current_user_id(),'pushReady'=>self::push_ready(),
            'pushEnabled'=>!empty(self::preferences(get_current_user_id())['push']),
            'preferenceUrl'=>rest_url('decka-typer/v1/push-preference'),
            'appId'=>self::push_ready()?(string)DT_ONESIGNAL_APP_ID:'',
            'workerPath'=>wp_make_link_relative(add_query_arg('dt_onesignal_worker','1',home_url('/'))),
            'workerScope'=>trailingslashit(wp_make_link_relative(home_url('/'))),'homeUrl'=>home_url('/'),
            'subscriptionUrl'=>rest_url('decka-typer/v1/push-subscription'),
            'testUrl'=>rest_url('decka-typer/v1/push-test'),
            'welcome'=>self::render_template('welcome'),
            'iconUrl'=>DT_URL.'assets/img/app-icon-192.png',
            'nonce'=>wp_create_nonce('wp_rest'),
        ]);
    }

    public static function schedule_changed(int $matchId, int $roundId, ?string $old, ?string $new, array $userIds): void {
        global $wpdb;
        $wpdb->insert(DT_DB::table('schedule_changes'),['match_id'=>$matchId,'round_id'=>$roundId,'old_starts_at'=>$old,'new_starts_at'=>$new,'reset_count'=>count($userIds),'detected_at'=>current_time('mysql')]);
        $context=self::template_context($roundId,$matchId);
        $copy=self::render_template('schedule_change',array_merge($context,['stary_termin'=>self::template_date($old),'nowy_termin'=>self::template_date($new)]));
        foreach (array_unique(array_map('intval',$userIds)) as $uid) {
            if (!$uid) continue;
            $prefs=self::preferences($uid);
            $title=$copy['title'];
            $message=$copy['message'];
            $eventKey='schedule-'.$matchId.'-'.md5((string)$new);
            self::send_channel($uid,'inapp','schedule_change',$eventKey,$title,$message,$roundId,$matchId);
            if (!empty($prefs['schedule_changes']) || !empty($prefs['postponed'])) {
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
        $context=self::template_context((int)$match->round_id,(int)$match->id);
        foreach ((array)$users as $uid) {
            $uid=(int)$uid;$prefs=self::preferences($uid);
            if (empty($prefs['standard']) || empty($prefs['incomplete']) || empty($prefs['reminder_'.$window])) continue;
            $has=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".DT_DB::table('predictions')." WHERE user_id=%d AND match_id=%d",$uid,(int)$match->id));
            if ($has) continue;
            $remaining=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".DT_DB::table('matches')." m LEFT JOIN ".DT_DB::table('predictions')." p ON p.match_id=m.id AND p.user_id=%d WHERE m.round_id=%d AND m.starts_at>%s AND p.id IS NULL",$uid,(int)$match->round_id,current_time('mysql')));
            if (!$remaining) continue;
            $copy=self::render_template('reminder_'.$window,array_merge($context,['pozostalo'=>number_format_i18n($remaining)]));
            self::deliver($uid,'incomplete','reminder-'.$window.'-'.(int)$match->id.'-'.md5((string)$match->starts_at),$copy['title'],$copy['message'],(int)$match->round_id,(int)$match->id);
        }
    }

    public static function deliver(int $uid,string $type,string $eventKey,string $title,string $message,int $roundId=0,int $matchId=0): void {
        $prefs=self::preferences($uid);
        self::send_channel($uid,'inapp',$type,$eventKey,$title,$message,$roundId,$matchId);
        if (!empty($prefs['push'])) self::send_channel($uid,'push',$type,$eventKey,$title,$message,$roundId,$matchId);
    }

    public static function send_admin_test(int $uid): array {
        global $wpdb;
        $eventKey='admin-test-'.$uid.'-'.wp_generate_uuid4();
        $copy=self::render_template('test');
        $title=$copy['title'];
        $message=$copy['message'];
        $channels=['push'];
        foreach ($channels as $channel) self::send_channel($uid,$channel,'admin_test',$eventKey,$title,$message,0,0,[],15);

        $rows=$wpdb->get_results($wpdb->prepare(
            'SELECT channel,status FROM '.DT_DB::table('notifications').' WHERE user_id=%d AND event_key=%s ORDER BY id ASC',
            $uid,
            $eventKey
        ),ARRAY_A);
        return is_array($rows)?$rows:[];
    }

    private static function send_channel(int $uid,string $channel,string $type,string $eventKey,string $title,string $message,int $roundId,int $matchId,array $forcedSubscriptions=[],int $delaySeconds=0): array {
        global $wpdb;
        $title=self::notification_title($title);
        $table=DT_DB::table('notifications');$now=current_time('mysql');
        $inserted=$wpdb->insert($table,['user_id'=>$uid,'channel'=>$channel,'event_key'=>$eventKey,'event_type'=>$type,'title'=>$title,'message'=>$message,'round_id'=>$roundId?:null,'match_id'=>$matchId?:null,'status'=>'queued','created_at'=>$now]);
        if (!$inserted) return ['ok'=>false,'status'=>'failed','response'=>'Nie udało się zapisać testu w historii powiadomień.'];
        $id=(int)$wpdb->insert_id;$ok=false;$response='';
        if ($channel==='inapp') {
            $ok=true;$response='Wiadomość zapisana w aplikacji';
        } elseif ($channel==='push' && self::push_ready()) {
            $subscriptionIds=$forcedSubscriptions?:self::subscription_ids($uid);
            $payload=['app_id'=>(string)DT_ONESIGNAL_APP_ID,'target_channel'=>'push','headings'=>['pl'=>$title,'en'=>$title],'contents'=>['pl'=>$message,'en'=>$message],'url'=>home_url('/')];
            if ($delaySeconds>0) $payload['send_after']=gmdate('c',time()+$delaySeconds);
            if ($subscriptionIds) $payload['include_subscription_ids']=$subscriptionIds;
            else $payload['include_aliases']=['external_id'=>[(string)$uid]];
            $request=wp_remote_post('https://api.onesignal.com/notifications',['timeout'=>15,'headers'=>['Authorization'=>'Key '.(string)DT_ONESIGNAL_REST_API_KEY,'Content-Type'=>'application/json'],'body'=>wp_json_encode($payload)]);
            if (is_wp_error($request)) {
                $response=$request->get_error_message();
            } else {
                $code=wp_remote_retrieve_response_code($request);
                $response=(string)wp_remote_retrieve_body($request);
                $decoded=json_decode($response,true);
                $providerErrors=is_array($decoded)&&!empty($decoded['errors']);
                $providerAccepted=is_array($decoded)&&!empty($decoded['id']);
                $ok=$code>=200&&$code<300&&!$providerErrors&&$providerAccepted;
                if (!$subscriptionIds) $response='Brak zapisanego urządzenia; próba przez alias external_id. '.$response;
            }
        } else $response='OneSignal nie jest skonfigurowany';
        $excerpt=function_exists('mb_substr')?mb_substr($response,0,1000):substr($response,0,1000);
        $status=$ok?'sent':'failed';
        $wpdb->update($table,['status'=>$status,'provider_response'=>$excerpt,'sent_at'=>$ok?$now:null],['id'=>$id]);
        return ['ok'=>$ok,'status'=>$status,'response'=>$excerpt];
    }
}
