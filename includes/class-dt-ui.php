<?php
if (!defined('ABSPATH')) exit;

class DT_UI {
    public static function register(): void {
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_contrast'], 99);
        add_action('wp_enqueue_scripts', [__CLASS__, 'frontend_cleanup'], 99);
        add_filter('do_shortcode_tag', [__CLASS__, 'remove_wordpress_login_option'], 20, 4);
    }

    public static function admin_contrast(string $hook): void {
        if (strpos($hook, 'decka-typer') === false) return;
        $css = '
            .dt-admin .dt-button,
            .dt-admin .button-primary,
            .dt-admin a.dt-button,
            .dt-admin button.dt-button,
            .dt-update-panel .dt-button,
            .dt-update-panel .button-primary {
                color:#fff !important;
                background:#1756a9 !important;
                border-color:#1756a9 !important;
                text-shadow:none !important;
            }
            .dt-admin .dt-button:hover,.dt-admin .dt-button:focus,
            .dt-admin .button-primary:hover,.dt-admin .button-primary:focus,
            .dt-update-panel .dt-button:hover,.dt-update-panel .dt-button:focus {
                color:#fff !important;
                background:#0f478d !important;
                border-color:#0f478d !important;
            }
            .dt-admin .dt-button .dashicons,.dt-admin .dt-button span,.dt-admin .dt-button strong,
            .dt-admin .button-primary .dashicons,.dt-admin .button-primary span,.dt-admin .button-primary strong,
            .dt-update-panel .dt-button .dashicons,.dt-update-panel .dt-button span,.dt-update-panel .dt-button strong {
                color:#fff !important;
            }
        ';
        wp_add_inline_style('dt-admin', $css);
    }

    public static function frontend_cleanup(): void {
        if (!class_exists('DT_Frontend') || !DT_Frontend::is_typer_page()) return;
        wp_add_inline_style('dt-front', '.dt-login-divider,.dt-wp-login{display:none!important}');
    }

    public static function remove_wordpress_login_option(string $output, string $tag, array $attr, array $m): string {
        if ($tag !== 'decka_typer' || is_user_logged_in()) return $output;
        $output = preg_replace('~<div class="dt-login-divider">.*?</div>\s*<a class="dt-social-button dt-wp-login"[^>]*>.*?</a>~s', '', $output);
        return is_string($output) ? $output : '';
    }
}
