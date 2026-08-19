<?php
if (!defined('ABSPATH')) exit;

class DT_Scoring {
    public static function score(int $selectedTeamId, int $homeTeamId, int $awayTeamId, int $realHome, int $realAway): array {
        $settings = DT_DB::settings();
        if ($realHome === $realAway) return ['points'=>0.0, 'code'=>'void'];
        $winnerId = $realHome > $realAway ? $homeTeamId : $awayTeamId;
        if ($selectedTeamId === $winnerId) {
            return ['points'=>(float) $settings['points_winner'], 'code'=>'winner'];
        }
        return ['points'=>0.0, 'code'=>'miss'];
    }

    public static function recalc_match(int $matchId): int {
        global $wpdb;
        $match = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . DT_DB::table('matches') . " WHERE id=%d", $matchId));
        if (!$match || $match->score_home === null || $match->score_away === null) return 0;

        $predictions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . DT_DB::table('predictions') . " WHERE match_id=%d AND selected_team_id IS NOT NULL",
            $matchId
        ));
        $changed = 0;
        foreach ($predictions as $prediction) {
            $score = self::score(
                (int) $prediction->selected_team_id,
                (int) $match->home_team_id,
                (int) $match->away_team_id,
                (int) $match->score_home,
                (int) $match->score_away
            );
            $wpdb->update(
                DT_DB::table('predictions'),
                ['points'=>$score['points'], 'scoring_code'=>$score['code']],
                ['id'=>(int) $prediction->id],
                ['%f','%s'],
                ['%d']
            );
            $changed++;
        }
        return $changed;
    }

    public static function recalc_round(int $roundId): int {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM " . DT_DB::table('matches') . " WHERE round_id=%d AND score_home IS NOT NULL AND score_away IS NOT NULL",
            $roundId
        ));
        $count = 0;
        foreach ($ids as $id) $count += self::recalc_match((int) $id);
        return $count;
    }

    public static function ranking(string $season, int $limit = 100, int $roundId = 0): array {
        global $wpdb;
        $pred = DT_DB::table('predictions');
        $mat = DT_DB::table('matches');
        $rnd = DT_DB::table('rounds');
        $adj = DT_DB::table('point_adjustments');
        $users = $wpdb->users;
        $settings = DT_DB::settings();

        $roundWhere = $roundId ? $wpdb->prepare(' AND r.id=%d ', $roundId) : '';
        $adjSql = $roundId ? '0' : "COALESCE((SELECT SUM(a.points) FROM $adj a WHERE a.user_id=u.ID AND a.season=" . $wpdb->prepare('%s', $season) . "),0)";
        $sql = "SELECT u.ID AS user_id, u.display_name,
                    COUNT(p.id) predictions,
                    COALESCE(SUM(p.points),0) + $adjSql AS points,
                    SUM(CASE WHEN p.scoring_code='winner' THEN 1 ELSE 0 END) winner_hits
                FROM $users u
                JOIN $pred p ON p.user_id=u.ID
                JOIN $mat m ON m.id=p.match_id
                JOIN $rnd r ON r.id=m.round_id
                WHERE r.season=" . $wpdb->prepare('%s', $season) . "
                  AND p.selected_team_id IS NOT NULL $roundWhere
                GROUP BY u.ID
                ORDER BY points DESC, winner_hits DESC, u.display_name ASC
                LIMIT " . max(1, min(500, max($limit, 100)));
        $rows = $wpdb->get_results($sql, ARRAY_A);

        $perfect = [];
        $bonus = (float) ($settings['perfect_round_bonus'] ?? 0);
        $perfectWhere = $roundId ? $wpdb->prepare(' AND r.id=%d ', $roundId) : '';
        if ($rows) {
            $perfectSql = "SELECT x.user_id, COUNT(*) perfect_rounds FROM (
                SELECT p.user_id, r.id round_id, COUNT(p.id) pred_count,
                       SUM(CASE WHEN p.scoring_code='winner' THEN 1 ELSE 0 END) good_count,
                       (SELECT COUNT(*) FROM $mat mm WHERE mm.round_id=r.id) match_count
                FROM $pred p
                JOIN $mat m ON m.id=p.match_id
                JOIN $rnd r ON r.id=m.round_id
                WHERE r.season=" . $wpdb->prepare('%s', $season) . "
                  AND p.selected_team_id IS NOT NULL $perfectWhere
                GROUP BY p.user_id, r.id
                HAVING pred_count=match_count AND good_count=match_count AND match_count>0
            ) x GROUP BY x.user_id";
            foreach ($wpdb->get_results($perfectSql, ARRAY_A) as $row) {
                $perfect[(int) $row['user_id']] = (int) $row['perfect_rounds'];
            }
        }

        foreach ($rows as &$row) {
            $uid = (int) $row['user_id'];
            $row['points'] = (float) $row['points'] + (($perfect[$uid] ?? 0) * $bonus);
            $row['predictions'] = (int) $row['predictions'];
            $row['winner_hits'] = (int) $row['winner_hits'];
            $row['perfect_rounds'] = (int) ($perfect[$uid] ?? 0);
        }
        unset($row);

        usort($rows, static function(array $a, array $b): int {
            if ($a['points'] != $b['points']) return $a['points'] < $b['points'] ? 1 : -1;
            if ($a['winner_hits'] != $b['winner_hits']) return $b['winner_hits'] <=> $a['winner_hits'];
            return strcasecmp((string) $a['display_name'], (string) $b['display_name']);
        });
        $rows = array_slice($rows, 0, max(1, min(500, $limit)));

        $rank = 0;
        $last = null;
        $seen = 0;
        foreach ($rows as &$row) {
            $seen++;
            $key = $row['points'] . '|' . $row['winner_hits'];
            if ($key !== $last) $rank = $seen;
            $row['rank'] = $rank;
            $last = $key;
        }
        unset($row);
        return $rows;
    }
}
