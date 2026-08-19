<?php
if (!defined('ABSPATH')) exit;

class DT_OAuth {
    public static function register(): void {
        add_action('admin_post_nopriv_dt_oauth_start', [__CLASS__, 'start']);
        add_action('admin_post_dt_oauth_start', [__CLASS__, 'start']);
        add_action('admin_post_nopriv_dt_oauth_callback', [__CLASS__, 'callback']);
        add_action('admin_post_dt_oauth_callback', [__CLASS__, 'callback']);
        add_action('rest_api_init', [__CLASS__, 'rest_routes']);
    }

    public static function rest_routes(): void {
        register_rest_route('decka-typer/v1', '/oauth/(?P<provider>google|facebook)/callback', [
            'methods'=>['GET','POST'],
            'callback'=>[__CLASS__, 'rest_callback'],
            'permission_callback'=>'__return_true',
        ]);
    }

    public static function rest_callback(WP_REST_Request $request) {
        $_REQUEST['provider'] = sanitize_key((string) $request['provider']);
        foreach ($request->get_params() as $key=>$value) {
            if (is_scalar($value)) $_REQUEST[$key] = (string) $value;
        }
        self::callback();
        return new WP_REST_Response(null, 204);
    }

    public static function configured(string $provider): bool {
        $settings = DT_DB::settings();
        return match ($provider) {
            'google' => !empty($settings['google_client_id']) && !empty($settings['google_client_secret']),
            'facebook' => !empty($settings['facebook_app_id']) && !empty($settings['facebook_app_secret']),
            default => false,
        };
    }

    public static function start(): void {
        $provider = sanitize_key($_GET['provider'] ?? '');
        if (!in_array($provider, ['google','facebook'], true) || !self::configured($provider)) {
            self::fail('Logowanie tym sposobem nie jest jeszcze skonfigurowane.');
        }
        $state = wp_generate_password(48, false, false);
        set_transient('dt_oauth_' . hash('sha256', $state), ['provider'=>$provider, 'created'=>time()], 10 * MINUTE_IN_SECONDS);
        $callback = self::callback_url($provider);
        $settings = DT_DB::settings();

        if ($provider === 'google') {
            $url = add_query_arg([
                'client_id'=>$settings['google_client_id'],
                'redirect_uri'=>$callback,
                'response_type'=>'code',
                'scope'=>'openid email profile',
                'state'=>$state,
                'prompt'=>'select_account',
                'access_type'=>'online',
            ], 'https://accounts.google.com/o/oauth2/v2/auth');
        } else {
            $url = add_query_arg([
                'client_id'=>$settings['facebook_app_id'],
                'redirect_uri'=>$callback,
                'response_type'=>'code',
                'scope'=>'email,public_profile',
                'state'=>$state,
            ], 'https://www.facebook.com/v26.0/dialog/oauth');
        }
        wp_redirect($url, 302, 'Decka Typer');
        exit;
    }

    public static function callback(): void {
        $provider = sanitize_key($_REQUEST['provider'] ?? '');
        $state = sanitize_text_field($_REQUEST['state'] ?? '');
        $code = sanitize_text_field($_REQUEST['code'] ?? '');
        $error = sanitize_text_field($_REQUEST['error'] ?? '');
        if ($error) self::fail('Logowanie anulowane lub odrzucone: ' . $error);
        if (!in_array($provider, ['google','facebook'], true) || !$state || !$code) {
            self::fail('Niepełna odpowiedź dostawcy logowania.');
        }

        $key = 'dt_oauth_' . hash('sha256', $state);
        $saved = get_transient($key);
        delete_transient($key);
        if (!$saved || !hash_equals((string) $saved['provider'], $provider)) self::fail('Sesja logowania wygasła. Spróbuj ponownie.');

        try {
            $identity = $provider === 'google' ? self::google_identity($code) : self::facebook_identity($code);
            $userId = self::login_identity($provider, $identity);
            wp_set_current_user($userId);
            wp_set_auth_cookie($userId, true, is_ssl());
            DT_Logger::log('oauth_login', 'Logowanie społecznościowe.', ['provider'=>$provider], 'info', $userId);
            wp_safe_redirect(add_query_arg('dt_login', 'ok', self::typer_url()));
            exit;
        } catch (Throwable $e) {
            DT_Logger::log('oauth_error', $e->getMessage(), ['provider'=>$provider], 'error');
            self::fail('Nie udało się zalogować. ' . $e->getMessage());
        }
    }

    private static function google_identity(string $code): array {
        $settings = DT_DB::settings();
        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'timeout'=>20,
            'body'=>[
                'code'=>$code,
                'client_id'=>$settings['google_client_id'],
                'client_secret'=>$settings['google_client_secret'],
                'redirect_uri'=>self::callback_url('google'),
                'grant_type'=>'authorization_code',
            ],
        ]);
        $token = self::json_response($response, 'Google token');
        if (empty($token['access_token'])) throw new RuntimeException('Google nie zwrócił tokenu dostępu.');
        $response = wp_remote_get('https://openidconnect.googleapis.com/v1/userinfo', [
            'timeout'=>20,
            'headers'=>['Authorization'=>'Bearer ' . $token['access_token']],
        ]);
        $user = self::json_response($response, 'Google userinfo');
        if (empty($user['sub']) || empty($user['email']) || empty($user['email_verified'])) {
            throw new RuntimeException('Google nie potwierdził adresu e-mail.');
        }
        return [
            'sub'=>(string) $user['sub'],
            'email'=>sanitize_email($user['email']),
            'name'=>sanitize_text_field($user['name'] ?? $user['email']),
        ];
    }

    private static function facebook_identity(string $code): array {
        $settings = DT_DB::settings();
        $url = add_query_arg([
            'client_id'=>$settings['facebook_app_id'],
            'client_secret'=>$settings['facebook_app_secret'],
            'redirect_uri'=>self::callback_url('facebook'),
            'code'=>$code,
        ], 'https://graph.facebook.com/v26.0/oauth/access_token');
        $token = self::json_response(wp_remote_get($url, ['timeout'=>20]), 'Facebook token');
        if (empty($token['access_token'])) throw new RuntimeException('Facebook nie zwrócił tokenu dostępu.');
        $profileUrl = add_query_arg([
            'fields'=>'id,name,email',
            'access_token'=>$token['access_token'],
        ], 'https://graph.facebook.com/v26.0/me');
        $user = self::json_response(wp_remote_get($profileUrl, ['timeout'=>20]), 'Facebook profile');
        if (empty($user['id'])) throw new RuntimeException('Facebook nie zwrócił identyfikatora konta.');
        if (empty($user['email'])) throw new RuntimeException('Konto Facebook nie udostępniło adresu e-mail.');
        return [
            'sub'=>(string) $user['id'],
            'email'=>sanitize_email($user['email']),
            'name'=>sanitize_text_field($user['name'] ?? $user['email']),
        ];
    }

    private static function login_identity(string $provider, array $identity): int {
        global $wpdb;
        $table = DT_DB::table('social_accounts');
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE provider=%s AND provider_user_id=%s",
            $provider, $identity['sub']
        ));
        $now = current_time('mysql');
        if ($row) {
            $wpdb->update($table, ['last_login_at'=>$now, 'email'=>$identity['email']], ['id'=>(int) $row->id]);
            return (int) $row->user_id;
        }

        $user = get_user_by('email', $identity['email']);
        if (!$user) {
            $base = sanitize_user(strstr($identity['email'], '@', true) ?: 'kibic', true);
            if (!$base) $base = 'kibic';
            $login = $base;
            $n = 1;
            while (username_exists($login)) $login = $base . (++$n);
            $uid = wp_create_user($login, wp_generate_password(32, true, true), $identity['email']);
            if (is_wp_error($uid)) throw new RuntimeException($uid->get_error_message());
            wp_update_user([
                'ID'=>$uid,
                'display_name'=>$identity['name'] ?: $login,
                'nickname'=>$identity['name'] ?: $login,
            ]);
            $user = get_user_by('id', $uid);
        }
        $wpdb->insert($table, [
            'user_id'=>$user->ID,
            'provider'=>$provider,
            'provider_user_id'=>$identity['sub'],
            'email'=>$identity['email'],
            'created_at'=>$now,
            'last_login_at'=>$now,
        ]);
        return (int) $user->ID;
    }

    public static function callback_url(string $provider): string {
        return rest_url('decka-typer/v1/oauth/' . sanitize_key($provider) . '/callback');
    }

    public static function start_url(string $provider): string {
        return add_query_arg(['action'=>'dt_oauth_start', 'provider'=>$provider], admin_url('admin-post.php'));
    }

    private static function typer_url(): string {
        $settings = DT_DB::settings();
        $url = !empty($settings['typer_page_id']) ? get_permalink((int) $settings['typer_page_id']) : home_url('/typer/');
        return $url ?: home_url('/typer/');
    }

    private static function fail(string $message): void {
        wp_safe_redirect(add_query_arg('dt_login_error', rawurlencode($message), self::typer_url()));
        exit;
    }

    private static function json_response($response, string $label): array {
        if (is_wp_error($response)) throw new RuntimeException($label . ': ' . $response->get_error_message());
        $code = wp_remote_retrieve_response_code($response);
        $json = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($json)) {
            $detail = is_array($json) ? ($json['error_description'] ?? $json['error']['message'] ?? $json['error'] ?? '') : '';
            throw new RuntimeException($label . ' zwrócił błąd' . ($detail ? ': ' . sanitize_text_field((string) $detail) : '.'));
        }
        return $json;
    }
}
