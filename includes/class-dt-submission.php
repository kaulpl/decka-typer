<?php
if (!defined('ABSPATH')) exit;

/**
 * Immutable round submission service.
 *
 * 0.2.4 uses authenticated admin-ajax.php as the primary frontend transport.
 * The REST route stays available as a compatibility fallback, while both
 * transports use exactly the same validation and database write service.
 */
class DT_Submission {
    private const AJAX_ACTION = 'dt_save_submission';
    private const AJAX_NONCE = 'dt_save_submission_nonce';

    public static function register(): void {
        add_action('rest_api_init', [__CLASS__, 'route'], 20);
        add_action('wp_ajax_' . self::AJAX_ACTION, [__CLASS__, 'ajax_save']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_bridge'], 8);
    }

    public static function route(): void {
        register_rest_route('decka-typer/v1', '/submission', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'save'],
            'permission_callback' => static fn() => is_user_logged_in(),
        ], true);
    }

    public static function enqueue_bridge(): void {
        if (!class_exists('DT_Frontend') || !DT_Frontend::is_typer_page()) return;

        wp_enqueue_script(
            'dt-submission-ajax',
            DT_URL . 'assets/js/submission-ajax.js',
            [],
            DT_VERSION,
            true
        );
        wp_localize_script('dt-submission-ajax', 'DeckaTyperSubmission', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => self::AJAX_ACTION,
            'nonce' => wp_create_nonce(self::AJAX_NONCE),
        ]);
    }

    public static function save(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $body = $request->get_json_params();
        if (!is_array($body)) $body = [];
        return self::save_payload($body, get_current_user_id());
    }

    public static function ajax_save(): void {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message'=>'Sesja wygasła. Zaloguj się ponownie.'], 401);
        }

        if (!check_ajax_referer(self::AJAX_NONCE, 'nonce', false)) {
            wp_send_json_error(['message'=>'Sesja bezpieczeństwa wygasła. Odśwież stronę i spróbuj ponownie.'], 403);
        }

        $payloadRaw = isset($_POST['payload']) ? wp_unslash((string) $_POST['payload']) : '';
        $body = json_decode($payloadRaw, true);
        if (!is_array($body)) {
            wp_send_json_error(['message'=>'Nieprawidłowe dane kuponu. Odśwież stronę i spróbuj ponownie.'], 422);
        }

        $result = self::save_payload($body, get_current_user_id());

        if (is_wp_error($result)) {
            $errorData = $result->get_error_data();
            $status = is_array($errorData) ? (int)($errorData['status'] ?? 500) : 500;
            if ($status < 400 || $status > 599) $status = 500;
            wp_send_json_error([
                'code'=>$result->get_error_code(),
                'message'=>$result->get_error_message(),
            ], $status);
        }

        $data = $result instanceof WP_REST_Response ? $result->get_data() : $result;
        wp_send_json_success(is_array($data) ? $data : ['ok'=>true], 201);
    }

    private static function save_payload(array $body, int $uid): WP_REST_Response|WP_Error {
        global $wpdb;

        if (!$uid) return new WP_Error('not_logged_in', 'Zaloguj się ponownie i spróbuj jeszcze raz.', ['status'=>401]);

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
                return new WP_Error('schema_missing', 'Wtyczka wymaga aktualizacji bazy danych. Otwórz panel Decka Typer i odśwież stronę.', ['status'=>500]);
            }
        }

        $round = $wpdb->get_row($wpdb->prepare("SELECT * FROM $roundTable WHERE id=%d", $roundId), ARRAY_A);
        if (!$round) return new WP_Error('not_found', 'Nie znaleziono kolejki.', ['status'=>404]);
        if (!self::round_open($round)) return new WP_Error('round_closed', 'Typowanie tej kolejki jest zamknięte.', ['status'=>409]);

        $already = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $subTable WHERE user_id=%d AND round_id=%d LIMIT 1",
            $uid,
            $roundId
        ));
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

        $round = $wpdb->get_row($wpdb->prepare("SELECT * FROM $roundTable WHERE id=%d", $roundId), ARRAY_A);
        if (!$round || !self::round_open($round)) {
            return new WP_Error('round_closed', 'Czas na typowanie właśnie się zakończył.', ['status'=>409]);
        }

        $now = current_time('mysql');
        $wpdb->last_error = '';
        $transactionStarted = false;

        try {
            if ($wpdb->query('START TRANSACTION') === false) {
                throw new RuntimeException($wpdb->last_error ?: 'Nie można rozpocząć transakcji zapisu.');
            }
            $transactionStarted = true;

            $sql = $wpdb->prepare(
                "INSERT INTO $subTable (user_id,round_id,prediction_count,submitted_at) VALUES (%d,%d,%d,%s)",
                $uid,
                $roundId,
                count($clean),
                $now
            );
            if ($wpdb->query($sql) !== 1) {
                throw new RuntimeException($wpdb->last_error ?: 'Nie można utworzyć kuponu.');
            }

            foreach ($clean as $matchId => $teamId) {
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
                    $uid,
                    $matchId,
                    $teamId,
                    $now,
                    $now
                );
                if ($wpdb->query($sql) === false) {
                    throw new RuntimeException($wpdb->last_error ?: 'Nie udało się zapisać typu dla meczu ' . $matchId . '.');
                }
            }

            if ($wpdb->query('COMMIT') === false) {
                throw new RuntimeException($wpdb->last_error ?: 'Nie udało się zatwierdzić kuponu.');
            }
            $transactionStarted = false;
        } catch (Throwable $e) {
            if ($transactionStarted) $wpdb->query('ROLLBACK');
            DT_Logger::log('submission_error', $e->getMessage(), [
                'round_id'=>$roundId,
                'db_error'=>$wpdb->last_error,
                'transport'=>wp_doing_ajax() ? 'ajax' : 'rest',
            ], 'error', $uid);
            return new WP_Error('save_failed', 'Nie udało się zapisać kuponu. Szczegóły zapisano w Historii Typera.', ['status'=>500]);
        }

        DT_Logger::log('round_submitted', 'Zapisano nieedytowalny kupon kolejki.', [
            'round_id'=>$roundId,
            'prediction_count'=>count($clean),
            'transport'=>wp_doing_ajax() ? 'ajax' : 'rest',
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
