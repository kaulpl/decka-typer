<?php
if (!defined('ABSPATH')) exit;

/** Informational scores from PZKosz's public "teraz gramy" list. Never writes results. */
class DT_Live_Scores {
    private const URL = 'https://rozgrywki.pzkosz.pl/livescore.html';
    private const CACHE_KEY = 'dt_live_scores_pzkosz';

    public static function route(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $roundId = absint($request->get_param('round_id'));
        $round = $wpdb->get_row($wpdb->prepare(
            'SELECT id,league_key FROM ' . DT_DB::table('rounds') . ' WHERE id=%d', $roundId
        ), ARRAY_A);
        if (!$round) return new WP_Error('not_found', 'Nie znaleziono kolejki.', ['status'=>404]);
        if (!in_array($round['league_key'], ['1lm', '2lm'], true)) return new WP_REST_Response(['matches'=>[]]);

        $matches = $wpdb->get_results($wpdb->prepare(
            'SELECT m.id,m.starts_at,m.score_home,m.score_away,h.name home_name,a.name away_name
             FROM ' . DT_DB::table('matches') . ' m
             JOIN ' . DT_DB::table('teams') . ' h ON h.id=m.home_team_id
             JOIN ' . DT_DB::table('teams') . ' a ON a.id=m.away_team_id
             WHERE m.round_id=%d AND m.starts_at BETWEEN DATE_SUB(%s, INTERVAL 8 HOUR) AND DATE_ADD(%s, INTERVAL 18 HOUR)',
            $roundId, current_time('mysql'), current_time('mysql')
        ), ARRAY_A);
        if (!$matches) return new WP_REST_Response(['matches'=>[]]);

        $live = self::feed();
        $result = [];
        foreach ($matches as $match) {
            foreach ($live as $item) {
                if ($item['league'] !== strtoupper((string)$round['league_key'])) continue;
                if ($item['day'] !== substr((string)$match['starts_at'], 0, 10)) continue;
                if (self::name_key($item['home']) !== self::name_key((string)$match['home_name'])) continue;
                if (self::name_key($item['away']) !== self::name_key((string)$match['away_name'])) continue;
                $result[(int)$match['id']] = [
                    'home'=>$item['home_score'], 'away'=>$item['away_score'],
                    'quarter'=>$item['quarter'], 'clock'=>$item['clock'],
                ];
                break;
            }
        }
        return new WP_REST_Response(['matches'=>$result]);
    }

    private static function feed(): array {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached)) return $cached;
        // A short lock protects PZKosz when many visitors request the same round at once.
        if (get_transient(self::CACHE_KEY . '_lock')) return [];
        set_transient(self::CACHE_KEY . '_lock', 1, 12);
        $response = wp_remote_get(self::URL, ['timeout'=>5, 'redirection'=>2]);
        if (is_wp_error($response) || (int)wp_remote_retrieve_response_code($response) !== 200) {
            set_transient(self::CACHE_KEY, [], 15);
            delete_transient(self::CACHE_KEY . '_lock');
            return [];
        }
        $items = self::parse((string)wp_remote_retrieve_body($response));
        set_transient(self::CACHE_KEY, $items, 15);
        delete_transient(self::CACHE_KEY . '_lock');
        return $items;
    }

    public static function parse(string $html): array {
        if (!class_exists('DOMDocument') || strlen($html) > 2000000) return [];
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        if (!$loaded) return [];
        $xp = new DOMXPath($dom);
        $result = [];
        // Only the first table immediately following the "teraz gramy" heading.
        // The mobile copy and "zakończone dzisiaj" tables must never enter this list.
        foreach ($xp->query('(//h2[normalize-space()="teraz gramy"])[1]/parent::div/following-sibling::table[1]//tr[contains(concat(" ",normalize-space(@class)," ")," match ")]') as $row) {
            $value = static function(string $class) use ($xp, $row): string {
                $node = $xp->query('.//td[contains(concat(" ",normalize-space(@class)," ")," ' . $class . ' ")]', $row)->item(0);
                return $node ? trim(preg_replace('/\s+/u', ' ', (string)$node->textContent)) : '';
            };
            $league = $value('info');
            if (!preg_match('/^(1LM|2LM)(?:\b|\s|$)/u', $league, $leagueMatch)) continue;
            if (!preg_match('/(\d{2})\.(\d{2})\.(\d{4})/', $value('matchdate'), $date)) continue;
            if (!preg_match('/^\s*(\d{1,3})\s*:\s*(\d{1,3})\s*$/', $value('result'), $score)) continue;
            if (!preg_match('/\bK([1-4]|[5-9])\b/u', $value('matchtime'), $quarter)) continue;
            $timer = $value('matchtime');
            $clock = preg_match('/\b(\d{1,2}:\d{2})(?::\d{2})?\b/', $timer, $time) ? $time[1] : null;
            $home = $value('team1'); $away = $value('team2');
            if ($home === '' || $away === '') continue;
            $result[] = [
                'league'=>$leagueMatch[1], 'day'=>$date[3] . '-' . $date[2] . '-' . $date[1],
                'home'=>$home, 'away'=>$away, 'home_score'=>(int)$score[1],
                'away_score'=>(int)$score[2], 'quarter'=>(int)$quarter[1], 'clock'=>$clock,
            ];
        }
        return $result;
    }

    private static function name_key(string $name): string {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower(remove_accents($name))));
    }
}
