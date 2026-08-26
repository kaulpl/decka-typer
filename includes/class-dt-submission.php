<?php
if (!defined('ABSPATH')) exit;

/**
 * Immutable winner-only round submission service.
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
        wp_enqueue_script('dt-submission-ajax', DT_URL . 'assets/js/submission-ajax.js', [], DT_VERSION, true);
        wp_localize_script('dt-submission-ajax', 'DeckaTyperSubmission', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => self::AJAX_ACTION,
            'nonce' => wp_create_nonce(self::AJAX_NONCE),
        ]);
    }

    public static function save(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $body = $request->get_json_params();
        if (!is_array($body)) $body = [];
        return self::save_payload($body, get_current_user_id(), 'rest');
    }

    public static function ajax_save(): void {
        $requestId = 'DT-' . strtoupper(substr(str_replace('-', '', wp_generate_uuid4()), 0, 8));
        $finished = false;
        $initialBufferLevel = ob_get_level();
        ob_start();

        register_shutdown_function(static function () use (&$finished, $requestId, $initialBufferLevel): void {
            if ($finished) return;
            $error = error_get_last();
            if (!$error || !in_array((int)$error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
            while (ob_get_level() > $initialBufferLevel) @ob_end_clean();
            if (!headers_sent()) {
                status_header(500);
                nocache_headers();
                header('Content-Type: application/json; charset=' . get_option('blog_charset'));
            }
            echo wp_json_encode([
                'success'=>false,
                'data'=>[
                    'code'=>'fatal_error',
                    'request_id'=>$requestId,
                    'message'=>'Wystąpił błąd PHP podczas zapisu kuponu. Identyfikator: ' . $requestId . '.',
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        });

        try {
            self::ajax_save_inner($requestId, $finished, $initialBufferLevel);
        } catch (Throwable $e) {
            self::safe_log('submission_fatal_caught', $e->getMessage(), [
                'request_id'=>$requestId,
                'type'=>get_class($e),
                'file'=>basename($e->getFile()),
                'line'=>$e->getLine(),
            ], get_current_user_id());
            while (ob_get_level() > $initialBufferLevel) @ob_end_clean();
            $finished = true;
            wp_send_json_error([
                'code'=>'submission_exception',
                'request_id'=>$requestId,
                'message'=>'Nie udało się zapisać kuponu. Identyfikator błędu: ' . $requestId . '.',
            ], 500);
        }
    }

    private static function ajax_save_inner(string $requestId, bool &$finished, int $initialBufferLevel): void {
        if (!is_user_logged_in()) {
            self::ajax_error('not_logged_in', 'Sesja wygasła. Zaloguj się ponownie.', 401, $requestId, $finished, $initialBufferLevel);
        }
        if (!check_ajax_referer(self::AJAX_NONCE, 'nonce', false)) {
            self::ajax_error('bad_nonce', 'Sesja bezpieczeństwa wygasła. Odśwież stronę i spróbuj ponownie.', 403, $requestId, $finished, $initialBufferLevel);
        }

        $roundId = isset($_POST['round_id']) ? absint($_POST['round_id']) : 0;
        $compact = isset($_POST['picks']) ? sanitize_text_field(wp_unslash((string)$_POST['picks'])) : '';
        $picks = self::parse_compact_picks($compact);
        if (!$roundId || !$picks) {
            self::ajax_error('invalid_payload', 'Nieprawidłowe dane kuponu. Odśwież stronę i spróbuj ponownie.', 422, $requestId, $finished, $initialBufferLevel);
        }

        $result = self::save_payload(['round_id'=>$roundId, 'picks'=>$picks], get_current_user_id(), 'ajax');
        if (is_wp_error($result)) {
            $errorData = $result->get_error_data();
            $status = is_array($errorData) ? (int)($errorData['status'] ?? 500) : 500;
            if ($status < 400 || $status > 599) $status = 500;
            self::ajax_error($result->get_error_code(), $result->get_error_message(), $status, $requestId, $finished, $initialBufferLevel);
        }

        $data = $result instanceof WP_REST_Response ? $result->get_data() : $result;
        while (ob_get_level() > $initialBufferLevel) @ob_end_clean();
        $finished = true;
        wp_send_json_success(is_array($data) ? $data : ['ok'=>true], 200);
    }

    private static function ajax_error(string $code, string $message, int $status, string $requestId, bool &$finished, int $initialBufferLevel): void {
        while (ob_get_level() > $initialBufferLevel) @ob_end_clean();
        $finished = true;
        wp_send_json_error(['code'=>$code, 'request_id'=>$requestId, 'message'=>$message], $status);
    }

    private static function parse_compact_picks(string $compact): array {
        if ($compact === '') return [];
        $result = [];
        foreach (explode(',', $compact) as $pair) {
            if (!preg_match('/^(\d+):(\d+)$/', trim($pair), $m)) return [];
            $matchId = (int)$m[1];
            $teamId = (int)$m[2];
            if ($matchId < 1 || $teamId < 1) return [];
            $result[] = ['match_id'=>$matchId, 'team_id'=>$teamId];
        }
        return $result;
    }

    private static function save_payload(array $body, int $uid, string $transport): WP_REST_Response|WP_Error {
        global $wpdb;
        if (!$uid) return new WP_Error('not_logged_in', 'Zaloguj się ponownie i spróbuj jeszcze raz.', ['status'=>401]);

        $roundId = max(0, (int)($body['round_id'] ?? 0));
        $picks = isset($body['picks']) && is_array($body['picks']) ? $body['picks'] : [];
        if (!$roundId) return new WP_Error('invalid_round', 'Nie wybrano kolejki.', ['status'=>422]);

        $roundTable = DT_DB::table('rounds');
        $matchTable = DT_DB::table('matches');
        $subTable = DT_DB::table('round_submissions');
        $predTable = DT_DB::table('predictions');

        $round = $wpdb->get_row($wpdb->prepare("SELECT * FROM $roundTable WHERE id=%d", $roundId), ARRAY_A);
        if (!$round) return new WP_Error('not_found', 'Nie znaleziono kolejki.', ['status'=>404]);
        if (($round['status'] ?? '') !== 'open') return new WP_Error('round_closed', 'Typowanie tej kolejki jest zamknięte.', ['status'=>409]);

        $matches = $wpdb->get_results($wpdb->prepare(
            "SELECT id,home_team_id,away_team_id,starts_at,start_time_known FROM $matchTable WHERE round_id=%d ORDER BY id", $roundId
        ), ARRAY_A);
        if (!$matches) return new WP_Error('empty_round', 'Ta kolejka nie ma meczów.', ['status'=>422]);

        $matchMap = [];
        foreach ($matches as $match) {
            $matchMap[(int)$match['id']] = $match;
        }

        $clean = [];
        foreach ($picks as $pick) {
            if (!is_array($pick)) continue;
            $matchId = (int)($pick['match_id'] ?? 0);
            $teamId = (int)($pick['team_id'] ?? 0);
            if (!$matchId || !$teamId || !isset($matchMap[$matchId])) {
                return new WP_Error('invalid_pick', 'Jeden z typów jest nieprawidłowy.', ['status'=>422]);
            }
            if (!in_array($teamId, [(int)$matchMap[$matchId]['home_team_id'],(int)$matchMap[$matchId]['away_team_id']], true)) {
                return new WP_Error('invalid_team', 'Wybrana drużyna nie gra w tym meczu.', ['status'=>422]);
            }
            if (!(int)$matchMap[$matchId]['start_time_known'] || empty($matchMap[$matchId]['starts_at'])) {
                return new WP_Error('unknown_start', 'Termin tego meczu nie jest jeszcze potwierdzony.', ['status'=>409]);
            }
            if ((string)$matchMap[$matchId]['starts_at'] <= current_time('mysql')) {
                return new WP_Error('match_started', 'Ten mecz już się rozpoczął. Typ nie został zapisany.', ['status'=>409]);
            }
            $clean[$matchId] = $teamId;
        }
        if (!$clean) return new WP_Error('empty_picks', 'Wybierz co najmniej jeden mecz do zapisania.', ['status'=>422]);

        $now = current_time('mysql');

        // Winner-only persistence. No predicted score fields exist in this model.
        foreach ($clean as $matchId => $teamId) {
            $existing=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $predTable WHERE user_id=%d AND match_id=%d",$uid,$matchId));
            if ($existing) continue;
            $sql = $wpdb->prepare(
                "INSERT INTO $predTable
                    (user_id,match_id,selected_team_id,points,scoring_code,submitted_at,updated_at)
                 VALUES (%d,%d,%d,0,NULL,%s,%s)
                 ON DUPLICATE KEY UPDATE id=id",
                $uid, $matchId, $teamId, $now, $now
            );
            if ($wpdb->query($sql) === false) {
                self::safe_log('submission_db_error', 'Nie udało się zapisać typu zwycięzcy.', [
                    'round_id'=>$roundId,
                    'match_id'=>$matchId,
                    'db_error'=>$wpdb->last_error,
                    'transport'=>$transport,
                ], $uid);
                return new WP_Error('save_pick_failed', 'Nie udało się zapisać jednego z typów. Spróbuj ponownie.', ['status'=>500]);
            }
        }

        $savedCount=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $predTable p JOIN $matchTable m ON m.id=p.match_id WHERE p.user_id=%d AND m.round_id=%d",$uid,$roundId));
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $subTable (user_id,round_id,prediction_count,submitted_at) VALUES (%d,%d,%d,%s)
             ON DUPLICATE KEY UPDATE prediction_count=VALUES(prediction_count),submitted_at=VALUES(submitted_at)",
            $uid,$roundId,$savedCount,$now
        ));

        self::safe_log('matches_submitted', 'Zapisano wybrane mecze z kuponu kolejki.', [
            'round_id'=>$roundId,
            'prediction_count'=>$savedCount,
            'saved_now'=>count($clean),
            'transport'=>$transport,
        ], $uid, 'notice');

        return new WP_REST_Response([
            'ok'=>true,
            'round_id'=>$roundId,
            'submitted_at'=>$now,
            'prediction_count'=>$savedCount,
            'saved_now'=>count($clean),
        ], 200);
    }

    private static function safe_log(string $event, string $message, array $context, int $uid, string $level = 'error'): void {
        try {
            if (class_exists('DT_Logger')) DT_Logger::log($event, $message, $context, $level, $uid ?: null);
        } catch (Throwable $ignored) {
        }
    }

    private static function round_open(array $round): bool {
        if (($round['status'] ?? '') !== 'open' || empty($round['closes_at'])) return false;
        return (string)$round['closes_at'] > current_time('mysql');
    }
}
