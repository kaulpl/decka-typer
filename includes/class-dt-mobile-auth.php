<?php
if (!defined('ABSPATH')) exit;

/**
 * Native iOS authentication bridge.
 * OAuth happens in ASWebAuthenticationSession, the app receives a signed bearer
 * token, then exchanges it for a one-time WordPress web session inside WKWebView.
 */
class DT_Mobile_Auth {
    private const TOKEN_VERSION = 'v1';
    private const TOKEN_TTL = 7776000; // 90 days.
    private const APP_CALLBACK = 'deckatyper://oauth';

    public static function register(): void {
        add_filter('determine_current_user', [__CLASS__, 'determine_current_user'], 30);
        add_action('rest_api_init', [__CLASS__, 'routes'], 5);
    }

    public static function routes(): void {
        register_rest_route('decka-typer/v1', '/mobile/auth/(?P<provider>google|facebook)/start', [
            'methods'=>'GET', 'callback'=>[__CLASS__, 'start'], 'permission_callback'=>'__return_true',
        ]);
        register_rest_route('decka-typer/v1', '/mobile/auth/(?P<provider>google|facebook)/callback', [
            'methods'=>['GET','POST'], 'callback'=>[__CLASS__, 'callback'], 'permission_callback'=>'__return_true',
        ]);
        register_rest_route('decka-typer/v1', '/mobile/session', [
            'methods'=>'GET', 'callback'=>[__CLASS__, 'session'],
            'permission_callback'=>static fn()=>is_user_logged_in(),
        ]);
        register_rest_route('decka-typer/v1', '/mobile/web-session', [
            'methods'=>'POST', 'callback'=>[__CLASS__, 'web_session'],
            'permission_callback'=>static fn()=>is_user_logged_in(),
        ]);
        register_rest_route('decka-typer/v1', '/mobile/web-login', [
            'methods'=>'GET', 'callback'=>[__CLASS__, 'web_login'], 'permission_callback'=>'__return_true',
        ]);
        register_rest_route('decka-typer/v1', '/mobile/logout', [
            'methods'=>'POST', 'callback'=>[__CLASS__, 'logout'],
            'permission_callback'=>static fn()=>is_user_logged_in(),
        ]);
    }

    public static function determine_current_user($userId): int {
        $userId = (int)$userId;
        if ($userId > 0) return $userId;
        $token = self::request_token();
        if (!$token) return 0;
        $payload = self::verify_token($token);
        return $payload ? (int)$payload['uid'] : 0;
    }

    public static function start(WP_REST_Request $request) {
        $provider = sanitize_key((string)$request['provider']);
        if (!in_array($provider, ['google','facebook'], true) || !DT_OAuth::configured($provider)) {
            return new WP_Error('provider_not_configured', 'Ten sposób logowania nie jest skonfigurowany.', ['status'=>400]);
        }

        $state = wp_generate_password(48, false, false);
        set_transient('dt_mobile_oauth_' . hash('sha256', $state), [
            'provider'=>$provider,
            'created'=>time(),
        ], 10 * MINUTE_IN_SECONDS);

        $settings = DT_DB::settings();
        $callback = self::provider_callback_url($provider);
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

        wp_redirect($url, 302, 'Decka Typer iOS');
        exit;
    }

    public static function callback(WP_REST_Request $request) {
        $provider = sanitize_key((string)$request['provider']);
        $state = sanitize_text_field((string)$request->get_param('state'));
        $code = sanitize_text_field((string)$request->get_param('code'));
        $error = sanitize_text_field((string)$request->get_param('error'));
        if ($error) self::redirect_to_app(['error'=>$error]);
        if (!$state || !$code || !in_array($provider, ['google','facebook'], true)) {
            self::redirect_to_app(['error'=>'invalid_response']);
        }

        $key = 'dt_mobile_oauth_' . hash('sha256', $state);
        $saved = get_transient($key);
        delete_transient($key);
        if (!$saved || !hash_equals((string)($saved['provider'] ?? ''), $provider)) {
            self::redirect_to_app(['error'=>'expired_state']);
        }

        try {
            $identity = $provider === 'google'
                ? self::google_identity($code, self::provider_callback_url('google'))
                : self::facebook_identity($code, self::provider_callback_url('facebook'));
            $uid = self::login_identity($provider, $identity);
            $token = self::issue_token($uid);
            try { DT_Logger::log('mobile_login', 'Logowanie w aplikacji iOS.', ['provider'=>$provider], 'info', $uid); } catch (Throwable $ignored) {}
            self::redirect_to_app(['token'=>$token]);
        } catch (Throwable $e) {
            try { DT_Logger::log('mobile_oauth_error', $e->getMessage(), ['provider'=>$provider], 'error'); } catch (Throwable $ignored) {}
            self::redirect_to_app(['error'=>'oauth_failed']);
        }
    }

    public static function session(WP_REST_Request $request): WP_REST_Response {
        $uid = get_current_user_id();
        $user = get_userdata($uid);
        return new WP_REST_Response([
            'ok'=>true,
            'user_id'=>$uid,
            'display_name'=>$user ? $user->display_name : 'Kibic',
            'email'=>$user ? $user->user_email : '',
            'avatar'=>get_avatar_url($uid, ['size'=>160]),
        ]);
    }

    public static function web_session(WP_REST_Request $request): WP_REST_Response {
        $uid = get_current_user_id();
        $code = wp_generate_password(48, false, false);
        set_transient('dt_mobile_web_' . hash('sha256', $code), [
            'uid'=>$uid,
            'created'=>time(),
        ], 2 * MINUTE_IN_SECONDS);
        return new WP_REST_Response([
            'ok'=>true,
            'url'=>add_query_arg('code', rawurlencode($code), rest_url('decka-typer/v1/mobile/web-login')),
        ]);
    }

    public static function web_login(WP_REST_Request $request) {
        $code = sanitize_text_field((string)$request->get_param('code'));
        if (!$code) return new WP_Error('invalid_code', 'Brak kodu sesji.', ['status'=>400]);
        $key = 'dt_mobile_web_' . hash('sha256', $code);
        $saved = get_transient($key);
        delete_transient($key);
        $uid = (int)($saved['uid'] ?? 0);
        if (!$uid || !get_userdata($uid)) return new WP_Error('expired_code', 'Kod sesji wygasł.', ['status'=>401]);

        wp_set_current_user($uid);
        wp_set_auth_cookie($uid, true, is_ssl());
        wp_safe_redirect(self::typer_url());
        exit;
    }

    public static function logout(WP_REST_Request $request): WP_REST_Response {
        $uid = get_current_user_id();
        $version = max(1, (int)get_user_meta($uid, 'dt_mobile_session_version', true));
        update_user_meta($uid, 'dt_mobile_session_version', $version + 1);
        return new WP_REST_Response(['ok'=>true]);
    }

    private static function google_identity(string $code, string $redirectUri): array {
        $settings = DT_DB::settings();
        $token = self::json_response(wp_remote_post('https://oauth2.googleapis.com/token', [
            'timeout'=>20,
            'body'=>[
                'code'=>$code,
                'client_id'=>$settings['google_client_id'],
                'client_secret'=>$settings['google_client_secret'],
                'redirect_uri'=>$redirectUri,
                'grant_type'=>'authorization_code',
            ],
        ]), 'Google token');
        if (empty($token['access_token'])) throw new RuntimeException('Google nie zwrócił tokenu.');
        $user = self::json_response(wp_remote_get('https://openidconnect.googleapis.com/v1/userinfo', [
            'timeout'=>20,
            'headers'=>['Authorization'=>'Bearer ' . $token['access_token']],
        ]), 'Google userinfo');
        if (empty($user['sub']) || empty($user['email']) || empty($user['email_verified'])) {
            throw new RuntimeException('Google nie potwierdził adresu e-mail.');
        }
        return [
            'sub'=>(string)$user['sub'],
            'email'=>sanitize_email($user['email']),
            'name'=>sanitize_text_field($user['name'] ?? $user['email']),
        ];
    }

    private static function facebook_identity(string $code, string $redirectUri): array {
        $settings = DT_DB::settings();
        $url = add_query_arg([
            'client_id'=>$settings['facebook_app_id'],
            'client_secret'=>$settings['facebook_app_secret'],
            'redirect_uri'=>$redirectUri,
            'code'=>$code,
        ], 'https://graph.facebook.com/v26.0/oauth/access_token');
        $token = self::json_response(wp_remote_get($url, ['timeout'=>20]), 'Facebook token');
        if (empty($token['access_token'])) throw new RuntimeException('Facebook nie zwrócił tokenu.');
        $profile = add_query_arg([
            'fields'=>'id,name,email',
            'access_token'=>$token['access_token'],
        ], 'https://graph.facebook.com/v26.0/me');
        $user = self::json_response(wp_remote_get($profile, ['timeout'=>20]), 'Facebook profile');
        if (empty($user['id']) || empty($user['email'])) throw new RuntimeException('Facebook nie zwrócił wymaganych danych konta.');
        return [
            'sub'=>(string)$user['id'],
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
            $wpdb->update($table, ['last_login_at'=>$now, 'email'=>$identity['email']], ['id'=>(int)$row->id]);
            return (int)$row->user_id;
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
        $inserted = $wpdb->insert($table, [
            'user_id'=>$user->ID,
            'provider'=>$provider,
            'provider_user_id'=>$identity['sub'],
            'email'=>$identity['email'],
            'created_at'=>$now,
            'last_login_at'=>$now,
        ]);
        if ($inserted === false) throw new RuntimeException('Nie udało się połączyć konta społecznościowego.');
        return (int)$user->ID;
    }

    private static function issue_token(int $uid): string {
        $sessionVersion = (int)get_user_meta($uid, 'dt_mobile_session_version', true);
        if ($sessionVersion < 1) {
            $sessionVersion = 1;
            update_user_meta($uid, 'dt_mobile_session_version', $sessionVersion);
        }
        $payload = [
            'uid'=>$uid,
            'iat'=>time(),
            'exp'=>time() + self::TOKEN_TTL,
            'sv'=>$sessionVersion,
            'nonce'=>wp_generate_password(16, false, false),
        ];
        $encoded = self::b64url_encode((string)wp_json_encode($payload));
        $body = self::TOKEN_VERSION . '.' . $encoded;
        $signature = self::b64url_encode(hash_hmac('sha256', $body, wp_salt('auth'), true));
        return $body . '.' . $signature;
    }

    private static function verify_token(string $token): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== self::TOKEN_VERSION) return null;
        [$version,$encoded,$signature] = $parts;
        $body = $version . '.' . $encoded;
        $expected = self::b64url_encode(hash_hmac('sha256', $body, wp_salt('auth'), true));
        if (!hash_equals($expected, $signature)) return null;
        $payload = json_decode(self::b64url_decode($encoded), true);
        if (!is_array($payload) || empty($payload['uid']) || empty($payload['exp']) || (int)$payload['exp'] < time()) return null;
        $uid = (int)$payload['uid'];
        if (!get_userdata($uid)) return null;
        $sessionVersion = (int)get_user_meta($uid, 'dt_mobile_session_version', true);
        if ($sessionVersion < 1) $sessionVersion = 1;
        if ((int)($payload['sv'] ?? 0) !== $sessionVersion) return null;
        return $payload;
    }

    private static function request_token(): string {
        $header = '';
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) $header = (string)$_SERVER['HTTP_AUTHORIZATION'];
        elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $header = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) return trim($m[1]);
        if (!empty($_SERVER['HTTP_X_DECKA_TYPER_TOKEN'])) return trim((string)$_SERVER['HTTP_X_DECKA_TYPER_TOKEN']);
        return '';
    }

    private static function provider_callback_url(string $provider): string {
        return rest_url('decka-typer/v1/mobile/auth/' . sanitize_key($provider) . '/callback');
    }

    private static function typer_url(): string {
        $settings = DT_DB::settings();
        $url = !empty($settings['typer_page_id']) ? get_permalink((int)$settings['typer_page_id']) : home_url('/typer/');
        return $url ?: home_url('/typer/');
    }

    private static function redirect_to_app(array $params): void {
        $url = self::APP_CALLBACK . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        wp_redirect($url, 302, 'Decka Typer iOS');
        exit;
    }

    private static function json_response($response, string $label): array {
        if (is_wp_error($response)) throw new RuntimeException($label . ': ' . $response->get_error_message());
        $code = (int)wp_remote_retrieve_response_code($response);
        $json = json_decode(wp_remote_retrieve_body($response), true);
        if ($code < 200 || $code >= 300 || !is_array($json)) {
            $detail = is_array($json) ? ($json['error_description'] ?? $json['error']['message'] ?? $json['error'] ?? '') : '';
            throw new RuntimeException($label . ' zwrócił błąd' . ($detail ? ': ' . sanitize_text_field((string)$detail) : '.'));
        }
        return $json;
    }

    private static function b64url_encode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function b64url_decode(string $value): string {
        $padding = strlen($value) % 4;
        if ($padding) $value .= str_repeat('=', 4 - $padding);
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return is_string($decoded) ? $decoded : '';
    }
}
