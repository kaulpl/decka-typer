<?php
if (!defined('ABSPATH')) exit;

/**
 * Front-end account settings for Decka Typer users.
 * Keeps the public ranking name separate from the WordPress account display name.
 */
class DT_User_Settings {
    private const META_RANKING_NAME = 'dt_ranking_name';

    public static function register(): void {
        add_action('rest_api_init', [__CLASS__, 'routes'], 20);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue'], 45);
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
        wp_enqueue_script(
            'dt-user-settings',
            DT_URL . 'assets/js/user-settings.js',
            ['dt-front'],
            DT_VERSION,
            true
        );
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

        update_user_meta($uid, self::META_RANKING_NAME, $name);
        try {
            DT_Logger::log('account_settings_saved', 'Użytkownik zmienił ustawienia konta Typera.', [
                'ranking_name'=>$name,
            ], 'info', $uid);
        } catch (Throwable $ignored) {}

        return new WP_REST_Response([
            'ok'=>true,
            'message'=>'Ustawienia zostały zapisane.',
            'account'=>self::payload($uid),
        ]);
    }

    public static function ranking_name(int $uid, string $fallback = ''): string {
        $name = trim((string)get_user_meta($uid, self::META_RANKING_NAME, true));
        if ($name !== '') return $name;
        if ($fallback !== '') return $fallback;
        $user = get_userdata($uid);
        return $user ? (string)$user->display_name : 'Kibic';
    }

    public static function apply_ranking_names(array $rows): array {
        foreach ($rows as &$row) {
            $uid = (int)($row['user_id'] ?? 0);
            if ($uid > 0) {
                $row['display_name'] = self::ranking_name($uid, (string)($row['display_name'] ?? ''));
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

        $returnUrl = class_exists('DT_Frontend') && DT_Frontend::is_typer_page()
            ? get_permalink()
            : home_url('/typer/');

        return [
            'user_id'=>$uid,
            'username'=>(string)$user->user_login,
            'email'=>(string)$user->user_email,
            'display_name'=>(string)$user->display_name,
            'ranking_name'=>self::ranking_name($uid, (string)$user->display_name),
            'registered_at'=>(string)$user->user_registered,
            'providers'=>array_values(array_unique($providers)),
            'password_url'=>wp_lostpassword_url($returnUrl ?: home_url('/typer/')),
            'logout_url'=>wp_logout_url($returnUrl ?: home_url('/typer/')),
        ];
    }
}
