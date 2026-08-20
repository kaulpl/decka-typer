<?php
if (!defined('ABSPATH')) exit;

class DT_Multileague_REST_Defaults {
    public static function register(): void {
        add_filter('rest_request_before_callbacks', [__CLASS__, 'defaults'], 5, 3);
    }

    public static function defaults($response, array $handler, WP_REST_Request $request) {
        if ($response !== null) return $response;
        if ($request->get_route() !== '/decka-typer/v1/multileague/ranking') return $response;
        if (sanitize_key((string)$request->get_param('scope')) !== 'round') return $response;
        if ((int)$request->get_param('round_id') > 0) return $response;

        $league = sanitize_key((string)$request->get_param('league'));
        if (!in_array($league, ['plk','1lm','2lm'], true)) $league = '1lm';
        $season = sanitize_text_field((string)$request->get_param('season'));
        if ($season === '') $season = (string)(DT_DB::settings()['season'] ?? '2026/2027');
        $group = strtoupper(sanitize_text_field((string)$request->get_param('group')));
        if ($league !== '2lm' || !in_array($group, ['A','B','C','D'], true)) $group = '';

        global $wpdb;
        $sql = "SELECT id FROM " . DT_DB::table('rounds') . " WHERE league_code=%s AND season=%s AND status IN ('open','closed')";
        $args = [$league, $season];
        if ($group !== '') { $sql .= ' AND group_code=%s'; $args[] = $group; }
        $sql .= " ORDER BY CASE WHEN status='open' THEN 0 ELSE 1 END, round_no DESC, id DESC LIMIT 1";
        $id = (int)$wpdb->get_var($wpdb->prepare($sql, ...$args));
        if ($id > 0) $request->set_param('round_id', $id);
        return $response;
    }
}
