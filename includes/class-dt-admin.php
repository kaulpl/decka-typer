<?php
if (!defined('ABSPATH')) exit;

class DT_Admin {
    public static function register(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        foreach ([
            'sync_now','save_settings','save_match','add_match','add_round','adjust_points',
            'open_round','close_round','reset_typer_data','save_avatar','toggle_expert','update_feedback','test_notifications'
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
            ['decka-typer-users','Użytkownicy','users'],
            ['decka-typer-feedback','Feedback','feedback'],
            ['decka-typer-notifications','Powiadomienia','notifications'],
            ['decka-typer-stats','Statystyki','stats'],
            ['decka-typer-sync','Synchronizacja danych PZKosz','sync'],
            ['decka-typer-logs','Historia','logs'],
            ['decka-typer-avatar','AVATAR','avatar'],
            ['decka-typer-settings','Ustawienia','settings'],
        ];
        foreach ($items as [$slug,$label,$method]) {
            add_submenu_page('decka-typer',$label,$label,$cap,$slug,[__CLASS__,$method]);
        }
    }

    public static function assets(string $hook): void {
        if (strpos($hook, 'decka-typer') === false) return;
        wp_enqueue_style('dt-admin', DT_URL . 'assets/css/admin.css', [], DT_VERSION);
        wp_enqueue_style('dt-admin-predictions', DT_URL . 'assets/css/admin-predictions.css', ['dt-admin'], DT_VERSION);
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
        echo '</section><section class="dt-card"><span class="dt-eyebrow">STAN SYSTEMU</span><h2>Kontrola</h2><div class="dt-health"><div><span>Ostatnia synchronizacja</span><strong>' . esc_html($last['at'] ?? 'jeszcze nie wykonano') . '</strong></div><div><span>Źródła</span><strong>PLK · PZKosz 1LM · PZKosz 2LM</strong></div><div><span>Tryb typowania</span><strong>Zwycięzca meczu</strong></div><div><span>Edycja kuponu</span><strong>Wyłączona po zapisie</strong></div></div></section></div>';
        echo '<section class="dt-card dt-section"><span class="dt-eyebrow">TOP 5</span><h2>Liderzy klasyfikacji</h2>';
        self::ranking_table(DT_Scoring::ranking($season,5));
        echo '</section>';
        self::end_shell();
    }

    public static function rounds(): void {
        global $wpdb;
        $s = DT_DB::settings();
        $league = sanitize_key($_GET['league'] ?? 'all');
        if (!in_array($league,['all','plk','1lm','2lm'],true)) $league='all';
        $group = $league === '2lm' ? strtoupper(sanitize_text_field($_GET['group'] ?? '')) : '';
        if($league==='2lm'&&$group==='')$group=strtoupper((string)$wpdb->get_var($wpdb->prepare("SELECT group_key FROM ".DT_DB::table('rounds')." WHERE season=%s AND league_key='2lm' AND group_key<>'' ORDER BY group_key LIMIT 1",$s['season'])));
        $leagueSql = $league !== 'all' ? $wpdb->prepare(' AND r.league_key=%s ', $league) : '';
        $groupSql = $group !== '' ? $wpdb->prepare(' AND r.group_key=%s ', $group) : '';
        $dtPage=max(1,(int)($_GET['dt_paged']??1));$perPage=25;
        $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".DT_DB::table('rounds')." r WHERE r.season=%s $leagueSql $groupSql",$s['season']));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*,COUNT(m.id) matches,MIN(m.starts_at) first_match,MAX(m.starts_at) last_match,
             MIN(CASE WHEN m.start_time_known=1 THEN m.starts_at END) first_known_match,
             (SELECT COUNT(*) FROM " . DT_DB::table('round_submissions') . " ss WHERE ss.round_id=r.id) submissions
             FROM " . DT_DB::table('rounds') . " r LEFT JOIN " . DT_DB::table('matches') . " m ON m.round_id=r.id
             WHERE r.season=%s $leagueSql $groupSql GROUP BY r.id ORDER BY r.league_key,r.group_key,r.round_no LIMIT %d OFFSET %d", $s['season'],$perPage,($dtPage-1)*$perPage
        ));
        self::shell('Kolejki','Tylko kolejka otwarta przez administratora jest dostępna do nowych typów.');
        echo '<div class="dt-toolbar">';
        self::league_tabs('decka-typer-rounds',$league);
        echo '<button class="button button-primary dt-button" data-dt-open="dt-round-modal"><span class="dashicons dashicons-plus-alt2"></span> Dodaj kolejkę</button></div>';
        if($league==='2lm') self::group_tabs('decka-typer-rounds',$group);
        echo '<section class="dt-card"><table class="widefat dt-table"><thead><tr><th>Kolejka</th><th>Termin meczów</th><th>Mecze</th><th>Kupony</th><th>Status</th><th>Zamknięcie typowania</th><th>Źródło</th><th>Akcje</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $range = $r->first_match ? substr(self::date_pl($r->first_match),0,10) . ' – ' . substr(self::date_pl($r->last_match),0,10) : '—';
            $openData = [
                'id'=>(int)$r->id,
                'title'=>$r->title,
                'default_close'=>self::html_datetime($r->closes_at ?: $r->first_known_match),
                'first_match'=>self::date_pl($r->first_known_match ?: $r->first_match),
            ];
            $leagueLabel = strtoupper((string)$r->league_key) . ($r->group_key ? ' · grupa '.(string)$r->group_key : '');
            echo '<tr><td><small class="dt-muted">'.esc_html($leagueLabel).'</small><br><strong>' . esc_html($r->title) . '</strong></td><td>' . esc_html($range) . '</td><td>' . (int)$r->matches . '</td><td>' . (int)$r->submissions . '</td><td>' . self::round_badge((string)$r->status) . '</td><td><strong>' . esc_html(self::date_pl($r->closes_at)) . '</strong></td><td>' . self::badge($r->source === 'manual' ? 'Ręcznie' : 'Auto PZKosz', $r->source === 'manual' ? 'orange' : 'blue') . '</td><td>';
            if ($r->status === 'open') {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">';
                echo '<input type="hidden" name="action" value="dt_close_round"><input type="hidden" name="round_id" value="' . (int)$r->id . '">';
                wp_nonce_field('dt_close_round');
                echo '<button class="button" onclick="return confirm(\'Zamknąć typowanie tej kolejki?\')">Zamknij</button></form>';
            } else {
                echo '<button type="button" class="button dt-open-round" data-round="' . esc_attr(wp_json_encode($openData)) . '">Otwórz typowanie</button>';
            }
            $matchesUrl = add_query_arg(['page'=>'decka-typer-matches','league'=>$r->league_key,'group'=>$r->group_key,'round_id'=>(int)$r->id],admin_url('admin.php'));
            echo ' <a class="button" href="'.esc_url($matchesUrl).'">Przejdź do kolejki</a>';
            echo '</td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="8" class="dt-empty">Brak kolejek. Uruchom synchronizację danych PZKosz.</td></tr>';
        echo '</tbody></table></section>';
        self::pagination($total,$perPage,$dtPage,['page'=>'decka-typer-rounds','league'=>$league,'group'=>$group]);

        echo '<dialog id="dt-round-modal" class="dt-modal"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><button type="button" class="dt-modal-x" data-dt-close>×</button><span class="dt-eyebrow">NOWA KOLEJKA</span><h2>Dodaj kolejkę ręcznie</h2><input type="hidden" name="action" value="dt_add_round">';
        wp_nonce_field('dt_add_round');
        echo '<div class="dt-form-2"><label>Liga<select name="league_key"><option value="plk">PLK</option><option value="1lm" selected>1LM</option><option value="2lm">2LM</option></select></label><label>Grupa (dla 2LM)<input name="group_key" placeholder="np. A, B, C lub D"></label></div><label>Numer kolejki<input type="number" name="round_no" min="1" max="99" required></label><label>Nazwa<input name="title" placeholder="np. 12. kolejka"></label><div class="dt-inline-warning">Nowa kolejka powstanie jako szkic. Otworzysz ją osobno po ustawieniu terminu zamknięcia typowania.</div><button class="button button-primary dt-button">Zapisz kolejkę</button></form></dialog>';

        echo '<dialog id="dt-open-round-modal" class="dt-modal"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><button type="button" class="dt-modal-x" data-dt-close>×</button><span class="dt-eyebrow">OTWARCIE TYPOWANIA</span><h2 id="dt-open-round-title">Otwórz kolejkę</h2><input type="hidden" name="action" value="dt_open_round"><input type="hidden" name="round_id" id="dt-open-round-id">';
        wp_nonce_field('dt_open_round');
        echo '<label>Zamknięcie możliwości typowania<input type="datetime-local" name="closes_at" id="dt-open-round-close" required></label><div class="dt-inline-warning">Po zapisaniu kuponu użytkownik nie będzie mógł go zmienić. Termin zamknięcia nie może być późniejszy niż start pierwszego meczu ze znaną godziną.</div><button class="button button-primary dt-button">Otwórz typowanie</button></form></dialog>';
        echo '<script>document.addEventListener("click",function(e){var b=e.target.closest(".dt-open-round");if(!b)return;var d={};try{d=JSON.parse(b.dataset.round||"{}");}catch(_){return;}document.getElementById("dt-open-round-id").value=d.id||"";document.getElementById("dt-open-round-title").textContent="Otwórz: "+(d.title||"kolejkę");document.getElementById("dt-open-round-close").value=d.default_close||"";document.getElementById("dt-open-round-modal").showModal();});</script>';
        self::end_shell();
    }

    public static function feedback(): void {
        global $wpdb;
        $table = DT_DB::table('feedback');
        $status = sanitize_key((string)($_GET['status'] ?? 'all'));
        $allowed = ['all','new','in_progress','resolved','cancelled'];
        if (!in_array($status, $allowed, true)) $status = 'all';
        $where = $status === 'all' ? '' : $wpdb->prepare(' WHERE f.status=%s', $status);
        $page = max(1, (int)($_GET['dt_paged'] ?? 1));
        $perPage = 30;
        $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM $table f$where");
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT f.*,u.display_name FROM $table f LEFT JOIN {$wpdb->users} u ON u.ID=f.user_id$where ORDER BY f.created_at DESC,f.id DESC LIMIT %d OFFSET %d",
            $perPage, ($page - 1) * $perPage
        ));
        $counts = array_fill_keys(['new','in_progress','resolved','cancelled'], 0);
        foreach ((array)$wpdb->get_results("SELECT status,COUNT(*) total FROM $table GROUP BY status") as $row) {
            if (isset($counts[$row->status])) $counts[$row->status] = (int)$row->total;
        }
        $labels = ['new'=>'Nowe','in_progress'=>'W trakcie','resolved'=>'Rozwiązano','cancelled'=>'Anulowano'];
        $tones = ['new'=>'orange','in_progress'=>'blue','resolved'=>'green','cancelled'=>'neutral'];

        self::shell('Feedback', 'Zgłoszenia problemów przesłane przez zalogowanych użytkowników.');
        echo '<div class="dt-feedback-summary">';
        foreach ($labels as $key=>$label) self::metric($label, $counts[$key], $key === 'new' ? 'warning' : ($key === 'resolved' ? 'yes-alt' : 'feedback'), $tones[$key] === 'neutral' ? 'violet' : $tones[$key]);
        echo '</div><div class="dt-toolbar dt-feedback-filters">';
        $allUrl = add_query_arg(['page'=>'decka-typer-feedback','status'=>'all'], admin_url('admin.php'));
        echo '<a class="button ' . ($status === 'all' ? 'button-primary' : '') . '" href="' . esc_url($allUrl) . '">Wszystkie</a>';
        foreach ($labels as $key=>$label) {
            $url = add_query_arg(['page'=>'decka-typer-feedback','status'=>$key], admin_url('admin.php'));
            echo '<a class="button ' . ($status === $key ? 'button-primary' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . ' (' . $counts[$key] . ')</a>';
        }
        echo '</div><section class="dt-card dt-feedback-list">';
        if (!$rows) echo '<div class="dt-empty">Brak zgłoszeń w wybranym statusie.</div>';
        foreach ($rows as $row) {
            echo '<article class="dt-feedback-item"><header><div><strong>#' . (int)$row->id . ' · ' . esc_html($row->display_name ?: 'Użytkownik #' . (int)$row->user_id) . '</strong><a href="mailto:' . esc_attr($row->email) . '">' . esc_html($row->email) . '</a></div><div>' . self::badge($labels[$row->status] ?? 'Nowe', $tones[$row->status] ?? 'neutral') . '<time>' . esc_html(self::date_pl($row->created_at)) . '</time></div></header>';
            echo '<div class="dt-feedback-message">' . nl2br(esc_html($row->message)) . '</div>';
            if ($row->page_url) echo '<a class="dt-feedback-source" href="' . esc_url($row->page_url) . '" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-admin-links"></span>' . esc_html($row->page_url) . '</a>';
            echo '<footer><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="dt_update_feedback"><input type="hidden" name="feedback_id" value="' . (int)$row->id . '"><input type="hidden" name="return_status" value="' . esc_attr($status) . '">';
            wp_nonce_field('dt_update_feedback_' . (int)$row->id);
            echo '<label>Status zgłoszenia <select name="status">';
            foreach ($labels as $key=>$label) echo '<option value="' . esc_attr($key) . '" ' . selected($row->status, $key, false) . '>' . esc_html($label) . '</option>';
            echo '</select></label><button class="button button-primary">Zapisz status</button></form></footer></article>';
        }
        echo '</section>';
        if ($total > $perPage) {
            $base = add_query_arg(['page'=>'decka-typer-feedback','status'=>$status,'dt_paged'=>'%#%'], admin_url('admin.php'));
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(paginate_links(['base'=>$base,'format'=>'','current'=>$page,'total'=>(int)ceil($total/$perPage)])) . '</div></div>';
        }
        self::end_shell();
    }

    public static function matches(): void {
        global $wpdb;
        $s = DT_DB::settings();
        $league = sanitize_key($_GET['league'] ?? 'all');
        if (!in_array($league,['all','plk','1lm','2lm'],true)) $league='all';
        $group = $league === '2lm' ? strtoupper(sanitize_text_field($_GET['group'] ?? '')) : '';
        if($league==='2lm'&&$group==='')$group=strtoupper((string)$wpdb->get_var($wpdb->prepare("SELECT group_key FROM ".DT_DB::table('rounds')." WHERE season=%s AND league_key='2lm' AND group_key<>'' ORDER BY group_key LIMIT 1",$s['season'])));
        $roundId = (int)($_GET['round_id'] ?? 0);
        $leagueSql = $league !== 'all' ? $wpdb->prepare(' AND league_key=%s ', $league) : '';
        $groupSql = $group !== '' ? $wpdb->prepare(' AND group_key=%s ', $group) : '';
        $rounds = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . DT_DB::table('rounds') . " WHERE season=%s $leagueSql $groupSql ORDER BY league_key,group_key,round_no", $s['season']));
        $roundIds = array_map('intval',wp_list_pluck($rounds,'id'));
        if ($roundId && !in_array($roundId,$roundIds,true)) $roundId=0;
        if (!$roundId && $rounds) $roundId = (int)$rounds[0]->id;
        $currentRound = $roundId ? $wpdb->get_row($wpdb->prepare('SELECT * FROM '.DT_DB::table('rounds').' WHERE id=%d',$roundId)) : null;
        $dtPage = max(1,(int)($_GET['dt_paged'] ?? 1));
        $perPage = 25;
        $total = $roundId ? (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DT_DB::table('matches').' WHERE round_id=%d',$roundId)) : 0;
        $rows = $roundId ? $wpdb->get_results($wpdb->prepare(
            'SELECT m.*,h.name home_name,a.name away_name FROM ' . DT_DB::table('matches') . ' m JOIN ' . DT_DB::table('teams') . ' h ON h.id=m.home_team_id JOIN ' . DT_DB::table('teams') . ' a ON a.id=m.away_team_id WHERE m.round_id=%d ORDER BY m.starts_at,m.id LIMIT %d OFFSET %d', $roundId,$perPage,($dtPage-1)*$perPage
        )) : [];
        self::shell('Mecze','Wynik meczu służy wyłącznie do rozliczenia wskazanego zwycięzcy.');
        echo '<div class="dt-toolbar">'; self::league_tabs('decka-typer-matches',$league); echo '<button class="button button-primary dt-button" data-dt-open="dt-add-match"><span class="dashicons dashicons-plus-alt2"></span> Dodaj mecz</button></div>';
        if($league==='2lm') self::group_tabs('decka-typer-matches',$group);
        if ($rounds) {
            echo '<nav class="dt-round-tabs" aria-label="Wybór kolejki">';
            foreach ($rounds as $r) {
                $label = ($r->group_key ? 'Grupa '.strtoupper((string)$r->group_key).' · ' : '').$r->title;
                $url=add_query_arg(['page'=>'decka-typer-matches','league'=>$league,'group'=>$group,'round_id'=>(int)$r->id],admin_url('admin.php'));
                echo '<a class="dt-round-tab '.($roundId===(int)$r->id?'is-active':'').'" href="'.esc_url($url).'">'.esc_html($label).'</a>';
            }
            echo '</nav>';
        }
        if ($currentRound) echo '<div class="dt-list-context"><strong>'.esc_html(strtoupper((string)$currentRound->league_key).($currentRound->group_key?' · grupa '.strtoupper((string)$currentRound->group_key):'')).'</strong><span>'.esc_html($currentRound->title).' · '.(int)$total.' meczów</span></div>';
        echo '<section class="dt-card"><table class="widefat dt-table"><thead><tr><th>Mecz</th><th>Start</th><th>Wynik</th><th>Status</th><th>Synchronizacja</th><th></th></tr></thead><tbody>';
        foreach ($rows as $m) {
            $score = $m->score_home === null ? '—' : (int)$m->score_home . ' : ' . (int)$m->score_away;
            $data = ['id'=>(int)$m->id,'home'=>$m->home_name,'away'=>$m->away_name,'starts_at'=>self::html_datetime($m->starts_at),'home_score'=>$m->score_home,'away_score'=>$m->score_away,'manual_lock'=>(int)$m->manual_lock];
            echo '<tr><td><strong>' . esc_html($m->home_name) . '</strong><span class="dt-versus">vs</span><strong>' . esc_html($m->away_name) . '</strong></td><td>' . esc_html(self::date_pl($m->starts_at)) . '</td><td><span class="dt-score">' . esc_html($score) . '</span></td><td>' . self::badge($m->status === 'finished' ? 'Zakończony':'Zaplanowany',$m->status === 'finished' ? 'green':'neutral') . '</td><td>' . ((int)$m->manual_lock ? self::badge('Ręczny · chroniony','orange') : self::badge('Auto PZKosz','blue')) . '</td><td><button type="button" class="button dt-edit-match" data-match="' . esc_attr(wp_json_encode($data)) . '">Edytuj</button></td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="6" class="dt-empty">Brak meczów w tej kolejce.</td></tr>';
        echo '</tbody></table></section>';
        self::pagination($total,$perPage,$dtPage,['page'=>'decka-typer-matches','league'=>$league,'group'=>$group,'round_id'=>$roundId]);
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
        if (!$add) echo '<label class="dt-check"><input type="checkbox" name="manual_lock" value="1" data-field="manual_lock"><span><strong>Chroń przed synchronizacją danych PZKosz</strong><small>Automatyczny import nie nadpisze ręcznie poprawionego terminu ani wyniku.</small></span></label>';
        echo '<button class="button button-primary dt-button">Zapisz mecz</button></form></dialog>';
    }

    private static function league_tabs(string $page, string $active): void {
        echo '<nav class="dt-league-tabs" aria-label="Wybór ligi">';
        foreach (['all'=>'Wszystkie','plk'=>'PLK','1lm'=>'1LM','2lm'=>'2LM'] as $key=>$label) {
            $url=add_query_arg(['page'=>$page,'league'=>$key],admin_url('admin.php'));
            echo '<a class="dt-league-tab '.($active===$key?'is-active':'').'" href="'.esc_url($url).'">'.esc_html($label).'</a>';
        }
        echo '</nav>';
    }

    private static function group_tabs(string $page, string $active): void {
        global $wpdb;
        $season=(string)(DT_DB::settings()['season']??'');
        $active=strtoupper(trim($active));
        $groups=array_values(array_unique(array_filter(array_map(static fn($group)=>strtoupper(trim((string)$group)),(array)$wpdb->get_col($wpdb->prepare("SELECT DISTINCT group_key FROM ".DT_DB::table('rounds')." WHERE season=%s AND league_key='2lm' AND group_key<>'' ORDER BY group_key",$season))))));
        echo '<nav class="dt-group-tabs" aria-label="Wybór grupy 2LM">';
        foreach ($groups as $key) {
            $label='Grupa '.$key;
            $url=add_query_arg(['page'=>$page,'league'=>'2lm','group'=>$key],admin_url('admin.php'));
            echo '<a class="dt-group-tab '.($active===$key?'is-active':'').'" href="'.esc_url($url).'">'.esc_html($label).'</a>';
        }
        echo '</nav>';
    }

    private static function pagination(int $total, int $perPage, int $current, array $args): void {
        $pages=(int)ceil($total/$perPage);
        if ($pages<2) return;
        echo '<nav class="dt-pagination" aria-label="Stronicowanie">';
        for ($page=1;$page<=$pages;$page++) {
            $url=add_query_arg(array_merge($args,['dt_paged'=>$page]),admin_url('admin.php'));
            echo '<a class="'.($page===$current?'is-active':'').'" href="'.esc_url($url).'">'.(int)$page.'</a>';
        }
        echo '</nav>';
    }

    public static function predictions(): void {
        global $wpdb;
        $type = sanitize_key((string)($_GET['prediction_type'] ?? 'all'));
        if (!in_array($type, ['all','match','pre1','pre2'], true)) $type = 'all';
        $statusFilter = sanitize_key((string)($_GET['prediction_status'] ?? 'all'));
        if (!in_array($statusFilter, ['all','pending','winner','miss'], true)) $statusFilter = 'all';
        $season = sanitize_text_field(wp_unslash((string)($_GET['season'] ?? 'all')));
        $league = sanitize_key((string)($_GET['league'] ?? 'all'));
        if (!in_array($league, ['all','1lm','plk','2lm'], true)) $league = 'all';
        $group = $league === '2lm' ? strtoupper(sanitize_text_field(wp_unslash((string)($_GET['group'] ?? '')))) : '';
        $roundId = max(0, (int)($_GET['round_id'] ?? 0));
        if (in_array($type, ['pre1','pre2'], true)) $roundId = 0;
        $search = trim(sanitize_text_field(wp_unslash((string)($_GET['s'] ?? ''))));
        $dtPage = max(1, (int)($_GET['dt_paged'] ?? 1));
        $perPage = 40;

        $roundsTable = DT_DB::table('rounds');
        $matchesTable = DT_DB::table('matches');
        $predictionsTable = DT_DB::table('predictions');
        $preseasonTable = DT_DB::table('preseason_predictions');
        $teamsTable = DT_DB::table('teams');

        $seasons = array_values(array_unique(array_filter(array_map('strval', (array)$wpdb->get_col(
            "SELECT season FROM $roundsTable WHERE season<>'' UNION SELECT season FROM $preseasonTable WHERE season<>'' ORDER BY season DESC"
        )))));
        if ($season !== 'all' && !in_array($season, $seasons, true)) $season = 'all';

        $roundFilter = '';
        if ($season !== 'all') $roundFilter .= $wpdb->prepare(' AND season=%s', $season);
        if ($league !== 'all') $roundFilter .= $wpdb->prepare(' AND league_key=%s', $league);
        if ($group !== '') $roundFilter .= $wpdb->prepare(' AND UPPER(group_key)=%s', $group);
        $rounds = $wpdb->get_results("SELECT id,title,season,league_key,group_key,round_no FROM $roundsTable WHERE 1=1 $roundFilter ORDER BY season DESC,league_key,group_key,round_no,id");
        $validRoundIds = array_map('intval', wp_list_pluck((array)$rounds, 'id'));
        if ($roundId && !in_array($roundId, $validRoundIds, true)) $roundId = 0;

        $regularWhere = ' WHERE p.selected_team_id IS NOT NULL';
        $preWhere = ' WHERE 1=1';
        if ($season !== 'all') {
            $regularWhere .= $wpdb->prepare(' AND r.season=%s', $season);
            $preWhere .= $wpdb->prepare(' AND pp.season=%s', $season);
        }
        if ($league !== 'all') {
            $regularWhere .= $wpdb->prepare(' AND r.league_key=%s', $league);
            $preWhere .= $wpdb->prepare(' AND pp.league_key=%s', $league);
        }
        if ($group !== '') {
            $regularWhere .= $wpdb->prepare(' AND UPPER(r.group_key)=%s', $group);
            $preWhere .= $wpdb->prepare(' AND UPPER(pp.group_key)=%s', $group);
        }
        if ($roundId) $regularWhere .= $wpdb->prepare(' AND r.id=%d', $roundId);
        if ($statusFilter === 'pending') $regularWhere .= ' AND (m.score_home IS NULL OR m.score_away IS NULL)';
        elseif ($statusFilter === 'winner') $regularWhere .= " AND p.scoring_code='winner'";
        elseif ($statusFilter === 'miss') $regularWhere .= " AND m.score_home IS NOT NULL AND m.score_away IS NOT NULL AND (p.scoring_code<>'winner' OR p.scoring_code IS NULL)";

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $regularWhere .= $wpdb->prepare(
                ' AND (u.display_name LIKE %s OR u.user_email LIKE %s OR h.name LIKE %s OR a.name LIKE %s OR sel.name LIKE %s OR r.title LIKE %s OR r.league_key LIKE %s OR p.scoring_code LIKE %s)',
                $like, $like, $like, $like, $like, $like, $like, $like
            );
            $preTeamSearch='';
            foreach ((array)$wpdb->get_col($wpdb->prepare("SELECT id FROM $teamsTable WHERE name LIKE %s LIMIT 30",$like)) as $teamId) {
                $preTeamSearch .= $wpdb->prepare(' OR pp.selections REGEXP %s','(^|[^0-9])'.(int)$teamId.'([^0-9]|$)');
            }
            $preWhere .= $wpdb->prepare(
                ' AND (u.display_name LIKE %s OR u.user_email LIKE %s OR pp.prediction_type LIKE %s OR pp.league_key LIKE %s OR pp.group_key LIKE %s OR pp.selections LIKE %s'.$preTeamSearch.')',
                $like, $like, $like, $like, $like, $like
            );
        }

        $parts = [];
        if (in_array($type, ['all','match'], true)) {
            $parts[] = "SELECT p.id source_id,'match' prediction_type,p.user_id,u.display_name,u.user_email,
                        r.season,r.league_key,r.group_key,r.id round_id,r.round_no,r.title round_title,
                        CONCAT(h.name,' – ',a.name) context_label,sel.name selection_data,
                        p.points,p.scoring_code,m.score_home,m.score_away,p.submitted_at
                 FROM $predictionsTable p
                 JOIN {$wpdb->users} u ON u.ID=p.user_id
                 JOIN $matchesTable m ON m.id=p.match_id
                 JOIN $roundsTable r ON r.id=m.round_id
                 JOIN $teamsTable h ON h.id=m.home_team_id
                 JOIN $teamsTable a ON a.id=m.away_team_id
                 LEFT JOIN $teamsTable sel ON sel.id=p.selected_team_id
                 $regularWhere";
        }
        if (!$roundId && in_array($type, ['all','pre1','pre2'], true) && in_array($statusFilter,['all','pending'],true)) {
            if ($type !== 'all') $preWhere .= $wpdb->prepare(' AND pp.prediction_type=%s', $type);
            $parts[] = "SELECT pp.id source_id,pp.prediction_type,pp.user_id,u.display_name,u.user_email,
                        pp.season,pp.league_key,pp.group_key,0 round_id,0 round_no,UPPER(pp.prediction_type) round_title,
                        'Typowanie przedsezonowe' context_label,pp.selections selection_data,
                        pp.points,'pending' scoring_code,NULL score_home,NULL score_away,pp.submitted_at
                 FROM $preseasonTable pp
                 JOIN {$wpdb->users} u ON u.ID=pp.user_id
                 $preWhere";
        }

        $union = $parts ? implode(' UNION ALL ', $parts) : '';
        $total = $union !== '' ? (int)$wpdb->get_var("SELECT COUNT(*) FROM ($union) dt_all_types") : 0;
        $pages = max(1, (int)ceil($total / $perPage));
        if ($dtPage > $pages) $dtPage = $pages;
        $offset = ($dtPage - 1) * $perPage;
        $rows = $union !== '' ? $wpdb->get_results("SELECT * FROM ($union) dt_all_types ORDER BY submitted_at DESC,prediction_type,source_id DESC LIMIT ".(int)$perPage.' OFFSET '.(int)$offset) : [];

        $teamNames = [];
        foreach ((array)$wpdb->get_results("SELECT id,name FROM $teamsTable") as $team) $teamNames[(int)$team->id] = (string)$team->name;

        self::shell('Typy','Wszystkie zapisane typy meczowe oraz prognozy PRE - FinalRanking i PRE - PlayOFF. Łącznie: '.number_format_i18n($total).'.');
        echo '<form method="get" class="dt-card dt-prediction-filters"><input type="hidden" name="page" value="decka-typer-predictions"><div class="dt-form-3">';
        echo '<label>Rodzaj<select name="prediction_type"><option value="all" '.selected($type,'all',false).'>Wszystkie typy</option><option value="match" '.selected($type,'match',false).'>Typy meczowe</option><option value="pre1" '.selected($type,'pre1',false).'>PRE - FinalRanking</option><option value="pre2" '.selected($type,'pre2',false).'>PRE - PlayOFF</option></select></label>';
        echo '<label>Status<select name="prediction_status"><option value="all" '.selected($statusFilter,'all',false).'>Wszystkie statusy</option><option value="pending" '.selected($statusFilter,'pending',false).'>Oczekujące</option><option value="winner" '.selected($statusFilter,'winner',false).'>Trafione</option><option value="miss" '.selected($statusFilter,'miss',false).'>Nietrafione</option></select></label>';
        echo '<label>Sezon<select name="season"><option value="all">Wszystkie sezony</option>'; foreach ($seasons as $item) echo '<option value="'.esc_attr($item).'" '.selected($season,$item,false).'>'.esc_html($item).'</option>'; echo '</select></label>';
        echo '<label>Liga<select name="league"><option value="all" '.selected($league,'all',false).'>Wszystkie ligi</option><option value="1lm" '.selected($league,'1lm',false).'>1LM</option><option value="plk" '.selected($league,'plk',false).'>PLK</option><option value="2lm" '.selected($league,'2lm',false).'>2LM</option></select></label>';
        echo '<label>Grupa 2LM<input name="group" value="'.esc_attr($group).'" placeholder="A, B, C lub D"></label>';
        echo '<label>Kolejka<select name="round_id"><option value="0">Wszystkie kolejki</option>'; foreach ((array)$rounds as $round) { $label=strtoupper((string)$round->league_key).($round->group_key?' · grupa '.strtoupper((string)$round->group_key):'').' · '.$round->title.' · '.$round->season; echo '<option value="'.(int)$round->id.'" '.selected($roundId,(int)$round->id,false).'>'.esc_html($label).'</option>'; } echo '</select></label>';
        echo '<label>Wyszukaj<input type="search" name="s" value="'.esc_attr($search).'" placeholder="Użytkownik, e-mail, drużyna, status…"></label></div><div class="dt-prediction-filter-actions"><button class="button button-primary">Filtruj i wyszukaj</button><a class="button" href="'.esc_url(admin_url('admin.php?page=decka-typer-predictions')).'">Wyczyść filtry</a></div></form>';

        echo '<section class="dt-card dt-predictions-table"><div class="dt-table-scroll"><table class="widefat dt-table"><thead><tr><th>Rodzaj</th><th>Użytkownik</th><th>Liga</th><th>Kolejka / mecz</th><th>Oddany typ</th><th>Status</th><th>Punkty</th><th>Zapisano</th></tr></thead><tbody>';
        foreach ((array)$rows as $row) {
            $uid=(int)$row->user_id;
            $displayName=class_exists('DT_User_Settings')?DT_User_Settings::ranking_name($uid,(string)$row->display_name):(string)$row->display_name;
            $isPre=in_array((string)$row->prediction_type,['pre1','pre2'],true);
            $typeLabel=$isPre?((string)$row->prediction_type==='pre1'?'PRE - FinalRanking':'PRE - PlayOFF'):'MECZ';
            $leagueLabel=strtoupper((string)$row->league_key).($row->group_key?' · '.$row->group_key:'').' · '.$row->season;
            $context=$isPre?$typeLabel:((string)$row->round_title.' · '.(string)$row->context_label);
            $selection=$isPre?self::preseason_selection_label((string)$row->prediction_type,(string)$row->selection_data,$teamNames):(string)($row->selection_data?:'—');
            if ($isPre) $status=self::badge('Oczekuje na rozliczenie','orange');
            elseif ($row->score_home === null || $row->score_away === null) $status=self::badge('Oczekuje','neutral');
            elseif ($row->scoring_code === 'winner') $status=self::badge('Trafiony','green');
            else $status=self::badge('Nietrafiony','red');
            echo '<tr><td>'.self::badge($typeLabel,$isPre?'orange':'blue').'</td><td><strong>'.esc_html($displayName).'</strong><small class="dt-muted">'.esc_html((string)$row->user_email).'</small></td><td>'.esc_html($leagueLabel).'</td><td>'.esc_html($context).'</td><td class="dt-prediction-selection">'.esc_html($selection).'</td><td>'.$status.'</td><td><strong>'.esc_html((string)(float)$row->points).'</strong></td><td>'.esc_html(self::date_pl((string)$row->submitted_at)).'</td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="8" class="dt-empty">Brak zapisanych typów spełniających wybrane kryteria.</td></tr>';
        echo '</tbody></table></div></section>';

        $paginationArgs=['page'=>'decka-typer-predictions','prediction_type'=>$type,'prediction_status'=>$statusFilter,'season'=>$season,'league'=>$league,'group'=>$group,'round_id'=>$roundId,'s'=>$search];
        if ($total > $perPage) {
            $base=add_query_arg(array_merge($paginationArgs,['dt_paged'=>'%#%']),admin_url('admin.php'));
            echo '<nav class="dt-pagination" aria-label="Stronicowanie">'.wp_kses_post(paginate_links(['base'=>$base,'format'=>'','current'=>$dtPage,'total'=>$pages,'mid_size'=>2,'end_size'=>1,'prev_text'=>'←','next_text'=>'→'])).'</nav>';
        }
        self::end_shell();
    }

    private static function preseason_selection_label(string $type, string $json, array $teamNames): string {
        $data=json_decode($json,true);
        if (!is_array($data) || !$data) return '—';
        if ($type === 'pre1') {
            $items=[];
            foreach ($data as $teamId=>$bracket) $items[] = ($teamNames[(int)$teamId] ?? 'Drużyna #'.(int)$teamId).': '.$bracket;
            return implode('; ', $items);
        }
        $items=[];
        foreach ($data as $teamId) $items[]=$teamNames[(int)$teamId] ?? 'Drużyna #'.(int)$teamId;
        return implode(', ', $items);
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
            echo '<tr class="'.(!empty($r['is_expert'])?'dt-expert-row':'').'"><td><span class="dt-rank dt-rank-' . min(3,(int)$r['rank']) . '">' . (int)$r['rank'] . '</span></td><td><strong>' . esc_html($r['display_name']) . '</strong>'.(!empty($r['is_expert'])?' <span class="dt-expert-badge">EKSPERT!</span>':'').'</td><td><strong>' . esc_html((string)(int)$r['points']) . '</strong></td><td>' . (int)$r['winner_hits'] . '</td><td>' . (int)$r['predictions'] . '</td><td>' . (int)$r['perfect_rounds'] . '</td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="6" class="dt-empty">Ranking jest jeszcze pusty.</td></tr>';
        echo '</tbody></table>';
    }

    public static function users(): void {
        global $wpdb;
        $s = DT_DB::settings();
        $seasonSql = $wpdb->prepare('%s', $s['season']);
        $dtPage=max(1,(int)($_GET['dt_paged']??1));$perPage=25;
        $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users} u WHERE EXISTS(SELECT 1 FROM ".DT_DB::table('predictions')." p WHERE p.user_id=u.ID) OR EXISTS(SELECT 1 FROM ".DT_DB::table('round_submissions')." s WHERE s.user_id=u.ID)");
        $users = $wpdb->get_results(
            "SELECT u.ID,u.display_name,u.user_email,
                (SELECT COUNT(*) FROM " . DT_DB::table('round_submissions') . " ss JOIN " . DT_DB::table('rounds') . " rr ON rr.id=ss.round_id WHERE ss.user_id=u.ID AND rr.season=$seasonSql) submissions,
                (SELECT COUNT(*) FROM " . DT_DB::table('predictions') . " pp JOIN " . DT_DB::table('matches') . " mm ON mm.id=pp.match_id JOIN " . DT_DB::table('rounds') . " rr2 ON rr2.id=mm.round_id WHERE pp.user_id=u.ID AND rr2.season=$seasonSql AND pp.selected_team_id IS NOT NULL) predictions,
                (SELECT COALESCE(SUM(pp.points),0) FROM " . DT_DB::table('predictions') . " pp JOIN " . DT_DB::table('matches') . " mm ON mm.id=pp.match_id JOIN " . DT_DB::table('rounds') . " rr2 ON rr2.id=mm.round_id WHERE pp.user_id=u.ID AND rr2.season=$seasonSql AND pp.selected_team_id IS NOT NULL) points,
                (SELECT COUNT(*) FROM " . DT_DB::table('predictions') . " pp JOIN " . DT_DB::table('matches') . " mm ON mm.id=pp.match_id JOIN " . DT_DB::table('rounds') . " rr2 ON rr2.id=mm.round_id WHERE pp.user_id=u.ID AND rr2.season=$seasonSql AND pp.scoring_code='winner') winner_hits
             FROM {$wpdb->users} u
             WHERE EXISTS(SELECT 1 FROM " . DT_DB::table('predictions') . " ppp WHERE ppp.user_id=u.ID)
                OR EXISTS(SELECT 1 FROM " . DT_DB::table('round_submissions') . " sss WHERE sss.user_id=u.ID)
             ORDER BY points DESC,u.display_name LIMIT ".(int)$perPage." OFFSET ".(int)(($dtPage-1)*$perPage)
        );
        self::shell('Użytkownicy','Uczestnicy Typera i ręczne korekty punktów');
        echo '<section class="dt-card"><table class="widefat dt-table"><thead><tr><th>Użytkownik</th><th>Kupony</th><th>Typy</th><th>Trafienia</th><th>Punkty</th><th>Ekspert</th><th>Korekta</th></tr></thead><tbody>';
        foreach ($users as $u) {
            $expert = DT_User_Settings::is_expert((int)$u->ID);
            echo '<tr class="'.($expert?'dt-expert-row':'').'"><td><div class="dt-user">' . get_avatar((int)$u->ID,34) . '<span><strong>' . esc_html($u->display_name) . ($expert?' <span class="dt-expert-badge">EKSPERT!</span>':'') . '</strong><small class="dt-muted">' . esc_html($u->user_email) . '</small></span></div></td><td>' . (int)$u->submissions . '</td><td>' . (int)$u->predictions . '</td><td>' . (int)$u->winner_hits . '</td><td><strong>' . (int)$u->points . '</strong></td><td><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="dt_toggle_expert"><input type="hidden" name="user_id" value="' . (int)$u->ID . '">'; wp_nonce_field('dt_toggle_expert'); echo '<button class="button '.($expert?'dt-unmark-expert':'dt-mark-expert').'">'.($expert?'Odznacz jako ekspert':'Oznacz jako ekspert').'</button></form></td><td><form class="dt-inline-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="dt_adjust_points"><input type="hidden" name="user_id" value="' . (int)$u->ID . '">'; wp_nonce_field('dt_adjust_points'); echo '<input type="number" step="1" name="points" placeholder="± pkt" required><input name="reason" placeholder="Powód" required><button class="button">Dodaj</button></form></td></tr>';
        }
        if (!$users) echo '<tr><td colspan="7" class="dt-empty">Brak uczestników.</td></tr>';
        echo '</tbody></table></section>';
        self::pagination($total,$perPage,$dtPage,['page'=>'decka-typer-users']);
        self::end_shell();
    }

    public static function notifications(): void {
        global $wpdb;
        $table=DT_DB::table('notifications');$page=max(1,(int)($_GET['dt_paged']??1));$perPage=40;
        $total=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
        $rows=$wpdb->get_results($wpdb->prepare("SELECT n.*,u.display_name,u.user_email FROM $table n LEFT JOIN {$wpdb->users} u ON u.ID=n.user_id ORDER BY n.created_at DESC,n.id DESC LIMIT %d OFFSET %d",$perPage,($page-1)*$perPage));
        self::shell('Powiadomienia','Historia wysłanych i nieudanych przypomnień Web Push/PWA oraz e-mail. Łącznie: '.number_format_i18n($total).'.');
        echo '<section class="dt-card"><table class="widefat dt-table"><thead><tr><th>Data</th><th>Użytkownik</th><th>Kanał</th><th>Zdarzenie</th><th>Wiadomość</th><th>Status</th></tr></thead><tbody>';
        foreach ((array)$rows as $row) echo '<tr><td>'.esc_html(self::date_pl((string)$row->created_at)).'</td><td><strong>'.esc_html((string)($row->display_name?:'Użytkownik #'.$row->user_id)).'</strong><small class="dt-muted">'.esc_html((string)$row->user_email).'</small></td><td>'.self::badge(strtoupper((string)$row->channel),'blue').'</td><td>'.esc_html((string)$row->event_type).'</td><td><strong>'.esc_html((string)$row->title).'</strong><small class="dt-muted">'.esc_html((string)$row->message).'</small></td><td>'.self::badge((string)$row->status,$row->status==='sent'?'green':($row->status==='failed'?'red':'orange')).'</td></tr>';
        if (!$rows) echo '<tr><td colspan="6" class="dt-empty">Historia powiadomień jest jeszcze pusta.</td></tr>';
        echo '</tbody></table></section>';
        self::pagination($total,$perPage,$page,['page'=>'decka-typer-notifications']);self::end_shell();
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
        self::shell('Synchronizacja danych PZKosz','Terminarze, wyniki i drużyny z PLK, 1LM oraz grup A–D 2LM');
        echo '<section class="dt-card"><span class="dt-eyebrow">OFICJALNE ŹRÓDŁA</span><h2>PLK i rozgrywki PZKosz</h2><div class="dt-health"><div><span>PLK</span><strong>'.esc_html($s['source_plk_url']).'</strong></div><div><span>1 Liga Mężczyzn</span><strong>'.esc_html($s['source_1lm_url']).'</strong></div><div><span>2 Liga Mężczyzn</span><strong>'.esc_html($s['source_2lm_url']).'</strong></div><div><span>Częstotliwość</span><strong>Co '.(int)($s['sync_interval_minutes']??60).' minut</strong></div></div><div class="dt-sync-state"><span class="dt-dot ' . (!empty($s['sync_enabled'])?'is-on':'') . '"></span><div><strong>' . (!empty($s['sync_enabled'])?'Synchronizacja automatyczna aktywna':'Synchronizacja automatyczna wyłączona') . '</strong><small>Ostatnio: ' . esc_html($last['at'] ?? 'nigdy') . '</small></div></div><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="dt_sync_now">'; wp_nonce_field('dt_sync_now'); echo '<button class="button button-primary dt-button"><span class="dashicons dashicons-update"></span> Synchronizuj wszystkie ligi</button></form></section>';
        if (!empty($last['result'])) echo '<section class="dt-card dt-section"><span class="dt-eyebrow">OSTATNI IMPORT</span><h2>Podsumowanie</h2><pre style="white-space:pre-wrap">' . esc_html(wp_json_encode($last['result'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) . '</pre></section>';
        self::end_shell();
    }

    public static function logs(): void {
        global $wpdb;
        $dtPage=max(1,(int)($_GET['dt_paged']??1));$perPage=30;$total=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.DT_DB::table('logs'));
        $rows = $wpdb->get_results('SELECT l.*,u.display_name FROM ' . DT_DB::table('logs') . ' l LEFT JOIN ' . $wpdb->users . ' u ON u.ID=l.user_id ORDER BY l.id DESC LIMIT '.(int)$perPage.' OFFSET '.(int)(($dtPage-1)*$perPage));
        self::shell('Historia','Log operacji, synchronizacji i zmian');
        echo '<section class="dt-card"><table class="widefat dt-table"><thead><tr><th>Czas</th><th>Zdarzenie</th><th>Opis</th><th>Użytkownik</th></tr></thead><tbody>';
        foreach ($rows as $r) echo '<tr><td>' . esc_html(self::date_pl($r->created_at)) . '</td><td>' . self::badge($r->event,$r->level==='error'?'red':($r->level==='notice'?'orange':'neutral')) . '</td><td>' . esc_html($r->message) . '</td><td>' . esc_html($r->display_name ?: 'System') . '</td></tr>';
        if (!$rows) echo '<tr><td colspan="4" class="dt-empty">Historia jest pusta.</td></tr>';
        echo '</tbody></table></section>';
        self::pagination($total,$perPage,$dtPage,['page'=>'decka-typer-logs']);
        self::end_shell();
    }

    public static function settings(): void {
        $s = DT_DB::settings();
        self::shell('Ustawienia','Rozgrywki, punktacja, logowanie i wygląd');
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="dt-settings"><input type="hidden" name="action" value="dt_save_settings">';
        wp_nonce_field('dt_save_settings');
        echo '<section class="dt-card"><span class="dt-eyebrow">TRYB SERWISU</span><h2>Dostępność TypujKosza.pl</h2><div class="dt-mode-options">';
        foreach (['production'=>'Produkcyjny','test'=>'Testowy','break'=>'Przerwa'] as $value=>$label) echo '<label class="dt-mode-card"><input type="radio" name="site_mode" value="'.esc_attr($value).'" '.checked(($s['site_mode']??'test'),$value,false).'><span><strong>'.esc_html($label).'</strong><small>'.esc_html($value==='production'?'Pełne działanie serwisu.':($value==='test'?'Żółty komunikat o wersji testowej.':'Ekran startowy; wp-admin pozostaje dostępny.')).'</small></span></label>';
        echo '</div></section>';
        echo '<section class="dt-card"><span class="dt-eyebrow">ROZGRYWKI</span><h2>Ligi i sezon</h2><div class="dt-form-2"><label>Sezon<input name="season" value="' . esc_attr($s['season']) . '"></label></div><div class="dt-form-3">';
        foreach (['plk'=>'ORLEN Basket Liga','1lm'=>'1 Liga Mężczyzn','2lm'=>'2 Liga Mężczyzn'] as $key=>$label) echo '<div class="dt-league-setting"><label class="dt-check"><input type="checkbox" name="leagues[]" value="'.esc_attr($key).'" '.checked(!empty(($s['leagues']??[])[$key]),true,false).'><span><strong>'.esc_html(strtoupper($key)).'</strong><small>Aktywna w typowaniu i rankingach</small></span></label><label>Nazwa ligi<input name="league_names['.esc_attr($key).']" value="'.esc_attr(($s['league_names']??[])[$key]??$label).'"></label></div>';
        echo '</div><div class="dt-form-3"><label>Adres terminarza PLK<input type="url" name="source_plk_url" value="' . esc_attr($s['source_plk_url']) . '"></label><label>Adres terminarza 1LM<input type="url" name="source_1lm_url" value="' . esc_attr($s['source_1lm_url']) . '"></label><label>Adres terminarza 2LM<input type="url" name="source_2lm_url" value="' . esc_attr($s['source_2lm_url']) . '"></label></div><div class="dt-form-2"><label class="dt-check"><input type="checkbox" name="sync_enabled" value="1" ' . checked(!empty($s['sync_enabled']),true,false) . '><span><strong>Automatyczna synchronizacja danych PZKosz</strong><small>Pobieraj terminarze, wyniki, drużyny i dostępne logotypy.</small></span></label><label>Synchronizuj co ile minut<input type="number" min="5" max="1440" step="5" name="sync_interval_minutes" value="'.esc_attr((int)($s['sync_interval_minutes']??60)).'"></label></div></section>';
        echo '<section class="dt-card"><span class="dt-eyebrow">PUNKTACJA</span><h2>Zasady Typera</h2><div class="dt-form-3"><label>Punkty za poprawnego zwycięzcę<input type="number" name="points_winner" step="1" value="' . esc_attr($s['points_winner']) . '"></label><label>Bonus za perfekcyjną kolejkę<input type="number" name="perfect_round_bonus" step="1" value="' . esc_attr($s['perfect_round_bonus']) . '"></label><label>Dodatkowe punkty za mecz BONUS<input type="number" min="0" step="1" name="bonus_points" value="'.esc_attr(class_exists('DT_Bonus')?DT_Bonus::points():0).'"></label></div><h3>Typowania specjalne PRE</h3><div class="dt-form-2"><label>PRE - FinalRanking — punkty za każdą trafioną drużynę<input type="number" min="0" step="1" name="pre1_hit_points" value="'.esc_attr((float)($s['pre1_hit_points']??1)).'"></label><label>PRE - FinalRanking — bonus za perfekcyjny komplet<input type="number" min="0" step="1" name="pre1_perfect_bonus" value="'.esc_attr((float)($s['pre1_perfect_bonus']??0)).'"></label><label>PRE - PlayOFF — punkty za każdą trafioną drużynę<input type="number" min="0" step="1" name="pre2_hit_points" value="'.esc_attr((float)($s['pre2_hit_points']??1)).'"></label><label>PRE - PlayOFF — bonus za perfekcyjny komplet<input type="number" min="0" step="1" name="pre2_perfect_bonus" value="'.esc_attr((float)($s['pre2_perfect_bonus']??0)).'"></label></div><p class="dt-muted">Punktacja PRE zostanie użyta podczas rozliczenia końcowej tabeli i składu play-off. Zmiana wartości nie usuwa zapisanych prognoz.</p><p class="dt-muted">Użytkownik wybiera wyłącznie zwycięzcę. Dokładny wynik nie jest typowany. W każdej kolejce można wskazać maksymalnie jeden mecz BONUS.</p></section>';
        echo '<section class="dt-card"><span class="dt-eyebrow">ODLICZANIE</span><h2>Liczniki startu lig i meczów</h2><label class="dt-check"><input type="checkbox" name="show_countdowns" value="1" ' . checked(!empty($s['show_countdowns']),true,false) . '><span><strong>Pokazuj odliczanie użytkownikom</strong><small>Wyświetla trzy liczniki startu lig na stronie głównej i w aplikacji oraz czas pozostały do rozpoczęcia każdego meczu w zakładce „Typuj”.</small></span></label></section>';
        $pushReady=class_exists('DT_Notifications')&&DT_Notifications::push_ready();
        echo '<section class="dt-card"><span class="dt-eyebrow">POWIADOMIENIA</span><h2>Web Push, PWA i e-mail</h2><div class="dt-sync-state"><span class="dt-dot '.($pushReady?'is-on':'').'"></span><div><strong>'.($pushReady?'OneSignal Web Push jest skonfigurowany':'Web Push oczekuje na konfigurację OneSignal').'</strong><small>E-mail działa przez wp_mail. Dla Push dodaj w wp-config.php stałe DT_ONESIGNAL_APP_ID i DT_ONESIGNAL_REST_API_KEY.</small></div></div><p class="dt-muted">Preferencje kanałów i terminów każdy użytkownik ustawia samodzielnie w „Moim koncie”. Aktualizacja nie nadpisuje tych ustawień.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dt-section"><input type="hidden" name="action" value="dt_test_notifications">';
        wp_nonce_field('dt_test_notifications');
        echo '<button type="submit" class="button dt-button"><span class="dashicons dashicons-bell"></span> Wyślij test na moje konto administratora</button><p class="dt-muted">Test zapisze wiadomość w aplikacji, wyśle e-mail na adres tego konta oraz Push, jeśli OneSignal jest skonfigurowany. Każdy wynik pojawi się w historii powiadomień.</p></form></section>';
        echo '<section class="dt-card"><span class="dt-eyebrow">REKLAMY</span><h2>Symulacja slotów reklamowych</h2><label class="dt-check"><input type="checkbox" name="ad_slot_preview" value="1" '.checked(!empty($s['ad_slot_preview']),true,false).'><span><strong>Pokazuj testowe miejsca reklamowe</strong><small>Wymiar przygotowywanej grafiki: H1 i F1 — 2360 × 250 px; S1 i S2 — 325 × 1210 px. Makiety są skalowane do rzeczywistego miejsca w interfejsie. Zwykli użytkownicy widzą wyłącznie aktywne kampanie.</small></span></label><p class="dt-muted"><a href="'.esc_url(admin_url('admin.php?page=decka-typer-ads')).'">Przejdź do zarządzania kampaniami →</a></p></section>';
        $aiKeyReady = defined('DT_GEMINI_API_KEY') && trim((string)DT_GEMINI_API_KEY) !== '';
        echo '<section class="dt-card"><span class="dt-eyebrow">ARTUR AI</span><h2>Koło ratunkowe Artura</h2><div class="dt-sync-state"><span class="dt-dot '.($aiKeyReady?'is-on':'').'"></span><div><strong>'.($aiKeyReady?'Klucz Gemini jest skonfigurowany':'Brak klucza DT_GEMINI_API_KEY').'</strong><small>Klucz jest odczytywany bezpiecznie z pliku wp-config.php i nie jest zapisywany w bazie.</small></div></div><label class="dt-check"><input type="checkbox" name="artur_ai_enabled" value="1" '.checked(!empty($s['artur_ai_enabled']),true,false).'><span><strong>Włącz Koło ratunkowe Artura</strong><small>Każdy użytkownik może wybrać jeden mecz w kolejce i zadać Arturowi pytania dotyczące tego spotkania.</small></span></label><div class="dt-inline-warning">W trybie testowym pytania nie mają limitu, nie przypisują koła do meczu i nie są trwale zapisywane. Tryb produkcyjny stosuje limit oraz blokadę jednego meczu na kolejkę.</div><div class="dt-form-2"><label>Model Gemini<input name="artur_ai_model" value="'.esc_attr((string)($s['artur_ai_model']??'gemini-2.5-flash-lite')).'" maxlength="100"></label><label>Liczba pytań po użyciu koła<input type="number" name="artur_ai_questions" min="1" max="5" value="'.esc_attr((int)($s['artur_ai_questions']??3)).'"></label></div><label>Instrukcja osobowości i odpowiedzi<textarea name="artur_ai_instruction" rows="8" maxlength="4000">'.esc_textarea((string)($s['artur_ai_instruction']??DT_DB::default_artur_ai_instruction())).'</textarea></label><p class="dt-muted">Instrukcję możesz dowolnie zmieniać. Aktualizacje wtyczki nie nadpiszą treści zapisanej w bazie danych.</p></section>';
        self::provider_fields('Google',[['google_client_id','Client ID','text'],['google_client_secret','Client Secret','password']],DT_OAuth::callback_url('google'),$s);
        self::provider_fields('Facebook',[['facebook_app_id','App ID','text'],['facebook_app_secret','App Secret','password']],DT_OAuth::callback_url('facebook'),$s);
        $privacyId = class_exists('DT_Legal') ? DT_Legal::privacy_page_id() : 0;
        $contactId = class_exists('DT_Legal') ? DT_Legal::contact_page_id() : 0;
        echo '<section class="dt-card"><span class="dt-eyebrow">STRONY I KONTAKT</span><h2>Polityka prywatności i formularz kontaktowy</h2><div class="dt-form-2"><label>Adres odbierający wiadomości<input type="email" name="contact_email" value="'.esc_attr($s['contact_email'] ?? get_option('admin_email')).'" required></label><div class="dt-page-actions"><strong>Treści publicznych stron</strong><p class="dt-muted">Strony są zwykłymi stronami WordPressa. Aktualizacje wtyczki nie nadpisują zapisanych treści.</p><p>' . ($privacyId ? '<a class="button" href="'.esc_url(get_edit_post_link($privacyId)).'">Edytuj politykę prywatności</a> ' : '') . ($contactId ? '<a class="button" href="'.esc_url(get_edit_post_link($contactId)).'">Edytuj stronę Kontakt</a>' : '') . '</p></div></div></section>';
        $leagueColors = wp_parse_args((array)($s['league_colors'] ?? []), ['1lm'=>'#055EFB','plk'=>'#FB5D0B','2lm'=>'#4F6F9D']);
        echo '<section class="dt-card"><span class="dt-eyebrow">WYGLĄD</span><h2>Kolory interfejsu</h2><div class="dt-form-3"><label>Niebieski<input type="color" name="brand_primary" value="' . esc_attr($s['brand_primary']) . '"></label><label>Akcent<input type="color" name="brand_accent" value="' . esc_attr($s['brand_accent']) . '"></label><label>Tło<input type="color" name="brand_surface" value="' . esc_attr($s['brand_surface']) . '"></label></div><h3>Kolory lig</h3><p class="dt-muted">Te kolory oznaczają ligi w odliczaniu oraz przy kolejkach w zakładce „Moje typy”.</p><div class="dt-form-3"><label>1LM<input type="color" name="league_colors[1lm]" value="' . esc_attr($leagueColors['1lm']) . '"></label><label>PLK<input type="color" name="league_colors[plk]" value="' . esc_attr($leagueColors['plk']) . '"></label><label>2LM<input type="color" name="league_colors[2lm]" value="' . esc_attr($leagueColors['2lm']) . '"></label></div></section>';
        echo '<div class="dt-savebar"><div><strong>Decka Typer ' . esc_html(DT_VERSION) . '</strong><span>Zmiany ustawień obowiązują od razu.</span></div><button class="button button-primary dt-button">Zapisz ustawienia</button></div></form>';
        echo '<section class="dt-card dt-danger-zone"><span class="dt-eyebrow">NARZĘDZIA TESTOWE</span><h2>Wyczyść dane Typera</h2><p>Usuwa wszystkie typy, punkty, wyniki, mecze, kolejki i drużyny, a następnie ponownie synchronizuje PLK, 1LM oraz 2LM. Konta WordPress, reklamy, Feedback, ustawienia Artura, AI i pozostała konfiguracja pozostają bez zmian.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" onsubmit="return confirm(\'Usunąć bezpowrotnie dane typerskie i sportowe, a następnie pobrać ligi od nowa?\')"><input type="hidden" name="action" value="dt_reset_typer_data">';
        wp_nonce_field('dt_reset_typer_data');
        echo '<label>Wpisz <strong>WYCZYŚĆ</strong>, aby potwierdzić<input name="confirmation" autocomplete="off" required></label><p><button class="button dt-danger-button"><span class="dashicons dashicons-trash"></span> Wyczyść dane i zsynchronizuj ligi</button></p></form></section>';
        self::end_shell();
    }

    public static function avatar(): void {
        $messages = DT_Avatar::messages();
        self::shell('AVATAR','Komunikaty pomocnika wyświetlane użytkownikom w odpowiednich momentach.');
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dt-settings"><input type="hidden" name="action" value="dt_save_avatar">';
        wp_nonce_field('dt_save_avatar');
        echo '<section class="dt-card"><span class="dt-eyebrow">POMOCNIK TYPERA</span><h2>Teksty w chmurkach</h2><p class="dt-muted">Artur losuje jedną z odpowiedzi przypisanych do sytuacji. Wszystkie teksty możesz edytować poniżej.</p><div class="dt-avatar-admin-list">';
        foreach ($messages as $key=>$item) {
            echo '<details class="dt-avatar-admin-row"><summary><img src="'.esc_url($item['url']).'" alt=""><span><strong>'.esc_html($item['label']).'</strong><small>'.count($item['texts']).' edytowalnych odpowiedzi</small></span></summary><div class="dt-avatar-message-grid">';
            foreach ($item['texts'] as $index=>$text) echo '<label><span>Odpowiedź '.($index+1).'</span><input name="messages['.esc_attr($key).']['.(int)$index.']" maxlength="180" value="'.esc_attr($text).'"></label>';
            echo '</div></details>';
        }
        echo '</div></section><div class="dt-savebar"><div><strong>Komunikaty avatara</strong><span>Zmiany będą widoczne od razu po odświeżeniu aplikacji.</span></div><button class="button button-primary dt-button">Zapisz komunikaty</button></div></form>';
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
        foreach (['season','source_url','source_plk_url','source_1lm_url','source_2lm_url','google_client_id','google_client_secret','facebook_app_id','facebook_app_secret'] as $k) {
            $v = wp_unslash($_POST[$k] ?? '');
            $new[$k] = str_contains($k, 'source_') || $k === 'source_url' ? esc_url_raw($v) : sanitize_text_field($v);
        }
        $contactEmail = sanitize_email(wp_unslash($_POST['contact_email'] ?? ''));
        $new['contact_email'] = is_email($contactEmail) ? $contactEmail : sanitize_email(get_option('admin_email'));
        foreach (['points_winner','perfect_round_bonus','pre1_hit_points','pre1_perfect_bonus','pre2_hit_points','pre2_perfect_bonus'] as $k) $new[$k] = max(0, (float)($_POST[$k] ?? 0));
        foreach (['brand_primary','brand_accent','brand_surface'] as $k) $new[$k] = sanitize_hex_color($_POST[$k] ?? '') ?: $old[$k];
        $oldLeagueColors = wp_parse_args((array)($old['league_colors'] ?? []), ['1lm'=>'#055EFB','plk'=>'#FB5D0B','2lm'=>'#4F6F9D']);
        foreach (['1lm','plk','2lm'] as $key) $new['league_colors'][$key] = sanitize_hex_color($_POST['league_colors'][$key] ?? '') ?: $oldLeagueColors[$key];
        $new['sync_enabled'] = !empty($_POST['sync_enabled']) ? 1 : 0;
        $new['show_countdowns'] = !empty($_POST['show_countdowns']) ? 1 : 0;
        $new['ad_slot_preview'] = !empty($_POST['ad_slot_preview']) ? 1 : 0;
        $new['artur_ai_enabled'] = !empty($_POST['artur_ai_enabled']) ? 1 : 0;
        $new['artur_ai_model'] = sanitize_text_field(wp_unslash($_POST['artur_ai_model'] ?? ($old['artur_ai_model'] ?? 'gemini-2.5-flash-lite')));
        $new['artur_ai_questions'] = max(1,min(5,(int)($_POST['artur_ai_questions']??3)));
        $instruction = trim(sanitize_textarea_field(wp_unslash($_POST['artur_ai_instruction'] ?? '')));
        $new['artur_ai_instruction'] = $instruction !== '' ? $instruction : (string)($old['artur_ai_instruction'] ?? DT_DB::default_artur_ai_instruction());
        $new['sync_interval_minutes'] = max(5,min(1440,(int)($_POST['sync_interval_minutes']??60)));
        foreach(['plk','1lm','2lm'] as $key)$new['league_names'][$key]=sanitize_text_field(wp_unslash($_POST['league_names'][$key]??''));
        $mode = sanitize_key($_POST['site_mode'] ?? 'test');
        $new['site_mode'] = in_array($mode, ['production','test','break'], true) ? $mode : 'test';
        $selected = array_map('sanitize_key', (array)($_POST['leagues'] ?? []));
        $new['leagues'] = ['plk'=>in_array('plk',$selected,true)?1:0,'1lm'=>in_array('1lm',$selected,true)?1:0,'2lm'=>in_array('2lm',$selected,true)?1:0];
        foreach (['apple_client_id','apple_team_id','apple_key_id','apple_private_key','points_exact','points_margin'] as $deprecated) unset($new[$deprecated]);
        update_option('dt_settings',$new);
        $timestamp=wp_next_scheduled('dt_sync_schedule');if($timestamp)wp_unschedule_event($timestamp,'dt_sync_schedule');DT_DB::ensure_cron();
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
            'season'=>$s['season'],'league_key'=>in_array(sanitize_key($_POST['league_key']??'1lm'),['plk','1lm','2lm'],true)?sanitize_key($_POST['league_key']):'1lm','group_key'=>sanitize_text_field($_POST['group_key']??''),'round_no'=>$no,'title'=>$title,'status'=>'draft','source'=>'manual',
            'external_key'=>sha1($s['season'].'|manual|'.$no.'|'.($_POST['league_key']??'1lm').'|'.($_POST['group_key']??'')),'created_at'=>$now,'updated_at'=>$now
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
        $wpdb->update(DT_DB::table('rounds'),['status'=>'open','manual_availability'=>1,'opens_at'=>$nowSql,'closes_at'=>$close,'updated_at'=>$nowSql],['id'=>$id],['%s','%d','%s','%s','%s'],['%d']);
        DT_Logger::log('round_opened','Administrator otworzył kolejkę do typowania.',['round_id'=>$id,'closes_at'=>$close], 'notice', get_current_user_id());
        self::redirect('decka-typer-rounds','Typowanie kolejki zostało otwarte do ' . self::date_pl($close) . '.');
    }

    public static function close_round(): void {
        self::guard('dt_close_round');
        global $wpdb;
        $id = (int)($_POST['round_id'] ?? 0);
        $now = current_time('mysql');
        $ok = $wpdb->query($wpdb->prepare("UPDATE " . DT_DB::table('rounds') . " SET status='closed',manual_availability=1,closes_at=CASE WHEN closes_at IS NULL OR closes_at>%s THEN %s ELSE closes_at END,updated_at=%s WHERE id=%d",$now,$now,$now,$id));
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
        $resetUsers=[];
        if ((string)$data['starts_at']!==(string)$m->starts_at) {
            $predictionTable=DT_DB::table('predictions');$submissionTable=DT_DB::table('round_submissions');$matchTable=DT_DB::table('matches');
            $resetUsers=array_map('intval',(array)$wpdb->get_col($wpdb->prepare("SELECT user_id FROM $predictionTable WHERE match_id=%d",$id)));
            $wpdb->delete($predictionTable,['match_id'=>$id],['%d']);
            foreach ($resetUsers as $resetUid) {
                $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $predictionTable p JOIN $matchTable mm ON mm.id=p.match_id WHERE p.user_id=%d AND mm.round_id=%d",$resetUid,(int)$m->round_id));
                if ($count) $wpdb->update($submissionTable,['prediction_count'=>$count],['user_id'=>$resetUid,'round_id'=>(int)$m->round_id],['%d'],['%d','%d']);
                else $wpdb->delete($submissionTable,['user_id'=>$resetUid,'round_id'=>(int)$m->round_id],['%d','%d']);
            }
        }
        $wpdb->update(DT_DB::table('matches'),$data,['id'=>$id]);
        if ($resetUsers && class_exists('DT_Notifications')) DT_Notifications::schedule_changed($id,(int)$m->round_id,(string)$m->starts_at,(string)$data['starts_at'],$resetUsers);
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

    public static function toggle_expert(): void {
        self::guard('dt_toggle_expert');
        $uid = (int)($_POST['user_id'] ?? 0);
        $user = $uid > 0 ? get_userdata($uid) : false;
        if (!$user) self::redirect('decka-typer-users','Nie znaleziono użytkownika.','error');
        $wasExpert = DT_User_Settings::is_expert($uid);
        if ($wasExpert) delete_user_meta($uid, 'dt_typer_expert');
        else update_user_meta($uid, 'dt_typer_expert', 1);
        DT_Logger::log('expert_status_changed',$wasExpert?'Odebrano oznaczenie eksperta.':'Nadano oznaczenie eksperta.',['target_user'=>$uid,'is_expert'=>$wasExpert?0:1],'notice',get_current_user_id());
        self::redirect('decka-typer-users',$wasExpert?'Użytkownik nie jest już oznaczony jako ekspert.':'Użytkownik został oznaczony jako ekspert.');
    }

    public static function update_feedback(): void {
        $id = max(0, (int)($_POST['feedback_id'] ?? 0));
        self::guard('dt_update_feedback_' . $id);
        global $wpdb;
        $status = sanitize_key((string)($_POST['status'] ?? 'new'));
        if (!in_array($status, ['new','in_progress','resolved','cancelled'], true)) {
            self::redirect('decka-typer-feedback', 'Nieprawidłowy status zgłoszenia.', 'error');
        }
        $ok = $wpdb->update(DT_DB::table('feedback'), [
            'status'=>$status,
            'admin_user_id'=>get_current_user_id(),
            'updated_at'=>current_time('mysql'),
        ], ['id'=>$id], ['%s','%d','%s'], ['%d']);
        DT_Logger::log('feedback_status_changed', 'Administrator zmienił status zgłoszenia.', ['feedback_id'=>$id,'status'=>$status], 'notice', get_current_user_id());
        $returnStatus = sanitize_key((string)($_POST['return_status'] ?? 'all'));
        self::redirect('decka-typer-feedback', $ok !== false ? 'Status zgłoszenia został zapisany.' : 'Nie udało się zapisać statusu.', $ok !== false ? 'success' : 'error', ['status'=>$returnStatus]);
    }

    public static function test_notifications(): void {
        self::guard('dt_test_notifications');
        $rows=class_exists('DT_Notifications')?DT_Notifications::send_admin_test(get_current_user_id()):[];
        $sent=[];$failed=[];
        foreach ($rows as $row) {
            $label=strtoupper((string)($row['channel']??''));
            if (($row['status']??'')==='sent') $sent[]=$label;
            else $failed[]=$label;
        }
        $message=$sent?'Test wysłany: '.implode(', ',$sent).'.':'Nie udało się wysłać testu.';
        if ($failed) $message.=' Niepowodzenie: '.implode(', ',$failed).'.'; sprawdź historię powiadomień.';
        self::redirect('decka-typer-settings',$message,$failed?'error':'success');
    }

    public static function save_avatar(): void {
        self::guard('dt_save_avatar');
        DT_Avatar::save((array)($_POST['messages'] ?? []));
        DT_Logger::log('avatar_messages_saved','Zapisano komunikaty avatara.',[], 'notice', get_current_user_id());
        self::redirect('decka-typer-avatar','Komunikaty avatara zostały zapisane.');
    }

    public static function reset_typer_data(): void {
        self::guard('dt_reset_typer_data');
        if (trim((string)wp_unslash($_POST['confirmation'] ?? '')) !== 'WYCZYŚĆ') {
            self::redirect('decka-typer-settings','Nie wpisano prawidłowego potwierdzenia WYCZYŚĆ.','error');
        }
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try {
            // Delete dependent data first, then the complete sports catalogue. This
            // also removes manually entered scores and locks, so the following sync
            // always rebuilds leagues from their official sources.
            foreach (['predictions','round_submissions','point_adjustments','artur_ai','preseason_predictions','matches','rounds','teams'] as $table) {
                if ($wpdb->query('DELETE FROM '.DT_DB::table($table)) === false) throw new RuntimeException($wpdb->last_error ?: 'Błąd czyszczenia tabeli '.$table);
            }
            if ($wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE meta_key IN (%s,%s)",'dt_ranking_name','dt_favorite_team_id')) === false) {
                throw new RuntimeException($wpdb->last_error ?: 'Błąd czyszczenia ustawień użytkowników');
            }
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            DT_Logger::log('typer_reset_failed',$e->getMessage(),[], 'error', get_current_user_id());
            self::redirect('decka-typer-settings','Nie udało się wyczyścić danych Typera.','error');
        }

        // Match IDs and round IDs are recreated by the import. Remove only their
        // mapping; keep the configured BONUS point value and every other setting.
        delete_option('dt_bonus_matches');
        delete_option('dt_1lm_standings_cache');
        $contextCache = $wpdb->esc_like('_transient_dt_lctx_') . '%';
        $contextTimeout = $wpdb->esc_like('_transient_timeout_dt_lctx_') . '%';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $contextCache,
            $contextTimeout
        ));

        DT_Logger::log(
            'typer_data_reset',
            'Administrator usunął dane typerskie, wyniki, mecze, kolejki i drużyny. Konta oraz konfigurację wtyczki zachowano.',
            [],
            'warning',
            get_current_user_id()
        );

        $sync = DT_Sync::run(true);
        $leagueResults = (array)($sync['leagues'] ?? []);
        $synced = [];
        $failed = [];
        foreach (['plk'=>'PLK','1lm'=>'1LM','2lm'=>'2LM'] as $key=>$label) {
            if (!empty($leagueResults[$key]['ok'])) $synced[] = $label;
            else $failed[] = $label;
        }

        $message = 'Dane Typera zostały wyczyszczone. Ponownie pobrano: ' . ($synced ? implode(', ', $synced) : 'brak lig') . '.';
        if ($failed) {
            $message .= ' Nie udało się pobrać: ' . implode(', ', $failed) . '. Szczegóły zapisano w Historii.';
            self::redirect('decka-typer-sync', $message, 'error');
        }
        $message .= ' Zaimportowano ' . (int)($sync['matches_new'] ?? 0) . ' meczów.';
        self::redirect('decka-typer-sync', $message, 'success');
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
