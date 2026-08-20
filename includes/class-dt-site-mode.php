<?php
if (!defined('ABSPATH')) exit;

/** Public availability modes independent from wp-admin availability. */
class DT_Site_Mode {
    public static function register(): void {
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets'], 230);
        add_filter('wp_authenticate_user', [__CLASS__, 'guard_wordpress_login'], 99, 2);
        add_action('template_redirect', [__CLASS__, 'guard_oauth_return'], -20);
    }

    public static function assets(): void {
        if (!class_exists('DT_Frontend') || !DT_Frontend::is_typer_page()) return;
        wp_enqueue_script('dt-site-mode', DT_URL . 'assets/js/site-mode.js', [], DT_VERSION, true);
        wp_localize_script('dt-site-mode', 'TypujKoszaSiteMode', [
            'mode'=>class_exists('DT_Multileague') ? DT_Multileague::site_mode() : 'production',
        ]);
    }

    public static function guard_wordpress_login($user, string $password) {
        if (!($user instanceof WP_User)) return $user;
        if (!class_exists('DT_Multileague') || DT_Multileague::site_mode() !== 'break') return $user;
        if (user_can($user, 'manage_options')) return $user;
        return new WP_Error(
            'typukosza_break',
            'TypujKosza.pl ma obecnie przerwę między sezonami. Logowanie użytkowników zostanie ponownie uruchomione przed startem rozgrywek.'
        );
    }

    public static function guard_oauth_return(): void {
        if (!class_exists('DT_Multileague') || DT_Multileague::site_mode() !== 'break') return;
        if (current_user_can('manage_options')) return;
        $state = isset($_GET['state']) ? sanitize_text_field(wp_unslash((string)$_GET['state'])) : '';
        $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash((string)$_GET['code'])) : '';
        $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash((string)$_GET['error'])) : '';
        if ($state === '' || ($code === '' && $error === '')) return;
        wp_safe_redirect(class_exists('DT_Canonical') ? DT_Canonical::URL : home_url('/'));
        exit;
    }
}
