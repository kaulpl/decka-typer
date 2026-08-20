<?php
if (!defined('ABSPATH')) exit;

class DT_Bonus {
    private const MATCHES_OPTION = 'dt_bonus_matches';
    private const POINTS_OPTION = 'dt_bonus_points';

    public static function register(): void {
        add_action('admin_post_dt_toggle_bonus', [__CLASS__, 'toggle']);
        add_action('admin_init', [__CLASS__, 'capture_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'front_assets'], 35);
        add_filter('rest_request_after_callbacks', [__CLASS__, 'rest_after_callbacks'], 20, 3);
    }

    public static function points(): float {
        return max(0, (float) get_option(self::POINTS_OPTION, 1));
    }

    public static function map(): array {
        $raw = (array) get_option(self::MATCHES_OPTION, []);
        $out = [];
        foreach ($raw as $roundId => $matchId) {
            $roundId = (int) $roundId;
            $matchId = (int) $matchId;
            if ($roundId > 0 && $matchId > 0) $out[$roundId] = $matchId;
        }
        return $out;
    }

    public static function match_ids(): array { return array_values(self::map()); }

    public static function is_bonus(int $matchId, int $roundId = 0): bool {
        if ($matchId <= 0) return false;
        $map = self::map();
        if ($roundId > 0) return isset($map[$roundId]) && (int) $map[$roundId] === $matchId;
        return in_array($matchId, array_values($map), true);
    }

    public static function capture_settings(): void {
        if (!is_admin() || !current_user_can('manage_options')) return;
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
        if (sanitize_key($_POST['action'] ?? '') !== 'dt_save_settings') return;
        $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? ''));
        if (!$nonce || !wp_verify_nonce($nonce, 'dt_save_settings')) return;
        $old = self::points();
        $new = max(0, (float) ($_POST['bonus_points'] ?? $old));
        update_option(self::POINTS_OPTION, $new, false);
        if ((float) $old !== (float) $new) self::recalc_all_bonus_matches();
    }

    public static function admin_assets(string $hook): void {
        if (strpos($hook, 'decka-typer') === false) return;
        wp_enqueue_style('dt-bonus', DT_URL . 'assets/css/bonus.css', ['dt-admin'], DT_VERSION);
        wp_enqueue_script('dt-bonus-admin', DT_URL . 'assets/js/bonus-admin.js', ['dt-admin'], DT_VERSION, true);
        wp_localize_script('dt-bonus-admin', 'DeckaTyperBonusAdmin', [
            'points'=>self::points(),
            'matchIds'=>self::match_ids(),
            'actionUrl'=>admin_url('admin-post.php'),
            'nonce'=>wp_create_nonce('dt_toggle_bonus'),
        ]);
    }

    public static function front_assets(): void {
        if (!class_exists('DT_Frontend') || !DT_Frontend::is_typer_page() || !is_user_logged_in()) return;
        wp_enqueue_style('dt-bonus-front', DT_URL . 'assets/css/bonus.css', ['dt-front'], DT_VERSION);
        wp_enqueue_script('dt-bonus-front', DT_URL . 'assets/js/bonus-ui.js', ['dt-front'], DT_VERSION, true);
        wp_localize_script('dt-bonus-front', 'DeckaTyperBonus', [
            'points'=>self::points(),
            'matchIds'=>self::match_ids(),
        ]);
    }

    public static function toggle(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        check_admin_referer('dt_toggle_bonus');
        global $wpdb;

        $matchId = max(0, (int) ($_POST['match_id'] ?? 0));
        $match = $wpdb->get_row($wpdb->prepare(
            'SELECT id,round_id FROM ' . DT_DB::table('matches') . ' WHERE id=%d',
            $matchId
        ));
        if (!$match) wp_die('Nie znaleziono meczu.');

        $roundId = (int) $match->round_id;
        $map = self::map();
        $oldMatchId = isset($map[$roundId]) ? (int) $map[$roundId] : 0;
        $enabled = $oldMatchId !== $matchId;
        if ($enabled) $map[$roundId] = $matchId;
        else unset($map[$roundId]);
        update_option(self::MATCHES_OPTION, $map, false);

        if ($oldMatchId > 0) DT_Scoring::recalc_match($oldMatchId);
        if ($enabled && $matchId !== $oldMatchId) DT_Scoring::recalc_match($matchId);

        DT_Logger::log(
            $enabled ? 'bonus_match_set' : 'bonus_match_removed',
            $enabled ? 'Administrator ustawił mecz BONUS.' : 'Administrator usunął oznaczenie BONUS.',
            ['round_id'=>$roundId,'match_id'=>$matchId,'bonus_points'=>self::points()],
            'notice',
            get_current_user_id()
        );

        wp_safe_redirect(add_query_arg([
            'page'=>'decka-typer-matches',
            'round_id'=>$roundId,
            'dt_notice'=>$enabled ? 'Mecz ustawiony jako BONUS.' : 'Oznaczenie BONUS usunięte.',
            'dt_type'=>'success',
        ], admin_url('admin.php')));
        exit;
    }

    public static function rest_after_callbacks($response, array $handler, WP_REST_Request $request) {
        if (is_wp_error($response) || !($response instanceof WP_REST_Response)) return $response;
        $route = $request->get_route();
        if (!str_starts_with($route, '/decka-typer/v1/')) return $response;
        $data = $response->get_data();
        if (!is_array($data)) return $response;

        $bonusIds = array_fill_keys(self::match_ids(), true);
        $bonusPoints = self::points();
        $decorateRound = static function (&$round) use ($bonusIds, $bonusPoints): void {
            if (!is_array($round) || empty($round['matches']) || !is_array($round['matches'])) return;
            foreach ($round['matches'] as &$match) {
                if (!is_array($match)) continue;
                $isBonus = isset($bonusIds[(int) ($match['id'] ?? 0)]);
                $match['is_bonus'] = $isBonus;
                $match['bonus_points'] = $isBonus ? $bonusPoints : 0;
            }
            unset($match);
        };

        if ($route === '/decka-typer/v1/bootstrap') {
            if (isset($data['current_round'])) $decorateRound($data['current_round']);
            if (!isset($data['scoring']) || !is_array($data['scoring'])) $data['scoring'] = [];
            $data['scoring']['bonus'] = $bonusPoints;
        } elseif (preg_match('~^/decka-typer/v1/round/\d+$~', $route)) {
            $decorateRound($data);
        }
        $response->set_data($data);
        return $response;
    }

    private static function recalc_all_bonus_matches(): void {
        foreach (self::match_ids() as $matchId) DT_Scoring::recalc_match((int) $matchId);
    }
}
