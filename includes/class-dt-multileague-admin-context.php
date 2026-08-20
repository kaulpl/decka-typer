<?php
if (!defined('ABSPATH')) exit;

class DT_Multileague_Admin_Context {
    public static function register(): void {
        add_action('admin_enqueue_scripts', [__CLASS__, 'localize'], 225);
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
            if ($league === 'PLK') $league = 'PLK';
            elseif ($league === '1LM') $league = '1LM';
            elseif ($league === '2LM') $league = '2LM';
            $group = trim((string)($row['group_code'] ?? ''));
            $label = $league . ($group !== '' ? ' · gr. ' . $group : '') . ' · ' . (string)$row['season'] . ' · ' . (string)$row['title'];
            $map[(string)(int)$row['id']] = $label;
        }
        wp_localize_script('dt-multileague-admin', 'TypujKoszaRoundContext', ['rounds'=>$map]);
    }
}
