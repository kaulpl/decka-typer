<?php
if (!defined('ABSPATH')) exit;

/**
 * Multi-league foundation for PLK, 1LM and 2LM (groups A-D).
 * Keeps the legacy 1LM engine compatible while exposing league-aware public
 * views, rankings, history, account achievements and administrator controls.
 */
class DT_Multileague {
    public const LEAGUES_OPTION = 'dt_leagues';

    public static function register(): void {
        add_action('rest_api_init', [__CLASS__, 'routes'], 30);
        add_action('wp_enqueue_scripts', [__CLASS__, 'frontend_assets'], 220);
        add_filter('template_include', [__CLASS__, 'break_template'], 150);
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 120);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets'], 220);
        add_action('admin_post_dt_ml_add_round', [__CLASS__, 'admin_add_round']);
        add_action('admin_post_dt_ml_save_leagues', [__CLASS__, 'admin_save_leagues']);
        add_filter('pre_update_option_dt_settings', [__CLASS__, 'save_site_mode_from_settings'], 10, 3);
        add_action('admin_post_nopriv_dt_oauth_start', [__CLASS__, 'block_public_auth_during_break'], 1);
        add_action('admin_post_dt_oauth_start', [__CLASS__, 'block_public_auth_during_break'], 1);
        add_action('init', [__CLASS__, 'ensure_league_config'], 5);
    }

    public static function definitions(): array {
        return [
            'plk' => [
                'code'=>'plk', 'name'=>'PLK', 'full_name'=>'Polska Liga Koszykówki',
                'groups'=>[], 'color'=>'#07162F', 'order'=>10,
            ],
            '1lm' => [
                'code'=>'1lm', 'name'=>'1LM', 'full_name'=>'1 Liga Mężczyzn',
                'groups'=>[], 'color'=>'#055EFB', 'order'=>20,
            ],
            '2lm' => [
                'code'=>'2lm', 'name'=>'2LM', 'full_name'=>'2 Liga Mężczyzn',
                'groups'=>['A','B','C','D'], 'color'=>'#FB5D0B', 'order'=>30,
            ],
        ];
    }

    public static function ensure_league_config(): void {
        $stored = (array)get_option(self::LEAGUES_OPTION, []);
        $defaults = [];
        foreach (self::definitions() as $code=>$def) {
            $defaults[$code] = [
                'enabled'=>1,
                'source_url'=>$code === '1lm' ? 'https://1lm.pzkosz.pl/terminarz-i-wyniki.html' : '',
                'groups'=>[],
            ];
            foreach ($def['groups'] as $group) $defaults[$code]['groups'][$group] = ['enabled'=>1,'source_url'=>''];
        }
        $merged = $stored;
        foreach ($defaults as $code=>$config) {
            $merged[$code] = wp_parse_args((array)($stored[$code] ?? []), $config);
            $merged[$code]['groups'] = wp_parse_args((array)($merged[$code]['groups'] ?? []), $config['groups']);
        }
        if ($merged !== $stored) update_option(self::LEAGUES_OPTION, $merged, false);
    }

    public static function league_config(): array {
        self::ensure_league_config();
        return (array)get_option(self::LEAGUES_OPTION, []);
    }

    public static function site_mode(): string {
        $mode = sanitize_key((string)(DT_DB::settings()['site_mode'] ?? 'production'));
        return in_array($mode, ['production','test','break'], true) ? $mode : 'production';
    }

    public static function routes(): void {
        register_rest_route('decka-typer/v1', '/multileague/bootstrap', [
            'methods'=>'GET', 'callback'=>[__CLASS__, 'api_bootstrap'],
            'permission_callback'=>static fn()=>is_user_logged_in(),
        ]);
        register_rest_route('decka-typer/v1', '/multileague/ranking', [
            'methods'=>'GET', 'callback'=>[__CLASS__, 'api_ranking'], 'permission_callback'=>'__return_true',
        ]);
        register_rest_route('decka-typer/v1', '/multileague/user-stats', [
            'methods'=>'GET', 'callback'=>[__CLASS__, 'api_user_stats'],
            'permission_callback'=>static fn()=>is_user_logged_in(),
        ]);
        register_rest_route('decka-typer/v1', '/multileague/my-types', [
            'methods'=>'GET', 'callback'=>[__CLASS__, 'api_my_types'],
            'permission_callback'=>static fn()=>is_user_logged_in(),
        ]);
    }

    public static function api_bootstrap(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response([
            'mode'=>self::site_mode(),
            'leagues'=>self::public_leagues(),
            'seasons'=>self::seasons(),
            'open_rounds'=>self::open_rounds(get_current_user_id()),
        ]);
    }

    public static function api_ranking(WP_REST_Request $request): WP_REST_Response {
        $scope = sanitize_key((string)$request->get_param('scope'));
        if (!in_array($scope, ['all','league','season','round'], true)) $scope = 'all';
        $league = self::clean_league((string)$request->get_param('league'));
        $group = self::clean_group($league, (string)$request->get_param('group'));
        $season = sanitize_text_field((string)$request->get_param('season'));
        $roundId = max(0, (int)$request->get_param('round_id'));

        if ($scope === 'league' && !$league) $league = '1lm';
        if (in_array($scope, ['season','round'], true)) {
            if (!$league) $league = '1lm';
            if ($season === '') $season = (string)(DT_DB::settings()['season'] ?? '2026/2027');
        }
        if ($scope !== 'round') $roundId = 0;

        return new WP_REST_Response([
            'scope'=>$scope,
            'league'=>$league,
            'group'=>$group,
            'season'=>$season,
            'round_id'=>$roundId,
            'leagues'=>self::public_leagues(),
            'seasons'=>self::seasons($league, $group),
            'rounds'=>self::round_choices($league, $season, $group),
            'ranking'=>self::ranking_rows($scope, $league, $season, $roundId, $group),
        ]);
    }

    public static function api_user_stats(WP_REST_Request $request): WP_REST_Response {
        $scope = sanitize_key((string)$request->get_param('scope'));
        if (!in_array($scope, ['all','league','season'], true)) $scope = 'all';
        $league = self::clean_league((string)$request->get_param('league'));
        $group = self::clean_group($league, (string)$request->get_param('group'));
        $season = sanitize_text_field((string)$request->get_param('season'));
        if ($scope === 'league' && !$league) $league = '1lm';
        if ($scope === 'season') {
            if (!$league) $league = '1lm';
            if ($season === '') $season = (string)(DT_DB::settings()['season'] ?? '2026/2027');
        }
        return new WP_REST_Response([
            'scope'=>$scope,
            'league'=>$league,
            'group'=>$group,
            'season'=>$season,
            'leagues'=>self::public_leagues(),
            'seasons'=>self::seasons($league, $group),
            'stats'=>self::user_stats(get_current_user_id(), $scope, $league, $season, $group),
        ]);
    }

    public static function api_my_types(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response([
            'items'=>self::my_types(get_current_user_id()),
            'leagues'=>self::public_leagues(),
        ]);
    }

    private static function public_leagues(): array {
        $config = self::league_config();
        $out = [];
        foreach (self::definitions() as $code=>$def) {
            if (empty($config[$code]['enabled'])) continue;
            $out[] = [
                'code'=>$code,
                'name'=>$def['name'],
                'full_name'=>$def['full_name'],
                'groups'=>$def['groups'],
                'color'=>$def['color'],
            ];
        }
        return $out;
    }

    private static function clean_league(string $league): string {
        $league = sanitize_key($league);
        return isset(self::definitions()[$league]) ? $league : '';
    }

    private static function clean_group(string $league, string $group): string {
        $group = strtoupper(sanitize_text_field($group));
        if ($league !== '2lm') return '';
        return in_array($group, ['A','B','C','D'], true) ? $group : '';
    }

    private static function league_label(string $league, string $group = ''): string {
        $def = self::definitions()[$league] ?? ['name'=>strtoupper($league)];
        return (string)$def['name'] . ($group !== '' ? ' · grupa ' . $group : '');
    }

    private static function seasons(string $league = '', string $group = ''): array {
        global $wpdb;
        $where = " WHERE season<>'' ";
        $args = [];
        if ($league !== '') { $where .= ' AND league_code=%s '; $args[] = $league; }
        if ($group !== '') { $where .= ' AND group_code=%s '; $args[] = $group; }
        $sql = 'SELECT DISTINCT season FROM ' . DT_DB::table('rounds') . $where . ' ORDER BY season DESC';
        if ($args) $sql = $wpdb->prepare($sql, ...$args);
        $items = array_values(array_unique(array_filter(array_map('strval', (array)$wpdb->get_col($sql)))));
        $current = (string)(DT_DB::settings()['season'] ?? '');
        usort($items, static function(string $a,string $b) use ($current): int {
            if ($a === $current && $b !== $current) return -1;
            if ($b === $current && $a !== $current) return 1;
            preg_match('/(20\d{2})/', $a, $ma); preg_match('/(20\d{2})/', $b, $mb);
            return ((int)($mb[1] ?? 0)) <=> ((int)($ma[1] ?? 0));
        });
        if (!$items && $current !== '') $items[] = $current;
        return array_slice($items, 0, 10);
    }

    private static function round_choices(string $league, string $season, string $group = ''): array {
        global $wpdb;
        if ($league === '' || $season === '') return [];
        $sql = "SELECT id,round_no,title,status,group_code FROM " . DT_DB::table('rounds') . " WHERE league_code=%s AND season=%s AND status IN ('open','closed')";
        $args = [$league,$season];
        if ($group !== '') { $sql .= ' AND group_code=%s'; $args[] = $group; }
        $sql .= ' ORDER BY round_no ASC,id ASC';
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
        foreach ((array)$rows as &$row) { $row['id']=(int)$row['id']; $row['round_no']=(int)$row['round_no']; }
        unset($row);
        return is_array($rows) ? $rows : [];
    }

    private static function open_rounds(int $uid): array {
        global $wpdb;
        $now = current_time('mysql');
        $rounds = DT_DB::table('rounds');
        $matches = DT_DB::table('matches');
        $subs = DT_DB::table('round_submissions');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT r.id,r.league_code,r.group_code,r.season,r.round_no,r.title,r.closes_at,r.status,
                    COUNT(m.id) match_count,
                    MAX(CASE WHEN s.id IS NULL THEN 0 ELSE 1 END) submitted
             FROM $rounds r
             LEFT JOIN $matches m ON m.round_id=r.id
             LEFT JOIN $subs s ON s.round_id=r.id AND s.user_id=%d
             WHERE r.status='open' AND (r.closes_at IS NULL OR r.closes_at>%s)
             GROUP BY r.id
             HAVING match_count>0
             ORDER BY FIELD(r.league_code,'plk','1lm','2lm'),r.group_code,r.round_no,r.id",
            $uid, $now
        ), ARRAY_A);
        $config = self::league_config();
        $out = [];
        foreach ((array)$rows as $row) {
            $league = self::clean_league((string)$row['league_code']);
            if ($league === '' || empty($config[$league]['enabled'])) continue;
            $group = self::clean_group($league, (string)$row['group_code']);
            if ($league === '2lm' && $group !== '' && empty($config['2lm']['groups'][$group]['enabled'])) continue;
            $out[] = [
                'id'=>(int)$row['id'], 'league'=>$league, 'league_label'=>self::league_label($league,$group),
                'group'=>$group, 'season'=>(string)$row['season'], 'round_no'=>(int)$row['round_no'],
                'title'=>(string)$row['title'], 'closes_at_iso'=>self::iso_datetime($row['closes_at'] ?? null),
                'match_count'=>(int)$row['match_count'], 'submitted'=>(bool)$row['submitted'],
            ];
        }
        return $out;
    }

    private static function ranking_filter(string $scope, string $league, string $season, int $roundId, string $group): array {
        global $wpdb;
        $sql = '';
        if ($scope === 'league' && $league !== '') $sql .= $wpdb->prepare(' AND r.league_code=%s ', $league);
        if ($scope === 'season') {
            $sql .= $wpdb->prepare(' AND r.league_code=%s AND r.season=%s ', $league, $season);
            if ($group !== '') $sql .= $wpdb->prepare(' AND r.group_code=%s ', $group);
        }
        if ($scope === 'round' && $roundId > 0) $sql .= $wpdb->prepare(' AND r.id=%d ', $roundId);
        if ($scope === 'league' && $group !== '') $sql .= $wpdb->prepare(' AND r.group_code=%s ', $group);
        return [$sql, $scope !== 'round'];
    }

    private static function ranking_rows(string $scope, string $league, string $season, int $roundId, string $group): array {
        global $wpdb;
        [$filter,$withAdjustments] = self::ranking_filter($scope,$league,$season,$roundId,$group);
        $pred=DT_DB::table('predictions'); $mat=DT_DB::table('matches'); $rnd=DT_DB::table('rounds');
        $adj=DT_DB::table('point_adjustments'); $users=$wpdb->users;
        $rows = $wpdb->get_results(
            "SELECT u.ID user_id,u.display_name,COUNT(p.id) predictions,COALESCE(SUM(p.points),0) points,
                    SUM(CASE WHEN p.scoring_code='winner' THEN 1 ELSE 0 END) winner_hits
             FROM $users u JOIN $pred p ON p.user_id=u.ID JOIN $mat m ON m.id=p.match_id JOIN $rnd r ON r.id=m.round_id
             WHERE p.selected_team_id IS NOT NULL $filter GROUP BY u.ID,u.display_name",
            ARRAY_A
        );
        if (!is_array($rows)) $rows=[];

        $adjustments=[];
        if ($withAdjustments) {
            $where=' WHERE 1=1 ';
            if ($scope==='league' && $league!=='') $where.=$wpdb->prepare(' AND league_code=%s ',$league);
            if ($scope==='season') {
                $where.=$wpdb->prepare(' AND league_code=%s AND season=%s ',$league,$season);
                if ($group!=='') $where.=$wpdb->prepare(' AND group_code=%s ',$group);
            }
            foreach ((array)$wpdb->get_results("SELECT user_id,COALESCE(SUM(points),0) points FROM $adj $where GROUP BY user_id",ARRAY_A) as $a) {
                $adjustments[(int)$a['user_id']] = (float)$a['points'];
            }
        }

        $perfect=[];
        $perfectSql="SELECT x.user_id,COUNT(*) perfect_rounds FROM (
            SELECT p.user_id,r.id round_id,COUNT(p.id) pred_count,
                   SUM(CASE WHEN p.scoring_code='winner' THEN 1 ELSE 0 END) good_count,
                   (SELECT COUNT(*) FROM $mat mm WHERE mm.round_id=r.id) match_count
            FROM $pred p JOIN $mat m ON m.id=p.match_id JOIN $rnd r ON r.id=m.round_id
            WHERE p.selected_team_id IS NOT NULL $filter
            GROUP BY p.user_id,r.id
            HAVING pred_count=match_count AND good_count=match_count AND match_count>0
        ) x GROUP BY x.user_id";
        foreach ((array)$wpdb->get_results($perfectSql,ARRAY_A) as $p) $perfect[(int)$p['user_id']] = (int)$p['perfect_rounds'];
        $perfectPoints=(float)(DT_DB::settings()['perfect_round_bonus'] ?? 0);

        foreach ($rows as &$row) {
            $uid=(int)$row['user_id'];
            $row['user_id']=$uid; $row['predictions']=(int)$row['predictions']; $row['winner_hits']=(int)$row['winner_hits'];
            $row['perfect_rounds']=(int)($perfect[$uid] ?? 0);
            $row['points']=(float)$row['points']+(float)($adjustments[$uid] ?? 0)+$row['perfect_rounds']*$perfectPoints;
            $row['efficiency']=$row['predictions']>0 ? round(($row['winner_hits']/$row['predictions'])*100,1) : 0.0;
        }
        unset($row);
        if (class_exists('DT_User_Settings')) $rows=DT_User_Settings::apply_ranking_names($rows);
        usort($rows, static function(array $a,array $b): int {
            if ((float)$a['points'] !== (float)$b['points']) return (float)$a['points'] < (float)$b['points'] ? 1 : -1;
            if ((int)$a['winner_hits'] !== (int)$b['winner_hits']) return (int)$b['winner_hits'] <=> (int)$a['winner_hits'];
            return strcasecmp((string)$a['display_name'],(string)$b['display_name']);
        });
        $rank=0;$seen=0;$last=null;
        foreach ($rows as &$row) { $seen++; $key=$row['points'].'|'.$row['winner_hits']; if($key!==$last)$rank=$seen; $row['rank']=$rank; $last=$key; }
        unset($row);
        return array_slice($rows,0,500);
    }

    private static function user_stats(int $uid,string $scope,string $league,string $season,string $group): array {
        $rows=self::ranking_rows($scope,$league,$season,0,$group);
        $found=null;
        foreach ($rows as $row) if ((int)$row['user_id']===$uid) { $found=$row; break; }
        if (!$found) $found=['rank'=>null,'points'=>0,'winner_hits'=>0,'predictions'=>0,'perfect_rounds'=>0,'efficiency'=>0.0];
        global $wpdb;
        $sub=DT_DB::table('round_submissions'); $rnd=DT_DB::table('rounds');
        $where=' WHERE s.user_id=%d '; $args=[$uid];
        if ($scope==='league' && $league!=='') { $where.=' AND r.league_code=%s '; $args[]=$league; }
        if ($scope==='season') { $where.=' AND r.league_code=%s AND r.season=%s '; $args[]=$league; $args[]=$season; if($group!==''){ $where.=' AND r.group_code=%s '; $args[]=$group; } }
        $count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $sub s JOIN $rnd r ON r.id=s.round_id $where",...$args));
        $found['submissions']=$count;
        return $found;
    }

    private static function my_types(int $uid): array {
        global $wpdb;
        $pred=DT_DB::table('predictions'); $mat=DT_DB::table('matches'); $rnd=DT_DB::table('rounds'); $teams=DT_DB::table('teams');
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT r.id round_id,r.league_code,r.group_code,r.season,r.round_no,r.title,
                    m.id match_id,m.starts_at,m.score_home,m.score_away,h.name home_name,a.name away_name,
                    s.name selected_team_name,p.points,p.scoring_code,p.submitted_at
             FROM $pred p JOIN $mat m ON m.id=p.match_id JOIN $rnd r ON r.id=m.round_id
             JOIN $teams h ON h.id=m.home_team_id JOIN $teams a ON a.id=m.away_team_id JOIN $teams s ON s.id=p.selected_team_id
             WHERE p.user_id=%d ORDER BY p.submitted_at DESC,r.id DESC,m.starts_at ASC,m.id ASC",
            $uid
        ),ARRAY_A);
        $groups=[];
        foreach ((array)$rows as $row) {
            $rid=(int)$row['round_id'];
            if (!isset($groups[$rid])) {
                $league=self::clean_league((string)$row['league_code']) ?: '1lm';
                $group=self::clean_group($league,(string)$row['group_code']);
                $groups[$rid]=[
                    'round_id'=>$rid,'league'=>$league,'league_label'=>self::league_label($league,$group),'group'=>$group,
                    'season'=>(string)$row['season'],'round_no'=>(int)$row['round_no'],'title'=>(string)$row['title'],
                    'submitted_at'=>(string)$row['submitted_at'],'matches'=>[],
                ];
            }
            $groups[$rid]['matches'][]=[
                'match_id'=>(int)$row['match_id'],'home_name'=>(string)$row['home_name'],'away_name'=>(string)$row['away_name'],
                'selected_team_name'=>(string)$row['selected_team_name'],'starts_at_iso'=>self::iso_datetime($row['starts_at'] ?? null),
                'score_home'=>$row['score_home']===null?null:(int)$row['score_home'],'score_away'=>$row['score_away']===null?null:(int)$row['score_away'],
                'points'=>(float)$row['points'],'scoring_code'=>(string)$row['scoring_code'],
                'result_known'=>$row['score_home']!==null && $row['score_away']!==null,
            ];
        }
        return array_values($groups);
    }

    private static function iso_datetime(?string $value): ?string {
        if (!$value) return null;
        $tz=wp_timezone(); $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,$tz);
        return $d ? $d->format(DATE_ATOM) : null;
    }

    public static function frontend_assets(): void {
        if (!class_exists('DT_Frontend') || !DT_Frontend::is_typer_page()) return;
        $mode=self::site_mode();
        wp_enqueue_style('dt-multileague',DT_URL.'assets/css/multileague.css',['dt-front'],DT_VERSION);
        if ($mode==='break' && !current_user_can('manage_options')) return;
        if (!is_user_logged_in()) return;
        wp_dequeue_script('dt-ranking-view');
        wp_dequeue_script('dt-my-coupons');
        wp_enqueue_script('dt-multileague',DT_URL.'assets/js/multileague.js',['dt-front'],DT_VERSION,true);
        wp_localize_script('dt-multileague','TypujKoszaMultiLeague',[
            'root'=>esc_url_raw(rest_url('decka-typer/v1/')),
            'nonce'=>wp_create_nonce('wp_rest'),
            'mode'=>$mode,
            'home'=>class_exists('DT_Canonical')?DT_Canonical::URL:home_url('/'),
            'favoriteTeamId'=>class_exists('DT_User_Settings')?DT_User_Settings::favorite_team_id(get_current_user_id()):0,
        ]);
    }

    public static function break_template(string $template): string {
        if (!class_exists('DT_Frontend') || !DT_Frontend::is_typer_page()) return $template;
        if (self::site_mode()!=='break' || current_user_can('manage_options')) return $template;
        $file=DT_DIR.'templates/season-break.php';
        return is_readable($file)?$file:$template;
    }

    public static function block_public_auth_during_break(): void {
        if (self::site_mode()!=='break' || current_user_can('manage_options')) return;
        wp_safe_redirect(class_exists('DT_Canonical')?DT_Canonical::URL:home_url('/'));
        exit;
    }

    public static function save_site_mode_from_settings($value,$oldValue,string $option) {
        if (!is_admin() || !current_user_can('manage_options')) return $value;
        if (sanitize_key((string)($_POST['action'] ?? ''))!=='dt_save_settings') return $value;
        $mode=sanitize_key((string)($_POST['site_mode'] ?? ($value['site_mode'] ?? 'production')));
        if (!in_array($mode,['production','test','break'],true)) $mode='production';
        if (is_array($value)) $value['site_mode']=$mode;
        return $value;
    }

    public static function admin_menu(): void {
        if (!current_user_can('manage_options')) return;
        remove_submenu_page('decka-typer','decka-typer-rounds');
        add_submenu_page('decka-typer','Kolejki i ligi','Kolejki i ligi','manage_options','decka-typer-rounds',[__CLASS__,'admin_rounds']);
        add_submenu_page('decka-typer','Ligi i źródła','Ligi i źródła','manage_options','decka-typer-leagues',[__CLASS__,'admin_leagues']);
    }

    public static function admin_assets(string $hook): void {
        if (strpos($hook,'decka-typer')===false) return;
        wp_enqueue_style('dt-multileague-admin',DT_URL.'assets/css/multileague-admin.css',['dt-admin'],DT_VERSION);
        wp_enqueue_script('dt-multileague-admin',DT_URL.'assets/js/multileague-admin.js',['dt-admin'],DT_VERSION,true);
        wp_localize_script('dt-multileague-admin','TypujKoszaMultiLeagueAdmin',[
            'mode'=>self::site_mode(),'leagues'=>array_values(self::definitions()),
        ]);
    }

    private static function admin_guard(string $nonce): void {
        if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
        check_admin_referer($nonce);
    }

    public static function admin_add_round(): void {
        self::admin_guard('dt_ml_add_round');
        global $wpdb;
        $league=self::clean_league((string)($_POST['league_code'] ?? ''));
        $group=self::clean_group($league,(string)($_POST['group_code'] ?? ''));
        $season=sanitize_text_field((string)($_POST['season'] ?? ''));
        $roundNo=max(1,(int)($_POST['round_no'] ?? 0));
        if (!$league || !$season) wp_die('Nieprawidłowa liga lub sezon.');
        $title=sanitize_text_field((string)($_POST['title'] ?? '')) ?: $roundNo.'. kolejka';
        $now=current_time('mysql');
        $ok=$wpdb->insert(DT_DB::table('rounds'),[
            'league_code'=>$league,'group_code'=>$group,'season'=>$season,'round_no'=>$roundNo,'title'=>$title,
            'status'=>'draft','source'=>'manual','external_key'=>sha1($league.'|'.$group.'|'.$season.'|manual|'.$roundNo),
            'created_at'=>$now,'updated_at'=>$now,
        ]);
        $url=add_query_arg(['page'=>'decka-typer-rounds','league'=>$league,'dt_notice'=>$ok?'Dodano kolejkę jako szkic.':'Nie udało się dodać kolejki.','dt_type'=>$ok?'success':'error'],admin_url('admin.php'));
        wp_safe_redirect($url); exit;
    }

    public static function admin_save_leagues(): void {
        self::admin_guard('dt_ml_save_leagues');
        $defs=self::definitions(); $old=self::league_config(); $new=$old;
        foreach ($defs as $code=>$def) {
            $new[$code]['enabled']=!empty($_POST['enabled'][$code])?1:0;
            $new[$code]['source_url']=esc_url_raw((string)($_POST['source_url'][$code] ?? ''));
            foreach ($def['groups'] as $group) {
                $new[$code]['groups'][$group]['enabled']=!empty($_POST['group_enabled'][$code][$group])?1:0;
                $new[$code]['groups'][$group]['source_url']=esc_url_raw((string)($_POST['group_source_url'][$code][$group] ?? ''));
            }
        }
        update_option(self::LEAGUES_OPTION,$new,false);
        wp_safe_redirect(add_query_arg(['page'=>'decka-typer-leagues','dt_notice'=>'Ustawienia lig zapisane.','dt_type'=>'success'],admin_url('admin.php'))); exit;
    }

    private static function admin_notice(): void {
        if (empty($_GET['dt_notice'])) return;
        $type=sanitize_key((string)($_GET['dt_type'] ?? 'success')); $msg=sanitize_text_field(wp_unslash((string)$_GET['dt_notice']));
        echo '<div class="notice notice-'.($type==='error'?'error':'success').' is-dismissible"><p>'.esc_html($msg).'</p></div>';
    }

    public static function admin_rounds(): void {
        global $wpdb;
        $league=self::clean_league((string)($_GET['league'] ?? ''));
        $group=self::clean_group($league,(string)($_GET['group'] ?? ''));
        $season=sanitize_text_field((string)($_GET['season'] ?? (DT_DB::settings()['season'] ?? '2026/2027')));
        $where=' WHERE r.season=%s '; $args=[$season];
        if ($league!=='') { $where.=' AND r.league_code=%s '; $args[]=$league; }
        if ($group!=='') { $where.=' AND r.group_code=%s '; $args[]=$group; }
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT r.*,COUNT(m.id) matches,MIN(m.starts_at) first_match,MAX(m.starts_at) last_match,
                    (SELECT COUNT(*) FROM ".DT_DB::table('round_submissions')." s WHERE s.round_id=r.id) submissions
             FROM ".DT_DB::table('rounds')." r LEFT JOIN ".DT_DB::table('matches')." m ON m.round_id=r.id
             $where GROUP BY r.id ORDER BY FIELD(r.league_code,'plk','1lm','2lm'),r.group_code,r.round_no,r.id",
            ...$args
        ));
        echo '<div class="wrap dt-admin tk-ml-admin"><h1>Kolejki i ligi</h1><p class="description">Jedno miejsce do obsługi PLK, 1LM oraz 2LM z grupami A–D.</p>'; self::admin_notice();
        echo '<div class="tk-ml-toolbar"><form method="get"><input type="hidden" name="page" value="decka-typer-rounds"><select name="league"><option value="">Wszystkie ligi</option>';
        foreach(self::definitions() as $code=>$def) echo '<option value="'.esc_attr($code).'" '.selected($league,$code,false).'>'.esc_html($def['name']).'</option>';
        echo '</select><select name="group"><option value="">Wszystkie grupy</option>'; foreach(['A','B','C','D'] as $g) echo '<option value="'.$g.'" '.selected($group,$g,false).'>2LM · grupa '.$g.'</option>'; echo '</select><input name="season" value="'.esc_attr($season).'" class="regular-text"><button class="button">Filtruj</button></form><button class="button button-primary" type="button" data-ml-open-add>Dodaj kolejkę</button></div>';
        echo '<div class="tk-ml-league-cards">'; foreach(self::definitions() as $code=>$def){ $count=0; foreach((array)$rows as $r) if($r->league_code===$code)$count++; echo '<div><strong>'.esc_html($def['name']).'</strong><span>'.(int)$count.' kolejek w widoku</span></div>'; } echo '</div>';
        echo '<section class="dt-card"><table class="widefat dt-table"><thead><tr><th>Liga</th><th>Kolejka</th><th>Sezon</th><th>Mecze</th><th>Typowania</th><th>Status</th><th>Zamknięcie</th><th>Akcje</th></tr></thead><tbody>';
        foreach((array)$rows as $r){ $lbl=self::league_label((string)$r->league_code,(string)$r->group_code); echo '<tr><td><span class="tk-league-badge is-'.esc_attr($r->league_code).'">'.esc_html($lbl).'</span></td><td><strong>'.esc_html($r->title).'</strong><small>#'.(int)$r->round_no.'</small></td><td>'.esc_html($r->season).'</td><td>'.(int)$r->matches.'</td><td>'.(int)$r->submissions.'</td><td><span class="tk-status is-'.esc_attr($r->status).'">'.esc_html($r->status==='open'?'OTWARTA':($r->status==='closed'?'ZAMKNIĘTA':'SZKIC')).'</span></td><td>'.esc_html($r->closes_at?:'—').'</td><td><a class="button" href="'.esc_url(add_query_arg(['page'=>'decka-typer-matches','round_id'=>(int)$r->id],admin_url('admin.php'))).'">Mecze</a> ';
            if($r->status==='open'){ echo '<form class="tk-inline" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dt_close_round"><input type="hidden" name="round_id" value="'.(int)$r->id.'">'; wp_nonce_field('dt_close_round'); echo '<button class="button">Zamknij</button></form>'; }
            else { echo '<form class="tk-inline tk-open-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dt_open_round"><input type="hidden" name="round_id" value="'.(int)$r->id.'">'; wp_nonce_field('dt_open_round'); echo '<input type="datetime-local" name="closes_at" required><button class="button">Otwórz</button></form>'; }
            echo '</td></tr>'; }
        if(!$rows) echo '<tr><td colspan="8" class="dt-empty">Brak kolejek dla wybranych filtrów.</td></tr>';
        echo '</tbody></table></section>';
        echo '<dialog id="tk-ml-add-round" class="dt-modal"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><button type="button" class="dt-modal-x" data-ml-close>×</button><span class="dt-eyebrow">NOWA KOLEJKA</span><h2>Dodaj kolejkę</h2><input type="hidden" name="action" value="dt_ml_add_round">'; wp_nonce_field('dt_ml_add_round');
        echo '<label>Liga<select name="league_code" id="tk-ml-league" required>'; foreach(self::definitions() as $code=>$def) echo '<option value="'.esc_attr($code).'">'.esc_html($def['name']).'</option>'; echo '</select></label><label>Grupa 2LM<select name="group_code" id="tk-ml-group"><option value="">—</option>'; foreach(['A','B','C','D'] as $g)echo '<option value="'.$g.'">Grupa '.$g.'</option>'; echo '</select></label><label>Sezon<input name="season" value="'.esc_attr($season).'" required></label><label>Numer kolejki<input type="number" name="round_no" min="1" max="99" required></label><label>Nazwa<input name="title" placeholder="np. 1. kolejka"></label><p class="description">Kolejka powstaje jako szkic. Mecze dodaj w module „Mecze”, a potem otwórz typowanie.</p><button class="button button-primary">Dodaj kolejkę</button></form></dialog></div>';
    }

    public static function admin_leagues(): void {
        $config=self::league_config();
        echo '<div class="wrap dt-admin tk-ml-admin"><h1>Ligi i źródła</h1><p class="description">Konfiguracja rozgrywek przygotowanych do obsługi w TypujKosza.pl.</p>'; self::admin_notice();
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="dt_ml_save_leagues">'; wp_nonce_field('dt_ml_save_leagues');
        echo '<div class="tk-league-settings">'; foreach(self::definitions() as $code=>$def){ echo '<section class="dt-card"><div class="tk-league-head"><div><span class="tk-league-badge is-'.esc_attr($code).'">'.esc_html($def['name']).'</span><h2>'.esc_html($def['full_name']).'</h2></div><label class="tk-switch"><input type="checkbox" name="enabled['.esc_attr($code).']" value="1" '.checked(!empty($config[$code]['enabled']),true,false).'><span>Aktywna</span></label></div><label>Adres źródła terminarza / wyników<input type="url" name="source_url['.esc_attr($code).']" value="'.esc_attr((string)($config[$code]['source_url'] ?? '')).'" placeholder="https://..."></label>';
            if($code==='1lm') echo '<p class="description">Synchronizacja 1LM korzysta z istniejącego modułu automatycznego importu.</p>';
            if($code==='plk') echo '<p class="description">Struktura PLK jest gotowa. Do czasu podpięcia parsera możesz dodawać kolejki i mecze ręcznie.</p>';
            if($code==='2lm'){ echo '<h3>Grupy 2LM</h3><div class="tk-group-grid">'; foreach(['A','B','C','D'] as $g){ echo '<div><label class="tk-switch"><input type="checkbox" name="group_enabled[2lm]['.$g.']" value="1" '.checked(!empty($config['2lm']['groups'][$g]['enabled']),true,false).'><span>Grupa '.$g.'</span></label><input type="url" name="group_source_url[2lm]['.$g.']" value="'.esc_attr((string)($config['2lm']['groups'][$g]['source_url'] ?? '')).'" placeholder="Adres źródła grupy '.$g.'"></div>'; } echo '</div>'; }
            echo '</section>'; }
        echo '</div><p><button class="button button-primary">Zapisz konfigurację lig</button></p></form></div>';
    }
}
