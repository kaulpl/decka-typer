<?php
if (!defined('ABSPATH')) exit;

class DT_Admin {
    public static function register(): void {
        add_action('admin_menu',[__CLASS__,'menu']);
        add_action('admin_enqueue_scripts',[__CLASS__,'assets']);
        foreach (['sync_now','save_settings','save_match','add_match','add_round','adjust_points'] as $action) {
            add_action('admin_post_dt_'.$action,[__CLASS__,$action]);
        }
    }

    public static function menu(): void {
        $cap='manage_options';
        add_menu_page('Decka Typer','Decka Typer',$cap,'decka-typer',[__CLASS__,'dashboard'],'dashicons-awards',26);
        $items=[
            ['decka-typer','Pulpit','dashboard'],['decka-typer-rounds','Kolejki','rounds'],
            ['decka-typer-matches','Mecze','matches'],['decka-typer-predictions','Typy','predictions'],
            ['decka-typer-ranking','Ranking','ranking'],['decka-typer-users','Użytkownicy','users'],
            ['decka-typer-stats','Statystyki','stats'],['decka-typer-sync','Synchronizacja 1LM','sync'],
            ['decka-typer-logs','Historia','logs'],['decka-typer-settings','Ustawienia','settings'],
        ];
        foreach ($items as [$slug,$label,$method]) {
            add_submenu_page('decka-typer',$label,$label,$cap,$slug,[__CLASS__,$method]);
        }
    }

    public static function assets(string $hook): void {
        if (strpos($hook,'decka-typer')===false) return;
        wp_enqueue_style('dt-admin',DT_URL.'assets/css/admin.css',[],DT_VERSION);
        wp_enqueue_script('dt-admin',DT_URL.'assets/js/admin.js',[],DT_VERSION,true);
    }

    private static function guard(string $nonce): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        check_admin_referer($nonce);
    }
    private static function shell(string $title,string $subtitle=''): void {
        echo '<div class="wrap dt-admin"><div class="dt-admin-head"><div><div class="dt-kicker">DECKA PELPLIN · TYPER</div><h1>'.esc_html($title).'</h1>'.($subtitle?'<p>'.esc_html($subtitle).'</p>':'').'</div><img src="'.esc_url(DT_URL.'assets/img/decka-logo.png').'" alt="Decka Pelplin"></div>';
        self::notice();
    }
    private static function end_shell(): void { echo '</div>'; }
    private static function notice(): void {
        if (empty($_GET['dt_notice'])) return;
        $type=sanitize_key($_GET['dt_type']??'success');
        $msg=sanitize_text_field(wp_unslash($_GET['dt_notice']));
        echo '<div class="dt-toast-static dt-'.esc_attr($type).'"><span class="dashicons dashicons-'.($type==='error'?'warning':'yes-alt').'"></span>'.esc_html($msg).'</div>';
    }
    private static function redirect(string $page,string $notice,string $type='success'): void {
        wp_safe_redirect(add_query_arg(['page'=>$page,'dt_notice'=>$notice,'dt_type'=>$type],admin_url('admin.php'))); exit;
    }
    private static function badge(string $text,string $type='neutral'): string {
        return '<span class="dt-badge dt-badge-'.esc_attr($type).'">'.esc_html($text).'</span>';
    }
    private static function metric(string $label,$value,string $icon,string $tone): void {
        echo '<div class="dt-metric dt-tone-'.esc_attr($tone).'"><div class="dt-metric-icon"><span class="dashicons dashicons-'.esc_attr($icon).'"></span></div><div><span>'.esc_html($label).'</span><strong>'.esc_html((string)$value).'</strong></div></div>';
    }
    private static function date_pl(?string $v): string {
        return $v ? wp_date('d.m.Y · H:i',strtotime($v),wp_timezone()) : '—';
    }

    public static function dashboard(): void {
        global $wpdb; $s=DT_DB::settings(); $season=$s['season'];
        $rounds=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DT_DB::table('rounds').' WHERE season=%s',$season));
        $matches=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DT_DB::table('matches').' m JOIN '.DT_DB::table('rounds').' r ON r.id=m.round_id WHERE r.season=%s',$season));
        $pred=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DT_DB::table('predictions').' p JOIN '.DT_DB::table('matches').' m ON m.id=p.match_id JOIN '.DT_DB::table('rounds').' r ON r.id=m.round_id WHERE r.season=%s',$season));
        $players=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(DISTINCT p.user_id) FROM '.DT_DB::table('predictions').' p JOIN '.DT_DB::table('matches').' m ON m.id=p.match_id JOIN '.DT_DB::table('rounds').' r ON r.id=m.round_id WHERE r.season=%s',$season));
        $manual=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.DT_DB::table('matches').' WHERE manual_lock=1');
        $pending=(int)$wpdb->get_var("SELECT COUNT(*) FROM ".DT_DB::table('matches')." WHERE starts_at < NOW() AND status!='finished'");
        $last=get_option('dt_last_sync');
        self::shell('Pulpit','Sezon '.$season.' · kontrola Typera');
        echo '<div class="dt-grid dt-grid-4">'; self::metric('Kolejki',$rounds,'calendar-alt','blue'); self::metric('Mecze',$matches,'tickets-alt','orange'); self::metric('Oddane typy',$pred,'edit-page','violet'); self::metric('Gracze',$players,'groups','green'); echo '</div>';
        echo '<div class="dt-grid dt-grid-2 dt-section"><section class="dt-card"><span class="dt-eyebrow">SZYBKIE AKCJE</span><h2>Obsługa rozgrywek</h2><div class="dt-action-grid">';
        foreach ([['decka-typer-rounds','Kolejki','calendar-alt'],['decka-typer-matches','Mecze i wyniki','edit'],['decka-typer-sync','Synchronizuj 1LM','update'],['decka-typer-ranking','Ranking','chart-bar']] as $a) {
            echo '<a class="dt-action" href="'.esc_url(admin_url('admin.php?page='.$a[0])).'"><span class="dashicons dashicons-'.$a[2].'"></span><strong>'.esc_html($a[1]).'</strong><span class="dashicons dashicons-arrow-right-alt2"></span></a>';
        }
        echo '</div></section><section class="dt-card"><span class="dt-eyebrow">STAN SYSTEMU</span><h2>Kontrola</h2><div class="dt-health"><div><span>Mecze chronione ręcznie</span><strong>'.$manual.'</strong></div><div><span>Po terminie bez wyniku</span><strong class="'.($pending?'dt-warn-text':'').'">'.$pending.'</strong></div><div><span>Ostatnia synchronizacja</span><strong>'.esc_html($last['at']??'jeszcze nie wykonano').'</strong></div><div><span>Źródło</span><strong>1lm.pzkosz.pl</strong></div></div></section></div>';
        echo '<section class="dt-card dt-section"><div class="dt-card-head"><div><span class="dt-eyebrow">TOP 5</span><h2>Liderzy klasyfikacji</h2></div></div>'; self::ranking_table(DT_Scoring::ranking($season,5)); echo '</section>';
        self::end_shell();
    }

    public static function rounds(): void {
        global $wpdb; $s=DT_DB::settings();
        $rows=$wpdb->get_results($wpdb->prepare('SELECT r.*,COUNT(m.id) matches,MIN(m.starts_at) first_match,MAX(m.starts_at) last_match FROM '.DT_DB::table('rounds').' r LEFT JOIN '.DT_DB::table('matches').' m ON m.round_id=r.id WHERE r.season=%s GROUP BY r.id ORDER BY r.round_no',$s['season']));
        self::shell('Kolejki','Zarządzaj kolejkami sezonu '.$s['season']);
        echo '<div class="dt-toolbar"><button class="button button-primary dt-button" data-dt-open="dt-round-modal"><span class="dashicons dashicons-plus-alt2"></span> Dodaj kolejkę</button></div><section class="dt-card"><table class="widefat dt-table"><thead><tr><th>Kolejka</th><th>Termin</th><th>Mecze</th><th>Status</th><th>Źródło</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $range=$r->first_match?wp_date('d.m',strtotime($r->first_match),wp_timezone()).' – '.wp_date('d.m.Y',strtotime($r->last_match),wp_timezone()):'—';
            echo '<tr><td><strong>'.esc_html($r->title).'</strong></td><td>'.esc_html($range).'</td><td>'.(int)$r->matches.'</td><td>'.self::badge($r->status==='published'?'Opublikowana':ucfirst($r->status),$r->status==='published'?'green':'neutral').'</td><td>'.self::badge($r->source==='1lm'?'1LM':'Ręcznie',$r->source==='1lm'?'blue':'orange').'</td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="5" class="dt-empty">Brak kolejek. Uruchom synchronizację 1LM lub dodaj kolejkę ręcznie.</td></tr>';
        echo '</tbody></table></section><dialog id="dt-round-modal" class="dt-modal"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><button type="button" class="dt-modal-x" data-dt-close>×</button><span class="dt-eyebrow">NOWA KOLEJKA</span><h2>Dodaj kolejkę ręcznie</h2><input type="hidden" name="action" value="dt_add_round">'; wp_nonce_field('dt_add_round');
        echo '<label>Numer kolejki<input type="number" name="round_no" min="1" max="99" required></label><label>Nazwa<input name="title" placeholder="np. 12. kolejka"></label><button class="button button-primary dt-button">Zapisz kolejkę</button></form></dialog>';
        self::end_shell();
    }

    public static function matches(): void {
        global $wpdb; $s=DT_DB::settings(); $roundId=(int)($_GET['round_id']??0);
        $rounds=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.DT_DB::table('rounds').' WHERE season=%s ORDER BY round_no',$s['season']));
        if (!$roundId && $rounds) $roundId=(int)$rounds[0]->id;
        $rows=$roundId?$wpdb->get_results($wpdb->prepare('SELECT m.*,h.name home_name,a.name away_name FROM '.DT_DB::table('matches').' m JOIN '.DT_DB::table('teams').' h ON h.id=m.home_team_id JOIN '.DT_DB::table('teams').' a ON a.id=m.away_team_id WHERE m.round_id=%d ORDER BY m.starts_at,m.id',$roundId)):[];
        self::shell('Mecze','Ręczna korekta może być chroniona przed automatycznym importem.');
        echo '<div class="dt-toolbar"><form method="get"><input type="hidden" name="page" value="decka-typer-matches"><select name="round_id" onchange="this.form.submit()">'; foreach($rounds as $r) echo '<option value="'.(int)$r->id.'" '.selected($roundId,$r->id,false).'>'.esc_html($r->title).'</option>'; echo '</select></form><button class="button button-primary dt-button" data-dt-open="dt-add-match"><span class="dashicons dashicons-plus-alt2"></span> Dodaj mecz</button></div>';
        echo '<section class="dt-card"><table class="widefat dt-table"><thead><tr><th>Mecz</th><th>Start</th><th>Wynik</th><th>Status</th><th>Synchronizacja</th><th></th></tr></thead><tbody>';
        foreach($rows as $m){
            $score=$m->score_home===null?'—':(int)$m->score_home.' : '.(int)$m->score_away;
            $data=['id'=>(int)$m->id,'home'=>$m->home_name,'away'=>$m->away_name,'starts_at'=>$m->starts_at?str_replace(' ','T',substr($m->starts_at,0,16)):'','home_score'=>$m->score_home,'away_score'=>$m->score_away,'manual_lock'=>(int)$m->manual_lock];
            echo '<tr><td><strong>'.esc_html($m->home_name).'</strong><span class="dt-versus">vs</span><strong>'.esc_html($m->away_name).'</strong></td><td>'.esc_html(self::date_pl($m->starts_at)).'</td><td><span class="dt-score">'.esc_html($score).'</span></td><td>'.self::badge($m->status==='finished'?'Zakończony':'Zaplanowany',$m->status==='finished'?'green':'neutral').'</td><td>'.((int)$m->manual_lock?self::badge('Ręczny · chroniony','orange'):self::badge('Auto 1LM','blue')).'</td><td><button type="button" class="button dt-edit-match" data-match="'.esc_attr(wp_json_encode($data)).'">Edytuj</button></td></tr>';
        }
        if(!$rows) echo '<tr><td colspan="6" class="dt-empty">Brak meczów w tej kolejce.</td></tr>';
        echo '</tbody></table></section>'; self::match_modal('dt-match-modal',false,$roundId); self::match_modal('dt-add-match',true,$roundId); self::end_shell();
    }

    private static function match_modal(string $id,bool $add,int $roundId): void {
        global $wpdb; $teams=$wpdb->get_results('SELECT id,name FROM '.DT_DB::table('teams').' ORDER BY name');
        echo '<dialog id="'.esc_attr($id).'" class="dt-modal"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><button type="button" class="dt-modal-x" data-dt-close>×</button><span class="dt-eyebrow">'.($add?'NOWY MECZ':'EDYCJA MECZU').'</span><h2>'.($add?'Dodaj mecz ręcznie':'Popraw dane meczu').'</h2><input type="hidden" name="action" value="'.($add?'dt_add_match':'dt_save_match').'">'; wp_nonce_field($add?'dt_add_match':'dt_save_match');
        if(!$add) echo '<input type="hidden" name="match_id" data-field="id">';
        echo '<input type="hidden" name="round_id" value="'.$roundId.'">';
        if($add){echo '<div class="dt-form-2"><label>Gospodarz<select name="home_team_id">';foreach($teams as $t)echo '<option value="'.$t->id.'">'.esc_html($t->name).'</option>';echo '</select></label><label>Gość<select name="away_team_id">';foreach($teams as $t)echo '<option value="'.$t->id.'">'.esc_html($t->name).'</option>';echo '</select></label></div>';}
        echo '<label>Data i godzina<input type="datetime-local" name="starts_at" data-field="starts_at" required></label><div class="dt-form-2"><label>Wynik gospodarzy<input type="number" name="score_home" data-field="home_score" min="0" max="250"></label><label>Wynik gości<input type="number" name="score_away" data-field="away_score" min="0" max="250"></label></div>';
        if(!$add) echo '<label class="dt-check"><input type="checkbox" name="manual_lock" value="1" data-field="manual_lock"><span><strong>Chroń przed synchronizacją 1LM</strong><small>Import nie nadpisze terminu ani wyniku.</small></span></label>';
        echo '<button class="button button-primary dt-button" '.(!$teams&&$add?'disabled':'').'>Zapisz</button></form></dialog>';
    }

    public static function predictions(): void {
        global $wpdb; $s=DT_DB::settings();
        $rows=$wpdb->get_results($wpdb->prepare('SELECT p.*,u.display_name,h.name home_name,a.name away_name,r.round_no,m.score_home,m.score_away FROM '.DT_DB::table('predictions').' p JOIN '.$wpdb->users.' u ON u.ID=p.user_id JOIN '.DT_DB::table('matches').' m ON m.id=p.match_id JOIN '.DT_DB::table('rounds').' r ON r.id=m.round_id JOIN '.DT_DB::table('teams').' h ON h.id=m.home_team_id JOIN '.DT_DB::table('teams').' a ON a.id=m.away_team_id WHERE r.season=%s ORDER BY p.updated_at DESC LIMIT 250',$s['season']));
        self::shell('Typy użytkowników','Ostatnie 250 zapisanych typów');
        echo '<section class="dt-card"><table class="widefat dt-table"><thead><tr><th>Użytkownik</th><th>Kolejka</th><th>Mecz</th><th>Typ</th><th>Wynik</th><th>Punkty</th></tr></thead><tbody>';
        foreach($rows as $p) echo '<tr><td><strong>'.esc_html($p->display_name).'</strong></td><td>#'.(int)$p->round_no.'</td><td>'.esc_html($p->home_name.' – '.$p->away_name).'</td><td><span class="dt-score">'.(int)$p->home_score.' : '.(int)$p->away_score.'</span></td><td>'.($p->score_home===null?'—':'<span class="dt-score">'.(int)$p->score_home.' : '.(int)$p->score_away.'</span>').'</td><td>'.self::badge(number_format((float)$p->points,0).' pkt',(float)$p->points>0?'green':'neutral').'</td></tr>';
        if(!$rows) echo '<tr><td colspan="6" class="dt-empty">Nie ma jeszcze żadnych typów.</td></tr>'; echo '</tbody></table></section>'; self::end_shell();
    }

    public static function ranking(): void { $s=DT_DB::settings(); self::shell('Ranking','Klasyfikacja generalna sezonu '.$s['season']); echo '<section class="dt-card">'; self::ranking_table(DT_Scoring::ranking($s['season'],500)); echo '</section>'; self::end_shell(); }
    private static function ranking_table(array $rank): void {
        echo '<table class="widefat dt-table dt-ranking"><thead><tr><th>#</th><th>Gracz</th><th>Punkty</th><th>Dokładne</th><th>Trafieni zwycięzcy</th><th>Typy</th></tr></thead><tbody>';
        foreach($rank as $r){$medal='<span class="dt-rank '.($r['rank']<=3?'dt-rank-'.$r['rank']:'').'">'.$r['rank'].'</span>';echo '<tr><td>'.$medal.'</td><td><strong>'.esc_html($r['display_name']).'</strong></td><td><strong>'.number_format((float)$r['points'],0).' pkt</strong></td><td>'.(int)$r['exact_hits'].'</td><td>'.(int)$r['winner_hits'].'</td><td>'.(int)$r['predictions'].'</td></tr>';}
        if(!$rank) echo '<tr><td colspan="6" class="dt-empty">Ranking pojawi się po pierwszych typach.</td></tr>'; echo '</tbody></table>';
    }

    public static function users(): void {
        $s=DT_DB::settings(); $rank=DT_Scoring::ranking($s['season'],500); self::shell('Użytkownicy','Gracze uczestniczący w Typerze');
        echo '<section class="dt-card"><table class="widefat dt-table"><thead><tr><th>Gracz</th><th>Miejsce</th><th>Punkty</th><th>Typy</th><th>Korekta punktów</th></tr></thead><tbody>';
        foreach($rank as $r){echo '<tr><td><div class="dt-user"><img src="'.esc_url(get_avatar_url((int)$r['user_id'],['size'=>64])).'"><strong>'.esc_html($r['display_name']).'</strong></div></td><td>#'.(int)$r['rank'].'</td><td><strong>'.number_format((float)$r['points'],0).' pkt</strong></td><td>'.(int)$r['predictions'].'</td><td><form class="dt-inline-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dt_adjust_points"><input type="hidden" name="user_id" value="'.(int)$r['user_id'].'">';wp_nonce_field('dt_adjust_points');echo '<input type="number" name="points" placeholder="± pkt" required><input name="reason" placeholder="Powód" required><button class="button">Dodaj</button></form></td></tr>';}
        if(!$rank) echo '<tr><td colspan="5" class="dt-empty">Brak aktywnych graczy.</td></tr>'; echo '</tbody></table></section>'; self::end_shell();
    }

    public static function stats(): void {
        global $wpdb; $s=DT_DB::settings(); $season=$s['season'];
        $all=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DT_DB::table('predictions').' p JOIN '.DT_DB::table('matches').' m ON m.id=p.match_id JOIN '.DT_DB::table('rounds').' r ON r.id=m.round_id WHERE r.season=%s',$season));
        $exact=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM ".DT_DB::table('predictions')." p JOIN ".DT_DB::table('matches')." m ON m.id=p.match_id JOIN ".DT_DB::table('rounds')." r ON r.id=m.round_id WHERE r.season=%s AND p.scoring_code='exact'",$season));
        self::shell('Statystyki','Zaangażowanie społeczności w sezonie '.$season); echo '<div class="dt-grid dt-grid-3">'; self::metric('Wszystkie typy',$all,'editor-ol','blue'); self::metric('Dokładne wyniki',$exact,'yes-alt','green'); self::metric('Skuteczność dokładnych',$all?round($exact/$all*100,1).'%':'0%','chart-line','orange'); echo '</div>'; self::end_shell();
    }

    public static function sync(): void {
        $s=DT_DB::settings(); $last=get_option('dt_last_sync'); self::shell('Synchronizacja 1LM','Terminarz i wyniki z oficjalnej strony ligi.');
        echo '<div class="dt-grid dt-grid-2"><section class="dt-card"><span class="dt-eyebrow">ŹRÓDŁO</span><h2>1lm.pzkosz.pl</h2><p class="dt-muted">'.esc_html($s['source_url']).'</p><div class="dt-sync-state"><span class="dt-dot '.(!empty($s['sync_enabled'])?'is-on':'').'"></span><div><strong>'.(!empty($s['sync_enabled'])?'Synchronizacja automatyczna aktywna':'Synchronizacja wyłączona').'</strong><small>WP-Cron · co godzinę</small></div></div><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dt_sync_now">'; wp_nonce_field('dt_sync_now'); echo '<button class="button button-primary dt-button"><span class="dashicons dashicons-update"></span> Synchronizuj teraz</button></form></section><section class="dt-card"><span class="dt-eyebrow">OSTATNI IMPORT</span><h2>'.esc_html($last['at']??'Brak synchronizacji').'</h2>';
        if(!empty($last['result'])){$r=$last['result'];echo '<div class="dt-health"><div><span>Nowe mecze</span><strong>'.(int)($r['matches_new']??0).'</strong></div><div><span>Zaktualizowane</span><strong>'.(int)($r['matches_updated']??0).'</strong></div><div><span>Pominięte ręczne</span><strong>'.(int)($r['matches_skipped']??0).'</strong></div><div><span>Przeliczone typy</span><strong>'.(int)($r['scores']??0).'</strong></div></div>';} else echo '<p class="dt-muted">Uruchom pierwszy import.</p>';
        echo '</section></div><section class="dt-card dt-section"><span class="dt-eyebrow">ZASADA BEZPIECZEŃSTWA</span><h2>Ręczny wpis ma pierwszeństwo</h2><p>Mecz z włączoną ochroną jest rozpoznawany przez synchronizację, ale jego data, godzina i wynik nie są nadpisywane.</p></section>'; self::end_shell();
    }

    public static function logs(): void {
        global $wpdb; $rows=$wpdb->get_results('SELECT l.*,u.display_name FROM '.DT_DB::table('logs').' l LEFT JOIN '.$wpdb->users.' u ON u.ID=l.user_id ORDER BY l.id DESC LIMIT 300'); self::shell('Historia','Log operacji, synchronizacji i zmian');
        echo '<section class="dt-card"><table class="widefat dt-table"><thead><tr><th>Czas</th><th>Zdarzenie</th><th>Opis</th><th>Użytkownik</th></tr></thead><tbody>'; foreach($rows as $r) echo '<tr><td>'.esc_html(self::date_pl($r->created_at)).'</td><td>'.self::badge($r->event,$r->level==='error'?'red':($r->level==='notice'?'orange':'neutral')).'</td><td>'.esc_html($r->message).'</td><td>'.esc_html($r->display_name?:'System').'</td></tr>'; if(!$rows) echo '<tr><td colspan="4" class="dt-empty">Historia jest pusta.</td></tr>'; echo '</tbody></table></section>'; self::end_shell();
    }

    public static function settings(): void {
        $s=DT_DB::settings(); self::shell('Ustawienia','Źródło danych, punktacja, logowanie i wygląd');
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="dt-settings"><input type="hidden" name="action" value="dt_save_settings">'; wp_nonce_field('dt_save_settings');
        echo '<section class="dt-card"><span class="dt-eyebrow">ROZGRYWKI</span><h2>Sezon i synchronizacja</h2><div class="dt-form-2"><label>Sezon<input name="season" value="'.esc_attr($s['season']).'"></label><label>Adres terminarza 1LM<input type="url" name="source_url" value="'.esc_attr($s['source_url']).'"></label></div><label class="dt-check"><input type="checkbox" name="sync_enabled" value="1" '.checked(!empty($s['sync_enabled']),true,false).'><span><strong>Automatyczna synchronizacja</strong><small>Pobieraj terminarz i wyniki co godzinę.</small></span></label></section>';
        echo '<section class="dt-card"><span class="dt-eyebrow">PUNKTACJA</span><h2>Zasady Typera</h2><div class="dt-form-4"><label>Dokładny wynik<input type="number" name="points_exact" value="'.esc_attr($s['points_exact']).'"></label><label>Zwycięzca + różnica<input type="number" name="points_margin" value="'.esc_attr($s['points_margin']).'"></label><label>Zwycięzca<input type="number" name="points_winner" value="'.esc_attr($s['points_winner']).'"></label><label>Bonus pełnej kolejki<input type="number" name="perfect_round_bonus" value="'.esc_attr($s['perfect_round_bonus']).'"></label></div></section>';
        self::provider_fields('Google',[['google_client_id','Client ID','text'],['google_client_secret','Client Secret','password']],DT_OAuth::callback_url('google'),$s);
        self::provider_fields('Facebook',[['facebook_app_id','App ID','text'],['facebook_app_secret','App Secret','password']],DT_OAuth::callback_url('facebook'),$s);
        echo '<section class="dt-card"><span class="dt-eyebrow">LOGOWANIE</span><h2>Apple ID</h2><div class="dt-form-3"><label>Services ID<input name="apple_client_id" value="'.esc_attr($s['apple_client_id']).'"></label><label>Team ID<input name="apple_team_id" value="'.esc_attr($s['apple_team_id']).'"></label><label>Key ID<input name="apple_key_id" value="'.esc_attr($s['apple_key_id']).'"></label></div><label>Klucz prywatny .p8<textarea name="apple_private_key" rows="7" spellcheck="false">'.esc_textarea($s['apple_private_key']).'</textarea></label><div class="dt-callback">Return URL: <code>'.esc_html(DT_OAuth::callback_url('apple')).'</code></div></section>';
        echo '<section class="dt-card"><span class="dt-eyebrow">WYGLĄD</span><h2>Kolory interfejsu</h2><div class="dt-form-3"><label>Niebieski<input type="color" name="brand_primary" value="'.esc_attr($s['brand_primary']).'"></label><label>Akcent<input type="color" name="brand_accent" value="'.esc_attr($s['brand_accent']).'"></label><label>Tło<input type="color" name="brand_surface" value="'.esc_attr($s['brand_surface']).'"></label></div></section>';
        echo '<div class="dt-savebar"><div><strong>Decka Typer '.esc_html(DT_VERSION).'</strong><span>Zmiany ustawień obowiązują od razu.</span></div><button class="button button-primary dt-button">Zapisz ustawienia</button></div></form>'; self::end_shell();
    }
    private static function provider_fields(string $title,array $fields,string $callback,array $s): void {
        echo '<section class="dt-card"><span class="dt-eyebrow">LOGOWANIE</span><h2>'.esc_html($title).'</h2><div class="dt-form-2">';
        foreach($fields as [$name,$label,$type]) echo '<label>'.esc_html($label).'<input type="'.esc_attr($type).'" name="'.esc_attr($name).'" value="'.esc_attr($s[$name]).'" '.($type==='password'?'autocomplete="new-password"':'').'></label>';
        echo '</div><div class="dt-callback">Redirect URI: <code>'.esc_html($callback).'</code></div></section>';
    }

    public static function sync_now(): void { self::guard('dt_sync_now'); $r=DT_Sync::run(true); self::redirect('decka-typer-sync',$r['ok']?'Synchronizacja zakończona. Nowe: '.(int)$r['matches_new'].', zaktualizowane: '.(int)$r['matches_updated'].'.':($r['error']??'Błąd synchronizacji.'),$r['ok']?'success':'error'); }
    public static function save_settings(): void {
        self::guard('dt_save_settings'); $old=DT_DB::settings(); $new=$old;
        $text=['season','source_url','google_client_id','google_client_secret','facebook_app_id','facebook_app_secret','apple_client_id','apple_team_id','apple_key_id'];
        foreach($text as $k){$v=wp_unslash($_POST[$k]??'');$new[$k]=$k==='source_url'?esc_url_raw($v):sanitize_text_field($v);}
        foreach(['points_exact','points_margin','points_winner','perfect_round_bonus'] as $k)$new[$k]=(float)($_POST[$k]??0);
        $new['apple_private_key']=trim(wp_unslash($_POST['apple_private_key']??''));
        foreach(['brand_primary','brand_accent','brand_surface'] as $k)$new[$k]=sanitize_hex_color($_POST[$k]??'')?:$old[$k];
        $new['sync_enabled']=!empty($_POST['sync_enabled'])?1:0; update_option('dt_settings',$new); DT_Logger::log('settings_saved','Zapisano ustawienia Typera.'); self::redirect('decka-typer-settings','Ustawienia zapisane.');
    }
    public static function add_round(): void {
        self::guard('dt_add_round'); global $wpdb; $s=DT_DB::settings(); $no=max(1,(int)($_POST['round_no']??0)); $title=sanitize_text_field($_POST['title']??'')?:$no.'. kolejka';
        $ok=$wpdb->insert(DT_DB::table('rounds'),['season'=>$s['season'],'round_no'=>$no,'title'=>$title,'status'=>'published','source'=>'manual','external_key'=>sha1($s['season'].'|manual|'.$no),'created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')]);
        self::redirect('decka-typer-rounds',$ok?'Kolejka dodana.':'Nie udało się dodać kolejki.',$ok?'success':'error');
    }
    public static function add_match(): void {
        self::guard('dt_add_match'); global $wpdb; $roundId=(int)($_POST['round_id']??0); $home=(int)($_POST['home_team_id']??0); $away=(int)($_POST['away_team_id']??0); $start=self::mysql_datetime($_POST['starts_at']??'');
        if(!$roundId||!$home||!$away||$home===$away||!$start) self::redirect('decka-typer-matches','Sprawdź dane meczu.','error');
        $sh=self::nullable_int($_POST['score_home']??''); $sa=self::nullable_int($_POST['score_away']??''); $now=current_time('mysql');
        $wpdb->insert(DT_DB::table('matches'),['round_id'=>$roundId,'external_key'=>sha1('manual|'.$roundId.'|'.$home.'|'.$away.'|'.$start.'|'.wp_generate_uuid4()),'home_team_id'=>$home,'away_team_id'=>$away,'starts_at'=>$start,'start_time_known'=>1,'score_home'=>$sh,'score_away'=>$sa,'status'=>($sh!==null&&$sa!==null)?'finished':'scheduled','manual_lock'=>1,'created_at'=>$now,'updated_at'=>$now]);
        $id=(int)$wpdb->insert_id; if($id&&$sh!==null&&$sa!==null)DT_Scoring::recalc_match($id); DT_Logger::log('match_added','Dodano mecz ręcznie.',['match_id'=>$id]); self::redirect('decka-typer-matches','Mecz dodany i zabezpieczony przed synchronizacją.');
    }
    public static function save_match(): void {
        self::guard('dt_save_match'); global $wpdb; $id=(int)($_POST['match_id']??0); $m=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DT_DB::table('matches').' WHERE id=%d',$id)); if(!$m)self::redirect('decka-typer-matches','Nie znaleziono meczu.','error');
        $start=self::mysql_datetime($_POST['starts_at']??''); $sh=self::nullable_int($_POST['score_home']??''); $sa=self::nullable_int($_POST['score_away']??'');
        $data=['starts_at'=>$start?:$m->starts_at,'start_time_known'=>1,'score_home'=>$sh,'score_away'=>$sa,'status'=>($sh!==null&&$sa!==null)?'finished':'scheduled','manual_lock'=>!empty($_POST['manual_lock'])?1:0,'updated_at'=>current_time('mysql')];
        $wpdb->update(DT_DB::table('matches'),$data,['id'=>$id]); if($sh!==null&&$sa!==null)DT_Scoring::recalc_match($id); DT_Logger::log('match_updated','Administrator poprawił mecz.',['match_id'=>$id,'manual_lock'=>$data['manual_lock']]); self::redirect('decka-typer-matches','Mecz zaktualizowany.');
    }
    public static function adjust_points(): void {
        self::guard('dt_adjust_points'); global $wpdb; $uid=(int)($_POST['user_id']??0); $pts=(float)($_POST['points']??0); $reason=sanitize_text_field($_POST['reason']??''); $s=DT_DB::settings(); if(!$uid||!$reason)self::redirect('decka-typer-users','Brak danych korekty.','error');
        $wpdb->insert(DT_DB::table('point_adjustments'),['user_id'=>$uid,'season'=>$s['season'],'points'=>$pts,'reason'=>$reason,'admin_user_id'=>get_current_user_id(),'created_at'=>current_time('mysql')]); DT_Logger::log('points_adjusted','Ręczna korekta punktów.',['target_user'=>$uid,'points'=>$pts,'reason'=>$reason]); self::redirect('decka-typer-users','Korekta punktów zapisana.');
    }

    private static function nullable_int($v): ?int { return ($v===''||$v===null)?null:max(0,min(250,(int)$v)); }
    private static function mysql_datetime(string $v): ?string { $v=sanitize_text_field($v); if(!$v)return null; try{return (new DateTimeImmutable($v,wp_timezone()))->format('Y-m-d H:i:s');}catch(Throwable $e){return null;} }
}
