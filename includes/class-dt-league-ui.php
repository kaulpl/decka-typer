<?php
if (!defined('ABSPATH')) exit;

/**
 * League context for the Typer UI: current 1LM table position and historical
 * last-five form calculated separately for the round being viewed.
 *
 * Performance rule: frontend requests never wait for 1lm.pzkosz.pl. Standings
 * are refreshed after the normal schedule sync (or via a background cron), while
 * round context is cached and invalidated automatically when synchronized data changes.
 */
class DT_League_UI {
    private const STANDINGS_URL = 'https://1lm.pzkosz.pl/tabele.html';
    private const PLK_STANDINGS_URL = 'https://plk.pl/tabele';
    private const CACHE_OPTION = 'dt_1lm_standings_cache';
    private const CACHE_TTL = 1800;
    private const CONTEXT_CACHE_TTL = 43200;

    public static function register(): void {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue'], 30);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('updated_option', [__CLASS__, 'after_option_update'], 10, 3);
        add_action('added_option', [__CLASS__, 'after_option_add'], 10, 2);
        add_action('dt_refresh_standings', [__CLASS__, 'background_standings_refresh']);
    }

    public static function routes(): void {
        register_rest_route('decka-typer/v1', '/league-context', [
            'methods'=>'GET',
            'callback'=>[__CLASS__, 'context'],
            'permission_callback'=>'__return_true',
            'args'=>[
                'round_id'=>[
                    'required'=>true,
                    'sanitize_callback'=>'absint',
                    'validate_callback'=>static fn($value)=>(int)$value > 0,
                ],
            ],
        ]);
    }

    public static function after_option_update(string $option, $oldValue, $value): void {
        if ($option === 'dt_last_sync') self::sync_standings(false);
    }

    public static function after_option_add(string $option, $value): void {
        if ($option === 'dt_last_sync') self::sync_standings(false);
    }

    public static function background_standings_refresh(): void {
        self::sync_standings(false);
    }

    public static function enqueue(): void {
        if (!class_exists('DT_Frontend') || !DT_Frontend::is_typer_page() || !is_user_logged_in()) return;

        // Never perform an external HTTP request while rendering /typer.
        // If standings are missing/stale, ask WP-Cron to refresh them after the response.
        self::schedule_standings_refresh_if_needed();

        wp_enqueue_style(
            'dt-league-ui',
            DT_URL . 'assets/css/league-ui.css',
            ['dt-front'],
            DT_VERSION
        );
        wp_enqueue_script(
            'dt-league-ui',
            DT_URL . 'assets/js/league-ui.js',
            ['dt-front'],
            DT_VERSION,
            true
        );
        wp_localize_script('dt-league-ui', 'DeckaTyperLeagueData', self::frontend_data());
    }

    private static function schedule_standings_refresh_if_needed(): void {
        $cache = (array)get_option(self::CACHE_OPTION, []);
        $fetchedAt = (int)($cache['fetched_at'] ?? 0);
        $season = (string)(DT_DB::settings()['season'] ?? '');
        $fresh = $fetchedAt
            && (time() - $fetchedAt) < self::CACHE_TTL
            && (string)($cache['season'] ?? '') === $season;
        if ($fresh) return;
        if (!wp_next_scheduled('dt_refresh_standings')) {
            wp_schedule_single_event(time() + 5, 'dt_refresh_standings');
        }
    }

    public static function sync_standings(bool $force = false): array {
        $lockKey = 'dt_league_standings_refresh_lock';
        if (!$force && get_transient($lockKey)) return ['ok'=>false, 'locked'=>true];
        set_transient($lockKey, 1, 60);

        try {
            $positions = [];
            $sources = ['1lm'=>self::STANDINGS_URL, 'plk'=>self::PLK_STANDINGS_URL];
            foreach ($sources as $leagueKey=>$url) {
                $response = wp_remote_get($url, [
                    'timeout'=>15,
                    'redirection'=>4,
                    'headers'=>['User-Agent'=>'DeckaTyper/' . DT_VERSION . ' (+' . home_url('/') . ')'],
                ]);
                if (is_wp_error($response)) return ['ok'=>false, 'error'=>strtoupper($leagueKey) . ': ' . $response->get_error_message()];
                $code = (int)wp_remote_retrieve_response_code($response);
                $html = (string)wp_remote_retrieve_body($response);
                if ($code !== 200 || strlen($html) < 300) return ['ok'=>false, 'error'=>strtoupper($leagueKey) . ' zwróciła HTTP ' . $code . '.'];
                $entries = $leagueKey === 'plk' ? self::parse_plk_standings($html) : self::parse_standings($html);
                if (count($entries) < 8) return ['ok'=>false, 'error'=>'Nie udało się wiarygodnie odczytać tabeli ' . strtoupper($leagueKey) . '.'];
                $positions[$leagueKey] = $entries;
            }

            $settings = DT_DB::settings();
            $payload = [
                'fetched_at'=>time(),
                'fetched_at_mysql'=>current_time('mysql'),
                'season'=>(string)($settings['season'] ?? ''),
                'sources'=>$sources,
                'positions_by_league'=>$positions,
                'positions'=>$positions['1lm'],
            ];
            update_option(self::CACHE_OPTION, $payload, false);
            self::log('standings_synced', 'Zaktualizowano miejsca drużyn w tabelach 1LM i PLK.', ['teams_1lm'=>count($positions['1lm']), 'teams_plk'=>count($positions['plk'])], 'info');
            return ['ok'=>true, 'teams'=>count($positions['1lm']) + count($positions['plk'])];
        } finally {
            delete_transient($lockKey);
        }
    }

    public static function parse_plk_standings(string $html): array {
        $decoded = str_replace(['\\"','\\/'], ['"','/'], $html);
        $out = [];
        if (preg_match_all('/"position":(\d{1,2}),"teamId":\d+,"teamName":"([^"]+)"/u', $decoded, $rows, PREG_SET_ORDER)) {
            foreach ($rows as $row) {
                $position = (int)$row[1];
                $name = trim(html_entity_decode($row[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($position < 1 || $position > 40 || $name === '') continue;
                $out[self::club_key($name)] = ['position'=>$position, 'name'=>$name];
            }
        }
        return $out;
    }

    private static function parse_standings(string $html): array {
        $out = [];
        if (class_exists('DOMDocument')) {
            $previous = libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if ($loaded) {
                $xp = new DOMXPath($dom);
                foreach ($xp->query('//tr') as $row) {
                    $cells = $xp->query('./td', $row);
                    if (!$cells || $cells->length < 3) continue;
                    $positionText = trim((string)$cells->item(0)->textContent);
                    if (!preg_match('/^\s*(\d{1,2})\s*$/', $positionText, $m)) continue;
                    $position = (int)$m[1];
                    if ($position < 1 || $position > 40) continue;

                    $teamName = '';
                    $teamLinks = $xp->query(".//a[contains(@href,'druzyny')]", $row);
                    if ($teamLinks && $teamLinks->length) {
                        foreach ($teamLinks as $link) {
                            $candidate = trim(preg_replace('/\s+/u', ' ', (string)$link->textContent));
                            if ($candidate !== '') { $teamName = $candidate; break; }
                        }
                    }
                    if ($teamName === '') {
                        $teamName = trim(preg_replace('/\s+/u', ' ', (string)$cells->item(2)->textContent));
                    }
                    if ($teamName === '') continue;
                    $out[self::club_key($teamName)] = ['position'=>$position, 'name'=>$teamName];
                }
            }
        }

        if ($out) return $out;

        if (preg_match_all('~<tr\b[^>]*>(.*?)</tr>~isu', $html, $rows)) {
            foreach ($rows[1] as $row) {
                if (!preg_match_all('~<td\b[^>]*>(.*?)</td>~isu', $row, $cells) || count($cells[1]) < 3) continue;
                $positionText = trim(html_entity_decode(strip_tags($cells[1][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (!preg_match('/^(\d{1,2})$/', $positionText, $m)) continue;
                $position = (int)$m[1];
                $teamName = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($cells[1][2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                if ($position < 1 || $position > 40 || $teamName === '') continue;
                $out[self::club_key($teamName)] = ['position'=>$position, 'name'=>$teamName];
            }
        }
        return $out;
    }

    private static function frontend_data(): array {
        $cache = (array)get_option(self::CACHE_OPTION, []);
        return [
            'version'=>DT_VERSION,
            'teams'=>self::team_payload([], '1lm'),
            'contextUrl'=>esc_url_raw(rest_url('decka-typer/v1/league-context')),
            'standingsUpdatedAt'=>(string)($cache['fetched_at_mysql'] ?? ''),
            'standingsSource'=>self::STANDINGS_URL,
        ];
    }

    public static function context(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $roundId = absint($request->get_param('round_id'));
        if (!$roundId) return new WP_Error('invalid_round', 'Nie wybrano kolejki.', ['status'=>422]);
        $payload = self::context_payload($roundId);
        return is_wp_error($payload) ? $payload : new WP_REST_Response($payload);
    }

    /**
     * Fast user-independent context. No external network calls are allowed here.
     */
    public static function context_payload(int $roundId) {
        global $wpdb;
        if ($roundId < 1) return new WP_Error('invalid_round', 'Nie wybrano kolejki.', ['status'=>422]);

        $round = $wpdb->get_row($wpdb->prepare(
            "SELECT id,season,league_key,round_no,status,closes_at,updated_at FROM " . DT_DB::table('rounds') . " WHERE id=%d",
            $roundId
        ), ARRAY_A);
        if (!$round) return new WP_Error('not_found', 'Nie znaleziono kolejki.', ['status'=>404]);

        $cutoff = self::round_cutoff($roundId, $round);
        $cacheKey = self::context_cache_key($roundId, $round, $cutoff);
        $cached = get_transient($cacheKey);
        if (is_array($cached) && !empty($cached['teams'])) {
            $cached['cache'] = 'hit';
            return $cached;
        }

        $forms = self::forms_before_cutoff((string)$round['season'], $roundId, $cutoff);
        $payload = [
            'round_id'=>$roundId,
            'round_no'=>(int)$round['round_no'],
            'cutoff'=>$cutoff,
            'cutoff_iso'=>self::iso_datetime($cutoff),
            'teams'=>self::team_payload($forms, (string)$round['league_key']),
            'cache'=>'miss',
        ];
        set_transient($cacheKey, $payload, self::CONTEXT_CACHE_TTL);
        return $payload;
    }

    private static function context_cache_key(int $roundId, array $round, string $cutoff): string {
        $lastSync = (array)get_option('dt_last_sync', []);
        $standings = (array)get_option(self::CACHE_OPTION, []);
        $signature = implode('|', [
            $roundId,
            (string)($round['season'] ?? ''),
            (string)($round['updated_at'] ?? ''),
            $cutoff,
            (string)($lastSync['at'] ?? ''),
            (string)($standings['fetched_at'] ?? ''),
        ]);
        return 'dt_lctx_' . substr(md5($signature), 0, 24);
    }

    private static function round_cutoff(int $roundId, array $round): string {
        global $wpdb;
        if (!empty($round['closes_at'])) return (string)$round['closes_at'];

        $firstMatch = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(starts_at) FROM " . DT_DB::table('matches') . " WHERE round_id=%d AND starts_at IS NOT NULL",
            $roundId
        ));
        if ($firstMatch) return (string)$firstMatch;
        return current_time('mysql');
    }

    private static function forms_before_cutoff(string $season, int $roundId, string $cutoff): array {
        global $wpdb;
        $teamsTable = DT_DB::table('teams');
        $matchesTable = DT_DB::table('matches');
        $roundsTable = DT_DB::table('rounds');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.id,m.round_id,m.home_team_id,m.away_team_id,m.score_home,m.score_away,m.starts_at,
                    h.name home_name,h.logo_url home_logo,a.name away_name,a.logo_url away_logo
             FROM $matchesTable m
             JOIN $roundsTable r ON r.id=m.round_id
             JOIN $teamsTable h ON h.id=m.home_team_id
             JOIN $teamsTable a ON a.id=m.away_team_id
             WHERE r.season=%s
               AND m.round_id<>%d
               AND m.score_home IS NOT NULL
               AND m.score_away IS NOT NULL
               AND m.starts_at IS NOT NULL
               AND m.starts_at<%s
             ORDER BY m.starts_at ASC,m.id ASC",
            $season,
            $roundId,
            $cutoff
        ), ARRAY_A);
        if (!is_array($rows)) $rows = [];

        $forms = [];
        foreach ($rows as $match) {
            $homeId = (int)$match['home_team_id'];
            $awayId = (int)$match['away_team_id'];
            $homeScore = (int)$match['score_home'];
            $awayScore = (int)$match['score_away'];
            $homeStatus = $homeScore === $awayScore ? 'neutral' : ($homeScore > $awayScore ? 'win' : 'loss');
            $awayStatus = $homeScore === $awayScore ? 'neutral' : ($awayScore > $homeScore ? 'win' : 'loss');

            $forms[$homeId][] = self::form_item($homeStatus, $awayId, (string)$match['away_name'], (string)$match['away_logo']);
            $forms[$awayId][] = self::form_item($awayStatus, $homeId, (string)$match['home_name'], (string)$match['home_logo']);
            if (count($forms[$homeId]) > 5) array_shift($forms[$homeId]);
            if (count($forms[$awayId]) > 5) array_shift($forms[$awayId]);
        }
        return $forms;
    }

    private static function team_payload(array $forms, string $leagueKey): array {
        global $wpdb;
        $cache = (array)get_option(self::CACHE_OPTION, []);
        $byLeague = is_array($cache['positions_by_league'] ?? null) ? $cache['positions_by_league'] : [];
        $positions = is_array($byLeague[$leagueKey] ?? null)
            ? $byLeague[$leagueKey]
            : ($leagueKey === '1lm' && is_array($cache['positions'] ?? null) ? $cache['positions'] : []);
        $teams = $wpdb->get_results("SELECT id,name,logo_url FROM " . DT_DB::table('teams'), ARRAY_A);
        if (!is_array($teams)) $teams = [];

        $payload = [];
        foreach ($teams as $team) {
            $id = (int)$team['id'];
            $items = array_values($forms[$id] ?? []);
            while (count($items) < 5) array_unshift($items, null);
            $key = self::club_key((string)$team['name']);
            $position = isset($positions[$key]['position']) ? (int)$positions[$key]['position'] : null;
            $payload[(string)$id] = [
                'position'=>$position,
                'form'=>array_slice($items, -5),
            ];
        }
        return $payload;
    }

    private static function form_item(string $status, int $opponentId, string $opponentName, string $storedLogo): array {
        $localLogo = class_exists('DT_Team_Logos') ? DT_Team_Logos::url_for($opponentName) : null;
        return [
            'status'=>$status,
            'opponent_id'=>$opponentId,
            'opponent_name'=>$opponentName,
            'opponent_logo'=>$localLogo ?: $storedLogo,
        ];
    }

    private static function iso_datetime(?string $value): ?string {
        if (!$value) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, wp_timezone());
        if (!$date) $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, wp_timezone());
        return $date ? $date->format(DateTimeInterface::ATOM) : null;
    }

    private static function club_key(string $name): string {
        $normalized = strtolower(remove_accents(wp_strip_all_tags($name)));
        $normalized = trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', $normalized)));
        $known = [
            'polonia 1912 leszno','polonia leszno','wkk','politechnika opolska','sokol lancut',
            'starogard gdanski','spojnia stargard','resovia rzeszow','miasto szkla krosno',
            'lks lodz','lks coolpack lodz','notec inowroclaw','polonia warszawa','gks tychy',
            'basket poznan','decka pelplin','kotwica','polonia bytom',
        ];
        foreach ($known as $needle) {
            if (str_contains($normalized, $needle)) {
                if ($needle === 'polonia leszno') return 'polonia 1912 leszno';
                if ($needle === 'lks coolpack lodz') return 'lks lodz';
                return $needle;
            }
        }
        return $normalized;
    }

    private static function log(string $event, string $message, array $context = [], string $level = 'info'): void {
        try {
            if (class_exists('DT_Logger')) DT_Logger::log($event, $message, $context, $level);
        } catch (Throwable $ignored) {}
    }
}
