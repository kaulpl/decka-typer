<?php
if (!defined('ABSPATH')) exit;

/** One AI lifeline per user and round, bound to one selected match. */
class DT_Artur_AI {
    private const ROUTE = 'decka-typer/v1';

    public static function register(): void {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes(): void {
        register_rest_route(self::ROUTE, '/artur-ai/status/(?P<round_id>\d+)', [
            'methods'=>'GET', 'callback'=>[__CLASS__, 'status'],
            'permission_callback'=>static fn(): bool=>is_user_logged_in(),
        ]);
        register_rest_route(self::ROUTE, '/artur-ai/ask', [
            'methods'=>'POST', 'callback'=>[__CLASS__, 'ask'],
            'permission_callback'=>static fn(): bool=>is_user_logged_in(),
        ]);
    }

    public static function enabled(): bool {
        $settings = DT_DB::settings();
        return !empty($settings['artur_ai_enabled']) && defined('DT_GEMINI_API_KEY') && trim((string)DT_GEMINI_API_KEY) !== '';
    }

    public static function status(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $roundId = (int)$request['round_id'];
        $round = self::round($roundId);
        if (!$round) return new WP_Error('not_found', 'Nie znaleziono kolejki.', ['status'=>404]);
        if (self::test_mode()) {
            return new WP_REST_Response([
                'enabled'=>self::enabled(), 'available'=>self::enabled(), 'unlimited'=>true,
                'limit'=>self::limit(), 'used'=>0, 'remaining'=>null, 'match_id'=>null, 'history'=>[],
            ]);
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT match_id,question_no,question,answer,created_at FROM '.DT_DB::table('artur_ai').' WHERE user_id=%d AND round_id=%d ORDER BY question_no',
            get_current_user_id(), $roundId
        ), ARRAY_A);
        $limit = self::limit();
        return new WP_REST_Response([
            'enabled'=>self::enabled(),
            'available'=>self::enabled() && DT_REST::round_accepts_picks($round) && !self::submitted($roundId),
            'limit'=>$limit,
            'used'=>count($rows),
            'remaining'=>max(0, $limit-count($rows)),
            'match_id'=>$rows ? (int)$rows[0]['match_id'] : null,
            'history'=>array_map(static fn(array $row): array=>[
                'question_no'=>(int)$row['question_no'],
                'question'=>(string)$row['question'],
                'answer'=>(string)$row['answer'],
                'created_at'=>(string)$row['created_at'],
            ], $rows),
        ]);
    }

    public static function ask(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        if (!self::enabled()) return new WP_Error('disabled', 'Koło ratunkowe Artura jest obecnie wyłączone.', ['status'=>503]);
        $data = (array)$request->get_json_params();
        $roundId = max(0, (int)($data['round_id'] ?? 0));
        $matchId = max(0, (int)($data['match_id'] ?? 0));
        $question = trim(sanitize_textarea_field(wp_unslash((string)($data['question'] ?? ''))));
        if (mb_strlen($question) < 5 || mb_strlen($question) > 300) {
            return new WP_Error('invalid_question', 'Pytanie musi mieć od 5 do 300 znaków.', ['status'=>422]);
        }
        $round = self::round($roundId);
        $testMode = self::test_mode();
        if (!$round || (!$testMode && (!DT_REST::round_accepts_picks($round) || self::submitted($roundId)))) {
            return new WP_Error('unavailable', 'Koło ratunkowe działa tylko przed zapisaniem kuponu otwartej kolejki.', ['status'=>409]);
        }
        $match = self::match($matchId, $roundId);
        if (!$match) return new WP_Error('invalid_match', 'Wybrany mecz nie należy do tej kolejki.', ['status'=>422]);

        $table = DT_DB::table('artur_ai');
        $uid = get_current_user_id();
        if ($testMode) {
            $answer = self::gemini($question, $match, $round);
            if (is_wp_error($answer)) return $answer;
            DT_Logger::log('artur_ai_test_question', 'Testowe pytanie do Artura bez limitu i trwałego zapisu.', [
                'round_id'=>$roundId, 'match_id'=>$matchId,
            ], 'notice', $uid);
            return new WP_REST_Response([
                'ok'=>true, 'unlimited'=>true, 'question_no'=>0, 'question'=>$question,
                'answer'=>$answer, 'remaining'=>null, 'match_id'=>$matchId,
            ]);
        }
        $existing = $wpdb->get_results($wpdb->prepare(
            "SELECT match_id,question_no FROM $table WHERE user_id=%d AND round_id=%d ORDER BY question_no FOR UPDATE",
            $uid, $roundId
        ), ARRAY_A);
        if ($existing && (int)$existing[0]['match_id'] !== $matchId) {
            return new WP_Error('lifeline_bound', 'Koło ratunkowe tej kolejki zostało już przypisane do innego meczu.', ['status'=>409]);
        }
        $number = count($existing)+1;
        if ($number > self::limit()) return new WP_Error('limit_reached', 'Wykorzystano już wszystkie pytania Artura w tej kolejce.', ['status'=>429]);

        $answer = self::gemini($question, $match, $round);
        if (is_wp_error($answer)) return $answer;
        $inserted = $wpdb->insert($table, [
            'user_id'=>$uid, 'round_id'=>$roundId, 'match_id'=>$matchId, 'question_no'=>$number,
            'question'=>$question, 'answer'=>$answer, 'model'=>self::model(), 'created_at'=>current_time('mysql'),
        ], ['%d','%d','%d','%d','%s','%s','%s','%s']);
        if (!$inserted) return new WP_Error('save_failed', 'Nie udało się zapisać odpowiedzi. Spróbuj ponownie.', ['status'=>409]);
        DT_Logger::log('artur_ai_question', 'Użytkownik wykorzystał pytanie Koła ratunkowego Artura.', [
            'round_id'=>$roundId, 'match_id'=>$matchId, 'question_no'=>$number,
        ], 'notice', $uid);
        return new WP_REST_Response([
            'ok'=>true, 'question_no'=>$number, 'question'=>$question, 'answer'=>$answer,
            'remaining'=>max(0, self::limit()-$number), 'match_id'=>$matchId,
        ]);
    }

    private static function gemini(string $question, array $match, array $round): string|WP_Error {
        $settings = DT_DB::settings();
        $instruction = trim((string)($settings['artur_ai_instruction'] ?? '')) ?: DT_DB::default_artur_ai_instruction();
        $context = self::context($match, $round);
        $body = [
            'system_instruction'=>['parts'=>[['text'=>$instruction]]],
            'contents'=>[['role'=>'user','parts'=>[['text'=>"DANE MECZU:\n$context\n\nPYTANIE UŻYTKOWNIKA:\n$question"]]]],
            'generationConfig'=>['temperature'=>0.72,'maxOutputTokens'=>260,'topP'=>0.9],
        ];
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode(self::model()).':generateContent';
        $response = wp_remote_post($url, [
            'timeout'=>25,
            'headers'=>['Content-Type'=>'application/json','x-goog-api-key'=>(string)DT_GEMINI_API_KEY],
            'body'=>wp_json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
        if (is_wp_error($response)) {
            DT_Logger::log('artur_ai_error', $response->get_error_message(), [], 'error', get_current_user_id());
            return new WP_Error('ai_unavailable', 'Artur chwilowo studiuje tablicę taktyczną. Spróbuj ponownie za moment.', ['status'=>503]);
        }
        $code = wp_remote_retrieve_response_code($response);
        $payload = json_decode(wp_remote_retrieve_body($response), true);
        $text = trim((string)($payload['candidates'][0]['content']['parts'][0]['text'] ?? ''));
        if ($code < 200 || $code >= 300 || $text === '') {
            $message = sanitize_text_field((string)($payload['error']['message'] ?? 'Nieprawidłowa odpowiedź Gemini.'));
            DT_Logger::log('artur_ai_error', $message, ['http_code'=>$code], 'error', get_current_user_id());
            return new WP_Error('ai_unavailable', 'Artur chwilowo studiuje tablicę taktyczną. Spróbuj ponownie za moment.', ['status'=>503]);
        }
        return mb_substr(wp_strip_all_tags($text), 0, 900);
    }

    private static function context(array $match, array $round): string {
        $home = self::team_form((int)$match['home_team_id'], (string)$match['starts_at'], 'home');
        $away = self::team_form((int)$match['away_team_id'], (string)$match['starts_at'], 'away');
        $h2h = self::head_to_head((int)$match['home_team_id'], (int)$match['away_team_id'], (string)$match['starts_at']);
        return implode("\n", [
            'Rozgrywki: '.(string)$round['league_key'].((string)$round['group_key'] !== '' ? ', grupa '.(string)$round['group_key'] : '').', kolejka '.(int)$round['round_no'].', sezon '.(string)$round['season'],
            'Mecz: '.(string)$match['home_name'].' (gospodarz) – '.(string)$match['away_name'].' (gość)',
            'Termin: '.(string)$match['starts_at'],
            'Gospodarz: '.self::form_text($home),
            'Gość: '.self::form_text($away),
            'Ostatnie mecze bezpośrednie: '.($h2h ?: 'brak danych'),
            'Zakaz: nie dopowiadaj informacji, których nie ma powyżej.',
        ]);
    }

    private static function team_form(int $teamId, string $before, string $venue): array {
        global $wpdb;
        $matches = DT_DB::table('matches');
        $all = $wpdb->get_results($wpdb->prepare(
            "SELECT home_team_id,away_team_id,score_home,score_away,starts_at FROM $matches
             WHERE starts_at<%s AND score_home IS NOT NULL AND score_away IS NOT NULL
               AND (home_team_id=%d OR away_team_id=%d) ORDER BY starts_at DESC,id DESC LIMIT 5",
            $before, $teamId, $teamId
        ), ARRAY_A);
        $venueColumn = $venue === 'home' ? 'home_team_id' : 'away_team_id';
        $venueRows = $wpdb->get_results($wpdb->prepare(
            "SELECT home_team_id,away_team_id,score_home,score_away FROM $matches
             WHERE starts_at<%s AND score_home IS NOT NULL AND score_away IS NOT NULL AND $venueColumn=%d
             ORDER BY starts_at DESC,id DESC LIMIT 3",
            $before, $teamId
        ), ARRAY_A);
        $wins=0;$losses=0;$scored=[];$allowed=[];$sequence=[];
        foreach ($all as $row) {
            $home=(int)$row['home_team_id']===$teamId;$for=$home?(int)$row['score_home']:(int)$row['score_away'];$against=$home?(int)$row['score_away']:(int)$row['score_home'];
            $for>$against?$wins++:$losses++;$sequence[]=$for>$against?'W':'P';
        }
        foreach ($venueRows as $row) {$home=(int)$row['home_team_id']===$teamId;$scored[]=$home?(int)$row['score_home']:(int)$row['score_away'];$allowed[]=$home?(int)$row['score_away']:(int)$row['score_home'];}
        return ['wins'=>$wins,'losses'=>$losses,'sequence'=>$sequence,'scored'=>$scored,'allowed'=>$allowed];
    }

    private static function form_text(array $form): string {
        $avg = static fn(array $values): string=>$values ? number_format(array_sum($values)/count($values), 1, ',', '') : 'brak danych';
        return 'ostatnie 5: '.($form['sequence'] ? implode('-', $form['sequence']) : 'brak danych').'; bilans '.$form['wins'].'-'.$form['losses'].'; ostatnie mecze w odpowiednim miejscu: średnio zdobyte '.$avg($form['scored']).', stracone '.$avg($form['allowed']).'.';
    }

    private static function head_to_head(int $homeId, int $awayId, string $before): string {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT home_team_id,score_home,score_away,starts_at FROM '.DT_DB::table('matches').' WHERE starts_at<%s AND score_home IS NOT NULL AND score_away IS NOT NULL AND ((home_team_id=%d AND away_team_id=%d) OR (home_team_id=%d AND away_team_id=%d)) ORDER BY starts_at DESC,id DESC LIMIT 3',
            $before,$homeId,$awayId,$awayId,$homeId
        ), ARRAY_A);
        if (!$rows) return '';
        return implode('; ', array_map(static fn(array $row): string=>substr((string)$row['starts_at'],0,10).' '.((int)$row['home_team_id']===$homeId?'gospodarz dzisiejszy u siebie':'gospodarz dzisiejszy na wyjeździe').' '.(int)$row['score_home'].':'.(int)$row['score_away'], $rows));
    }

    private static function round(int $id): ?array { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM '.DT_DB::table('rounds').' WHERE id=%d',$id), ARRAY_A) ?: null; }
    private static function match(int $id, int $roundId): ?array { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT m.*,h.name home_name,a.name away_name FROM '.DT_DB::table('matches').' m JOIN '.DT_DB::table('teams').' h ON h.id=m.home_team_id JOIN '.DT_DB::table('teams').' a ON a.id=m.away_team_id WHERE m.id=%d AND m.round_id=%d',$id,$roundId), ARRAY_A) ?: null; }
    private static function submitted(int $roundId): bool { global $wpdb; return (bool)$wpdb->get_var($wpdb->prepare('SELECT id FROM '.DT_DB::table('round_submissions').' WHERE user_id=%d AND round_id=%d',get_current_user_id(),$roundId)); }
    private static function model(): string { $model=sanitize_text_field((string)(DT_DB::settings()['artur_ai_model']??'')); return $model ?: 'gemini-2.5-flash-lite'; }
    private static function limit(): int { return max(1,min(5,(int)(DT_DB::settings()['artur_ai_questions']??3))); }
    private static function test_mode(): bool { return (string)(DT_DB::settings()['site_mode'] ?? 'test') === 'test'; }
}
