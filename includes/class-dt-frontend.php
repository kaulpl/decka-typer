<?php
if (!defined('ABSPATH')) exit;

class DT_Frontend {
    public static function register(): void {
        add_shortcode('decka_typer', [__CLASS__, 'shortcode']);
        add_filter('body_class', [__CLASS__, 'body_class']);
        add_filter('template_include', [__CLASS__, 'template_include'], 99);
        add_action('template_redirect', [__CLASS__, 'redirect_legacy_typer'], 1);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_filter('show_admin_bar', [__CLASS__, 'hide_admin_bar']);
        add_filter('wp_redirect', [__CLASS__, 'normalize_legacy_redirect'], 10, 2);
    }

    /**
     * Decka Typer is the standalone front page of the WordPress installation.
     * The old typer_page_id setting is intentionally ignored.
     */
    public static function is_typer_page(): bool {
        return is_front_page();
    }

    public static function frontend_url(): string {
        return home_url('/');
    }

    public static function redirect_legacy_typer(): void {
        if (is_admin() || self::is_typer_page()) return;

        $requestPath = isset($_SERVER['REQUEST_URI'])
            ? untrailingslashit((string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH))
            : '';
        $legacyPath = untrailingslashit((string) wp_parse_url(home_url('/typer/'), PHP_URL_PATH));
        $settings = DT_DB::settings();
        $legacyId = (int) ($settings['typer_page_id'] ?? 0);

        if (($requestPath !== '' && $requestPath === $legacyPath) || ($legacyId > 0 && is_page($legacyId))) {
            wp_safe_redirect(self::frontend_url(), 301, 'Decka Typer');
            exit;
        }
    }

    /**
     * Older modules still build /typer redirects. Keep them compatible by
     * transparently routing that exact legacy target to the site homepage.
     */
    public static function normalize_legacy_redirect(string $location, int $status): string {
        $legacyPath = untrailingslashit((string) wp_parse_url(home_url('/typer/'), PHP_URL_PATH));
        $targetPath = untrailingslashit((string) wp_parse_url($location, PHP_URL_PATH));
        $targetHost = strtolower((string) wp_parse_url($location, PHP_URL_HOST));
        $homeHost = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));

        if ($targetPath !== $legacyPath || ($targetHost !== '' && $homeHost !== '' && $targetHost !== $homeHost)) {
            return $location;
        }

        $query = (string) wp_parse_url($location, PHP_URL_QUERY);
        return $query !== '' ? home_url('/?' . $query) : self::frontend_url();
    }

    public static function hide_admin_bar(bool $show): bool {
        return self::is_typer_page() ? false : $show;
    }

    public static function template_include(string $template): string {
        if (!self::is_typer_page()) return $template;
        $standalone = DT_DIR . 'templates/typer.php';
        return is_readable($standalone) ? $standalone : $template;
    }

    public static function enqueue_assets(): void {
        if (!self::is_typer_page()) return;
        self::assets();
    }

    private static function assets(): void {
        $settings = DT_DB::settings();
        wp_enqueue_style('dt-front', DT_URL . 'assets/css/frontend.css', [], DT_VERSION);
        wp_enqueue_style('dt-countdowns', DT_URL . 'assets/css/countdowns.css', ['dt-front'], DT_VERSION);
        wp_enqueue_style('dt-match-insights', DT_URL . 'assets/css/match-insights.css', ['dt-front'], DT_VERSION);
        wp_enqueue_style('dt-artur-ai', DT_URL . 'assets/css/artur-ai.css', ['dt-front'], DT_VERSION);
        wp_enqueue_style('dt-mobile-nav', DT_URL . 'assets/css/mobile-nav.css', ['dt-front', 'dt-user-settings'], DT_VERSION);
        wp_enqueue_script('dt-front', DT_URL . 'assets/js/frontend.js', [], DT_VERSION, true);
        wp_enqueue_script('dt-countdowns', DT_URL . 'assets/js/countdowns.js', ['dt-front'], DT_VERSION, true);
        wp_enqueue_script('dt-artur-ai', DT_URL . 'assets/js/artur-ai.js', ['dt-front'], DT_VERSION, true);
        if (is_user_logged_in()) {
            wp_enqueue_style('dt-feedback', DT_URL . 'assets/css/feedback.css', ['dt-front'], DT_VERSION);
            wp_enqueue_script('dt-feedback', DT_URL . 'assets/js/feedback.js', ['dt-front'], DT_VERSION, true);
        }
        wp_localize_script('dt-front', 'DeckaTyper', [
            'root'=>esc_url_raw(rest_url('decka-typer/v1/')),
            'nonce'=>is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            'loggedIn'=>is_user_logged_in(),
            'season'=>$settings['season'],
            'leagueName'=>'PLK · 1LM · 2LM',
            'showCountdowns'=>!empty($settings['show_countdowns']),
            'arturAiEnabled'=>class_exists('DT_Artur_AI') && DT_Artur_AI::enabled(),
            'siteMode'=>(string)($settings['site_mode'] ?? 'test'),
            'timezone'=>wp_timezone_string() ?: 'Europe/Warsaw',
            'logo'=>DT_URL . 'assets/img/decka-logo.png',
            'colors'=>[
                'primary'=>$settings['brand_primary'],
                'accent'=>$settings['brand_accent'],
                'surface'=>$settings['brand_surface'],
            ],
            'avatar'=>class_exists('DT_Avatar') ? DT_Avatar::messages() : [],
        ]);
    }

    public static function body_class(array $classes): array {
        if (self::is_typer_page()) $classes[] = 'decka-typer-page decka-typer-standalone';
        return $classes;
    }

    public static function shortcode(): string {
        // The public Typer is intentionally available only as the site homepage.
        if (!self::is_typer_page() && !is_admin()) return '';

        $settings = DT_DB::settings();
        self::assets();
        ob_start();
        $leagueColors = wp_parse_args((array)($settings['league_colors'] ?? []), ['1lm'=>'#055EFB','plk'=>'#FB5D0B','2lm'=>'#4F6F9D']);
        echo '<div id="decka-typer" class="dt-app" style="--dt-primary:' . esc_attr($settings['brand_primary']) . ';--dt-accent:' . esc_attr($settings['brand_accent']) . ';--dt-surface:' . esc_attr($settings['brand_surface']) . ';--dt-league-1lm:' . esc_attr($leagueColors['1lm']) . ';--dt-league-plk:' . esc_attr($leagueColors['plk']) . ';--dt-league-2lm:' . esc_attr($leagueColors['2lm']) . '">';
        self::hero($settings);
        if (!empty($settings['show_countdowns'])) self::league_countdowns($settings);
        if (($settings['site_mode'] ?? 'test') === 'test') echo '<div class="dt-test-banner"><strong>Wersja testowa</strong><span>Serwis jest w trakcie testów. Dane i funkcje mogą jeszcze ulec zmianie.</span></div>';
        if (($settings['site_mode'] ?? 'test') === 'break' && !current_user_can('manage_options')) self::break_screen();
        elseif (!is_user_logged_in()) self::login(); else self::app_shell();
        if (($settings['site_mode'] ?? 'test') !== 'break' || current_user_can('manage_options')) self::avatar_helper(!is_user_logged_in());
        if (($settings['site_mode'] ?? 'test') !== 'break' || current_user_can('manage_options')) {
            if (class_exists('DT_Feedback')) DT_Feedback::render();
        }
        echo '<div id="dt-toast" class="dt-front-toast" role="status" aria-live="polite"></div>';
        echo '</div>';
        return ob_get_clean();
    }

    private static function hero(array $settings): void {
        echo '<header class="dt-front-hero"><div class="dt-hero-glow"></div><div class="dt-front-inner dt-hero-inner">';
        echo '<a class="dt-brand" href="' . esc_url(home_url('/')) . '" aria-label="TypujKosza.pl — strona główna"><img src="' . esc_url(DT_URL . 'assets/img/decka-logo.png') . '" alt="Decka Pelplin"><div><span>DECKA PELPLIN</span><strong>TYPER <em>' . esc_html($settings['season']) . '</em></strong></div></a>';
        echo '<div class="dt-live-pill"><i></i>PLK · 1LM · 2LM</div>';
        echo '</div></header>';
    }

    private static function league_countdowns(array $settings): void {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.league_key,MIN(m.starts_at) starts_at
             FROM " . DT_DB::table('rounds') . " r
             JOIN " . DT_DB::table('matches') . " m ON m.round_id=r.id
             WHERE r.season=%s AND m.start_time_known=1 AND m.starts_at IS NOT NULL
             GROUP BY r.league_key",
            (string)($settings['season'] ?? '')
        ), ARRAY_A);
        $starts = [];
        foreach ((array)$rows as $row) $starts[(string)$row['league_key']] = (string)$row['starts_at'];
        $names = (array)($settings['league_names'] ?? []);
        $fallback = ['1lm'=>'1 Liga Mężczyzn','plk'=>'ORLEN Basket Liga','2lm'=>'2 Liga Mężczyzn'];
        $now = current_datetime()->getTimestamp();

        echo '<section class="dt-league-countdowns" aria-labelledby="dt-league-countdowns-title"><div class="dt-front-inner"><div class="dt-countdown-heading"><span>SEZON ' . esc_html((string)($settings['season'] ?? '')) . '</span><strong id="dt-league-countdowns-title">Odliczanie do startu lig</strong></div><div class="dt-league-countdown-grid">';
        foreach (['1lm','plk','2lm'] as $key) {
            $mysql = $starts[$key] ?? '';
            $start = $mysql !== '' ? new DateTimeImmutable($mysql, wp_timezone()) : null;
            $timestamp = $start ? $start->getTimestamp() : false;
            $iso = $start ? $start->format(DATE_ATOM) : '';
            $state = $timestamp === false ? 'is-tbd' : ($timestamp <= $now ? 'is-started' : 'is-upcoming');
            echo '<article class="dt-league-countdown ' . esc_attr($state) . '"><div><span class="dt-league-code">' . esc_html(strtoupper($key)) . '</span><strong>' . esc_html((string)($names[$key] ?? $fallback[$key])) . '</strong></div>';
            if ($state === 'is-upcoming') echo '<div class="dt-countdown-clock" data-countdown-target="' . esc_attr($iso) . '" data-countdown-expired="Liga wystartowała"><small>Do startu pozostało</small><strong data-countdown-value>—</strong></div>';
            elseif ($state === 'is-started') echo '<div class="dt-countdown-status">Liga wystartowała</div>';
            else echo '<div class="dt-countdown-status">Termin do potwierdzenia</div>';
            echo '</article>';
        }
        echo '</div></div></section>';
    }

    private static function break_screen(): void {
        echo '<main class="dt-break-screen"><img src="' . esc_url(DT_Brand::logo_stacked_url()) . '" alt="TypujKosza.pl"><p>' . esc_html(DT_Brand::TAGLINE) . '</p><h1>Ruszamy w sezonie 2026/2027</h1></main>';
    }

    private static function login(): void {
        $error = isset($_GET['dt_login_error']) ? sanitize_text_field(wp_unslash($_GET['dt_login_error'])) : '';
        echo '<main class="dt-front-inner dt-login-wrap"><section class="dt-login-card">';
        echo '<div class="dt-login-copy"><span class="dt-front-kicker">TWOJE TYPY. TWÓJ RANKING.</span><h1>Wejdź do gry.</h1><p>Wybieraj zwycięzców wszystkich spotkań kolejki, zapisz jeden kupon i walcz o pierwsze miejsce w społeczności Decki Pelplin.</p>';
        echo '<div class="dt-login-features"><div>' . self::icon('bolt') . '<span><strong>Proste typowanie</strong><small>Jedno kliknięcie na zwycięzcę</small></span></div><div>' . self::icon('trophy') . '<span><strong>Ranking sezonu</strong><small>Punkty po każdym rozstrzygnięciu</small></span></div><div>' . self::icon('shield') . '<span><strong>Jeden kupon</strong><small>Po zapisie typów nie można edytować</small></span></div></div></div>';
        echo '<div class="dt-login-panel"><h2>Zaloguj się</h2><p>Jedno kliknięcie i jesteś w grze.</p>';
        if ($error) echo '<div class="dt-login-error">' . self::icon('alert') . '<span>' . esc_html($error) . '</span></div>';
        $any = false;
        if (DT_OAuth::configured('google')) { $any=true; self::oauth_button('google', 'Google'); }
        if (DT_OAuth::configured('facebook')) { $any=true; self::oauth_button('facebook', 'Facebook'); }
        echo '<div class="dt-login-divider"><span>lub</span></div><a class="dt-social-button dt-wp-login" href="' . esc_url(wp_login_url(self::frontend_url())) . '">' . self::icon('user') . '<span>Zaloguj kontem strony</span></a>';
        if (!$any && current_user_can('manage_options')) echo '<div class="dt-setup-note">Skonfiguruj Google lub Facebook w <strong>Decka Typer → Ustawienia</strong>.</div>';
        echo '<small class="dt-login-legal">Logując się, akceptujesz zasady Typera i politykę prywatności serwisu.</small></div></section></main>';
    }

    private static function oauth_button(string $provider, string $label): void {
        echo '<a class="dt-social-button dt-social-' . esc_attr($provider) . '" href="' . esc_url(DT_OAuth::start_url($provider)) . '">' . self::icon($provider) . '<span>Kontynuuj z ' . esc_html($label) . '</span>' . self::icon('arrow') . '</a>';
    }

    private static function app_shell(): void {
        echo '<main class="dt-front-inner dt-app-main">';
        echo '<div class="dt-app-top"><nav class="dt-tabs" aria-label="Typer"><button class="is-active" data-tab="picks">' . self::icon('target') . '<span>Typuj</span></button><button data-tab="ranking">' . self::icon('trophy') . '<span>Ranking</span></button><button data-tab="mine">' . self::icon('history') . '<span>Moje typy</span></button><button data-tab="settings">' . self::icon('user') . '<span>Moje konto</span></button></nav><div class="dt-league-ranks" id="dt-league-ranks" aria-label="Miejsca użytkownika w ligach"><span>1LM <b>#–</b></span><span>PLK <b>#–</b></span><span>2LM <b>#–</b></span></div><div class="dt-user-chip" id="dt-user-chip"><span class="dt-skeleton dt-sk-avatar"></span><span><b class="dt-skeleton dt-sk-text"></b><small>Ładowanie…</small></span></div></div>';

        echo '<div class="dt-tab-panel is-active" data-panel="picks"><div id="dt-league-rounds" class="dt-league-picker"></div><div class="dt-panel-head"><div><span class="dt-front-kicker">KOLEJKA</span><h1 id="dt-round-title">Ładowanie…</h1></div></div><div class="dt-round-nav dt-round-nav-wide"><button id="dt-prev-round" aria-label="Poprzednia kolejka">' . self::icon('chev-left') . '</button><select id="dt-round-select" aria-label="Wybierz kolejkę"></select><button id="dt-next-round" aria-label="Następna kolejka">' . self::icon('chev-right') . '</button></div><div id="dt-round-meta" class="dt-round-meta"></div><div id="dt-matches" class="dt-matches"><div class="dt-loading-card"></div><div class="dt-loading-card"></div></div>';
        echo '<div class="dt-save-dock" id="dt-save-dock"><div><strong id="dt-save-count">Wybierz zwycięzców</strong><span>Po zapisaniu kuponu nie będzie można go edytować.</span></div><button id="dt-save-all" disabled>' . self::icon('save') . '<span>Zapisz typy</span></button></div></div>';

        echo '<div class="dt-tab-panel" data-panel="ranking"><div class="dt-panel-head dt-ranking-title"><div><span class="dt-front-kicker">KLASYFIKACJA</span><h1>RANKING</h1></div></div><div class="dt-ranking-controls"><div class="dt-ranking-toggle"><button class="is-active" data-rank="season">Sezon</button><button data-rank="round">Kolejka</button></div></div><div id="dt-ranking" class="dt-ranking-list"></div></div>';
        echo '<div class="dt-tab-panel" data-panel="mine"><div class="dt-panel-head"><div><span class="dt-front-kicker">TWOJA HISTORIA</span><h1>Moje typy</h1></div></div><div id="dt-my-summary"></div><div id="dt-my-history" class="dt-history-list"></div></div>';
        echo '<div class="dt-tab-panel" data-panel="settings"><div class="dt-panel-head"><div><span class="dt-front-kicker">TWOJE KONTO</span><h1>Moje konto</h1></div></div><section class="dt-account-achievements"><div class="dt-achievement-controls"><strong>Moje osiągnięcia</strong><div class="dt-mini-segments" id="dt-achievement-scope"><button type="button" data-value="all" class="is-active">Wszechczasów</button><button type="button" data-value="season">Sezon</button></div></div><div class="dt-achievement-leagues" id="dt-achievement-leagues"></div></section><div id="dt-account-settings" class="dt-account-settings"><div class="dt-account-loading">Ładowanie ustawień…</div></div></div>';
        echo '</main>';

        echo '<dialog id="dt-submit-modal" class="dt-front-modal"><div class="dt-front-modal-body"><button class="dt-front-modal-x" type="button" data-modal-close aria-label="Zamknij">×</button><div class="dt-modal-icon">' . self::icon('lock') . '</div><span class="dt-front-kicker">OSTATECZNY ZAPIS</span><h2>Zapisać typy?</h2><p>Po zatwierdzeniu kuponu tej kolejki <strong>nie będzie można zmienić żadnego typu</strong>.</p><div class="dt-modal-actions"><button type="button" class="dt-modal-cancel" data-modal-close>Wróć</button><button type="button" class="dt-modal-confirm" id="dt-confirm-submit">Tak, zapisz kupon</button></div></div></dialog>';
        echo '<dialog id="dt-artur-ai-modal" class="dt-artur-ai-modal" aria-labelledby="dt-artur-ai-title"><div class="dt-artur-ai-card"><button type="button" class="dt-artur-ai-close" aria-label="Zamknij">×</button><header><img src="'.esc_url(DT_URL.'assets/img/artur-bot/02-myslenie.webp').'" alt="Artur"><div><span>KOŁO RATUNKOWE</span><h2 id="dt-artur-ai-title">Zapytaj Artura</h2><p id="dt-artur-ai-match"></p></div></header><div class="dt-artur-ai-status" id="dt-artur-ai-status">Sprawdzam dostępność…</div><div class="dt-artur-ai-history" id="dt-artur-ai-history" aria-live="polite"></div><div class="dt-artur-ai-prompts" id="dt-artur-ai-prompts"></div><form id="dt-artur-ai-form"><label for="dt-artur-ai-question">Twoje pytanie o ten mecz</label><textarea id="dt-artur-ai-question" maxlength="300" rows="3" required placeholder="Np. która drużyna jest ostatnio w lepszej formie?"></textarea><button type="submit">Zapytaj Artura</button></form><small>Treść pytania i statystyki meczu są przesyłane do Gemini. Nie przekazujemy Twojego nazwiska, e-maila ani zapisanego typu. Odpowiedź Artura jest podpowiedzią, a nie gwarancją wyniku.</small></div></dialog>';
    }

    private static function avatar_helper(bool $landing = false): void {
        echo '<aside id="dt-avatar-helper" class="dt-avatar-helper'.($landing?' is-landing':'').'" aria-live="polite" hidden><button type="button" class="dt-avatar-close" aria-label="Zamknij">×</button><div class="dt-avatar-bubble"></div><img alt="Avatar pomocnika TypujKosza.pl"></aside>';
    }

    private static function icon(string $name): string {
        $icons = [
            'bolt'=>'<path d="M13 2 3 14h8l-1 8 10-12h-8z"/>',
            'trophy'=>'<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M7 6H4v2a4 4 0 0 0 4 4M17 6h3v2a4 4 0 0 1-4 4"/>',
            'shield'=>'<path d="M12 22s8-3 8-10V5l-8-3-8 3v7c0 7 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
            'alert'=>'<path d="M12 9v4M12 17h.01"/><path d="M10.3 3.6 2.2 18a2 2 0 0 0 1.8 3h16a2 2 0 0 0 1.8-3L13.7 3.6a2 2 0 0 0-3.4 0z"/>',
            'user'=>'<path d="M20 21a8 8 0 0 0-16 0M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/>',
            'arrow'=>'<path d="M5 12h14M13 6l6 6-6 6"/>',
            'target'=>'<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="3"/><path d="M12 2V.5M12 23.5V22M2 12H.5M23.5 12H22"/>',
            'history'=>'<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
            'star'=>'<path d="m12 2 3 6 7 .9-5 4.8 1.2 6.8L12 17.3l-6.2 3.2L7 13.7 2 8.9 9 8z"/>',
            'check'=>'<path d="m5 12 4 4L19 6"/>',
            'chev-left'=>'<path d="m15 18-6-6 6-6"/>',
            'chev-right'=>'<path d="m9 18 6-6-6-6"/>',
            'save'=>'<path d="M5 21h14a2 2 0 0 0 2-2V7l-4-4H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2z"/><path d="M7 3v6h9V3M8 21v-8h8v8"/>',
            'lock'=>'<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
            'google'=>'<path d="M21.6 12.2c0-.7-.1-1.4-.2-2H12v3.8h5.4a4.6 4.6 0 0 1-2 3v2.5h3.2c1.9-1.7 3-4.3 3-7.3z"/><path d="M12 22c2.7 0 5-.9 6.6-2.4l-3.2-2.5c-.9.6-2 1-3.4 1-2.6 0-4.8-1.8-5.6-4.1H3.1v2.6A10 10 0 0 0 12 22z"/><path d="M6.4 14a6 6 0 0 1 0-4V7.4H3.1a10 10 0 0 0 0 9.2z"/><path d="M12 5.9c1.5 0 2.8.5 3.8 1.5l2.9-2.8A9.7 9.7 0 0 0 3.1 7.4L6.4 10C7.2 7.7 9.4 5.9 12 5.9z"/>',
            'facebook'=>'<path d="M14 8h3V4h-3c-3 0-5 2-5 5v3H6v4h3v7h4v-7h3l1-4h-4V9c0-.7.3-1 1-1z"/>',
        ];
        $body = $icons[$name] ?? $icons['check'];
        return '<svg class="dt-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">' . $body . '</svg>';
    }
}
