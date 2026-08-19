<?php
if (!defined('ABSPATH')) exit;

class DT_Scoring {
    public static function score(int $pred_home, int $pred_away, int $real_home, int $real_away): array {
        $s = DT_DB::settings();
        if ($pred_home === $real_home && $pred_away === $real_away) {
            return ['points' => (float)$s['points_exact'], 'code' => 'exact'];
        }
        $pred_diff = $pred_home - $pred_away;
        $real_diff = $real_home - $real_away;
        $pred_winner = $pred_diff <=> 0;
        $real_winner = $real_diff <=> 0;
        if ($pred_winner === $real_winner && $pred_winner !== 0 && abs($pred_diff) === abs($real_diff)) {
            return ['points' => (float)$s['points_margin'], 'code' => 'margin'];
        }
        if ($pred_winner === $real_winner && $pred_winner !== 0) {
            return ['points' => (float)$s['points_winner'], 'code' => 'winner'];
        }
        return ['points' => 0.0, 'code' => 'miss'];
    }

    public static function recalc_match(int $match_id): int {
        global $wpdb;
        $match = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . DT_DB::table('matches') . " WHERE id=%d", $match_id));
        if (!$match || $match->score_home === null || $match->score_away === null) return 0;
        $preds = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . DT_DB::table('predictions') . " WHERE match_id=%d", $match_id));
        $changed = 0;
        foreach ($preds as $p) {
            $score = self::score((int)$p->home_score, (int)$p->away_score, (int)$match->score_home, (int)$match->score_away);
            $wpdb->update(DT_DB::table('predictions'), ['points'=>$score['points'], 'scoring_code'=>$score['code']], ['id'=>(int)$p->id], ['%f','%s'], ['%d']);
            $changed++;
        }
        return $changed;
    }

    public static function recalc_round(int $round_id): int {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM " . DT_DB::table('matches') . " WHERE round_id=%d AND score_home IS NOT NULL AND score_away IS NOT NULL", $round_id));
        $count = 0;
        foreach ($ids as $id) $count += self::recalc_match((int)$id);
        return $count;
    }

    public static function ranking(string $season, int $limit = 100, int $round_id = 0): array {
        global $wpdb;
        $pred = DT_DB::table('predictions');
        $mat = DT_DB::table('matches');
        $rnd = DT_DB::table('rounds');
        $adj = DT_DB::table('point_adjustments');
        $users = $wpdb->users;
        $settings = DT_DB::settings();

        $roundWhere = $round_id ? $wpdb->prepare(' AND r.id=%d ', $round_id) : '';
        $adjSql = $round_id ? '0' : "COALESCE((SELECT SUM(a.points) FROM $adj a WHERE a.user_id=u.ID AND a.season=" . $wpdb->prepare('%s', $season) . "),0)";
        $sql = "SELECT u.ID AS user_id, u.display_name,
                    COUNT(p.id) predictions,
                    COALESCE(SUM(p.points),0) + $adjSql AS points,
                    SUM(CASE WHEN p.scoring_code='exact' THEN 1 ELSE 0 END) exact_hits,
                    SUM(CASE WHEN p.scoring_code IN ('exact','margin','winner') THEN 1 ELSE 0 END) winner_hits
                FROM $users u
                JOIN $pred p ON p.user_id=u.ID
                JOIN $mat m ON m.id=p.match_id
                JOIN $rnd r ON r.id=m.round_id
                WHERE r.season=" . $wpdb->prepare('%s', $season) . " $roundWhere
                GROUP BY u.ID
                ORDER BY points DESC, exact_hits DESC, winner_hits DESC, u.display_name ASC
                LIMIT " . max(1, min(500, max($limit, 100)));
        $rows = $wpdb->get_results($sql, ARRAY_A);

        $perfect = [];
        $bonus = (float)($settings['perfect_round_bonus'] ?? 0);
        if ($rows && $bonus != 0.0) {
            $perfectWhere = $round_id ? $wpdb->prepare(' AND r.id=%d ', $round_id) : '';
            $perfectSql = "SELECT x.user_id, COUNT(*) perfect_rounds FROM (
                SELECT p.user_id, r.id round_id, COUNT(p.id) pred_count,
                       SUM(CASE WHEN p.scoring_code IN ('exact','margin','winner') THEN 1 ELSE 0 END) good_count,
                       (SELECT COUNT(*) FROM $mat mm WHERE mm.round_id=r.id) match_count
                FROM $pred p
                JOIN $mat m ON m.id=p.match_id
                JOIN $rnd r ON r.id=m.round_id
                WHERE r.season=" . $wpdb->prepare('%s', $season) . " $perfectWhere
                GROUP BY p.user_id, r.id
                HAVING pred_count=match_count AND good_count=match_count AND match_count>0
            ) x GROUP BY x.user_id";
            foreach ($wpdb->get_results($perfectSql, ARRAY_A) as $pr) {
                $perfect[(int)$pr['user_id']] = (int)$pr['perfect_rounds'];
            }
        }

        foreach ($rows as &$row) {
            $uid = (int)$row['user_id'];
            $row['points'] = (float)$row['points'] + (($perfect[$uid] ?? 0) * $bonus);
            $row['predictions'] = (int)$row['predictions'];
            $row['exact_hits'] = (int)$row['exact_hits'];
            $row['winner_hits'] = (int)$row['winner_hits'];
            $row['perfect_rounds'] = (int)($perfect[$uid] ?? 0);
        }
        unset($row);

        usort($rows, static function($a,$b){
            if ($a['points'] != $b['points']) return $a['points'] < $b['points'] ? 1 : -1;
            if ($a['exact_hits'] != $b['exact_hits']) return $b['exact_hits'] <=> $a['exact_hits'];
            if ($a['winner_hits'] != $b['winner_hits']) return $b['winner_hits'] <=> $a['winner_hits'];
            return strcasecmp($a['display_name'], $b['display_name']);
        });
        $rows = array_slice($rows, 0, max(1, min(500, $limit)));

        $rank = 0; $last = null; $seen = 0;
        foreach ($rows as &$row) {
            $seen++;
            $key = $row['points'] . '|' . $row['exact_hits'] . '|' . $row['winner_hits'];
            if ($key !== $last) $rank = $seen;
            $row['rank'] = $rank;
            $last = $key;
        }
        unset($row);
        return $rows;
    }
}
