<?php
if (!defined('ABSPATH')) exit;

class DT_Round_Access {
    public static function register(): void {
        add_filter('rest_request_after_callbacks', [__CLASS__, 'after_callbacks'], 5, 3);
    }

    public static function after_callbacks($response, array $handler, WP_REST_Request $request) {
        $route = $request->get_route();
        if ($route === '/decka-typer/v1/bootstrap' && $response instanceof WP_REST_Response) {
            return self::extend_bootstrap($response);
        }

        if (preg_match('~^/decka-typer/v1/round/(\d+)$~', $route, $m) && is_wp_error($response)) {
            return self::closed_round_response((int) $m[1], $response);
        }
        return $response;
    }

    private static function extend_bootstrap(WP_REST_Response $response): WP_REST_Response {
        global $wpdb;
        $data = $response->get_data();
        if (!is_array($data)) return $response;
        $season = (string) ($data['season'] ?? DT_DB::settings()['season']);

        // Frontend selectors must contain only rounds that an administrator has actually opened
        // at least once: current open rounds and rounds already closed. Drafts stay hidden even
        // when a scheduled match date has already passed.
        $rounds = array_values(array_filter(
            is_array($data['rounds'] ?? null) ? $data['rounds'] : [],
            static fn(array $round): bool => in_array((string) ($round['status'] ?? ''), ['open','closed'], true)
        ));
        $known = [];
        foreach ($rounds as $round) $known[(int) ($round['id'] ?? 0)] = true;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*,COUNT(m.id) match_count,
                    SUM(CASE WHEN m.status='finished' THEN 1 ELSE 0 END) finished_count,
                    MIN(m.starts_at) first_match,MAX(m.starts_at) last_match
             FROM " . DT_DB::table('rounds') . " r
             LEFT JOIN " . DT_DB::table('matches') . " m ON m.round_id=r.id
             WHERE r.season=%s AND r.status='closed'
             GROUP BY r.id ORDER BY r.round_no ASC",
            $season
        ), ARRAY_A);
        if (!is_array($rows)) $rows = [];

        $submitted = [];
        if (is_user_logged_in()) {
            $subs = $wpdb->get_col($wpdb->prepare(
                'SELECT round_id FROM ' . DT_DB::table('round_submissions') . ' WHERE user_id=%d',
                get_current_user_id()
            ));
            foreach ($subs as $roundId) $submitted[(int) $roundId] = true;
        }

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (isset($known[$id])) continue;
            $row['id'] = $id;
            $row['round_no'] = (int) $row['round_no'];
            $row['match_count'] = (int) $row['match_count'];
            $row['finished_count'] = (int) $row['finished_count'];
            $row['is_open'] = false;
            $row['display_status'] = 'closed';
            $row['submitted'] = isset($submitted[$id]);
            $row['closes_at_iso'] = self::iso_datetime($row['closes_at'] ?? null);
            $row['first_match_iso'] = self::iso_datetime($row['first_match'] ?? null);
            $row['last_match_iso'] = self::iso_datetime($row['last_match'] ?? null);
            $rounds[] = $row;
        }

        usort($rounds, static fn(array $a, array $b): int => ((int) ($a['round_no'] ?? 0)) <=> ((int) ($b['round_no'] ?? 0)));
        $data['rounds'] = $rounds;

        $allowed = array_fill_keys(array_map(static fn(array $r): int => (int) ($r['id'] ?? 0), $rounds), true);
        $currentId = (int) (($data['current_round']['id'] ?? 0));
        if (!$currentId || !isset($allowed[$currentId])) {
            $preferredId = 0;
            foreach ($rounds as $round) {
                if (($round['status'] ?? '') === 'open' && (string)($round['league_key'] ?? '') === '1lm') {
                    $preferredId = (int) $round['id'];
                    break;
                }
            }
            foreach ($rounds as $round) {
                if ($preferredId) break;
                if (($round['status'] ?? '') === 'open') {
                    $preferredId = (int) $round['id'];
                    break;
                }
            }
            if (!$preferredId && $rounds) {
                $last = end($rounds);
                $preferredId = (int) ($last['id'] ?? 0);
            }
            $data['current_round'] = $preferredId ? self::round_payload($preferredId) : null;
        }

        $response->set_data($data);
        return $response;
    }

    private static function closed_round_response(int $roundId, WP_Error $original) {
        global $wpdb;
        $status = $wpdb->get_var($wpdb->prepare(
            'SELECT status FROM ' . DT_DB::table('rounds') . ' WHERE id=%d',
            $roundId
        ));
        if ($status !== 'closed') return $original;

        $payload = self::round_payload($roundId);
        if (is_array($payload)) return new WP_REST_Response($payload);
        return $original;
    }

    private static function round_payload(int $roundId): ?array {
        try {
            $method = new ReflectionMethod('DT_REST', 'round_payload');
            if (method_exists($method, 'setAccessible')) $method->setAccessible(true);
            $payload = $method->invoke(null, $roundId, false);
            if (is_array($payload)) {
                $status = (string) ($payload['status'] ?? '');
                $payload['is_open'] = $status === 'open' && !empty($payload['is_open']);
                $payload['display_status'] = $payload['is_open'] ? 'open' : 'closed';
                if (!$payload['is_open']) $payload['can_submit'] = false;
                return $payload;
            }
        } catch (Throwable $e) {
            if (class_exists('DT_Logger')) {
                DT_Logger::log('round_preview_error', $e->getMessage(), ['round_id'=>$roundId], 'warning');
            }
        }
        return null;
    }

    private static function iso_datetime(?string $value): ?string {
        if (!$value) return null;
        $tz = wp_timezone();
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $tz);
        if (!$date) $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, $tz);
        return $date ? $date->format(DateTimeInterface::ATOM) : null;
    }
}
