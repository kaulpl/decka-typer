<?php
if (!defined('ABSPATH')) exit;

class DT_Multileague_Admin_Context {
    public static function register(): void {
        add_action('admin_enqueue_scripts', [__CLASS__, 'localize'], 225);
        add_action('admin_post_dt_ml_save_leagues', [__CLASS__, 'sync_1lm_source_from_leagues'], 1);
        add_action('admin_post_dt_save_settings', [__CLASS__, 'sync_1lm_source_from_settings'], 1);
    }

    public static function localize(string $hook): void {
        if (strpos($hook, 'decka-typer') === false || !wp_script_is('dt-multileague-admin', 'enqueued')) return;
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT id,league_code,group_code,season,round_no,title,status FROM " . DT_DB::table('rounds') . " ORDER BY season DESC,FIELD(league_code,'plk','1lm','2lm'),group_code,round_no,id",
            ARRAY_A
        );
        $map = [];
        foreach ((array)$rows as $row) {
            $league = strtoupper((string)($row['league_code'] ?? '1lm'));
            $group = trim((string)($row['group_code'] ?? ''));
            $label = $league . ($group !== '' ? ' · gr. ' . $group : '') . ' · ' . (string)$row['season'] . ' · ' . (string)$row['title'];
            $map[(string)(int)$row['id']] = $label;
        }
        wp_localize_script('dt-multileague-admin', 'TypujKoszaRoundContext', ['rounds'=>$map]);
    }

    public static function sync_1lm_source_from_leagues(): void {
        if (!current_user_can('manage_options')) return;
        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash((string)$_POST['_wpnonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'dt_ml_save_leagues')) return;
        $sources = isset($_POST['source_url']) && is_array($_POST['source_url']) ? wp_unslash($_POST['source_url']) : [];
        $url = esc_url_raw((string)($sources['1lm'] ?? ''));
        if ($url === '') return;
        $settings = (array)get_option('dt_settings', []);
        $settings['source_url'] = $url;
        update_option('dt_settings', $settings);
    }

    public static function sync_1lm_source_from_settings(): void {
        if (!current_user_can('manage_options')) return;
        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash((string)$_POST['_wpnonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'dt_save_settings')) return;
        $url = esc_url_raw((string)wp_unslash($_POST['source_url'] ?? ''));
        if ($url === '') return;
        $leagues = (array)get_option(DT_Multileague::LEAGUES_OPTION, []);
        if (!isset($leagues['1lm']) || !is_array($leagues['1lm'])) $leagues['1lm'] = [];
        $leagues['1lm']['source_url'] = $url;
        update_option(DT_Multileague::LEAGUES_OPTION, $leagues, false);
    }
}
