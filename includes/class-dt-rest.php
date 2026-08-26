<?php
if (!defined('ABSPATH')) exit;

class DT_REST {
    public static function register(): void {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes(): void {
        register_rest_route('decka-typer/v1', '/bootstrap', [
            'methods'=>'GET', 'callback'=>[__CLASS__,'bootstrap'], 'permission_callback'=>'__return_true',
        ]);
        register_rest_route('decka-typer/v1', '/round/(?P<id>\\d+)', [
            'methods'=>'GET', 'callback'=>[__CLASS__,'round'], 'permission_callback'=>'__return_true',
        ]);
        register_rest_route('decka-typer/v1', '/submission', [
            'methods'=>'POST', 'callback'=>[__CLASS__,'save_submission'],
            'permission_callback'=>static fn()=>is_user_logged_in(),
        ]);
        register_rest_route('decka-typer/v1', '/ranking', [
            'methods'=>'GET', 'callback'=>[__CLASS__,'ranking'], 'permission_callback'=>'__return_true',
        ]);
        register_rest_route('decka-typer/v1', '/me', [
            'methods'=>'GET', 'callback'=>[__CLASS__,'me'], 'permission_callback'=>static fn()=>is_user_logged_in(),
        ]);
    }

    public static function bootstrap(WP_REST_Request $request): WP_REST_Response {
        $settings = DT_DB::settings();
        $rounds = self::visible_rounds((string) $settings['season']);
        $current = self::pick_current_round($rounds);
        $roundData = $current ? self::round_payload((int) $current['id'], true) : null;
        $me = is_user_logged_in() ? self::me_payload(get_current_user_id(), (string) $settings['season']) : null;

        return new WP_REST_Response([
            'version'=>DT_VERSION,
            'season'=>$settings['season'],
            'league_name'=>'PLK · 1LM · 2LM',
            'timezone'=>wp_timezone_string() ?: 'Europe/Warsaw',
            'rounds'=>$rounds,
            'leagues'=>self::league_catalog($rounds),
            'current_round'=>$roundData,
            'ranking'=>DT_Scoring::ranking((string) $settings['season'], 10),
            'me'=>$me,
            'server_time'=>current_time('mysql'),
            'scoring'=>['winner'=>(float) $settings['points_winner']],
        ]);
    }

    public static function round(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id = (int) $request['id'];
        $payload = self::round_payload($id, true);
        return $payload
            ? new WP_REST_Response($payload)
            : new WP_Error('not_found', 'Nie znaleziono dostępnej kolejki.', ['status'=>404]);
    }

    public static function save_submission(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $uid = get_current_user_id();
        $body = $request->get_json_params();
        $roundId = max(0, (int) ($body['round_id'] ?? 0));
        $picks = is_array($body['picks'] ?? null) ? $body['picks'] : [];
        if (!$roundId) return new WP_Error('invalid_round', 'Nie wybrano kolejki.', ['status'=>422]);

        $round = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . DT_DB::table('rounds') . " WHERE id=%d", $roundId), ARRAY_A);
        if (!$round) return new WP_Error('not_found', 'Nie znaleziono kolejki.', ['status'=>404]);
        if (!self::round_accepts_picks($round)) {
            return new WP_Error('round_closed', 'Typowanie tej kolejki jest zamknięte.', ['status'=>409]);
        }

        $subTable = DT_DB::table('round_submissions');
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $subTable WHERE user_id=%d AND round_id=%d", $uid, $roundId));
        if ($existing) {
            return new WP_Error('already_submitted', 'Ten kupon został już zapisany i nie można go edytować.', ['status'=>409]);
        }

        $matches = $wpdb->get_results($wpdb->prepare(
            "SELECT id,home_team_id,away_team_id FROM " . DT_DB::table('matches') . " WHERE round_id=%d ORDER BY id",
            $roundId
        ), ARRAY_A);
        if (!$matches) return new WP_Error('empty_round', 'Ta kolejka nie ma meczów.', ['status'=>422]);

        $matchMap = [];
        foreach ($matches as $m) $matchMap[(int) $m['id']] = [(int) $m['home_team_id'], (int) $m['away_team_id']];
        $clean = [];
        foreach ($picks as $pick) {
            if (!is_array($pick)) continue;
            $matchId = (int) ($pick['match_id'] ?? 0);
            $teamId = (int) ($pick['team_id'] ?? 0);
            if (!$matchId || !$teamId || !isset($matchMap[$matchId])) {
                return new WP_Error('invalid_pick', 'Jeden z typów jest nieprawidłowy.', ['status'=>422]);
            }
            if (!in_array($teamId, $matchMap[$matchId], true)) {
                return new WP_Error('invalid_team', 'Wybrana drużyna nie gra w tym meczu.', ['status'=>422]);
            }
            $clean[$matchId] = $teamId;
        }
        if (count($clean) !== count($matches)) {
            return new WP_Error('incomplete_coupon', 'Przed zapisem wytypuj zwycięzcę każdego meczu.', ['status'=>422]);
        }

        // Re-check the close time immediately before the immutable write.
        $round = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . DT_DB::table('rounds') . " WHERE id=%d", $roundId), ARRAY_A);
        if (!$round || !self::round_accepts_picks($round)) {
            return new WP_Error('round_closed', 'Czas na typowanie właśnie się zakończył.', ['status'=>409]);
        }

        $now = current_time('mysql');
        $predTable = DT_DB::table('predictions');
        $wpdb->query('START TRANSACTION');
        try {
            $inserted = $wpdb->insert($subTable, [
                'user_id'=>$uid,
                'round_id'=>$roundId,
                'prediction_count'=>count($clean),
                'submitted_at'=>$now,
            ], ['%d','%d','%d','%s']);
            if (!$inserted) throw new RuntimeException('Kupon został już zapisany lub nie można utworzyć zgłoszenia.');

            foreach ($clean as $matchId=>$teamId) {
                // A partial legacy 0.1.x pick may already exist. It may be finalized once as part of the
                // complete 0.2.0 coupon; after round_submissions is created there is no update route anymore.
                $predictionId = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM $predTable WHERE user_id=%d AND match_id=%d",
                    $uid, $matchId
                ));
                if ($predictionId) {
                    $ok = $wpdb->update($predTable, [
                        'selected_team_id'=>$teamId,
                        'points'=>0,
                        'scoring_code'=>null,
                        'updated_at'=>$now,
                    ], ['id'=>(int) $predictionId], ['%d','%d','%d','%f','%s','%s'], ['%d']);
                    if ($ok === false) throw new RuntimeException('Nie udało się zaktualizować starego typu.');
                } else {
                    $ok = $wpdb->insert($predTable, [
                        'user_id'=>$uid,
                        'match_id'=>$matchId,
                        'selected_team_id'=>$teamId,
                        'points'=>0,
                        'scoring_code'=>null,
                        'submitted_at'=>$now,
                        'updated_at'=>$now,
                    ], ['%d','%d','%d','%d','%d','%f','%s','%s','%s']);
                    if (!$ok) throw new RuntimeException('Nie udało się zapisać wszystkich typów.');
                }
            }
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            DT_Logger::log('submission_error', $e->getMessage(), ['round_id'=>$roundId], 'error', $uid);
            return new WP_Error('save_failed', 'Nie udało się zapisać kuponu. Odśwież stronę i spróbuj ponownie.', ['status'=>409]);
        }

        DT_Logger::log('round_submitted', 'Zapisano nieedytowalny kupon kolejki.', [
            'round_id'=>$roundId, 'prediction_count'=>count($clean),
        ], 'notice', $uid);
        return new WP_REST_Response(['ok'=>true, 'round_id'=>$roundId, 'submitted_at'=>$now]);
    }

    public static function ranking(WP_REST_Request $request): WP_REST_Response {
        $settings = DT_DB::settings();
        $roundId = max(0, (int) $request->get_param('round_id'));
        return new WP_REST_Response([
            'season'=>$settings['season'],
            'round_id'=>$roundId,
            'ranking'=>DT_Scoring::ranking((string) $settings['season'], 100, $roundId),
        ]);
    }

    public static function me(WP_REST_Request $request): WP_REST_Response {
        $settings = DT_DB::settings();
        $scope = sanitize_key((string)$request->get_param('scope'));
        $season = $scope === 'all' ? '' : (string)$settings['season'];
        $league = sanitize_key((string)$request->get_param('league'));
        if (!in_array($league,['all','plk','1lm','2lm'],true)) $league='all';
        return new WP_REST_Response(self::me_payload(get_current_user_id(), $season, $league));
    }

    private static function visible_rounds(string $season): array {
        global $wpdb;
        $now = current_time('mysql');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*,
                COUNT(m.id) match_count,
                SUM(CASE WHEN m.status='finished' THEN 1 ELSE 0 END) finished_count,
                MIN(m.starts_at) first_match,
                MAX(m.starts_at) last_match
             FROM " . DT_DB::table('rounds') . " r
             LEFT JOIN " . DT_DB::table('matches') . " m ON m.round_id=r.id
             WHERE r.season=%s
             GROUP BY r.id
             ORDER BY r.round_no ASC",
            $season
        ), ARRAY_A);

        $submissionMap = [];
        if (is_user_logged_in()) {
            $subs = $wpdb->get_results($wpdb->prepare(
                "SELECT round_id,submitted_at,prediction_count FROM " . DT_DB::table('round_submissions') . " WHERE user_id=%d",
                get_current_user_id()
            ), ARRAY_A);
            foreach ($subs as $sub) $submissionMap[(int) $sub['round_id']] = $sub;
        }

        $visible = [];
        foreach ($rows as $row) {
            $firstMatch = (string) ($row['first_match'] ?? '');
            $pastStarted = $firstMatch !== '' && $firstMatch <= $now;
            $id = (int) $row['id'];
            $sub = $submissionMap[$id] ?? null;
            $explicitOpen = (string) $row['status'] === 'open';
            // Future drafts/closed rounds stay hidden. A round becomes visible when the admin opens it,
            // when its first game has started, or to a user who has already submitted that coupon.
            if (!$explicitOpen && !$pastStarted && !$sub) continue;
            $isOpen = self::round_accepts_picks($row);
            $row['id'] = $id;
            $row['round_no'] = (int) $row['round_no'];
            $row['league_key'] = (string)($row['league_key'] ?? '1lm');
            $row['league_name'] = self::league_name($row['league_key']);
            $row['group_key'] = (string)($row['group_key'] ?? '');
            $row['match_count'] = (int) $row['match_count'];
            $row['finished_count'] = (int) $row['finished_count'];
            $row['is_open'] = $isOpen;
            $row['display_status'] = $isOpen ? 'open' : 'closed';
            $row['submitted'] = (bool) $sub;
            $row['closes_at_iso'] = self::iso_datetime($row['closes_at'] ?? null);
            $row['first_match_iso'] = self::iso_datetime($row['first_match'] ?? null);
            $row['last_match_iso'] = self::iso_datetime($row['last_match'] ?? null);
            $visible[] = $row;
        }
        return $visible;
    }

    private static function league_catalog(array $rounds): array {
        $out = [];
        foreach ($rounds as $round) {
            $key = (string)($round['league_key'] ?? '1lm');
            if (!isset($out[$key])) $out[$key] = ['key'=>$key,'name'=>self::league_name($key),'round_count'=>0,'open_count'=>0,'groups'=>[]];
            $out[$key]['round_count']++;
            if (!empty($round['is_open'])) $out[$key]['open_count']++;
            $group = (string)($round['group_key'] ?? '');
            if ($group !== '' && !in_array($group,$out[$key]['groups'],true)) $out[$key]['groups'][] = $group;
        }
        return array_values($out);
    }

    private static function league_name(string $key): string {
        $names=(array)(DT_DB::settings()['league_names']??[]);
        return (string)($names[$key]??(['plk'=>'ORLEN Basket Liga','1lm'=>'1 Liga Mężczyzn','2lm'=>'2 Liga Mężczyzn'][$key]??strtoupper($key)));
    }

    private static function round_payload(int $roundId, bool $visibleOnly = false): ?array {
        global $wpdb;
        $round = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . DT_DB::table('rounds') . " WHERE id=%d", $roundId), ARRAY_A);
        if (!$round) return null;

        $matches = $wpdb->get_results($wpdb->prepare(
            "SELECT m.*, h.name home_name,h.logo_url home_logo,
                    a.name away_name,a.logo_url away_logo
             FROM " . DT_DB::table('matches') . " m
             JOIN " . DT_DB::table('teams') . " h ON h.id=m.home_team_id
             JOIN " . DT_DB::table('teams') . " a ON a.id=m.away_team_id
             WHERE m.round_id=%d ORDER BY COALESCE(m.starts_at,'9999-12-31 23:59:59') ASC,m.id ASC",
            $roundId
        ), ARRAY_A);
        $now = current_time('mysql');
        $firstMatch = '';
        foreach ($matches as $m) {
            if (!empty($m['starts_at']) && ($firstMatch === '' || $m['starts_at'] < $firstMatch)) $firstMatch = $m['starts_at'];
        }
        $pastStarted = $firstMatch !== '' && $firstMatch <= $now;

        $historyFilter = $wpdb->prepare('r.season=%s AND r.league_key=%s', (string)($round['season'] ?? ''), (string)($round['league_key'] ?? '1lm'));
        if ((string)($round['league_key'] ?? '') === '2lm' && (string)($round['group_key'] ?? '') !== '') {
            $historyFilter .= $wpdb->prepare(' AND r.group_key=%s', (string)$round['group_key']);
        }
        $history = $wpdb->get_results(
            "SELECT m.id,m.round_id,m.home_team_id,m.away_team_id,m.score_home,m.score_away,m.starts_at,
                    r.round_no,h.name home_name,a.name away_name
             FROM " . DT_DB::table('matches') . " m
             JOIN " . DT_DB::table('rounds') . " r ON r.id=m.round_id
             JOIN " . DT_DB::table('teams') . " h ON h.id=m.home_team_id
             JOIN " . DT_DB::table('teams') . " a ON a.id=m.away_team_id
             WHERE $historyFilter AND m.score_home IS NOT NULL AND m.score_away IS NOT NULL
               AND (r.round_no<" . (int)($round['round_no'] ?? 0) . " OR r.id=" . (int)$roundId . ")
             ORDER BY r.round_no ASC,m.starts_at ASC,m.id ASC",
            ARRAY_A
        );

        $predMap = [];
        $submission = null;
        if (is_user_logged_in()) {
            $submission = $wpdb->get_row($wpdb->prepare(
                "SELECT submitted_at,prediction_count FROM " . DT_DB::table('round_submissions') . " WHERE user_id=%d AND round_id=%d",
                get_current_user_id(), $roundId
            ), ARRAY_A);
            if ($matches) {
                $ids = array_map(static fn($m)=>(int) $m['id'], $matches);
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                $query = $wpdb->prepare(
                    "SELECT * FROM " . DT_DB::table('predictions') . " WHERE user_id=%d AND match_id IN ($placeholders)",
                    array_merge([get_current_user_id()], $ids)
                );
                foreach ($wpdb->get_results($query, ARRAY_A) as $prediction) {
                    $predMap[(int) $prediction['match_id']] = $prediction;
                }
            }
        }

        if ($visibleOnly && (string) $round['status'] !== 'open' && !$pastStarted && !$submission) return null;

        $isOpen = self::round_accepts_picks($round);
        foreach ($matches as &$match) {
            $match['id'] = (int) $match['id'];
            $match['round_id'] = (int) $match['round_id'];
            $match['home_team_id'] = (int) $match['home_team_id'];
            $match['away_team_id'] = (int) $match['away_team_id'];
            $match['score_home'] = $match['score_home'] === null ? null : (int) $match['score_home'];
            $match['score_away'] = $match['score_away'] === null ? null : (int) $match['score_away'];
            $match['start_time_known'] = (bool) $match['start_time_known'];
            $match['manual_lock'] = (bool) $match['manual_lock'];
            $match['featured'] = (bool) $match['featured'];
            $match['starts_at_iso'] = self::iso_datetime($match['starts_at'] ?? null);
            $before = !empty($match['starts_at']) ? (string)$match['starts_at'] : '9999-12-31 23:59:59';
            $match['home_insights'] = self::team_insights((int)$match['home_team_id'], (string)$match['home_name'], 'home', $before, (int)$match['id'], (int)$roundId, (int)($round['round_no'] ?? 0), (array)$history);
            $match['away_insights'] = self::team_insights((int)$match['away_team_id'], (string)$match['away_name'], 'away', $before, (int)$match['id'], (int)$roundId, (int)($round['round_no'] ?? 0), (array)$history);
            $prediction = $predMap[$match['id']] ?? null;
            $match['prediction'] = $prediction ? [
                'selected_team_id'=>(int) $prediction['selected_team_id'],
                'points'=>(float) $prediction['points'],
                'scoring_code'=>$prediction['scoring_code'],
            ] : null;
            unset($match['source_hash']);
        }
        unset($match);

        $round['id'] = (int) $round['id'];
        $round['round_no'] = (int) $round['round_no'];
        $round['league_key'] = (string)($round['league_key'] ?? '1lm');
        $round['league_name'] = self::league_name($round['league_key']);
        $round['group_key'] = (string)($round['group_key'] ?? '');
        $round['matches'] = $matches;
        $round['is_open'] = $isOpen;
        $round['display_status'] = $isOpen ? 'open' : 'closed';
        $round['closes_at_iso'] = self::iso_datetime($round['closes_at'] ?? null);
        $round['submission'] = $submission ? [
            'submitted'=>true,
            'submitted_at'=>$submission['submitted_at'],
            'submitted_at_iso'=>self::iso_datetime($submission['submitted_at']),
            'prediction_count'=>(int) $submission['prediction_count'],
        ] : ['submitted'=>false];
        $round['can_submit'] = is_user_logged_in() && $isOpen && !$submission;
        return $round;
    }

    private static function team_insights(int $teamId, string $teamName, string $venue, string $before, int $currentMatchId, int $currentRoundId, int $currentRoundNo, array $history): array {
        $all = [];
        $venueMatches = [];
        $teamKey = self::team_key($teamName);
        foreach ($history as $item) {
            if ((int)($item['id'] ?? 0) === $currentMatchId) continue;
            $earlierRound = (int)($item['round_no'] ?? 0) < $currentRoundNo;
            $earlierInCurrentRound = (int)($item['round_id'] ?? 0) === $currentRoundId && (string)($item['starts_at'] ?? '') < $before;
            if (!$earlierRound && !$earlierInCurrentRound) continue;
            $homeKey = self::team_key((string)($item['home_name'] ?? ''));
            $awayKey = self::team_key((string)($item['away_name'] ?? ''));
            $side = null;
            if ($homeKey !== '' && $homeKey === $teamKey) $side = 'home';
            elseif ($awayKey !== '' && $awayKey === $teamKey) $side = 'away';
            elseif ((int)($item['home_team_id'] ?? 0) === $teamId) $side = 'home';
            elseif ((int)($item['away_team_id'] ?? 0) === $teamId) $side = 'away';
            if ($side === null) continue;
            $scored = $side === 'home' ? (int)$item['score_home'] : (int)$item['score_away'];
            $allowed = $side === 'home' ? (int)$item['score_away'] : (int)$item['score_home'];
            $entry = ['won'=>$scored > $allowed, 'points'=>$scored];
            $all[] = $entry;
            if ($venue === $side) $venueMatches[] = $entry;
        }
        $record = static function(array $items): array {
            $wins = count(array_filter($items, static fn($item): bool=>(bool)$item['won']));
            return ['wins'=>$wins, 'losses'=>count($items)-$wins];
        };
        $average = static function(array $items): ?float {
            $last = array_slice($items, -3);
            if (!$last) return null;
            return round(array_sum(array_column($last, 'points')) / count($last), 1);
        };
        return [
            'overall_record'=>$record($all),
            'venue_record'=>$record($venueMatches),
            'overall_average'=>$average($all),
            'venue_average'=>$average($venueMatches),
            'venue'=>$venue,
        ];
    }

    private static function team_key(string $name): string {
        $name = strtolower(remove_accents(wp_strip_all_tags($name)));
        $normalized = trim((string)preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', $name)));
        $known = [
            'polonia 1912 leszno','polonia leszno','wkk','politechnika opolska','sokol lancut',
            'starogard gdanski','spojnia stargard','resovia rzeszow','miasto szkla krosno',
            'lks lodz','lks coolpack lodz','notec inowroclaw','polonia warszawa','gks tychy',
            'basket poznan','decka pelplin','kotwica','polonia bytom',
        ];
        foreach ($known as $needle) {
            if (!str_contains($normalized, $needle)) continue;
            if ($needle === 'polonia leszno') return 'polonia 1912 leszno';
            if ($needle === 'lks coolpack lodz') return 'lks lodz';
            return $needle;
        }
        return $normalized;
    }

    private static function me_payload(int $uid, string $season, string $league = 'all'): array {
        global $wpdb;
        $user = get_userdata($uid);
        $roundFilter = '';
        $args = [$uid];
        if ($season !== '') { $roundFilter .= ' AND r.season=%s'; $args[]=$season; }
        if ($league !== 'all') { $roundFilter .= ' AND r.league_key=%s'; $args[]=$league; }
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(p.id) predictions,COALESCE(SUM(p.points),0) points,
                    SUM(CASE WHEN p.scoring_code='winner' THEN 1 ELSE 0 END) winner_hits
             FROM " . DT_DB::table('predictions') . " p
             JOIN " . DT_DB::table('matches') . " m ON m.id=p.match_id
             JOIN " . DT_DB::table('rounds') . " r ON r.id=m.round_id
             WHERE p.user_id=%d $roundFilter AND p.selected_team_id IS NOT NULL",
            ...$args
        ), ARRAY_A);
        $subArgs=[$uid]; if($season!=='')$subArgs[]=$season; if($league!=='all')$subArgs[]=$league;
        $submissions = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . DT_DB::table('round_submissions') . " s
             JOIN " . DT_DB::table('rounds') . " r ON r.id=s.round_id
             WHERE s.user_id=%d $roundFilter",
            ...$subArgs
        ));
        $adjustment = $season !== '' ? (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(points),0) FROM " . DT_DB::table('point_adjustments') . " WHERE user_id=%d AND season=%s", $uid, $season
        )) : 0.0;
        $ranking = $season !== '' && $league === 'all' ? DT_Scoring::ranking($season, 500) : [];
        $rank = null;
        foreach ($ranking as $item) if ((int) $item['user_id'] === $uid) { $rank = (int) $item['rank']; break; }

        $history = $wpdb->get_results($wpdb->prepare(
            "SELECT r.round_no,r.league_key,r.group_key,r.season,r.status round_status,m.starts_at,h.name home_name,a.name away_name,
                    p.selected_team_id,p.points,p.scoring_code,
                    h.id home_team_id,a.id away_team_id,m.score_home,m.score_away
             FROM " . DT_DB::table('predictions') . " p
             JOIN " . DT_DB::table('matches') . " m ON m.id=p.match_id
             JOIN " . DT_DB::table('rounds') . " r ON r.id=m.round_id
             JOIN " . DT_DB::table('teams') . " h ON h.id=m.home_team_id
             JOIN " . DT_DB::table('teams') . " a ON a.id=m.away_team_id
             WHERE p.user_id=%d $roundFilter AND p.selected_team_id IS NOT NULL
             ORDER BY r.round_no DESC,m.starts_at DESC,p.id DESC LIMIT 160",
            ...$args
        ), ARRAY_A);
        foreach ($history as &$item) {
            $selected = (int) $item['selected_team_id'];
            $item['round_no'] = (int) $item['round_no'];
            $item['home_team_id'] = (int) $item['home_team_id'];
            $item['away_team_id'] = (int) $item['away_team_id'];
            $item['selected_team_id'] = $selected;
            $item['selected_team_name'] = $selected === (int) $item['home_team_id'] ? $item['home_name'] : $item['away_name'];
            $item['points'] = (float) $item['points'];
            $item['result_known'] = $item['score_home'] !== null && $item['score_away'] !== null;
            $item['starts_at_iso'] = self::iso_datetime($item['starts_at'] ?? null);
            unset($item['score_home'], $item['score_away']);
        }
        unset($item);

        $currentSeason=(string)(DT_DB::settings()['season']??'');
        $leagueAchievements=self::league_achievements($uid,$season);
        $leagueRanks=$season===$currentSeason?$leagueAchievements:self::league_achievements($uid,$currentSeason);

        return [
            'user_id'=>$uid,
            'display_name'=>class_exists('DT_User_Settings') ? DT_User_Settings::ranking_name($uid, $user ? (string)$user->user_login : 'Kibic') : ($user ? $user->display_name : 'Kibic'),
            'avatar'=>get_avatar_url($uid, ['size'=>96]),
            'predictions'=>(int) ($row['predictions'] ?? 0),
            'submissions'=>$submissions,
            'points'=>(float) ($row['points'] ?? 0) + $adjustment,
            'winner_hits'=>(int) ($row['winner_hits'] ?? 0),
            'rank'=>$rank,
            'league_achievements'=>$leagueAchievements,
            'league_ranks'=>$leagueRanks,
            'history'=>$history,
        ];
    }

    private static function league_achievements(int $uid, string $season): array {
        global $wpdb;
        $seasonSql=$season!==''?$wpdb->prepare(' AND r.season=%s',$season):'';
        $rows=$wpdb->get_results(
            "SELECT p.user_id,r.league_key,COUNT(p.id) predictions,COALESCE(SUM(p.points),0) points,
                    SUM(CASE WHEN p.scoring_code='winner' THEN 1 ELSE 0 END) winner_hits
             FROM ".DT_DB::table('predictions')." p
             JOIN ".DT_DB::table('matches')." m ON m.id=p.match_id
             JOIN ".DT_DB::table('rounds')." r ON r.id=m.round_id
             WHERE p.selected_team_id IS NOT NULL AND r.league_key IN ('1lm','plk','2lm') $seasonSql
             GROUP BY r.league_key,p.user_id",
            ARRAY_A
        );
        $out=[];
        foreach(['1lm','plk','2lm'] as $key)$out[$key]=['rank'=>null,'points'=>0.0,'winner_hits'=>0,'predictions'=>0];
        $byLeague=['1lm'=>[],'plk'=>[],'2lm'=>[]];
        foreach((array)$rows as $row){$key=(string)($row['league_key']??'');if(isset($byLeague[$key]))$byLeague[$key][]=$row;}
        foreach($byLeague as $key=>$leagueRows){
            usort($leagueRows,static function($a,$b){$points=(float)$b['points']<=>(float)$a['points'];if($points!==0)return $points;$hits=(int)$b['winner_hits']<=>(int)$a['winner_hits'];if($hits!==0)return $hits;return (int)$a['user_id']<=>(int)$b['user_id'];});
            $rank=0;$seen=0;$last=null;
            foreach($leagueRows as $item){$seen++;$score=(string)$item['points'].'|'.(string)$item['winner_hits'];if($score!==$last)$rank=$seen;$last=$score;if((int)$item['user_id']===$uid){$out[$key]=['rank'=>$rank,'points'=>(float)$item['points'],'winner_hits'=>(int)$item['winner_hits'],'predictions'=>(int)$item['predictions']];break;}}
        }
        return $out;
    }

    public static function round_accepts_picks(array $round): bool {
        if (($round['status'] ?? '') !== 'open') return false;
        if (empty($round['closes_at'])) return false;
        return (string) $round['closes_at'] > current_time('mysql');
    }

    private static function pick_current_round(array $rounds): ?array {
        if (!$rounds) return null;
        // 1LM is the leading competition. Prefer its open round on the first
        // application load, then fall back to any other open competition.
        foreach ($rounds as $round) {
            if (!empty($round['is_open']) && (string)($round['league_key'] ?? '') === '1lm') return $round;
        }
        foreach ($rounds as $round) if (!empty($round['is_open'])) return $round;
        return end($rounds) ?: null;
    }

    private static function iso_datetime(?string $value): ?string {
        if (!$value) return null;
        $tz = wp_timezone();
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $tz);
        if (!$date) $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, $tz);
        return $date ? $date->format(DateTimeInterface::ATOM) : null;
    }
}
