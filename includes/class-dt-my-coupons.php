<?php
if (!defined('ABSPATH')) exit;

/**
 * Enhanced "Moje typy" presentation: one collapsible coupon per round.
 */
class DT_My_Coupons {
    public static function register(): void {
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets'], 30);
    }

    public static function assets(): void {
        if (!is_user_logged_in() || !class_exists('DT_Frontend') || !DT_Frontend::is_typer_page()) return;

        wp_enqueue_style(
            'dt-my-coupons',
            DT_URL . 'assets/css/my-coupons.css',
            ['dt-front'],
            DT_VERSION
        );
        wp_enqueue_script(
            'dt-my-coupons',
            DT_URL . 'assets/js/my-coupons.js',
            ['dt-front'],
            DT_VERSION,
            true
        );
    }
}
