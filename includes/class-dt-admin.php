<?php
if (!defined('ABSPATH')) exit;

class DT_Admin {
    public static function register(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        foreach ([
            'sync_now','save_settings','save_match','add_match','add_round','adjust_points',
            'open_round','close_round'
        ] as $action) {
            add_action('admin_post_dt_' . $action, [__CLASS__, $action]);
        }
    }

    public static function menu(): void {
        $cap = 'manage_options';
        add_menu_page('Decka Typer','Decka Typer',$cap,'decka-typer',[__CLASS__,'dashboard'],'dashicons-awards',26);
        $items = [
            ['decka-typer','Pulpit','dashboard'],
            ['decka-typer-rounds','Kolejki','rounds'],
            ['decka-typer-matches','Mecze','matches'],
            ['decka-typer-predictions','Typy','predictions'],
            ['decka-typer-ranking','Ranking','ranking'],
            ['decka-typer-users','Użytkownicy','users'],
            ['decka-typer-stats','Statystyki','stats'],
            ['decka-typer-sync','Synchronizacja 1LM','sync'],
            ['decka-typer-logs','Historia','logs'],
            ['decka-typer-settings','Ustawienia','settings'],
        ];
        foreach ($items as [$slug,$label,$method]) {
            add_submenu_page('decka-typer',$label,$label,$cap,$slug,[__CLASS__,$method]);
        }
    }

    public static function assets(string $hook): void {
        if (strpos($hook, 'decka-typer') === false) return;
        wp_enqueue_style('dt-admin', DT_URL . 'assets/css/admin.css', [], DT_VERSION);
        wp_enqueue_script('dt-admin', DT_URL . 'assets/js/admin.js', [], DT_VERSION, true);
    }

    private static function guard(string $nonce): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        check_admin_referer($nonce);
    }

    private static function shell(string $title, string $subtitle = ''): void {
        echo '<div class="wrap dt-admin"><div class="dt-admin-head"><div><div class="dt-kicker">DECKA PELPLIN · TYPER</div><h1>' . esc_html($title) . '</h1>' . ($subtitle ? '<p>' . esc_html($subtitle) . '</p>' : '') . '</div><img src="' . esc_url(DT_URL . 'assets/img/decka-logo.png') . '" alt="Decka Pelplin"></div>';
        self::notice();
    }

    private static function end_shell(): void { echo '</div>'; }

    private static function notice(): void {
        if (empty($_GET['dt_notice'])) return;
        $type = sanitize_key($_GET['dt_type'] ?? 'success');
        $msg = sanitize_text_field(wp_unslash($_GET['dt_notice']));
        echo '<div class="dt-toast-static dt-' . esc_attr($type) . '"><span class="dashicons dashicons-' . ($type === 'error' ? 'warning' : 'yes-alt') . '"></span>' . esc_html($msg) . '</div>';
    }

    private static function redirect(string $page, string $notice, string $type = 'success', array $extra = []): void {
        wp_safe_redirect(add_query_arg(array_merge(['page'=>$page,'dt_notice'=>$notice,'dt_type'=>$type], $extra), admin_url('admin.php')));
        exit;
    }

    private static function badge(string $text, string $type = 'neutral'): string {
        return '<span class="dt-badge dt-badge-' . esc_attr($type) . '">' . esc_html($text) . '</span>';
    }

    private static function round_badge(string $status): string {
        return match ($status) {
            'open' => self::badge('Otwarte do typowania','green'),
            'closed' => self::badge('Zamknięte','red'),
            default => self::badge('Szkic','neutral'),
        };
    }

    private static function metric(string $label, $value, string $icon, string $tone): void {
        echo '<div class="dt-metric dt-tone-' . esc_attr($tone) . '"><div class="dt-metric-icon"><span class="dashicons dashicons-' . esc_attr($icon) . '"></span></div><div><span>' . esc_html($label) . '</span><strong>' . esc_html((string)$value) . '</strong></div></div>';
    }

    /** DATETIME values in Decka Typer are stored as local site time. Never run them through strtotime()+wp_date(). */
    private static function local_dt(?string $value): ?DateTimeImmutable {
        if (!$value) return null;
        $tz = wp_timezone();
        $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $tz);
        if (!$d) $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, $tz);
        return $d ?: null;
    }

    private static function date_pl(?string $value): string {
        $d = self::local_dt($value);
        return $d ? $d->format('d.m.Y · H:i') : '—';
    }

    private static function html_datetime(?string $value): string {
        $d = self::local_dt($value);
        return $d ? $d->format('Y-m-d\TH:i') : '';
    }

    public static function dashboard(): void {
        global $wpdb;
        $s = DT_DB::settings();
        $season = $s['season'];
        $rounds = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . DT_DB::table('rounds') . ' WHERE season=%s', $season));
        $matches = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . DT_DB::table('matches') . ' m JOIN ' . DT_DB::table('rounds') . ' r ON r.id=m.round_id WHERE r.season=%s', $season));
        $submissions = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . DT_DB::table('round_submissions') . ' s JOIN ' . DT_DB::table('rounds') . ' r ON r.id=s.round_id WHERE r.season=%s', $season));
        $players = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(DISTINCT s.user_id) FROM ' . DT_DB::table('round_submissions') . ' s JOIN ' . DT_DB::table('rounds') . ' r ON r.id=s.round_id WHERE r.season=%s', $season));
        $open = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . DT_DB::table('rounds') . " WHERE season=%s AND status='open' ORDER BY round_no LIMIT 1", $season));
        $last = get_option('dt_last_sync');

        self::shell('Pulpit', 'Sezon ' . $season . ' · kontrola Typera');
        echo '<div class="dt-grid dt-grid-4">';
        self::metric('Kolejki',$rounds,'calendar-alt','blue');
        self::metric('Mecze',$matches,'tickets-alt','orange');
        self::metric('Kupony',$submissions,'forms','violet');
        self::metric('Gracze',$players,'groups','green');
        echo '</div>';

        echo '<div class="dt-grid dt-grid-2 dt-section"><section class="dt-card"><span class="dt-eyebrow">AKTYWNE TYPOWANIE</span><h2>' . esc_html($open ? $open->title : 'Brak otwartej kolejki') . '</h2>';
        if ($open) {
            echo '<p>Typowanie zamknie się <strong>' . esc_html(self::date_pl($open->closes_at)) . '</strong>.</p><a class="button dt-button" href="' . esc_url(admin_url('admin.php?page=decka-typer-rounds')) . '">Zarządzaj kolejką</a>';
        } else {
            echo '<p class="dt-muted">Otwórz kolejkę w module „Kolejki” i ustaw dokładny termin zamknięcia kuponów.</p><a class="button dt-button" href="' . esc_url(admin_url('admin.php?page=decka-typer-rounds')) . '">Otwórz kolejkę</a>';
        }
        echo '</section><section class="dt-card"><span class="dt-eyebrow">STAN SYSTEMU</span><h2>Kontrola</h2><div class="dt-health"><div><span>Ostatnia synchronizacja</span><strong>' . esc_html($last['at'] ?? 'jeszcze nie wykonano') . '</strong></div><div><span>Źródło</span><strong>1lm.pzkosz.pl</strong></div><div><span>Tryb typowania</span><strong>Zwycięzca meczu</strong></div><div><span>Edycja kuponu</span><strong>Wyłączona po zapisie</strong></div></div></section></div>';
        echo '<section class="dt-card dt-section"><span class="dt-eyebrow">TOP 5</span><h2>Liderzy klasyfikacji</h2>';
        self::ranking_table(DT_Scoring::ranking($season,5));
        echo '</section>';
        self::end_shell();
    }

    public static function rounds(): void {
        global $wpdb;
        $s = DT_DB::settings();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*,COUNT(m.id) matches,MIN(m.starts_at) first_match,MAX(m.starts_at) last_match,
             MIN(CASE WHEN m.start_time_known=1 THEN m.starts_at END) first_known_match,
             (SELECT COUNT(*) FROM " . DT_DB::table('round_submissions') . " ss WHERE ss.round_id=r.id) submissions
             FROM " . DT_DB::table('rounds') . " r LEFT JOIN " . DT_DB::table('matches') . " m ON m.round_id=r.id
             WHERE r.season=%s GROUP BY r.id ORDER BY r.round_no", $s['season']
        ));
        self::shell('Kolejki','Tylko kolejka otwarta przez administratora jest dostępna do nowych typów.');
        echo '<div class="dt-toolbar"><button class="button button-primary dt-button" data-dt-open="dt-round-modal"><span class="dashicons dashicons-plus-alt2"></span> Dodaj kolejkę</button></div>';
        echo '<section class="dt-card"><table class="widefat dt-table"><thead><tr><th>Kolejka</th><th>Termin meczów</th><th>Mecze</th><th>Kupony</th><th>Status</th><th>Zamknięcie typowania</th><th>Źródło</th><th>Akcje</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $range = $r->first_match ? substr(self::date_pl($r->first_match),0,10) . ' – ' . substr(self::date_pl($r->last_match),0,10) : '—';
            $openData = [
                'id'=>(int)$r->id,
                'title'=>$r->title,
                'default_close'=>self::html_datetime($r->closes_at ?: $r->first_known_match),
                'first_match'=>self::date_pl($r->first_known_match ?: $r->first_match),
            ];
            echo '<tr><td><strong>' . esc_html($r->title) . '</strong></td><td>' . esc_html($range) . '</td><td>' . (int)$r->matches . '</td><td>' . (int)$r->submissions . '</td><td>' . self::round_badge((string)$r->status) . '</td><td><strong>' . esc_html(self::date_pl($r->closes_at)) . '</strong></td><td>' . self::badge($r->source === '1lm' ? '1LM' : 'Ręcznie', $r->source === '1lm' ? 'blue' : 'orange') . '</td><td>';
            if ($r->status === 'open') {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
                echo '<input type="hidden" name="action" value="dt_close_round"><input type="hidden" name="round_id" value="' . (int)$r->id . '">';
                wp_nonce_field('dt_close_round');
                echo '<button class="button" onclick="return confirm(\'Zamknąć typowanie tej kolejki?\')">Zamknij</button></form>';
            } else {
                echo '<button type="button" class="button dt-open-round" data-round="' . esc_attr(wp_json_encode($openData)) . '">Otwórz typowanie</button>';
            }
            echo '</td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="8" class="dt-empty">Brak kolejek. Uruchom synchronizację 1LM.</td></tr>';
        echo '</tbody></table></section>';

        echo '<dialog id="dt-round-modal" class="dt-modal"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><button type="button" class="dt-modal-x" data-dt-close>×</button><span class="dt-eyebrow">NOWA KOLEJKA</span><h2>Dodaj kolejkę ręcznie</h2><input type="hidden" name="action" value="dt_add_round">';
        wp_nonce_field('dt_add_round');
        echo '<label>Numer kolejki<input type="number" name="round_no" min="1" max="99" required></label><label>Nazwa<input name="title" placeholder="np. 12. kolejka"></label><div class="dt-inline-warning">Nowa kolejka powstanie jako szkic. Otworzysz ją osobno po ustawieniu terminu zamknięcia typowania.</div><button class="button button-primary dt-button">Zapisz kolejkę</button></form></dialog>';

        echo '<dialog id="dt-open-round-modal" class="dt-modal"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><button type="button" class="dt-modal-x" data-dt-close>×</button><span class="dt-eyebrow">OTWARCIE TYPOWANIA</span><h2 id="dt-open-round-title">Otwórz kolejkę</h2><input type="hidden" name="action" value="dt_open_round"><input type="hidden" name="round_id" id="dt-open-round-id">';
        wp_nonce_field('dt_open_round');
        echo '<label>Zamknięcie możliwości typowania<input type="datetime-local" name="closes_at" id="dt-open-round-close" required></label><div class="dt-inline-warning">Po zapisaniu kuponu użytkownik nie będzie mógł go zmienić. Termin zamknięcia nie może być późniejszy niż start pierwszego meczu ze znaną godziną.</div><button class="button button-primary dt-button">Otwórz typowanie</button></form></dialog>';
        echo '<script>document.addEventListener("click",function(e){var b=e.target.closest(".dt-open-round");if(!b)return;var d={};try{d=JSON.parse(b.dataset.round||"{}");}catch(_){return;}document.getElementById("dt-open-round-id").value=d.id||"";document.getElementById("dt-open-round-title").textContent="Otwórz: "+(d.title||"kolejkę");document.getElementById("dt-open-round-close").value=d.default_close||"";document.getElementById("dt-open-round-modal").showModal();});</script>';
        self::end_shell();
    }

    public static function matches(): void {
        global $wpdb;
        $s = DT_DB::settings();
        $roundId = (int)($_GET['round_id'] ?? 0);
        $rounds = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . DT_DB::table('rounds') . ' WHERE season=%s ORDER BY round_no', $s['season']));
        if (!$roundId && $rounds) $roundId = (int)$rounds[0]->id;
        $rows = $roundId ? $wpdb->get_results($wpdb->prepare(
            'SELECT m.*,h.name home_name,a.name away_name FROM ' . DT_DB::table('matches') . ' m JOIN ' . DT_DB::table('teams') . ' h ON h.id=m.home_team_id JOIN ' . DT_DB::table('teams') . ' a ON a.id=m.away_team_id WHERE m.round_id=%d ORDER BY m.starts_at,m.id', $roundId
        )) : [];
        self::shell('Mecze','Wynik meczu służy wyłącznie do rozliczenia wskazanego zwycięzcy.');
        echo '<div class="dt-toolbar"><form method="get"><input type="hidden" name="page" value="decka-typer-matches"><select name="round_id" onchange="this.form.submit()">';
        foreach ($rounds as $r) echo '<option value="' . (int)$r->id . '" ' . selected($roundId,$r->id,false) . '>' . esc_html($r->title) . '</option>';
        echo '</select></form><button class="button button-primary dt-button" data-dt-open="dt-add-match"><span class="dashicons dashicons-plus-alt2"></span> Dodaj mecz</button></div>';
        echo '<section class="dt-card"><table class="widefat dt-table"><thead><tr><th>Mecz</th><th>Start</th><th>Wynik</th><th>Status</th><th>Synchronizacja</th><th></th></tr></thead><tbody>';
        foreach ($rows as $m) {
            $score = $m->score_home === null ? '—' : (int)$m->score_home . ' : ' . (int)$m->score_away;
            $data = ['id'=>(int)$m->id,'home'=>$m->home_name,'away'=>$m->away_name,'starts_at'=>self::html_datetime($m->starts_at),'home_score'=>$m->score_home,'away_score'=>$m->score_away,'manual_lock'=>(int)$m->manual_lock];
            echo '<tr><td><strong>' . esc_html($m->home_name) . '</strong><span class="dt-versus">vs</span><strong>' . esc_html($m->away_name) . '</strong></td><td>' . esc_html(self::date_pl($m->starts_at)) . '</td><td><span class="dt-score">' . esc_html($score) . '</span></td><td>' . self::badge($m->status === 'finished' ? 'Zakończony':'Zaplanowany',$m->status === 'finished' ? 'green':'neutral') . '</td><td>' . ((int)$m->manual_lock ? self::badge('Ręczny · chroniony','orange') : self::badge('Auto 1LM','blue')) . '</td><td><button type="button" class="button dt-edit-match" data-match="' . esc_attr(wp_json_encode($data)) . '">Edytuj</button></td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="6" class="dt-empty">Brak meczów w tej kolejce.</td></tr>';
        echo '</tbody></table></section>';
        self::match_modal('dt-match-modal',false,$roundId);
        self::match_modal('dt-add-match',true,$roundId);
        self::end_shell();
    }

    private static function match_modal(string $id, bool $add, int $roundId): void {
        global $wpdb;
        $teams = $wpdb->get_results('SELECT id,name FROM ' . DT_DB::table('teams') . ' ORDER BY name');
        echo '<dialog id="' . esc_attr($id) . '" class="dt-modal"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><button type="button" class="dt-modal-x" data-dt-close>×</button><span class="dt-eyebrow">' . ($add?'NOWY MECZ':'EDYCJA MECZU') . '</span><h2>' . ($add?'Dodaj mecz ręcznie':'Popraw dane meczu') . '</h2><input type="hidden" name="action" value="' . ($add?'dt_add_match':'dt_save_match') . '">';
        wp_nonce_field($add?'dt_add_match':'dt_save_match');
        if (!$add) echo '<input type="hidden" name="match_id" data-field="id">';
        echo '<input type="hidden" name="round_id" value="' . $roundId . '">';
        if ($add) {
            echo '<div class="dt-form-2"><label>Gospodarz<select name="home_team_id">'; foreach($teams as $t) echo '<option value="' . (int)$t->id . '">' . esc_html($t->name) . '</option>'; echo '</select></label><label>Gość<select name="away_team_id">'; foreach($teams as $t) echo '<option value="' . (int)$t->id . '">' . esc_html($t->name) . '</option>'; echo '</select></label></div>';
        }
        echo '<label>Data i godzina<input type="datetime-local" name="starts_at" data-field="starts_at" required></label><div class="dt-form-2"><label>Wynik gospodarzy<input type="number" name="score_home" data-field="home_score" min="0" max="250"></label><label>Wynik gości<input type="number" name="score_away" data-field="away_score" min="0" max="250"></label></div>';
        if (!$add) echo '<label class="dt-check"><input type="checkbox" name="manual_lock" value="1" data-field="manual_lock"><span><strong>Chroń przed synchronizacją 1LM</strong><small>Automatyczny import nie nadpisze ręcznie poprawionego terminu ani wyniku.</small></span></label>';
        echo '<button class="button button-primary dt-button">Zapisz mecz</button></form></dialog>';
    }

    public static function predictions(): void {
        global $wpdb;
        $roundId = (int)($_GET['round_id'] ?? 0);
        $s = DT_DB::settings();
        $rounds = $wpdb->get_results($wpdb->prepare('SELECT id,title FROM ' . DT_DB::table('rounds') . ' WHERE season=%s ORDER BY round_no',$s['season']));
        if (!$roundId && $rounds) $roundId = (int)$rounds[0]->id;
        $rows = $roundId ? $wpdb->get_results($wpdb->prepare(
            "SELECT p.*,u.display_name,h.name home_name,a.name away_name,sel.name selected_name,m.score_home,m.score_away
             FROM " . DT_DB::table('predictions') . " p
             JOIN {$wpdb->users} u ON u.ID=p.user_id
             JOIN " . DT_DB::table('matches') . " m ON m.id=p.match_id
             JOIN " . DT_DB::table('teams') . " h ON h.id=m.home_team_id
             JOIN " . DT_DB::table('teams') . " a ON a.id=m.away_team_id
             LEFT JOIN " . DT_DB::table('teams') . " sel ON sel.id=p.selected_team_id
             WHERE m.round_id=%d ORDER BY u.display_name,m.starts_at,m.id", $roundId
        )) : [];
        self::shell('Typy','Kupony są nieedytowalne po zapisaniu.');
        echo '<div class="dt-toolbar"><form method="get"><input type="hidden" name="page" value="decka-typer-predictions"><select name="round_id" onchange="this.form.submit()">'; foreach($rounds as $r) echo '<option value="'.(int)$r->id.'" '.selected($roundId,$r->id,false).'>'.esc_html($r->title).'</option>'; echo '</select></form></div>';
        echo '<section class="dt-card"><table class="widefat dt-table"><thead><tr><th>Użytkownik</th><th>Mecz</th><th>Typowany zwycięzca</th><th>Punkty</th><th>Zapisano</th></tr></thead><tbody>';
        foreach ($rows as $r) echo '<tr><td><strong>'.esc_html($r->display_name).'</strong></td><td>'.esc_html($r->home_name).' – '.esc_html($r->away_name).'</td><td>'.esc_html($r->selected_name ?: '—').'</td><td><strong>'.esc_html((string)(int)$r->points).'</strong></td><td>'.esc_html(self::date_pl($r->submitted_at)).'</td></tr>';
        if (!$rows) echo '<tr><td colspan="5" class="dt-empty">Brak zapisanych typów.</td></tr>';
        echo '</tbody></table></section>';
        self::end_shell();
    }

    public static function ranking(): void {
        $s = DT_DB::settings();
        self::shell('Ranking','Klasyfikacja sezonu ' . $s['season']);
        echo '<section class="dt-card">'; self::ranking_table(DT_Scoring::ranking($s['season'],500)); echo '</section>';
        self::end_shell();
    }

    private static function ranking_table(array $rows): void {
        echo '<table class="widefat dt-table"><thead><tr><th>#</th><th>Użytkownik</th><th>Punkty</th><th>Trafienia</th><th>Typy</th><th>Perfekcyjne kolejki</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td><span class="dt-rank dt-rank-' . min(3,(int)$r['rank']) . '">' . (int)$r['rank'] . '</span></td><td><strong>' . esc_html($r['display_name']) . '</strong></td><td><strong>' . esc_html((string)(int)$r['points']) . '</strong></td><td>' . (int)$r['winner_hits'] . '</td><td>' . (int)$r['predictions'] . '</td><td>' . (int)$r['perfect_rounds'] . '</td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="6" class="dt-empty">Ranking jest jeszcze pusty.</td></tr>';
        echo '</tbody></table>';
    }

    public static function users(): void {
        global $wpdb;
        $s = DT_DB::settings();
        $seasonSql = $wpdb->prepare('%s', $s['season']);
        $users = $wpdb->get_results(
            "SELECT u.ID,u.display_name,u.user_email,
                (SELECT COUNT(*) FROM " . DT_DB::table('round_submissions') . " ss JOIN " . DT_DB::table('rounds') . " rr ON rr.id=ss.round_id WHERE ss.user_id=u.ID AND rr.season=$seasonSql) submissions,
                (SELECT COUNT(*) FROM " . DT_DB::table('predictions') . " pp JOIN " . DT_DB::table('matches') . " mm ON mm.id=pp.match_id JOIN " . DT_DB::table('rounds') . " rr2 ON rr2.id=mm.round_id WHERE pp.user_id=u.ID AND rr2.season=$seasonSql AND pp.selected_team_id IS NOT NULL) predictions,
                (SELECT COALESCE(SUM(pp.points),0) FROM " . DT_DB::table('predictions') . " pp JOIN " . DT_DB::table('matches') . " mm ON mm.id=pp.match_id JOIN " . DT_DB::table('rounds') . " rr2 ON rr2.id=mm.round_id WHERE pp.user_id=u.ID AND rr2.season=$seasonSql AND pp.selected_team_id IS NOT NULL) points,
                (SELECT COUNT(*) FROM " . DT_DB::table('predictions') . " pp JOIN " . DT_DB::table('matches') . " mm ON mm.id=pp.match_id JOIN " . DT_DB::table('rounds') . " rr2 ON rr2.id=mm.round_id WHERE pp.user_id=u.ID AND rr2.season=$seasonSql AND pp.scoring_code='winner') winner_hits
             FROM {$wpdb->users} u
             WHERE EXISTS(SELECT 1 FROM " . DT_DB::table('predictions') . " ppp WHERE ppp.user_id=u.ID)
                OR EXISTS(SELECT 1 FROM " . DT_DB::table('round_submissions') . " sss WHERE sss.user_id=u.ID)
             ORDER BY points DESC,u.display_name"
        );
        self::shell('Użytkownicy','Uczestnicy Typera i ręczne korekty punktów');
        echo '<section class="dt-card"><table class="widefat dt-table"><thead><tr><th>Użytkownik</th><th>Kupony</th><th>Typy</th><th>Trafienia</th><th>Punkty</th><th>Korekta</th></tr></thead><tbody>';
        foreach ($users as $u) {
            echo '<tr><td><div class="dt-user">' . get_avatar((int)$u->ID,34) . '<span><strong>' . esc_html($u->display_name) . '</strong><small class="dt-muted">' . esc_html($u->user_email) . '</small></span></div></td><td>' . (int)$u->submissions . '</td><td>' . (int)$u->predictions . '</td><td>' . (int)$u->winner_hits . '</td><td><strong>' . (int)$u->points . '</strong></td><td><form class="dt-inline-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="dt_adjust_points"><input type="hidden" name="user_id" value="' . (int)$u->ID . '">'; wp_nonce_field('dt_adjust_points'); echo '<input type="number" step="1" name="points" placeholder="± pkt" required><input name="reason" placeholder="Powód" required><button class="button">Dodaj</button></form></td></tr>';
        }
        if (!$users) echo '<tr><td colspan="6" class="dt-empty">Brak uczestników.</td></tr>';
        echo '</tbody></table></section>';
        self::end_shell();
    }

    public static function stats(): void {
        global $wpdb;
        $s = DT_DB::settings();
        $total = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . DT_DB::table('round_submissions') . ' s JOIN ' . DT_DB::table('rounds') . ' r ON r.id=s.round_id WHERE r.season=%s',$s['season']));
        $players = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(DISTINCT s.user_id) FROM ' . DT_DB::table('round_submissions') . ' s JOIN ' . DT_DB::table('rounds') . ' r ON r.id=s.round_id WHERE r.season=%s',$s['season']));
        $hits = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . DT_DB::table('predictions') . " p JOIN " . DT_DB::table('matches') . " m ON m.id=p.match_id JOIN " . DT_DB::table('rounds') . " r ON r.id=m.round_id WHERE r.season=%s AND p.scoring_code='winner'",$s['season']));
        self::shell('Statystyki','Aktywność uczestników w sezonie ' . $s['season']);
        echo '<div class="dt-grid dt-grid-3">'; self::metric('Kupony',$total,'forms','blue'); self::metric('Gracze',$players,'groups','green'); self::metric('Trafione mecze',$hits,'yes-alt','orange'); echo '</div>';
        self::end_shell();
    }

    public static function sync(): void {
        $s = DT_DB::settings();
        $last = get_option('dt_last_sync');
        self::shell('Synchronizacja 1LM','Terminarz i wyniki z oficjalnego źródła');
        echo '<section class="dt-card"><span class="dt-eyebrow">ŹRÓDŁO</span><h2>1lm.pzkosz.pl</h2><p><code>' . esc_html($s['source_url']) . '</code></p><div class="dt-sync-state"><span class="dt-dot ' . (!empty($s['sync_enabled'])?'is-on':'') . '"></span><div><strong>' . (!empty($s['sync_enabled'])?'Synchronizacja automatyczna aktywna':'Synchronizacja automatyczna wyłączona') . '</strong><small>Ostatnio: ' . esc_html($last['at'] ?? 'nigdy') . '</small></div></div><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="dt_sync_now">'; wp_nonce_field('dt_sync_now'); echo '<button class="button button-primary dt-button"><span class="dashicons dashicons-update"></span> Synchronizuj teraz</button></form></section>';
        if (!empty($last['result'])) echo '<section class="dt-card dt-section"><span class="dt-eyebrow">OSTATNI IMPORT</span><h2>Podsumowanie</h2><pre style="white-space:pre-wrap">' . esc_html(wp_json_encode($last['result'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) . '</pre></section>';
        self::end_shell();
    }

    public static function logs(): void {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT l.*,u.display_name FROM ' . DT_DB::table('logs') . ' l LEFT JOIN ' . $wpdb->users . ' u ON u.ID=l.user_id ORDER BY l.id DESC LIMIT 300');
        self::shell('Historia','Log operacji, synchronizacji i zmian');
        echo '<section class="dt-card"><table class="widefat dt-table"><thead><tr><th>Czas</th><th>Zdarzenie</th><th>Opis</th><th>Użytkownik</th></tr></thead><tbody>';
        foreach ($rows as $r) echo '<tr><td>' . esc_html(self::date_pl($r->created_at)) . '</td><td>' . self::badge($r->event,$r->level==='error'?'red':($r->level==='notice'?'orange':'neutral')) . '</td><td>' . esc_html($r->message) . '</td><td>' . esc_html($r->display_name ?: 'System') . '</td></tr>';
        if (!$rows) echo '<tr><td colspan="4" class="dt-empty">Historia jest pusta.</td></tr>';
        echo '</tbody></table></section>';
        self::end_shell();
    }

    public static function settings(): void {
        $s = DT_DB::settings();
        self::shell('Ustawienia','Rozgrywki, punktacja, logowanie i wygląd');
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dt-settings"><input type="hidden" name="action" value="dt_save_settings">';
        wp_nonce_field('dt_save_settings');
        echo '<section class="dt-card"><span class="dt-eyebrow">ROZGRYWKI</span><h2>Sezon i synchronizacja</h2><div class="dt-form-2"><label>Sezon<input name="season" value="' . esc_attr($s['season']) . '"></label><label>Nazwa ligi w nagłówku Typera<input name="league_name" value="' . esc_attr($s['league_name']) . '"></label></div><label>Adres terminarza 1LM<input type="url" name="source_url" value="' . esc_attr($s['source_url']) . '"></label><label class="dt-check"><input type="checkbox" name="sync_enabled" value="1" ' . checked(!empty($s['sync_enabled']),true,false) . '><span><strong>Automatyczna synchronizacja</strong><small>Pobieraj terminarz i wyniki co godzinę. Stan otwarcia kolejki pozostaje zawsze pod kontrolą administratora.</small></span></label></section>';
        echo '<section class="dt-card"><span class="dt-eyebrow">PUNKTACJA</span><h2>Zasady Typera</h2><div class="dt-form-2"><label>Punkty za poprawnego zwycięzcę<input type="number" name="points_winner" step="1" value="' . esc_attr($s['points_winner']) . '"></label><label>Bonus za perfekcyjną kolejkę<input type="number" name="perfect_round_bonus" step="1" value="' . esc_attr($s['perfect_round_bonus']) . '"></label></div><p class="dt-muted">Użytkownik wybiera wyłącznie zwycięzcę. Dokładny wynik nie jest typowany.</p></section>';
        self::provider_fields('Google',[['google_client_id','Client ID','text'],['google_client_secret','Client Secret','password']],DT_OAuth::callback_url('google'),$s);
        self::provider_fields('Facebook',[['facebook_app_id','App ID','text'],['facebook_app_secret','App Secret','password']],DT_OAuth::callback_url('facebook'),$s);
        echo '<section class="dt-card"><span class="dt-eyebrow">WYGLĄD</span><h2>Kolory interfejsu</h2><div class="dt-form-3"><label>Niebieski<input type="color" name="brand_primary" value="' . esc_attr($s['brand_primary']) . '"></label><label>Akcent<input type="color" name="brand_accent" value="' . esc_attr($s['brand_accent']) . '"></label><label>Tło<input type="color" name="brand_surface" value="' . esc_attr($s['brand_surface']) . '"></label></div></section>';
        echo '<div class="dt-savebar"><div><strong>Decka Typer ' . esc_html(DT_VERSION) . '</strong><span>Zmiany ustawień obowiązują od razu.</span></div><button class="button button-primary dt-button">Zapisz ustawienia</button></div></form>';
        self::end_shell();
    }

    private static function provider_fields(string $title, array $fields, string $callback, array $s): void {
        echo '<section class="dt-card"><span class="dt-eyebrow">LOGOWANIE</span><h2>' . esc_html($title) . '</h2><div class="dt-form-2">';
        foreach ($fields as [$name,$label,$type]) echo '<label>' . esc_html($label) . '<input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($s[$name]) . '" ' . ($type === 'password' ? 'autocomplete="new-password"':'') . '></label>';
        echo '</div><div class="dt-callback">Redirect URI: <code>' . esc_html($callback) . '</code></div></section>';
    }

    public static function sync_now(): void {
        self::guard('dt_sync_now');
        $r = DT_Sync::run(true);
        self::redirect('decka-typer-sync',$r['ok']?'Synchronizacja zakończona. Nowe: '.(int)$r['matches_new'].', zaktualizowane: '.(int)$r['matches_updated'].'.':($r['error']??'Błąd synchronizacji.'),$r['ok']?'success':'error');
    }

    public static function save_settings(): void {
        self::guard('dt_save_settings');
        $old = DT_DB::settings();
        $new = $old;
        foreach (['season','league_name','source_url','google_client_id','google_client_secret','facebook_app_id','facebook_app_secret'] as $k) {
            $v = wp_unslash($_POST[$k] ?? '');
            $new[$k] = $k === 'source_url' ? esc_url_raw($v) : sanitize_text_field($v);
        }
        foreach (['points_winner','perfect_round_bonus'] as $k) $new[$k] = (float)($_POST[$k] ?? 0);
        foreach (['brand_primary','brand_accent','brand_surface'] as $k) $new[$k] = sanitize_hex_color($_POST[$k] ?? '') ?: $old[$k];
        $new['sync_enabled'] = !empty($_POST['sync_enabled']) ? 1 : 0;
        foreach (['apple_client_id','apple_team_id','apple_key_id','apple_private_key','points_exact','points_margin'] as $deprecated) unset($new[$deprecated]);
        update_option('dt_settings',$new);
        DT_Logger::log('settings_saved','Zapisano ustawienia Typera.');
        self::redirect('decka-typer-settings','Ustawienia zapisane.');
    }

    public static function add_round(): void {
        self::guard('dt_add_round');
        global $wpdb;
        $s = DT_DB::settings();
        $no = max(1,(int)($_POST['round_no'] ?? 0));
        $title = sanitize_text_field($_POST['title'] ?? '') ?: $no . '. kolejka';
        $now = current_time('mysql');
        $ok = $wpdb->insert(DT_DB::table('rounds'),[
            'season'=>$s['season'],'round_no'=>$no,'title'=>$title,'status'=>'draft','source'=>'manual',
            'external_key'=>sha1($s['season'].'|manual|'.$no),'created_at'=>$now,'updated_at'=>$now
        ]);
        self::redirect('decka-typer-rounds',$ok?'Kolejka dodana jako szkic.':'Nie udało się dodać kolejki.',$ok?'success':'error');
    }

    public static function open_round(): void {
        self::guard('dt_open_round');
        global $wpdb;
        $id = (int)($_POST['round_id'] ?? 0);
        $close = self::mysql_datetime($_POST['closes_at'] ?? '');
        $round = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . DT_DB::table('rounds') . ' WHERE id=%d',$id));
        if (!$round || !$close) self::redirect('decka-typer-rounds','Nieprawidłowe dane kolejki lub terminu zamknięcia.','error');
        $closeDt = self::local_dt($close);
        $now = new DateTimeImmutable('now', wp_timezone());
        if (!$closeDt || $closeDt <= $now) self::redirect('decka-typer-rounds','Termin zamknięcia musi być w przyszłości.','error');
        $firstKnown = $wpdb->get_var($wpdb->prepare('SELECT MIN(starts_at) FROM ' . DT_DB::table('matches') . ' WHERE round_id=%d AND start_time_known=1 AND starts_at IS NOT NULL',$id));
        $firstDt = self::local_dt($firstKnown ?: null);
        if ($firstDt && $closeDt > $firstDt) self::redirect('decka-typer-rounds','Typowanie musi zostać zamknięte najpóźniej o rozpoczęciu pierwszego meczu (' . self::date_pl($firstKnown) . ').','error');
        if ($firstDt && $firstDt <= $now) self::redirect('decka-typer-rounds','Nie można otworzyć kolejki po rozpoczęciu pierwszego meczu.','error');

        $nowSql = current_time('mysql');
        $wpdb->query($wpdb->prepare("UPDATE " . DT_DB::table('rounds') . " SET status='closed',closes_at=CASE WHEN closes_at IS NULL OR closes_at>%s THEN %s ELSE closes_at END,updated_at=%s WHERE season=%s AND status='open' AND id<>%d",$nowSql,$nowSql,$nowSql,$round->season,$id));
        $wpdb->update(DT_DB::table('rounds'),['status'=>'open','opens_at'=>$nowSql,'closes_at'=>$close,'updated_at'=>$nowSql],['id'=>$id],['%s','%s','%s','%s'],['%d']);
        DT_Logger::log('round_opened','Administrator otworzył kolejkę do typowania.',['round_id'=>$id,'closes_at'=>$close], 'notice', get_current_user_id());
        self::redirect('decka-typer-rounds','Typowanie kolejki zostało otwarte do ' . self::date_pl($close) . '.');
    }

    public static function close_round(): void {
        self::guard('dt_close_round');
        global $wpdb;
        $id = (int)($_POST['round_id'] ?? 0);
        $now = current_time('mysql');
        $ok = $wpdb->query($wpdb->prepare("UPDATE " . DT_DB::table('rounds') . " SET status='closed',closes_at=CASE WHEN closes_at IS NULL OR closes_at>%s THEN %s ELSE closes_at END,updated_at=%s WHERE id=%d",$now,$now,$now,$id));
        DT_Logger::log('round_closed','Administrator zamknął typowanie kolejki.',['round_id'=>$id], 'notice', get_current_user_id());
        self::redirect('decka-typer-rounds',$ok !== false ? 'Typowanie kolejki zamknięte.' : 'Nie udało się zamknąć kolejki.',$ok !== false?'success':'error');
    }

    public static function add_match(): void {
        self::guard('dt_add_match');
        global $wpdb;
        $roundId=(int)($_POST['round_id']??0); $home=(int)($_POST['home_team_id']??0); $away=(int)($_POST['away_team_id']??0); $start=self::mysql_datetime($_POST['starts_at']??'');
        if(!$roundId||!$home||!$away||$home===$away||!$start) self::redirect('decka-typer-matches','Sprawdź dane meczu.','error',['round_id'=>$roundId]);
        $sh=self::nullable_int($_POST['score_home']??''); $sa=self::nullable_int($_POST['score_away']??''); $now=current_time('mysql');
        $wpdb->insert(DT_DB::table('matches'),['round_id'=>$roundId,'external_key'=>sha1('manual|'.$roundId.'|'.$home.'|'.$away.'|'.$start.'|'.wp_generate_uuid4()),'home_team_id'=>$home,'away_team_id'=>$away,'starts_at'=>$start,'start_time_known'=>1,'score_home'=>$sh,'score_away'=>$sa,'status'=>($sh!==null&&$sa!==null)?'finished':'scheduled','manual_lock'=>1,'created_at'=>$now,'updated_at'=>$now]);
        $mid=(int)$wpdb->insert_id; if($mid&&$sh!==null&&$sa!==null) DT_Scoring::recalc_match($mid);
        DT_Logger::log('match_added','Dodano mecz ręcznie.',['match_id'=>$mid]);
        self::redirect('decka-typer-matches','Mecz dodany i zabezpieczony przed synchronizacją.','success',['round_id'=>$roundId]);
    }

    public static function save_match(): void {
        self::guard('dt_save_match');
        global $wpdb;
        $id=(int)($_POST['match_id']??0); $m=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DT_DB::table('matches').' WHERE id=%d',$id));
        if(!$m) self::redirect('decka-typer-matches','Nie znaleziono meczu.','error');
        $start=self::mysql_datetime($_POST['starts_at']??''); $sh=self::nullable_int($_POST['score_home']??''); $sa=self::nullable_int($_POST['score_away']??'');
        $data=['starts_at'=>$start?:$m->starts_at,'start_time_known'=>1,'score_home'=>$sh,'score_away'=>$sa,'status'=>($sh!==null&&$sa!==null)?'finished':'scheduled','manual_lock'=>!empty($_POST['manual_lock'])?1:0,'updated_at'=>current_time('mysql')];
        $wpdb->update(DT_DB::table('matches'),$data,['id'=>$id]);
        if($sh!==null&&$sa!==null) DT_Scoring::recalc_match($id);
        DT_Logger::log('match_updated','Administrator poprawił mecz.',['match_id'=>$id,'manual_lock'=>$data['manual_lock']]);
        self::redirect('decka-typer-matches','Mecz zaktualizowany.','success',['round_id'=>(int)$m->round_id]);
    }

    public static function adjust_points(): void {
        self::guard('dt_adjust_points');
        global $wpdb;
        $s=DT_DB::settings(); $uid=(int)($_POST['user_id']??0); $points=(float)($_POST['points']??0); $reason=sanitize_text_field($_POST['reason']??'');
        if(!$uid||$reason==='') self::redirect('decka-typer-users','Sprawdź dane korekty.','error');
        $wpdb->insert(DT_DB::table('point_adjustments'),['user_id'=>$uid,'season'=>$s['season'],'points'=>$points,'reason'=>$reason,'admin_user_id'=>get_current_user_id(),'created_at'=>current_time('mysql')]);
        DT_Logger::log('points_adjusted','Administrator skorygował punkty.',['target_user'=>$uid,'points'=>$points,'reason'=>$reason],'notice',get_current_user_id());
        self::redirect('decka-typer-users','Korekta punktów zapisana.');
    }

    private static function nullable_int($value): ?int {
        if ($value === '' || $value === null) return null;
        return max(0,(int)$value);
    }

    private static function mysql_datetime($value): ?string {
        $value = sanitize_text_field(wp_unslash((string)$value));
        if (!$value) return null;
        $tz = wp_timezone();
        $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $tz);
        if (!$d) $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $tz);
        return $d ? $d->format('Y-m-d H:i:s') : null;
    }
}
