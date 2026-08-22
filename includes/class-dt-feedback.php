<?php
if (!defined('ABSPATH')) exit;

class DT_Feedback {
    public static function register(): void {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes(): void {
        register_rest_route('decka-typer/v1', '/feedback', [
            'methods'=>'POST',
            'callback'=>[__CLASS__, 'submit'],
            'permission_callback'=>static fn(): bool=>is_user_logged_in(),
        ]);
    }

    public static function submit(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $uid = get_current_user_id();
        $user = get_userdata($uid);
        if (!$user) return new WP_Error('not_logged_in', 'Zaloguj się, aby wysłać zgłoszenie.', ['status'=>401]);
        $email = sanitize_email((string)$user->user_email);
        if (!is_email($email)) return new WP_Error('missing_email', 'Twoje konto nie ma prawidłowego adresu e-mail.', ['status'=>422]);

        $rateKey = 'dt_feedback_rate_' . $uid;
        if (get_transient($rateKey)) {
            return new WP_Error('too_many_requests', 'Odczekaj chwilę przed wysłaniem kolejnego zgłoszenia.', ['status'=>429]);
        }

        $body = (array)$request->get_json_params();
        $message = trim(sanitize_textarea_field((string)($body['message'] ?? '')));
        $length = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
        if ($length < 10) return new WP_Error('message_too_short', 'Opisz problem w co najmniej 10 znakach.', ['status'=>422]);
        if ($length > 2000) return new WP_Error('message_too_long', 'Opis problemu może mieć maksymalnie 2000 znaków.', ['status'=>422]);

        $pageUrl = esc_url_raw((string)($body['page_url'] ?? ''));
        $homeHost = strtolower((string)wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ($pageUrl !== '' && strtolower((string)wp_parse_url($pageUrl, PHP_URL_HOST)) !== $homeHost) $pageUrl = '';
        $now = current_time('mysql');
        $ok = $wpdb->insert(DT_DB::table('feedback'), [
            'user_id'=>$uid,
            'email'=>$email,
            'message'=>$message,
            'page_url'=>$pageUrl,
            'status'=>'new',
            'created_at'=>$now,
            'updated_at'=>$now,
        ], ['%d','%s','%s','%s','%s','%s','%s']);
        if (!$ok) return new WP_Error('save_failed', 'Nie udało się zapisać zgłoszenia. Spróbuj ponownie.', ['status'=>500]);

        set_transient($rateKey, 1, MINUTE_IN_SECONDS);
        DT_Logger::log('feedback_submitted', 'Użytkownik wysłał zgłoszenie problemu.', ['feedback_id'=>(int)$wpdb->insert_id], 'notice', $uid);
        return new WP_REST_Response(['ok'=>true, 'message'=>'Dziękujemy. Zgłoszenie zostało zapisane.'], 201);
    }

    public static function render(): void {
        if (!is_user_logged_in()) return;
        echo '<button type="button" class="dt-feedback-trigger" id="dt-feedback-trigger" aria-haspopup="dialog"><span aria-hidden="true">!</span>Zgłoś problem</button>';
        echo '<dialog class="dt-feedback-modal" id="dt-feedback-modal" aria-labelledby="dt-feedback-title"><form method="dialog" class="dt-feedback-card" id="dt-feedback-form">';
        echo '<button type="button" class="dt-feedback-close" data-feedback-close aria-label="Zamknij">×</button>';
        echo '<div class="dt-feedback-icon" aria-hidden="true">!</div><p class="dt-feedback-kicker">POMÓŻ NAM ULEPSZAĆ SERWIS</p><h2 id="dt-feedback-title">Zgłoś problem</h2>';
        echo '<p class="dt-feedback-copy">Krótko opisz, co nie działa lub co powinniśmy poprawić. Do zgłoszenia automatycznie przypiszemy Twój adres e-mail.</p>';
        echo '<label for="dt-feedback-message">Opis problemu</label><textarea id="dt-feedback-message" name="message" minlength="10" maxlength="2000" rows="6" required placeholder="Np. w zakładce Ranking po wybraniu 2LM nie mogę zaznaczyć grupy A…"></textarea>';
        echo '<div class="dt-feedback-meta"><span>Zgłoszenie z konta: <strong>' . esc_html(wp_get_current_user()->user_email) . '</strong></span><span id="dt-feedback-count">0/2000</span></div>';
        echo '<div class="dt-feedback-actions"><button type="button" class="dt-feedback-cancel" data-feedback-close>Anuluj</button><button type="submit" class="dt-feedback-submit">Wyślij zgłoszenie</button></div>';
        echo '</form></dialog>';
    }
}
