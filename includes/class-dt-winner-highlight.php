<?php
if (!defined('ABSPATH')) exit;

/**
 * Visual result-state enhancement for resolved matches.
 * Keeps the outer match frame tied to the user's prediction result while
 * independently highlighting the actual winner/loser team tiles.
 */
class DT_Winner_Highlight {
    public static function register(): void {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue'], 40);
    }

    public static function enqueue(): void {
        if (!class_exists('DT_Frontend') || !DT_Frontend::is_typer_page() || !is_user_logged_in()) return;

        wp_enqueue_style(
            'dt-winner-highlight',
            DT_URL . 'assets/css/winner-highlight.css',
            ['dt-league-ui'],
            DT_VERSION
        );
        wp_enqueue_script(
            'dt-winner-highlight',
            DT_URL . 'assets/js/winner-highlight.js',
            ['dt-league-ui'],
            DT_VERSION,
            true
        );
    }
}
