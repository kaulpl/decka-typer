<?php
if (!defined('ABSPATH')) exit;

class DT_Ranking_View {
    public static function register(): void {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets'], 40);
    }

    public static function routes(): void {
        register_rest_route('decka-typer/v1', '/ranking-view', [
            'methods'=>'GET',
            'callback'=>[__CLASS__, 'ranking'],
            'permission_callback'=>'__return_true',
        ]);
    }

    public static function assets(): void {
        if (!is_user_logged_in() || !class_exists('DT_Frontend') || !DT_Frontend::is_typer_page()) return;
        wp_enqueue_style('dt-ranking-view', DT_URL . 'assets/css/ranking-view.css', ['dt-front'], DT_VERSION);
        wp_enqueue_script('dt-ranking-view', DT_URL . 'assets/js/ranking-view.js', ['dt-front'], DT_VERSION, true);
    }

    public static function ranking(WP_REST_Request $request): WP_REST_Response {
        $scope = sanitize_key((string) $request->get_param('scope'));
        if (!in_array($scope, ['all','season','month','round'], true)) $scope = 'all';

        $settings = DT_DB::settings();
        $seasons = self::seasons((string)($settings['season'] ?? ''));
        $season = sanitize_text_field((string) $request->get_param('season'));
        if ($season === '' || !in_array($season, $seasons, true)) $season = $seasons[0] ?? (string)($settings['season'] ?? '');

        $league = sanitize_key((string)$request->get_param('league'));
        if (!in_array($league,['all','plk','1lm','2lm'],true)) $league = 'all';
        $availableGroups = $league === '2lm' ? self::groups($season) : [];
        $group = $league === '2lm' ? strtoupper(sanitize_text_field((string)$request->get_param('group'))) : '';
        if ($league === '2lm' && ($group === '' || !in_array($group,$availableGroups,true))) $group=$availableGroups[0]??'';

        $rounds = self::rounds($season, $league, $group);
        $months = self::months($season, $league, $group);
        $month = sanitize_text_field((string)$request->get_param('month'));
        if ($scope === 'month' && !in_array($month, $months, true)) $month = $months[0] ?? '';
        if ($scope !== 'month') $month = '';
        $roundId = max(0, (int) $request->get_param('round_id'));
        if ($scope === 'round') {
            $validIds = array_map(static fn($r)=>(int)$r['id'], $rounds);
            if (!$roundId || !in_array($roundId, $validIds, true)) {
                $roundId = $validIds ? (int) end($validIds) : 0;
            }
        } else {
            $roundId = 0;
        }

        return new WP_REST_Response([
            'scope'=>$scope,
            'season'=>$season,
            'league'=>$league,
            'group'=>$group,
            'groups'=>$availableGroups,
            'leagues'=>[['key'=>'all','name'=>'Wszystkie'],['key'=>'1lm','name'=>'1LM'],['key'=>'plk','name'=>'PLK'],['key'=>'2lm','name'=>'2LM']],
            'round_id'=>$roundId,
            'month'=>$month,
            'seasons'=>$seasons,
            'rounds'=>$rounds,
            'months'=>$months,
            'ranking'=>self::rows($scope, $season, $roundId, $league, $group, $month),
        ]);
    }

    private static function seasons(string $current): array {
        global $wpdb;
        $items = $wpdb->get_col('SELECT DISTINCT season FROM ' . DT_DB::table('rounds') . " WHERE season<>'' ORDER BY season DESC");
        $items = array_values(array_unique(array_filter(array_map('strval', (array)$items))));
        usort($items, static function(string $a, string $b) use ($current): int {
            if ($a === $current && $b !== $current) return -1;
            if ($b === $current && $a !== $current) return 1;
            preg_match('/(20\d{2})/', $a, $ma);
            preg_match('/(20\d{2})/', $b, $mb);
            return ((int)($mb[1] ?? 0)) <=> ((int)($ma[1] ?? 0));
        });
        return array_slice($items, 0, 6);
    }

    private static function groups(string $season): array {
        global $wpdb;
        $groups = array_map(static function($value): string {
            $value = strtoupper(trim((string)$value));
            return preg_replace('/^GRUPA\s+/u', '', $value) ?: '';
        }, (array)$wpdb->get_col($wpdb->prepare("SELECT DISTINCT group_key FROM ".DT_DB::table('rounds')." WHERE season=%s AND league_key='2lm' AND group_key<>'' ORDER BY group_key",$season)));
        return array_values(array_unique(array_filter($groups)));
    }

    private static function rounds(string $season, string $league = 'all', string $group = ''): array {
        global $wpdb;
        $leagueSql = $league !== 'all' ? $wpdb->prepare(' AND league_key=%s ', $league) : '';
        $groupSql = $group !== '' ? $wpdb->prepare(" AND REPLACE(UPPER(TRIM(group_key)),'GRUPA ','')=%s ", $group) : '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id,round_no,title,status,closes_at,league_key,group_key FROM " . DT_DB::table('rounds') . " WHERE season=%s $leagueSql $groupSql AND status IN ('open','closed') ORDER BY league_key,group_key,round_no ASC,id ASC",
            $season
        ), ARRAY_A);
        if (!is_array($rows)) return [];
        foreach ($rows as &$row) {
            $row['id'] = (int)$row['id'];
            $row['round_no'] = (int)$row['round_no'];
        }
        unset($row);
        return $rows;
    }

    private static function months(string $season, string $league = 'all', string $group = ''): array {
        global $wpdb;
        $leagueSql = $league !== 'all' ? $wpdb->prepare(' AND r.league_key=%s ', $league) : '';
        $groupSql = $group !== '' ? $wpdb->prepare(" AND REPLACE(UPPER(TRIM(r.group_key)),'GRUPA ','')=%s ", $group) : '';
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT DATE_FORMAT(m.starts_at,'%%Y-%%m') month_key
             FROM ".DT_DB::table('matches')." m
             JOIN ".DT_DB::table('rounds')." r ON r.id=m.round_id
             WHERE r.season=%s $leagueSql $groupSql
               AND m.starts_at IS NOT NULL AND m.score_home IS NOT NULL AND m.score_away IS NOT NULL
             ORDER BY month_key DESC",
            $season
        ));
        return array_values(array_filter(array_map('strval', (array)$rows), static fn(string $value): bool=>(bool)preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)));
    }

    private static function rows(string $scope, string $season, int $roundId, string $league = 'all', string $group = '', string $month = ''): array {
        global $wpdb;
        $pred = DT_DB::table('predictions');
        $mat = DT_DB::table('matches');
        $rnd = DT_DB::table('rounds');
        $adj = DT_DB::table('point_adjustments');
        $users = $wpdb->users;

        $filter = '';
        if ($scope === 'season') {
            $filter = $wpdb->prepare(' AND r.season=%s ', $season);
        } elseif ($scope === 'month') {
            $filter = $wpdb->prepare(" AND r.season=%s AND DATE_FORMAT(m.starts_at,'%%Y-%%m')=%s ", $season, $month);
        } elseif ($scope === 'round') {
            $filter = $wpdb->prepare(' AND r.season=%s AND r.id=%d ', $season, $roundId);
        }
        if ($league !== 'all') $filter .= $wpdb->prepare(' AND r.league_key=%s ', $league);
        if ($group !== '') $filter .= $wpdb->prepare(" AND REPLACE(UPPER(TRIM(r.group_key)),'GRUPA ','')=%s ", $group);

        $sql = "SELECT u.ID user_id,u.display_name,
                       COUNT(p.id) predictions,
                       COALESCE(SUM(p.points),0) points,
                       SUM(CASE WHEN p.scoring_code='winner' THEN 1 ELSE 0 END) winner_hits
                FROM $users u
                JOIN $pred p ON p.user_id=u.ID
                JOIN $mat m ON m.id=p.match_id
                JOIN $rnd r ON r.id=m.round_id
                WHERE p.selected_team_id IS NOT NULL $filter
                GROUP BY u.ID,u.display_name";
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) $rows = [];

        $adjustments = [];
        if (in_array($scope, ['all','season'], true) && $league === 'all') {
            $adjWhere = $scope === 'season' ? $wpdb->prepare(' WHERE season=%s ', $season) : '';
            foreach ((array)$wpdb->get_results("SELECT user_id,COALESCE(SUM(points),0) points FROM $adj $adjWhere GROUP BY user_id", ARRAY_A) as $row) {
                $adjustments[(int)$row['user_id']] = (float)$row['points'];
            }
        }

        $perfect = [];
        $perfectSql = "SELECT x.user_id,COUNT(*) perfect_rounds,
                              SUM(CASE WHEN x.match_count=8 THEN 1 ELSE 0 END) perfect_eight_rounds FROM (
            SELECT p.user_id,r.id round_id,COUNT(p.id) pred_count,
                   SUM(CASE WHEN p.scoring_code='winner' THEN 1 ELSE 0 END) good_count,
                   (SELECT COUNT(*) FROM $mat mm WHERE mm.round_id=r.id) match_count
            FROM $pred p
            JOIN $mat m ON m.id=p.match_id
            JOIN $rnd r ON r.id=m.round_id
            WHERE p.selected_team_id IS NOT NULL $filter
            GROUP BY p.user_id,r.id
            HAVING pred_count=match_count AND good_count=match_count AND match_count>0
        ) x GROUP BY x.user_id";
        foreach ((array)$wpdb->get_results($perfectSql, ARRAY_A) as $row) {
            $perfect[(int)$row['user_id']] = [
                'all'=>(int)$row['perfect_rounds'],
                'eight'=>(int)$row['perfect_eight_rounds'],
            ];
        }

        $bonusHits = [];
        $bonusIds = class_exists('DT_Bonus') ? array_values(array_filter(array_map('intval', DT_Bonus::match_ids()))) : [];
        if ($bonusIds) {
            $placeholders = implode(',', array_fill(0, count($bonusIds), '%d'));
            $in = $wpdb->prepare($placeholders, ...$bonusIds);
            $bonusSql = "SELECT p.user_id,COUNT(*) hits
                         FROM $pred p
                         JOIN $mat m ON m.id=p.match_id
                         JOIN $rnd r ON r.id=m.round_id
                         WHERE p.scoring_code='winner' AND m.id IN ($in) $filter
                         GROUP BY p.user_id";
            foreach ((array)$wpdb->get_results($bonusSql, ARRAY_A) as $row) {
                $bonusHits[(int)$row['user_id']] = (int)$row['hits'];
            }
        }

        $settings = DT_DB::settings();
        $perfectPoints = (float)($settings['perfect_round_bonus'] ?? 0);
        $bonusValue = class_exists('DT_Bonus') ? DT_Bonus::points() : 0.0;
        foreach ($rows as &$row) {
            $uid = (int)$row['user_id'];
            $row['user_id'] = $uid;
            $row['predictions'] = (int)$row['predictions'];
            $row['winner_hits'] = (int)$row['winner_hits'];
            $row['perfect_rounds'] = (int)($perfect[$uid]['all'] ?? 0);
            $row['perfect_eight_rounds'] = (int)($perfect[$uid]['eight'] ?? 0);
            $row['bonus_hits'] = (int)($bonusHits[$uid] ?? 0);
            $row['bonus_points'] = $row['bonus_hits'] * $bonusValue;
            $row['points'] = (float)$row['points'] + (float)($adjustments[$uid] ?? 0) + ($row['perfect_rounds'] * $perfectPoints);
            $row['efficiency'] = $row['predictions'] > 0 ? ($row['winner_hits'] / $row['predictions']) * 100 : 0.0;
        }
        unset($row);

        if (class_exists('DT_User_Settings')) $rows = DT_User_Settings::apply_ranking_names($rows);

        usort($rows, static function(array $a,array $b): int {
            if ((float)$a['points'] !== (float)$b['points']) return (float)$a['points'] < (float)$b['points'] ? 1 : -1;
            if ((int)$a['winner_hits'] !== (int)$b['winner_hits']) return (int)$b['winner_hits'] <=> (int)$a['winner_hits'];
            return strcasecmp((string)$a['display_name'], (string)$b['display_name']);
        });

        $rank=0;$seen=0;$last=null;
        foreach ($rows as &$row) {
            $seen++;
            $key = (string)$row['points'] . '|' . (string)$row['winner_hits'];
            if ($key !== $last) $rank=$seen;
            $row['rank']=$rank;
            $last=$key;
        }
        unset($row);
        return array_slice($rows, 0, 500);
    }
}
