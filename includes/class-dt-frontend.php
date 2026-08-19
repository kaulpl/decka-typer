<?php
if (!defined('ABSPATH')) exit;

class DT_Frontend {
    public static function register(): void {
        add_shortcode('decka_typer',[__CLASS__,'shortcode']);
        add_filter('body_class',[__CLASS__,'body_class']);
    }

    public static function body_class(array $classes):array{
        $s=DT_DB::settings();
        if(is_page((int)$s['typer_page_id']))$classes[]='decka-typer-page';
        return $classes;
    }

    public static function shortcode(): string {
        $s=DT_DB::settings();
        wp_enqueue_style('dt-front',DT_URL.'assets/css/frontend.css',[],DT_VERSION);
        wp_enqueue_script('dt-front',DT_URL.'assets/js/frontend.js',[],DT_VERSION,true);
        wp_localize_script('dt-front','DeckaTyper',[
            'root'=>esc_url_raw(rest_url('decka-typer/v1/')),
            'nonce'=>is_user_logged_in()?wp_create_nonce('wp_rest'):'',
            'loggedIn'=>is_user_logged_in(),
            'season'=>$s['season'],
            'logo'=>DT_URL.'assets/img/decka-logo.png',
            'colors'=>['primary'=>$s['brand_primary'],'accent'=>$s['brand_accent'],'surface'=>$s['brand_surface']],
        ]);

        ob_start();
        echo '<div id="decka-typer" class="dt-app" style="--dt-primary:'.esc_attr($s['brand_primary']).';--dt-accent:'.esc_attr($s['brand_accent']).';--dt-surface:'.esc_attr($s['brand_surface']).'">';
        self::hero($s);
        if(!is_user_logged_in()) self::login($s); else self::app_shell();
        echo '<div id="dt-toast" class="dt-front-toast" role="status" aria-live="polite"></div></div>';
        return ob_get_clean();
    }

    private static function hero(array $s):void{
        echo '<header class="dt-front-hero"><div class="dt-hero-glow"></div><div class="dt-front-inner dt-hero-inner"><div class="dt-brand"><img src="'.esc_url(DT_URL.'assets/img/decka-logo.png').'" alt="Decka Pelplin"><div><span>DECKA PELPLIN</span><strong>TYPER <em>'.$s['season'].'</em></strong></div></div><div class="dt-live-pill"><i></i> PEKAO S.A. 1 LIGA</div></div></header>';
    }

    private static function login(array $s):void{
        $error=isset($_GET['dt_login_error'])?sanitize_text_field(wp_unslash($_GET['dt_login_error'])):'';
        echo '<main class="dt-front-inner dt-login-wrap"><section class="dt-login-card"><div class="dt-login-copy"><span class="dt-front-kicker">TWOJE TYPY. TWÓJ RANKING.</span><h1>Wejdź do gry.</h1><p>Typuj wyniki każdej kolejki 1 Ligi, zdobywaj punkty i walcz o pierwsze miejsce w społeczności Decki Pelplin.</p><div class="dt-login-features"><div>'.self::icon('bolt').'<span><strong>Szybkie typowanie</strong><small>Do rozpoczęcia każdego meczu</small></span></div><div>'.self::icon('trophy').'<span><strong>Ranking sezonu</strong><small>Punkty aktualizowane po wynikach</small></span></div><div>'.self::icon('shield').'<span><strong>Bezpieczne konto</strong><small>Logowanie przez zaufanego dostawcę</small></span></div></div></div><div class="dt-login-panel"><h2>Zaloguj się</h2><p>Jedno kliknięcie i jesteś w grze.</p>';
        if($error)echo '<div class="dt-login-error">'.self::icon('alert').'<span>'.esc_html($error).'</span></div>';
        $any=false;
        if(DT_OAuth::configured('google')){$any=true;self::oauth_button('google','Google');}
        if(DT_OAuth::configured('facebook')){$any=true;self::oauth_button('facebook','Facebook');}
        if(DT_OAuth::configured('apple')){$any=true;self::oauth_button('apple','Apple');}
        echo '<div class="dt-login-divider"><span>lub</span></div><a class="dt-social-button dt-wp-login" href="'.esc_url(wp_login_url(get_permalink())).'">'.self::icon('user').'<span>Zaloguj kontem strony</span></a>';
        if(!$any && current_user_can('manage_options')) echo '<div class="dt-setup-note">Skonfiguruj Google, Facebook lub Apple w <strong>Decka Typer → Ustawienia</strong>.</div>';
        echo '<small class="dt-login-legal">Logując się, akceptujesz zasady Typera i politykę prywatności serwisu.</small></div></section></main>';
    }

    private static function oauth_button(string $provider,string $label):void{
        $icon=match($provider){'google'=>'google','facebook'=>'facebook','apple'=>'apple',default=>'user'};
        echo '<a class="dt-social-button dt-social-'.esc_attr($provider).'" href="'.esc_url(DT_OAuth::start_url($provider)).'">'.self::icon($icon).'<span>Kontynuuj z '.esc_html($label).'</span>'.self::icon('arrow').'</a>';
    }

    private static function app_shell():void{
        echo '<main class="dt-front-inner dt-app-main"><div class="dt-app-top"><nav class="dt-tabs" aria-label="Typer"><button class="is-active" data-tab="picks">'.self::icon('target').'<span>Typuj</span></button><button data-tab="ranking">'.self::icon('trophy').'<span>Ranking</span></button><button data-tab="mine">'.self::icon('history').'<span>Moje typy</span></button></nav><div class="dt-user-chip" id="dt-user-chip"><span class="dt-skeleton dt-sk-avatar"></span><span><b class="dt-skeleton dt-sk-text"></b><small>Ładowanie…</small></span></div></div>';
        echo '<section class="dt-user-stats" id="dt-user-stats"><div class="dt-stat"><span>'.self::icon('trophy').'Miejsce</span><strong>—</strong></div><div class="dt-stat"><span>'.self::icon('star').'Punkty</span><strong>—</strong></div><div class="dt-stat"><span>'.self::icon('check').'Dokładne</span><strong>—</strong></div></section>';
        echo '<div class="dt-tab-panel is-active" data-panel="picks"><div class="dt-panel-head"><div><span class="dt-front-kicker">KOLEJKA DO TYPOWANIA</span><h1 id="dt-round-title">Ładowanie…</h1></div><div class="dt-round-nav"><button id="dt-prev-round" aria-label="Poprzednia kolejka">'.self::icon('chev-left').'</button><select id="dt-round-select" aria-label="Wybierz kolejkę"></select><button id="dt-next-round" aria-label="Następna kolejka">'.self::icon('chev-right').'</button></div></div><div id="dt-round-meta" class="dt-round-meta"></div><div id="dt-matches" class="dt-matches"><div class="dt-loading-card"></div><div class="dt-loading-card"></div><div class="dt-loading-card"></div></div><div class="dt-save-dock" id="dt-save-dock"><div><strong id="dt-save-count">0 zmian</strong><span>Typy możesz poprawiać do startu meczu.</span></div><button id="dt-save-all" disabled>'.self::icon('save').'<span>Zapisz typy</span></button></div></div>';
        echo '<div class="dt-tab-panel" data-panel="ranking"><div class="dt-panel-head"><div><span class="dt-front-kicker">KLASYFIKACJA</span><h1>Ranking sezonu</h1></div><div class="dt-ranking-toggle"><button class="is-active" data-rank="season">Sezon</button><button data-rank="round">Kolejka</button></div></div><div id="dt-ranking" class="dt-ranking-list"></div></div>';
        echo '<div class="dt-tab-panel" data-panel="mine"><div class="dt-panel-head"><div><span class="dt-front-kicker">TWOJA HISTORIA</span><h1>Moje typy</h1></div></div><div id="dt-my-summary"></div><div id="dt-my-history" class="dt-history-list"></div></div>';
        echo '</main>';
    }

    private static function icon(string $name):string{
        $icons=[
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
            'chev-left'=>'<path d="m15 18-6-6 6-6"/>','chev-right'=>'<path d="m9 18 6-6-6-6"/>',
            'save'=>'<path d="M5 21h14a2 2 0 0 0 2-2V7l-4-4H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2z"/><path d="M7 3v6h9V3M8 21v-8h8v8"/>',
            'calendar'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
            'lock'=>'<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
            'google'=>'<path d="M21.6 12.2c0-.7-.1-1.4-.2-2H12v3.8h5.4a4.6 4.6 0 0 1-2 3v2.5h3.2c1.9-1.7 3-4.3 3-7.3z"/><path d="M12 22c2.7 0 5-.9 6.6-2.4l-3.2-2.5c-.9.6-2 1-3.4 1-2.6 0-4.8-1.8-5.6-4.1H3.1v2.6A10 10 0 0 0 12 22z"/><path d="M6.4 14a6 6 0 0 1 0-4V7.4H3.1a10 10 0 0 0 0 9.2z"/><path d="M12 5.9c1.5 0 2.8.5 3.8 1.5l2.9-2.8A9.7 9.7 0 0 0 3.1 7.4L6.4 10C7.2 7.7 9.4 5.9 12 5.9z"/>',
            'facebook'=>'<path d="M14 8h3V4h-3c-3 0-5 2-5 5v3H6v4h3v7h4v-7h3l1-4h-4V9c0-.7.3-1 1-1z"/>',
            'apple'=>'<path d="M17.1 12.6c0-2.3 1.9-3.4 2-3.5-1.1-1.6-2.8-1.8-3.4-1.8-1.4-.2-2.8.9-3.5.9-.7 0-1.8-.9-3-.9-1.5 0-3 .9-3.8 2.2-1.7 2.9-.4 7.2 1.2 9.5.8 1.2 1.8 2.5 3.1 2.4 1.2 0 1.7-.8 3.2-.8s1.9.8 3.2.8c1.3 0 2.2-1.2 3-2.4.9-1.3 1.3-2.7 1.3-2.8-.1 0-3.3-1.3-3.3-3.6zM14.8 5.8c.7-.9 1.2-2.1 1.1-3.3-1 .1-2.3.7-3.1 1.6-.7.8-1.2 2-1.1 3.1 1.2.1 2.4-.6 3.1-1.4z"/>',
        ];
        $body=$icons[$name]??$icons['check'];return '<svg class="dt-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">'.$body.'</svg>';
    }
}
