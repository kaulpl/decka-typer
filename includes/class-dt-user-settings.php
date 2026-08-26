<?php
if (!defined('ABSPATH')) exit;

/**
 * Front-end account settings for TypujKosza.pl users.
 * Keeps the public ranking name separate from the WordPress account display name.
 */
class DT_User_Settings {
    private const META_RANKING_NAME = 'dt_ranking_name';
    private const META_FAVORITE_TEAM = 'dt_favorite_team_id';

    public static function register(): void {
        add_action('rest_api_init', [__CLASS__, 'routes'], 20);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue'], 45);
        add_action('admin_post_dt_typer_logout', [__CLASS__, 'logout']);
        add_action('admin_post_nopriv_dt_typer_logout', [__CLASS__, 'logout_guest']);
    }

    public static function routes(): void {
        register_rest_route('decka-typer/v1', '/account', [
            [
                'methods'=>'GET',
                'callback'=>[__CLASS__, 'get_account'],
                'permission_callback'=>static fn()=>is_user_logged_in(),
            ],
            [
                'methods'=>'POST',
                'callback'=>[__CLASS__, 'save_account'],
                'permission_callback'=>static fn()=>is_user_logged_in(),
            ],
        ]);
    }

    public static function enqueue(): void {
        if (!class_exists('DT_Frontend') || !DT_Frontend::is_typer_page() || !is_user_logged_in()) return;

        wp_enqueue_style(
            'dt-user-settings',
            DT_URL . 'assets/css/user-settings.css',
            ['dt-front'],
            DT_VERSION
        );
        wp_enqueue_style(
            'dt-account-typography',
            DT_URL . 'assets/css/account-typography.css',
            ['dt-user-settings'],
            DT_VERSION
        );
        wp_enqueue_style('dt-name-onboarding', DT_URL . 'assets/css/onboarding.css', ['dt-user-settings'], DT_VERSION);
        wp_enqueue_style(
            'dt-favorite-team-ribbon',
            DT_URL . 'assets/css/favorite-team-ribbon.css',
            ['dt-user-settings'],
            DT_VERSION
        );
        wp_enqueue_script(
            'dt-user-settings',
            DT_URL . 'assets/js/user-settings.js',
            ['dt-front'],
            DT_VERSION,
            true
        );
        wp_enqueue_script(
            'dt-logout-fix',
            DT_URL . 'assets/js/logout-fix.js',
            ['dt-user-settings'],
            DT_VERSION,
            true
        );
        wp_localize_script('dt-user-settings', 'DeckaTyperAccountConfig', [
            'favoriteTeamId'=>self::favorite_team_id(get_current_user_id()),
            'siteUrl'=>self::public_home_url(),
            'pwaQrUrl'=>DT_URL . 'assets/img/typujkosza-pwa-qr.png',
        ]);
    }

    public static function get_account(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response(self::payload(get_current_user_id()));
    }

    public static function save_account(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $uid = get_current_user_id();
        $body = $request->get_json_params();
        if (!is_array($body)) $body = [];

        $name = sanitize_text_field((string)($body['ranking_name'] ?? ''));
        $name = trim((string)preg_replace('/\s+/u', ' ', wp_strip_all_tags($name)));
        $length = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);

        if ($length < 2 || $length > 40) {
            return new WP_Error(
                'invalid_ranking_name',
                'Nazwa w rankingu musi mieć od 2 do 40 znaków.',
                ['status'=>422]
            );
        }

        global $wpdb;
        $duplicate = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key=%s AND LOWER(meta_value)=LOWER(%s) AND user_id<>%d LIMIT 1",
            self::META_RANKING_NAME,
            $name,
            $uid
        ));
        if ($duplicate) {
            return new WP_Error('ranking_name_taken','Ta nazwa jest już używana. Wybierz inną.',['status'=>409]);
        }

        $hasFavoriteTeam = array_key_exists('favorite_team_id', $body);
        $favoriteTeamId = $hasFavoriteTeam ? max(0, (int)$body['favorite_team_id']) : self::favorite_team_id($uid);
        if ($favoriteTeamId > 0 && !self::team_exists($favoriteTeamId)) {
            return new WP_Error(
                'invalid_favorite_team',
                'Wybrana ulubiona drużyna nie istnieje.',
                ['status'=>422]
            );
        }

        update_user_meta($uid, self::META_RANKING_NAME, $name);
        if ($hasFavoriteTeam) {
            if ($favoriteTeamId > 0) update_user_meta($uid, self::META_FAVORITE_TEAM, $favoriteTeamId);
            else delete_user_meta($uid, self::META_FAVORITE_TEAM);
        }
        $notificationPreferences=class_exists('DT_Notifications')
            ? DT_Notifications::save_preferences($uid,is_array($body['notifications']??null)?$body['notifications']:DT_Notifications::preferences($uid))
            : [];

        try {
            DT_Logger::log('account_settings_saved', 'Użytkownik zmienił ustawienia konta Typera.', [
                'ranking_name'=>$name,
                'favorite_team_id'=>$favoriteTeamId,
                'notifications'=>$notificationPreferences,
            ], 'info', $uid);
        } catch (Throwable $ignored) {}

        return new WP_REST_Response([
            'ok'=>true,
            'message'=>'Ustawienia zostały zapisane.',
            'account'=>self::payload($uid),
        ]);
    }

    public static function logout(): void {
        if (!is_user_logged_in()) {
            self::redirect_home();
        }

        check_admin_referer('dt_typer_logout');
        $uid = get_current_user_id();

        try {
            DT_Logger::log('frontend_logout', 'Użytkownik wylogował się z TypujKosza.pl.', [], 'info', $uid);
        } catch (Throwable $ignored) {}

        wp_logout();
        self::redirect_home();
    }

    public static function logout_guest(): void {
        self::redirect_home();
    }

    public static function ranking_name(int $uid, string $fallback = ''): string {
        $name = trim((string)get_user_meta($uid, self::META_RANKING_NAME, true));
        if ($name !== '') return $name;
        $user = get_userdata($uid);
        if ($user && (string)$user->user_login !== '') return (string)$user->user_login;
        if ($fallback !== '') return $fallback;
        return 'Kibic';
    }

    public static function favorite_team_id(int $uid): int {
        return max(0, (int)get_user_meta($uid, self::META_FAVORITE_TEAM, true));
    }

    public static function is_expert(int $uid): bool {
        return $uid > 0 && (bool)get_user_meta($uid, 'dt_typer_expert', true);
    }

    public static function apply_ranking_names(array $rows): array {
        foreach ($rows as &$row) {
            $uid = (int)($row['user_id'] ?? 0);
            if ($uid > 0) {
                $row['display_name'] = self::ranking_name($uid, (string)($row['display_name'] ?? ''));
                $row['is_expert'] = self::is_expert($uid);
            }
        }
        unset($row);
        return $rows;
    }

    private static function payload(int $uid): array {
        global $wpdb;
        $user = get_userdata($uid);
        if (!$user) return [];

        $providers = [];
        $table = DT_DB::table('social_accounts');
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT provider FROM $table WHERE user_id=%d ORDER BY provider ASC",
            $uid
        ));
        foreach ((array)$rows as $provider) {
            $provider = sanitize_key((string)$provider);
            if (in_array($provider, ['google','facebook'], true)) $providers[] = $provider;
        }

        $returnUrl = self::public_home_url();

        $favoriteTeamId = self::favorite_team_id($uid);
        $teams = self::teams();
        $favoriteTeamName = '';
        foreach ($teams as $team) {
            if ((int)$team['id'] === $favoriteTeamId) {
                $favoriteTeamName = (string)$team['name'];
                break;
            }
        }

        return [
            'user_id'=>$uid,
            'username'=>(string)$user->user_login,
            'email'=>(string)$user->user_email,
            'display_name'=>(string)$user->display_name,
            'ranking_name'=>self::ranking_name($uid, (string)$user->user_login),
            'ranking_name_set'=>trim((string)get_user_meta($uid, self::META_RANKING_NAME, true)) !== '',
            'favorite_team_id'=>$favoriteTeamId,
            'favorite_team_name'=>$favoriteTeamName,
            'teams'=>$teams,
            'notifications'=>class_exists('DT_Notifications')?DT_Notifications::preferences($uid):[],
            'push_ready'=>class_exists('DT_Notifications')&&DT_Notifications::push_ready(),
            'registered_at'=>(string)$user->user_registered,
            'providers'=>array_values(array_unique($providers)),
            'password_url'=>wp_lostpassword_url($returnUrl),
            'logout_url'=>self::logout_url(),
        ];
    }

    private static function logout_url(): string {
        return add_query_arg([
            'action'=>'dt_typer_logout',
            '_wpnonce'=>wp_create_nonce('dt_typer_logout'),
        ], admin_url('admin-post.php'));
    }

    private static function public_home_url(): string {
        return class_exists('DT_Canonical') ? DT_Canonical::URL : home_url('/');
    }

    private static function redirect_home(): void {
        wp_safe_redirect(self::public_home_url());
        exit;
    }

    private static function teams(): array {
        global $wpdb;
        $season = (string)(DT_DB::settings()['season'] ?? '');
        $teamsTable = DT_DB::table('teams');
        $matchesTable = DT_DB::table('matches');
        $roundsTable = DT_DB::table('rounds');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT t.id,t.name,t.logo_url,GROUP_CONCAT(DISTINCT r.league_key ORDER BY r.league_key SEPARATOR ',') league_keys
             FROM $teamsTable t
             JOIN (
                SELECT home_team_id team_id,round_id FROM $matchesTable
                UNION ALL
                SELECT away_team_id team_id,round_id FROM $matchesTable
             ) mt ON mt.team_id=t.id
             JOIN $roundsTable r ON r.id=mt.round_id
             WHERE t.name<>'' AND r.season=%s AND r.league_key IN ('1lm','plk','2lm')
             GROUP BY t.id,t.name,t.logo_url
             ORDER BY t.name ASC,t.id ASC",
            $season
        ), ARRAY_A);
        if (!is_array($rows)) return [];

        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $name = trim((string)($row['name'] ?? ''));
            if ($id <= 0 || $name === '') continue;
            $key = function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = [
                'id'=>$id,
                'name'=>$name,
                'logo_url'=>(string)($row['logo_url'] ?? ''),
                'leagues'=>array_values(array_filter(array_map('sanitize_key', explode(',', (string)($row['league_keys'] ?? ''))))),
            ];
        }
        return $out;
    }

    private static function team_exists(int $teamId): bool {
        global $wpdb;
        return (bool)$wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . DT_DB::table('teams') . ' WHERE id=%d LIMIT 1',
            $teamId
        ));
    }
}
