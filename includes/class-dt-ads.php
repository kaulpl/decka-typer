<?php
if (!defined('ABSPATH')) exit;

/** Scheduled advertising campaigns, fixed placements and aggregate reporting. */
class DT_Ads {
    private const PAGE = 'decka-typer-ads';
    private const SLOTS = [
        'd1' => ['name'=>'D1 — Billboard górny', 'width'=>970, 'height'=>250, 'location'=>'Pod nagłówkiem, nad treścią strony'],
        'd2' => ['name'=>'D2 — Billboard dolny', 'width'=>970, 'height'=>250, 'location'=>'Bezpośrednio przed stopką strony'],
    ];

    public static function register(): void {
        add_action('admin_menu', [__CLASS__, 'menu'], 12);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
        add_action('admin_post_dt_save_ad', [__CLASS__, 'save']);
        add_action('admin_post_dt_change_ad_status', [__CLASS__, 'change_status']);
        add_action('admin_post_dt_ad_click', [__CLASS__, 'click']);
        add_action('admin_post_nopriv_dt_ad_click', [__CLASS__, 'click']);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets'], 130);
        add_action('wp_footer', [__CLASS__, 'render_footer_slot'], 20);
    }

    public static function slots(): array {
        return apply_filters('dt_ad_slots', self::SLOTS);
    }

    public static function menu(): void {
        add_submenu_page('decka-typer', 'Reklamy', 'Reklamy', 'manage_options', self::PAGE, [__CLASS__, 'admin_page']);
    }

    public static function admin_assets(string $hook): void {
        if (strpos($hook, self::PAGE) === false) return;
        wp_enqueue_media();
    }

    public static function assets(): void {
        if (!class_exists('DT_Frontend') || !DT_Frontend::is_typer_page()) return;
        wp_enqueue_style('dt-ads', DT_URL.'assets/css/ads.css', ['dt-front'], DT_VERSION);
        wp_enqueue_script('dt-ads', DT_URL.'assets/js/ads.js', [], DT_VERSION, true);
        wp_localize_script('dt-ads', 'TypujKoszaAds', ['root'=>esc_url_raw(rest_url('decka-typer/v1/'))]);
    }

    public static function routes(): void {
        register_rest_route('decka-typer/v1', '/ads/impression', [
            'methods'=>'POST', 'callback'=>[__CLASS__, 'impression'], 'permission_callback'=>'__return_true',
        ]);
    }

    public static function impression(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $data = (array)$request->get_json_params();
        $id = absint($data['ad_id'] ?? 0);
        $token = sanitize_text_field((string)($data['token'] ?? ''));
        $ad = self::ad($id);
        if (!$ad || !hash_equals(self::token($ad), $token) || !self::eligible($ad)) {
            return new WP_Error('invalid_ad', 'Reklama nie jest aktywna.', ['status'=>404]);
        }
        self::increment($id, 'impressions');
        return new WP_REST_Response(['ok'=>true]);
    }

    public static function click(): void {
        $id = absint($_GET['ad'] ?? 0);
        $token = sanitize_text_field(wp_unslash((string)($_GET['token'] ?? '')));
        $ad = self::ad($id);
        if (!$ad || !hash_equals(self::token($ad), $token)) {
            wp_safe_redirect(home_url('/'));
            exit;
        }
        if (self::eligible($ad)) self::increment($id, 'clicks');
        $target = esc_url_raw((string)$ad->target_url);
        wp_redirect($target ?: home_url('/'), 302, 'TypujKosza.pl reklamy');
        exit;
    }

    public static function render_slot(string $slot): void {
        $slots = self::slots();
        if (!isset($slots[$slot])) return;
        $settings = DT_DB::settings();
        if (!empty($settings['ad_slot_preview']) && current_user_can('manage_options')) {
            self::placeholder($slot, $slots[$slot]);
            return;
        }
        $ad = self::active_for_slot($slot);
        if (!$ad) return;
        $click = add_query_arg([
            'action'=>'dt_ad_click', 'ad'=>(int)$ad->id, 'token'=>self::token($ad),
        ], admin_url('admin-post.php'));
        echo '<aside class="dt-ad-slot dt-ad-slot-'.esc_attr($slot).'" aria-label="Reklama"><span class="dt-ad-label">REKLAMA</span><a href="'.esc_url($click).'" target="_blank" rel="sponsored noopener noreferrer" data-dt-ad="'.(int)$ad->id.'" data-dt-ad-token="'.esc_attr(self::token($ad)).'" aria-label="'.esc_attr((string)($ad->alt_text ?: $ad->name)).'"><img src="'.esc_url((string)$ad->image_url).'" alt="'.esc_attr((string)($ad->alt_text ?: $ad->name)).'" width="'.(int)$slots[$slot]['width'].'" height="'.(int)$slots[$slot]['height'].'" loading="'.($slot === 'd1' ? 'eager' : 'lazy').'" decoding="async"></a></aside>';
    }

    public static function render_footer_slot(): void {
        if (class_exists('DT_Frontend') && DT_Frontend::is_typer_page()) self::render_slot('d2');
    }

    private static function placeholder(string $slot, array $config): void {
        echo '<aside class="dt-ad-slot dt-ad-slot-preview dt-ad-slot-'.esc_attr($slot).'" aria-label="Symulacja miejsca reklamowego"><div><strong>'.esc_html(strtoupper($slot).' · '.$config['name']).'</strong><span>'.(int)$config['width'].' × '.(int)$config['height'].' px</span><small>'.esc_html((string)$config['location']).'</small></div></aside>';
    }

    private static function active_for_slot(string $slot): ?object {
        global $wpdb;
        $now = current_time('mysql');
        $ads = DT_DB::table('ads');
        $stats = DT_DB::table('ad_stats');
        return $wpdb->get_row($wpdb->prepare(
            "SELECT a.*,COALESCE(s.impressions,0) impressions,COALESCE(s.clicks,0) clicks
             FROM $ads a
             LEFT JOIN (SELECT ad_id,SUM(impressions) impressions,SUM(clicks) clicks FROM $stats GROUP BY ad_id) s ON s.ad_id=a.id
             WHERE a.slot_key=%s AND a.status='active' AND a.starts_at<=%s AND a.ends_at>=%s
               AND (a.max_impressions=0 OR COALESCE(s.impressions,0)<a.max_impressions)
               AND (a.max_clicks=0 OR COALESCE(s.clicks,0)<a.max_clicks)
             ORDER BY a.priority DESC,a.id ASC LIMIT 1",
            $slot, $now, $now
        )) ?: null;
    }

    private static function ad(int $id): ?object {
        global $wpdb;
        return $id ? ($wpdb->get_row($wpdb->prepare('SELECT * FROM '.DT_DB::table('ads').' WHERE id=%d', $id)) ?: null) : null;
    }

    private static function eligible(object $ad): bool {
        if ((string)$ad->status !== 'active') return false;
        $now = current_time('timestamp');
        $start = self::timestamp((string)$ad->starts_at);
        $end = self::timestamp((string)$ad->ends_at);
        if (!$start || !$end || $now < $start || $now > $end) return false;
        global $wpdb;
        $totals = $wpdb->get_row($wpdb->prepare('SELECT COALESCE(SUM(impressions),0) impressions,COALESCE(SUM(clicks),0) clicks FROM '.DT_DB::table('ad_stats').' WHERE ad_id=%d', (int)$ad->id));
        if ((int)$ad->max_impressions > 0 && (int)$totals->impressions >= (int)$ad->max_impressions) return false;
        if ((int)$ad->max_clicks > 0 && (int)$totals->clicks >= (int)$ad->max_clicks) return false;
        return true;
    }

    private static function token(object $ad): string {
        return hash_hmac('sha256', (int)$ad->id.'|'.(string)$ad->updated_at, wp_salt('nonce'));
    }

    private static function increment(int $id, string $column): void {
        if (!in_array($column, ['impressions','clicks'], true)) return;
        global $wpdb;
        $table = DT_DB::table('ad_stats');
        $date = current_time('Y-m-d');
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (ad_id,stat_date,$column) VALUES (%d,%s,1)
             ON DUPLICATE KEY UPDATE $column=$column+1",
            $id, $date
        ));
    }

    public static function save(): void {
        self::guard('dt_save_ad');
        global $wpdb;
        $id = absint($_POST['id'] ?? 0);
        $slots = self::slots();
        $slot = sanitize_key($_POST['slot_key'] ?? '');
        $status = sanitize_key($_POST['status'] ?? 'draft');
        $name = sanitize_text_field(wp_unslash((string)($_POST['name'] ?? '')));
        $advertiser = sanitize_text_field(wp_unslash((string)($_POST['advertiser'] ?? '')));
        $image = esc_url_raw(wp_unslash((string)($_POST['image_url'] ?? '')));
        $target = esc_url_raw(wp_unslash((string)($_POST['target_url'] ?? '')));
        $start = self::posted_datetime((string)($_POST['starts_at'] ?? ''));
        $end = self::posted_datetime((string)($_POST['ends_at'] ?? ''));
        if ($name === '' || $advertiser === '' || !$image || !$target || !isset($slots[$slot]) || !$start || !$end || $end <= $start) {
            self::redirect('Nie udało się zapisać kampanii. Sprawdź nazwę, reklamodawcę, grafikę, link i daty.', 'error', $id ? ['edit'=>$id] : []);
        }
        if (!in_array($status, ['draft','active','paused','archived'], true)) $status = 'draft';
        $now = current_time('mysql');
        $data = [
            'name'=>$name, 'advertiser'=>$advertiser, 'slot_key'=>$slot, 'image_url'=>$image,
            'target_url'=>$target, 'alt_text'=>sanitize_text_field(wp_unslash((string)($_POST['alt_text'] ?? ''))),
            'status'=>$status, 'priority'=>max(0,min(100,(int)($_POST['priority'] ?? 10))),
            'starts_at'=>$start->format('Y-m-d H:i:s'), 'ends_at'=>$end->format('Y-m-d H:i:s'),
            'max_impressions'=>max(0,(int)($_POST['max_impressions'] ?? 0)),
            'max_clicks'=>max(0,(int)($_POST['max_clicks'] ?? 0)),
            'notes'=>sanitize_textarea_field(wp_unslash((string)($_POST['notes'] ?? ''))),
            'updated_at'=>$now,
        ];
        if ($id) {
            $ok = $wpdb->update(DT_DB::table('ads'), $data, ['id'=>$id]);
        } else {
            $data['created_by'] = get_current_user_id();
            $data['created_at'] = $now;
            $ok = $wpdb->insert(DT_DB::table('ads'), $data);
            $id = (int)$wpdb->insert_id;
        }
        DT_Logger::log('ad_campaign_saved', 'Zapisano kampanię reklamową.', ['ad_id'=>$id,'slot'=>$slot,'status'=>$status], 'notice', get_current_user_id());
        self::redirect($ok === false ? 'Nie udało się zapisać kampanii.' : 'Kampania została zapisana.', $ok === false ? 'error' : 'success');
    }

    public static function change_status(): void {
        self::guard('dt_change_ad_status');
        global $wpdb;
        $id = absint($_POST['id'] ?? 0);
        $status = sanitize_key($_POST['status'] ?? 'paused');
        if (!in_array($status, ['active','paused','archived'], true)) $status = 'paused';
        $ok = $wpdb->update(DT_DB::table('ads'), ['status'=>$status,'updated_at'=>current_time('mysql')], ['id'=>$id]);
        DT_Logger::log('ad_campaign_status', 'Zmieniono status kampanii reklamowej.', ['ad_id'=>$id,'status'=>$status], 'notice', get_current_user_id());
        self::redirect($ok === false ? 'Nie udało się zmienić statusu.' : 'Status kampanii został zmieniony.', $ok === false ? 'error' : 'success');
    }

    public static function admin_page(): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        global $wpdb;
        $ads = DT_DB::table('ads');
        $stats = DT_DB::table('ad_stats');
        $editId = absint($_GET['edit'] ?? 0);
        $editing = $editId ? self::ad($editId) : null;
        $rows = $wpdb->get_results("SELECT a.*,COALESCE(s.impressions,0) impressions,COALESCE(s.clicks,0) clicks
            FROM $ads a LEFT JOIN (SELECT ad_id,SUM(impressions) impressions,SUM(clicks) clicks FROM $stats GROUP BY ad_id) s ON s.ad_id=a.id
            ORDER BY a.id DESC");
        $totals = $wpdb->get_row("SELECT COALESCE(SUM(impressions),0) impressions,COALESCE(SUM(clicks),0) clicks FROM $stats");
        $active = 0; foreach ((array)$rows as $row) if (self::eligible($row)) $active++;
        echo '<div class="wrap dt-admin"><div class="dt-admin-head"><div><div class="dt-kicker">TYPUJKOSZA.PL · MONETYZACJA</div><h1>Reklamy</h1><p>Kampanie, harmonogramy, sloty i statystyki emisji</p></div></div>';
        self::notice();
        echo '<div class="dt-grid dt-grid-4">';
        self::metric('Kampanie', count((array)$rows)); self::metric('Aktywne teraz', $active); self::metric('Wyświetlenia', (int)($totals->impressions??0)); self::metric('Kliknięcia', (int)($totals->clicks??0));
        echo '</div>';
        self::form($editing);
        echo '<section class="dt-card dt-section"><div class="dt-card-head"><div><span class="dt-eyebrow">KAMPANIE</span><h2>Lista reklam</h2></div><a class="button dt-button" href="'.esc_url(admin_url('admin.php?page='.self::PAGE)).'">Nowa kampania</a></div><div class="dt-ad-admin-list">';
        foreach ((array)$rows as $row) self::row($row);
        if (!$rows) echo '<div class="dt-empty">Nie utworzono jeszcze żadnej kampanii reklamowej.</div>';
        echo '</div></section></div>';
    }

    private static function form(?object $ad): void {
        $slots = self::slots();
        $start = $ad ? str_replace(' ', 'T', substr((string)$ad->starts_at,0,16)) : current_datetime()->format('Y-m-d\TH:i');
        $end = $ad ? str_replace(' ', 'T', substr((string)$ad->ends_at,0,16)) : current_datetime()->modify('+1 month')->format('Y-m-d\TH:i');
        echo '<section class="dt-card dt-section"><span class="dt-eyebrow">'.($ad?'EDYCJA KAMPANII':'NOWA KAMPANIA').'</span><h2>'.esc_html($ad?(string)$ad->name:'Dodaj reklamę').'</h2><form class="dt-ad-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dt_save_ad"><input type="hidden" name="id" value="'.(int)($ad->id??0).'">';
        wp_nonce_field('dt_save_ad');
        echo '<div class="dt-form-3"><label>Nazwa kampanii<input name="name" required maxlength="190" value="'.esc_attr((string)($ad->name??'')).'"></label><label>Reklamodawca / od kogo<input name="advertiser" required maxlength="190" value="'.esc_attr((string)($ad->advertiser??'')).'"></label><label>Slot reklamowy<select name="slot_key" required>';
        foreach ($slots as $key=>$slot) echo '<option value="'.esc_attr($key).'" '.selected((string)($ad->slot_key??'d1'),$key,false).'>'.esc_html($slot['name'].' · '.$slot['width'].'×'.$slot['height'].' px').'</option>';
        echo '</select></label></div><div class="dt-form-2"><label>Grafika reklamy — URL<div class="dt-media-field"><input type="url" name="image_url" data-dt-media-input required value="'.esc_attr((string)($ad->image_url??'')).'"><button type="button" class="button" data-dt-media>Wybierz z biblioteki</button></div></label><label>Link docelowy<input type="url" name="target_url" required value="'.esc_attr((string)($ad->target_url??'')).'" placeholder="https://..."></label></div><label>Opis alternatywny grafiki<input name="alt_text" maxlength="255" value="'.esc_attr((string)($ad->alt_text??'')).'" placeholder="Krótki opis dostępny dla czytników ekranu"></label><div class="dt-form-4"><label>Start emisji<input type="datetime-local" name="starts_at" required value="'.esc_attr($start).'"></label><label>Koniec emisji<input type="datetime-local" name="ends_at" required value="'.esc_attr($end).'"></label><label>Status<select name="status">';
        foreach (['draft'=>'Szkic','active'=>'Aktywna','paused'=>'Wstrzymana','archived'=>'Archiwalna'] as $key=>$label) echo '<option value="'.$key.'" '.selected((string)($ad->status??'draft'),$key,false).'>'.$label.'</option>';
        echo '</select></label><label>Priorytet 0–100<input type="number" min="0" max="100" name="priority" value="'.esc_attr((int)($ad->priority??10)).'"></label></div><div class="dt-form-2"><label>Limit wyświetleń <small>0 = bez limitu</small><input type="number" min="0" name="max_impressions" value="'.esc_attr((int)($ad->max_impressions??0)).'"></label><label>Limit kliknięć <small>0 = bez limitu</small><input type="number" min="0" name="max_clicks" value="'.esc_attr((int)($ad->max_clicks??0)).'"></label></div><label>Notatki wewnętrzne<textarea name="notes" rows="3">'.esc_textarea((string)($ad->notes??'')).'</textarea></label><div class="dt-ad-form-actions"><button class="button button-primary dt-button">'.($ad?'Zapisz zmiany':'Utwórz kampanię').'</button>'.($ad?' <a class="button" href="'.esc_url(admin_url('admin.php?page='.self::PAGE)).'">Anuluj edycję</a>':'').'</div></form></section>';
    }

    private static function row(object $ad): void {
        $slots = self::slots();
        $impressions = (int)$ad->impressions; $clicks = (int)$ad->clicks;
        $ctr = $impressions > 0 ? number_format(($clicks/$impressions)*100, 2, ',', ' ').'%' : '0,00%';
        $effective = self::effective_status($ad);
        $duration = self::duration((string)$ad->starts_at, (string)$ad->ends_at);
        echo '<article class="dt-ad-admin-item"><img src="'.esc_url((string)$ad->image_url).'" alt=""><div class="dt-ad-admin-main"><header><div><strong>'.esc_html((string)$ad->name).'</strong><span>'.esc_html((string)$ad->advertiser).'</span></div><span class="dt-badge dt-badge-'.esc_attr($effective['tone']).'">'.esc_html($effective['label']).'</span></header><div class="dt-ad-admin-meta"><span><b>Slot</b>'.esc_html((string)($slots[$ad->slot_key]['name']??$ad->slot_key)).'</span><span><b>Emisja</b>'.esc_html(self::date((string)$ad->starts_at).' → '.self::date((string)$ad->ends_at)).'</span><span><b>Czas</b>'.esc_html($duration).'</span><span><b>Priorytet</b>'.(int)$ad->priority.'</span></div><div class="dt-ad-admin-stats"><span><b>'.number_format_i18n($impressions).'</b>wyświetleń</span><span><b>'.number_format_i18n($clicks).'</b>kliknięć</span><span><b>'.$ctr.'</b>CTR</span></div><footer><a class="button" href="'.esc_url(admin_url('admin.php?page='.self::PAGE.'&edit='.(int)$ad->id)).'">Edytuj</a><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dt_change_ad_status"><input type="hidden" name="id" value="'.(int)$ad->id.'">'; wp_nonce_field('dt_change_ad_status');
        if ((string)$ad->status === 'active') echo '<input type="hidden" name="status" value="paused"><button class="button">Wstrzymaj</button>';
        else echo '<input type="hidden" name="status" value="active"><button class="button">Aktywuj</button>';
        echo '</form><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dt_change_ad_status"><input type="hidden" name="id" value="'.(int)$ad->id.'"><input type="hidden" name="status" value="archived">'; wp_nonce_field('dt_change_ad_status'); echo '<button class="button">Archiwizuj</button></form></footer></div></article>';
    }

    private static function effective_status(object $ad): array {
        if ((string)$ad->status === 'archived') return ['label'=>'Archiwalna','tone'=>'neutral'];
        if ((string)$ad->status === 'paused') return ['label'=>'Wstrzymana','tone'=>'orange'];
        if ((string)$ad->status === 'draft') return ['label'=>'Szkic','tone'=>'neutral'];
        $now = current_time('timestamp'); $start = self::timestamp((string)$ad->starts_at); $end = self::timestamp((string)$ad->ends_at);
        if ($now < $start) return ['label'=>'Zaplanowana','tone'=>'blue'];
        if ($now > $end) return ['label'=>'Zakończona','tone'=>'red'];
        if ((int)$ad->max_impressions > 0 && (int)$ad->impressions >= (int)$ad->max_impressions) return ['label'=>'Limit wyświetleń','tone'=>'red'];
        if ((int)$ad->max_clicks > 0 && (int)$ad->clicks >= (int)$ad->max_clicks) return ['label'=>'Limit kliknięć','tone'=>'red'];
        return ['label'=>'Emituje się','tone'=>'green'];
    }

    private static function posted_datetime(string $value): ?DateTimeImmutable {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', sanitize_text_field($value), wp_timezone());
        return $date ?: null;
    }

    private static function timestamp(string $value): int {
        try { return (new DateTimeImmutable($value, wp_timezone()))->getTimestamp(); }
        catch (Throwable $e) { return 0; }
    }

    private static function duration(string $start, string $end): string {
        try { $a=new DateTimeImmutable($start,wp_timezone());$b=new DateTimeImmutable($end,wp_timezone());$days=max(1,(int)$a->diff($b)->days+1);$months=round($days/30.44,1);return $days.' dni · ok. '.str_replace('.',',',(string)$months).' mies.'; } catch (Throwable $e) { return '—'; }
    }

    private static function date(string $value): string {
        try { return (new DateTimeImmutable($value,wp_timezone()))->format('d.m.Y H:i'); } catch (Throwable $e) { return '—'; }
    }

    private static function guard(string $nonce): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        check_admin_referer($nonce);
    }

    private static function redirect(string $message, string $type='success', array $extra=[]): void {
        wp_safe_redirect(add_query_arg(array_merge(['page'=>self::PAGE,'dt_notice'=>$message,'dt_type'=>$type],$extra),admin_url('admin.php'))); exit;
    }

    private static function notice(): void {
        if (empty($_GET['dt_notice'])) return;
        $type = sanitize_key($_GET['dt_type'] ?? 'success');
        echo '<div class="dt-toast-static dt-'.esc_attr($type).'">'.esc_html(sanitize_text_field(wp_unslash($_GET['dt_notice']))).'</div>';
    }

    private static function metric(string $label, int $value): void {
        echo '<div class="dt-metric"><div><span>'.esc_html($label).'</span><strong>'.esc_html(number_format_i18n($value)).'</strong></div></div>';
    }
}
