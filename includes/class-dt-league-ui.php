<?php
if (!defined('ABSPATH')) exit;

/**
 * League context for the Typer UI: current 1LM table position and last-five form.
 * Standings are cached from the official 1LM table page; recent form is derived
 * from finished matches already synchronized into Decka Typer.
 */
class DT_League_UI {
    private const STANDINGS_URL = 'https://1lm.pzkosz.pl/tabele.html';
    private const CACHE_OPTION = 'dt_1lm_standings_cache';
    private const CACHE_TTL = 1800;

    public static function register(): void {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue'], 30);
        add_action('updated_option', [__CLASS__, 'after_option_update'], 10, 3);
        add_action('added_option', [__CLASS__, 'after_option_add'], 10, 2);
    }

    public static function after_option_update(string $option, $oldValue, $value): void {
        if ($option === 'dt_last_sync') self::sync_standings(false);
    }

    public static function after_option_add(string $option, $value): void {
        if ($option === 'dt_last_sync') self::sync_standings(false);
    }

    public static function enqueue(): void {
        if (!class_exists('DT_Frontend') || !DT_Frontend::is_typer_page() || !is_user_logged_in()) return;

        self::ensure_standings();

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

    private static function ensure_standings(): void {
        $cache = (array) get_option(self::CACHE_OPTION, []);
        $fetchedAt = (int) ($cache['fetched_at'] ?? 0);
        $season = (string) (DT_DB::settings()['season'] ?? '');
        if ($fetchedAt && (time() - $fetchedAt) < self::CACHE_TTL && (string)($cache['season'] ?? '') === $season) return;
        self::sync_standings(false);
    }

    public static function sync_standings(bool $force = false): array {
        $lockKey = 'dt_1lm_standings_refresh_lock';
        if (!$force && get_transient($lockKey)) return ['ok'=>false, 'locked'=>true];
        set_transient($lockKey, 1, 60);

        try {
            $response = wp_remote_get(self::STANDINGS_URL, [
                'timeout'=>10,
                'redirection'=>4,
                'headers'=>['User-Agent'=>'DeckaTyper/' . DT_VERSION . ' (+' . home_url('/') . ')'],
            ]);
            if (is_wp_error($response)) {
                self::log('standings_sync_error', $response->get_error_message(), ['url'=>self::STANDINGS_URL], 'warning');
                return ['ok'=>false, 'error'=>$response->get_error_message()];
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            $html = (string) wp_remote_retrieve_body($response);
            if ($code !== 200 || strlen($html) < 300) {
                $message = 'Tabela 1LM zwróciła HTTP ' . $code . '.';
                self::log('standings_sync_error', $message, ['bytes'=>strlen($html)], 'warning');
                return ['ok'=>false, 'error'=>$message];
            }

            $entries = self::parse_standings($html);
            if (count($entries) < 8) {
                $message = 'Nie udało się wiarygodnie odczytać tabeli 1LM.';
                self::log('standings_parse_error', $message, ['entries'=>count($entries)], 'warning');
                return ['ok'=>false, 'error'=>$message];
            }

            $settings = DT_DB::settings();
            $payload = [
                'fetched_at'=>time(),
                'fetched_at_mysql'=>current_time('mysql'),
                'season'=>(string)($settings['season'] ?? ''),
                'source'=>self::STANDINGS_URL,
                'positions'=>$entries,
            ];
            update_option(self::CACHE_OPTION, $payload, false);
            self::log('standings_synced', 'Zaktualizowano miejsca drużyn w tabeli 1LM.', ['teams'=>count($entries)], 'info');
            return ['ok'=>true, 'teams'=>count($entries)];
        } finally {
            delete_transient($lockKey);
        }
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

        // Conservative fallback for hosts without DOMDocument.
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
        global $wpdb;
        $settings = DT_DB::settings();
        $season = (string)($settings['season'] ?? '');
        $cache = (array)get_option(self::CACHE_OPTION, []);
        $positions = is_array($cache['positions'] ?? null) ? $cache['positions'] : [];

        $teamsTable = DT_DB::table('teams');
        $matchesTable = DT_DB::table('matches');
        $roundsTable = DT_DB::table('rounds');

        $teams = $wpdb->get_results("SELECT id,name,logo_url FROM $teamsTable", ARRAY_A);
        if (!is_array($teams)) $teams = [];

        $teamIndex = [];
        foreach ($teams as $team) {
            $teamIndex[(int)$team['id']] = $team;
        }

        $forms = [];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT m.id,m.home_team_id,m.away_team_id,m.score_home,m.score_away,m.starts_at,
                    h.name home_name,h.logo_url home_logo,a.name away_name,a.logo_url away_logo
             FROM $matchesTable m
             JOIN $roundsTable r ON r.id=m.round_id
             JOIN $teamsTable h ON h.id=m.home_team_id
             JOIN $teamsTable a ON a.id=m.away_team_id
             WHERE r.season=%s AND m.score_home IS NOT NULL AND m.score_away IS NOT NULL
             ORDER BY COALESCE(m.starts_at,m.created_at) ASC,m.id ASC",
            $season
        ), ARRAY_A);
        if (!is_array($rows)) $rows = [];

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

        $payloadTeams = [];
        foreach ($teams as $team) {
            $id = (int)$team['id'];
            $items = array_values($forms[$id] ?? []);
            while (count($items) < 5) array_unshift($items, null);
            $key = self::club_key((string)$team['name']);
            $position = isset($positions[$key]['position']) ? (int)$positions[$key]['position'] : null;
            $payloadTeams[(string)$id] = [
                'position'=>$position,
                'form'=>array_slice($items, -5),
            ];
        }

        return [
            'version'=>DT_VERSION,
            'teams'=>$payloadTeams,
            'standingsUpdatedAt'=>(string)($cache['fetched_at_mysql'] ?? ''),
            'standingsSource'=>self::STANDINGS_URL,
        ];
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
