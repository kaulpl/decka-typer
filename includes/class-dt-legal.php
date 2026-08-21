<?php
if (!defined('ABSPATH')) exit;

class DT_Legal {
    public static function register(): void {
        add_action('init', [__CLASS__, 'ensure_pages'], 30);
        add_shortcode('decka_typer_contact', [__CLASS__, 'contact_form']);
        add_shortcode('decka_typer_contact_email', [__CLASS__, 'contact_email_shortcode']);
        add_filter('template_include', [__CLASS__, 'template_include'], 100);
        add_filter('body_class', [__CLASS__, 'body_class']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets']);
        add_action('admin_post_dt_contact', [__CLASS__, 'handle_contact']);
        add_action('admin_post_nopriv_dt_contact', [__CLASS__, 'handle_contact']);
    }

    public static function ensure_pages(): void {
        $settings = DT_DB::settings();
        $changed = false;
        $privacy = self::ensure_page(
            (int)($settings['privacy_page_id'] ?? 0),
            'polityka-prywatnosci',
            'Polityka prywatności',
            self::default_privacy_content()
        );
        $contact = self::ensure_page(
            (int)($settings['contact_page_id'] ?? 0),
            'kontakt',
            'Kontakt',
            '<p>Masz pytanie, reklamację, propozycję współpracy albo pomysł na rozwój TypujKosza.pl? Wybierz temat i napisz do nas. Odpowiemy na podany adres e-mail.</p>\n[decka_typer_contact]'
        );
        if ($privacy && (int)($settings['privacy_page_id'] ?? 0) !== $privacy) { $settings['privacy_page_id'] = $privacy; $changed = true; }
        if ($contact && (int)($settings['contact_page_id'] ?? 0) !== $contact) { $settings['contact_page_id'] = $contact; $changed = true; }
        if ($changed) update_option('dt_settings', $settings);
        if ($privacy && !(int)get_option('wp_page_for_privacy_policy')) update_option('wp_page_for_privacy_policy', $privacy);
    }

    private static function ensure_page(int $savedId, string $slug, string $title, string $content): int {
        if ($savedId > 0 && get_post($savedId)) return $savedId;
        $existing = get_page_by_path($slug, OBJECT, 'page');
        if ($existing) return (int)$existing->ID;
        $result = wp_insert_post(['post_title'=>$title,'post_name'=>$slug,'post_status'=>'publish','post_type'=>'page','post_content'=>$content], true);
        return is_wp_error($result) ? 0 : (int)$result;
    }

    private static function default_privacy_content(): string {
        return '<p><strong>Ostatnia aktualizacja: '.esc_html(wp_date('d.m.Y')).'</strong></p>
<h2>1. Administrator danych</h2><p>Administratorem danych użytkowników serwisu TypujKosza.pl jest operator serwisu. Kontakt w sprawach prywatności: [decka_typer_contact_email].</p>
<h2>2. Jakie dane przetwarzamy</h2><p>Możemy przetwarzać dane konta i logowania, nazwę wyświetlaną, adres e-mail, identyfikator dostawcy logowania, wybraną ulubioną drużynę, zapisane typy, wyniki i miejsca w rankingach, treść wiadomości z formularza kontaktowego oraz niezbędne dane techniczne i dzienniki bezpieczeństwa.</p>
<h2>3. Cele i podstawy przetwarzania</h2><p>Dane wykorzystujemy do prowadzenia konta i zabawy typerskiej, zapisywania kuponów, obliczania punktów i rankingów, obsługi zgłoszeń, ochrony serwisu przed nadużyciami oraz realizacji obowiązków prawnych. Podstawą jest wykonanie usługi, prawnie uzasadniony interes administratora, obowiązek prawny albo zgoda — zależnie od sytuacji.</p>
<h2>4. Odbiorcy danych</h2><p>Dane mogą być powierzane dostawcom hostingu, poczty elektronicznej, obsługi technicznej i logowania społecznościowego wyłącznie w zakresie potrzebnym do działania serwisu.</p>
<h2>5. Okres przechowywania</h2><p>Dane konta i rozgrywek przechowujemy przez okres korzystania z serwisu oraz czas niezbędny do rozliczenia sezonu, obsługi roszczeń i obowiązków prawnych. Wiadomości kontaktowe przechowujemy do zakończenia sprawy i przez wymagany okres archiwizacji.</p>
<h2>6. Prawa użytkownika</h2><p>Użytkownik może żądać dostępu do danych, ich sprostowania, usunięcia, ograniczenia przetwarzania, przeniesienia danych oraz wnieść sprzeciw lub wycofać zgodę. Przysługuje także prawo skargi do Prezesa Urzędu Ochrony Danych Osobowych.</p>
<h2>7. Pliki cookie i dane techniczne</h2><p>Serwis wykorzystuje pliki cookie i pamięć przeglądarki niezbędne do logowania, utrzymania sesji, bezpieczeństwa i zapamiętywania ustawień. Wyłączenie niezbędnych mechanizmów może uniemożliwić korzystanie z części funkcji.</p>
<h2>8. Dobrowolność danych i zmiany polityki</h2><p>Podanie danych jest dobrowolne, ale niektóre informacje są konieczne do założenia konta, zapisania kuponu lub otrzymania odpowiedzi. O istotnych zmianach tej polityki poinformujemy w serwisie.</p>';
    }

    public static function privacy_page_id(): int { return (int)(DT_DB::settings()['privacy_page_id'] ?? 0); }
    public static function contact_page_id(): int { return (int)(DT_DB::settings()['contact_page_id'] ?? 0); }
    public static function privacy_url(): string { $id=self::privacy_page_id(); return $id ? get_permalink($id) : home_url('/polityka-prywatnosci/'); }
    public static function contact_url(): string { $id=self::contact_page_id(); return $id ? get_permalink($id) : home_url('/kontakt/'); }
    public static function is_public_page(): bool { return is_page([self::privacy_page_id(), self::contact_page_id()]); }

    public static function template_include(string $template): string {
        if (!self::is_public_page()) return $template;
        $standalone = DT_DIR.'templates/legal.php';
        return is_readable($standalone) ? $standalone : $template;
    }

    public static function body_class(array $classes): array { if (self::is_public_page()) $classes[]='decka-typer-legal decka-typer-standalone'; return $classes; }

    public static function assets(): void {
        if (!self::is_public_page()) return;
        wp_enqueue_style('dt-front', DT_URL.'assets/css/frontend.css', [], DT_VERSION);
        wp_enqueue_style('tk-brand', DT_URL.'assets/css/brand.css', ['dt-front'], DT_VERSION);
        wp_enqueue_style('dt-legal', DT_URL.'assets/css/legal.css', ['tk-brand'], DT_VERSION);
    }

    public static function contact_email_shortcode(): string {
        $email = sanitize_email(DT_DB::settings()['contact_email'] ?? get_option('admin_email'));
        return $email ? '<a href="mailto:'.esc_attr($email).'">'.esc_html($email).'</a>' : '';
    }

    public static function contact_form(): string {
        $status = sanitize_key($_GET['dt_contact_status'] ?? '');
        $notices = ['sent'=>['success','Dziękujemy. Wiadomość została wysłana.'],'error'=>['error','Nie udało się wysłać wiadomości. Sprawdź pola i spróbuj ponownie.'],'rate'=>['error','Wiadomość została już niedawno wysłana. Spróbuj ponownie za chwilę.']];
        ob_start();
        if (isset($notices[$status])) echo '<div class="dt-contact-notice is-'.esc_attr($notices[$status][0]).'" role="status">'.esc_html($notices[$status][1]).'</div>';
        echo '<form class="dt-contact-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dt_contact">';
        wp_nonce_field('dt_contact','dt_contact_nonce');
        echo '<div class="dt-contact-grid"><label>Imię i nazwisko<input name="name" maxlength="100" autocomplete="name" required></label><label>Adres e-mail<input type="email" name="email" maxlength="190" autocomplete="email" required></label></div>';
        echo '<label>Temat<select name="topic" required><option value="question">Pytanie</option><option value="complaint">Reklamacja</option><option value="cooperation">Współpraca</option><option value="idea">Pomysł lub ulepszenie</option></select></label>';
        echo '<label>Wiadomość<textarea name="message" minlength="10" maxlength="5000" rows="8" required></textarea></label>';
        echo '<label class="dt-contact-consent"><input type="checkbox" name="consent" value="1" required><span>Zgadzam się na wykorzystanie podanych danych w celu obsługi mojego zgłoszenia zgodnie z <a href="'.esc_url(self::privacy_url()).'">polityką prywatności</a>.</span></label>';
        echo '<label class="dt-contact-trap" aria-hidden="true">Pozostaw puste<input name="website" tabindex="-1" autocomplete="off"></label>';
        echo '<button type="submit">Wyślij wiadomość</button></form>';
        return ob_get_clean();
    }

    public static function handle_contact(): void {
        $redirect = self::contact_url();
        if (!isset($_POST['dt_contact_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['dt_contact_nonce'])), 'dt_contact')) self::redirect($redirect,'error');
        if (!empty($_POST['website'])) self::redirect($redirect,'sent');
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        $topic = sanitize_key($_POST['topic'] ?? 'question');
        $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
        $topics = ['question'=>'Pytanie','complaint'=>'Reklamacja','cooperation'=>'Współpraca','idea'=>'Pomysł lub ulepszenie'];
        if ($name==='' || !is_email($email) || !isset($topics[$topic]) || strlen($message)<10 || empty($_POST['consent'])) self::redirect($redirect,'error');
        $fingerprint = hash('sha256', $email.'|'.($_SERVER['REMOTE_ADDR'] ?? '').'|'.wp_salt('nonce'));
        $key = 'dt_contact_'.substr($fingerprint,0,32);
        if (get_transient($key)) self::redirect($redirect,'rate');
        set_transient($key,1,MINUTE_IN_SECONDS);
        $to = sanitize_email(DT_DB::settings()['contact_email'] ?? get_option('admin_email'));
        $subject = sprintf('[TypujKosza.pl] %s — %s',$topics[$topic],$name);
        $body = "Temat: {$topics[$topic]}\nNadawca: {$name}\nE-mail: {$email}\n\n{$message}";
        $headers = ['Content-Type: text/plain; charset=UTF-8','Reply-To: '.$name.' <'.$email.'>'];
        self::redirect($redirect, ($to && wp_mail($to,$subject,$body,$headers)) ? 'sent' : 'error');
    }

    private static function redirect(string $url, string $status): void { wp_safe_redirect(add_query_arg('dt_contact_status',$status,$url)); exit; }
}
