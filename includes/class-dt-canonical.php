<?php
if (!defined('ABSPATH')) exit;

/**
 * Central canonical URL layer for the public TypujKosza.pl application.
 * Keeps public entry points and OAuth return URLs on the production domain.
 */
class DT_Canonical {
    public const URL = 'https://typujkosza.pl/';
    public const HOST = 'typujkosza.pl';

    public static function register(): void {
        add_action('template_redirect', [__CLASS__, 'redirect_public_frontend'], 0);
        add_action('admin_init', [__CLASS__, 'maybe_handle_web_oauth_callback'], 0);
        add_filter('wp_redirect', [__CLASS__, 'rewrite_public_redirect'], 99, 2);
        add_filter('rest_url', [__CLASS__, 'rewrite_auth_rest_url'], 99, 4);
        add_action('wp_head', [__CLASS__, 'canonical_link'], 4);
    }

    public static function url(array $query = []): string {
        return $query ? add_query_arg($query, self::URL) : self::URL;
    }

    public static function web_oauth_callback_url(): string {
        return rtrim(self::URL, '/') . '/wp-admin/admin-post.php';
    }

    /**
     * If the public Typer is reached through another host (including www),
     * send visitors to the canonical TypujKosza.pl domain.
     */
    public static function redirect_public_frontend(): void {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) return;

        $requestPath = isset($_SERVER['REQUEST_URI'])
            ? (string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH)
            : '/';
        $requestPath = '/' . ltrim($requestPath ?: '/', '/');
        $isPublicEntry = is_front_page() || untrailingslashit($requestPath) === '/typer';
        if (!$isPublicEntry) return;

        $currentHost = strtolower(preg_replace('/:\d+$/', '', sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? ''))));
        if ($currentHost === self::HOST) return;

        $query = [];
        if (!empty($_SERVER['QUERY_STRING'])) {
            parse_str(wp_unslash($_SERVER['QUERY_STRING']), $query);
        }
        wp_redirect(self::url(is_array($query) ? $query : []), 301, 'TypujKosza.pl canonical');
        exit;
    }

    /**
     * Old modules still redirect to /typer or to the current WordPress home.
     * Normalize those destinations to the production homepage while keeping
     * query parameters such as dt_login and dt_login_error.
     */
    public static function rewrite_public_redirect(string $location, int $status): string {
        $targetHost = strtolower((string) wp_parse_url($location, PHP_URL_HOST));
        $homeHost = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $allowedHosts = array_filter([$homeHost, self::HOST, 'www.' . self::HOST]);

        if ($targetHost !== '' && !in_array($targetHost, $allowedHosts, true)) return $location;

        $path = (string) wp_parse_url($location, PHP_URL_PATH);
        $normalizedPath = untrailingslashit('/' . ltrim($path ?: '/', '/'));
        if (!in_array($normalizedPath, ['', '/', '/typer'], true)) return $location;

        $queryString = (string) wp_parse_url($location, PHP_URL_QUERY);
        $query = [];
        if ($queryString !== '') parse_str($queryString, $query);
        return self::url(is_array($query) ? $query : []);
    }

    /**
     * Google and Facebook WWW OAuth use one clean callback without query
     * parameters. The provider is recovered from the short-lived state
     * transient created at the beginning of the login flow.
     *
     * Keeping the redirect URI free of action/provider query parameters makes
     * the value copied to Google Cloud unambiguous and avoids mismatch caused
     * by a different query string representation.
     */
    public static function rewrite_auth_rest_url(string $url, string $path, ?int $blogId, string $scheme): string {
        $cleanPath = ltrim($path, '/');

        if (preg_match('#^decka-typer/v1/oauth/(google|facebook)/callback$#', $cleanPath)) {
            return self::web_oauth_callback_url();
        }

        $isMobileAuthEndpoint = (bool) preg_match(
            '#^decka-typer/v1/(?:mobile/auth/(?:google|facebook)/callback|mobile/web-login)$#',
            $cleanPath
        );
        if (!$isMobileAuthEndpoint) return $url;

        return rtrim(self::URL, '/') . '/wp-json/' . $cleanPath;
    }

    /**
     * admin-post.php normally dispatches by the `action` parameter. The web
     * OAuth callback intentionally has no query parameters registered in
     * Google/Facebook, so intercept the returned state/code during admin_init,
     * recover the provider and hand the request to the existing OAuth service.
     */
    public static function maybe_handle_web_oauth_callback(): void {
        if (!class_exists('DT_OAuth')) return;

        $path = isset($_SERVER['REQUEST_URI'])
            ? (string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH)
            : '';
        if (untrailingslashit($path) !== '/wp-admin/admin-post.php') return;
        if (!empty($_REQUEST['action'])) return;

        $state = isset($_REQUEST['state']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['state'])) : '';
        $code = isset($_REQUEST['code']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['code'])) : '';
        $error = isset($_REQUEST['error']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['error'])) : '';
        if ($state === '' || ($code === '' && $error === '')) return;

        $saved = get_transient('dt_oauth_' . hash('sha256', $state));
        $provider = is_array($saved) ? sanitize_key((string) ($saved['provider'] ?? '')) : '';
        if (!in_array($provider, ['google', 'facebook'], true)) return;

        $_REQUEST['provider'] = $provider;
        DT_OAuth::callback();
        exit;
    }

    public static function canonical_link(): void {
        if (!class_exists('DT_Frontend') || !DT_Frontend::is_typer_page()) return;
        echo '<link rel="canonical" href="' . esc_url(self::URL) . '">' . "\n";
    }
}
