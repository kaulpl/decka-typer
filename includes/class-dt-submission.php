<?php
if (!defined('ABSPATH')) exit;

/**
 * Hardened immutable round submission endpoint.
 * Registered after DT_REST and intentionally overrides only POST /submission.
 */
class DT_Submission {
    public static function register(): void {
        add_action('rest_api_init', [__CLASS__, 'route'], 20);
    }

    public static function route(): void {
        register_rest_route('decka-typer/v1', '/submission', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'save'],
            'permission_callback' => static fn() => is_user_logged_in(),
        ], true);
    }

    public static function save(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;

        $uid = get_current_user_id();
        if (!$uid) return new WP_Error('not_logged_in', 'Zaloguj się ponownie i spróbuj jeszcze raz.', ['status'=>401]);

        $body = $request->get_json_params();
        if (!is_array($body)) $body = [];
        $roundId = max(0, (int)($body['round_id'] ?? 0));
        $picks = isset($body['picks']) && is_array($body['picks']) ? $body['picks'] : [];
        if (!$roundId) return new WP_Error('invalid_round', 'Nie wybrano kolejki.', ['status'=>422]);

        $roundTable = DT_DB::table('rounds');
        $matchTable = DT_DB::table('matches');
        $subTable = DT_DB::table('round_submissions');
        $predTable = DT_DB::table('predictions');

        foreach ([$roundTable, $matchTable, $subTable, $predTable] as $table) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
            if ((string)$exists !== (string)$table) {
                DT_Logger::log('submission_schema_missing', 'Brak wymaganej tabeli podczas zapisu kuponu.', ['table'=>$table], 'error', $uid);
                return new WP_Error('schema_missing', 'Wtyczka wymaga aktualizacji bazy danych. Wejdź ponownie do panelu Decka Typer i spróbuj jeszcze raz.', ['status'=>500]);
            }
        }

        $round = $wpdb->get_row($wpdb->prepare("SELECT * FROM $roundTable WHERE id=%d", $roundId), ARRAY_A);
        if (!$round) return new WP_Error('not_found', 'Nie znaleziono kolejki.', ['status'=>404]);
        if (!self::round_open($round)) return new WP_Error('round_closed', 'Typowanie tej kolejki jest zamknięte.', ['status'=>409]);

        $already = $wpdb->get_var($wpdb->prepare("SELECT id FROM $subTable WHERE user_id=%d AND round_id=%d LIMIT 1", $uid, $roundId));
        if ($already) return new WP_Error('already_submitted', 'Ten kupon został już zapisany i nie można go edytować.', ['status'=>409]);

        $matches = $wpdb->get_results($wpdb->prepare(
            "SELECT id,home_team_id,away_team_id FROM $matchTable WHERE round_id=%d ORDER BY id",
            $roundId
        ), ARRAY_A);
        if (!$matches) return new WP_Error('empty_round', 'Ta kolejka nie ma meczów.', ['status'=>422]);

        $matchMap = [];
        foreach ($matches as $match) {
            $matchMap[(int)$match['id']] = [(int)$match['home_team_id'], (int)$match['away_team_id']];
        }

        $clean = [];
        foreach ($picks as $pick) {
            if (!is_array($pick)) continue;
            $matchId = (int)($pick['match_id'] ?? 0);
            $teamId = (int)($pick['team_id'] ?? 0);
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

        // Last server-side lock check immediately before the immutable transaction.
        $round = $wpdb->get_row($wpdb->prepare("SELECT * FROM $roundTable WHERE id=%d", $roundId), ARRAY_A);
        if (!$round || !self::round_open($round)) {
            return new WP_Error('round_closed', 'Czas na typowanie właśnie się zakończył.', ['status'=>409]);
        }

        $now = current_time('mysql');
        $wpdb->last_error = '';

        try {
            if ($wpdb->query('START TRANSACTION') === false) {
                throw new RuntimeException($wpdb->last_error ?: 'Nie można rozpocząć transakcji zapisu.');
            }

            $sql = $wpdb->prepare(
                "INSERT INTO $subTable (user_id,round_id,prediction_count,submitted_at) VALUES (%d,%d,%d,%s)",
                $uid, $roundId, count($clean), $now
            );
            if ($wpdb->query($sql) !== 1) {
                throw new RuntimeException($wpdb->last_error ?: 'Nie można utworzyć kuponu.');
            }

            foreach ($clean as $matchId => $teamId) {
                // ON DUPLICATE KEY is used only for legacy partial 0.1.x picks.
                // Once round_submissions exists, this endpoint cannot be used again by that user/round.
                $sql = $wpdb->prepare(
                    "INSERT INTO $predTable
                        (user_id,match_id,selected_team_id,home_score,away_score,points,scoring_code,submitted_at,updated_at)
                     VALUES (%d,%d,%d,NULL,NULL,0,NULL,%s,%s)
                     ON DUPLICATE KEY UPDATE
                        selected_team_id=VALUES(selected_team_id),
                        home_score=NULL,
                        away_score=NULL,
                        points=0,
                        scoring_code=NULL,
                        updated_at=VALUES(updated_at)",
                    $uid, $matchId, $teamId, $now, $now
                );
                if ($wpdb->query($sql) === false) {
                    throw new RuntimeException($wpdb->last_error ?: 'Nie udało się zapisać typu dla meczu ' . $matchId . '.');
                }
            }

            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException($wpdb->last_error ?: 'Nie udało się zatwierdzić kuponu.');
            }
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            DT_Logger::log('submission_error', $e->getMessage(), [
                'round_id'=>$roundId,
                'db_error'=>$wpdb->last_error,
            ], 'error', $uid);
            return new WP_Error('save_failed', 'Nie udało się zapisać kuponu. Szczegóły błędu zapisano w Historii Typera.', ['status'=>500]);
        }

        DT_Logger::log('round_submitted', 'Zapisano nieedytowalny kupon kolejki.', [
            'round_id'=>$roundId,
            'prediction_count'=>count($clean),
        ], 'notice', $uid);

        return new WP_REST_Response([
            'ok'=>true,
            'round_id'=>$roundId,
            'submitted_at'=>$now,
            'prediction_count'=>count($clean),
        ], 201);
    }

    private static function round_open(array $round): bool {
        if (($round['status'] ?? '') !== 'open' || empty($round['closes_at'])) return false;
        return (string)$round['closes_at'] > current_time('mysql');
    }
}
