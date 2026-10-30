<?php
if (!defined('ABSPATH')) exit;

/** Special preseason predictions kept separate from ordinary match coupons. */
class DT_Preseason {
    private const TYPES = ['pre1','pre2'];
    private const BRACKETS = ['1-4','5-8','9-12','13-16'];

    public static function register(): void {
        add_action('rest_api_init', [__CLASS__, 'routes'], 25);
        add_action('wp_enqueue_scripts', [__CLASS__, 'assets'], 36);
    }

    public static function routes(): void {
        register_rest_route('decka-typer/v1', '/preseason', [
            ['methods'=>'GET','callback'=>[__CLASS__,'get'],'permission_callback'=>static fn()=>is_user_logged_in()],
            ['methods'=>'POST','callback'=>[__CLASS__,'save'],'permission_callback'=>static fn()=>is_user_logged_in()],
        ]);
    }

    public static function assets(): void {
        if (!is_user_logged_in() || !class_exists('DT_Frontend') || !DT_Frontend::is_typer_page()) return;
        wp_enqueue_style('dt-preseason', DT_URL.'assets/css/preseason.css', ['dt-front'], DT_VERSION);
        wp_enqueue_style('dt-preseason-collapse', DT_URL.'assets/css/preseason-collapse.css', ['dt-preseason'], DT_VERSION);
        wp_enqueue_script('dt-preseason', DT_URL.'assets/js/preseason.js', ['dt-front'], DT_VERSION, true);
    }

    public static function get(WP_REST_Request $request): WP_REST_Response {
        return new WP_REST_Response(self::payload(get_current_user_id()));
    }

    public static function save(WP_REST_Request $request): WP_REST_Response|WP_Error {
        if (!self::is_open()) return new WP_Error('preseason_closed','Typowania PRE - FinalRanking i PRE - PlayOFF są już zamknięte.',['status'=>409]);
        $body = $request->get_json_params();
        if (!is_array($body)) $body=[];
        $league = sanitize_key((string)($body['league_key']??''));
        $group = strtoupper(sanitize_text_field((string)($body['group_key']??'')));
        $type = sanitize_key((string)($body['prediction_type']??''));
        if (!in_array($league,['1lm','plk','2lm'],true) || !in_array($type,self::TYPES,true)) {
            return new WP_Error('invalid_preseason_scope','Nieprawidłowa liga lub rodzaj typowania.',['status'=>422]);
        }
        if ($league!=='2lm') $group='';
        $teams=self::teams($league,$group);
        if (!$teams) return new WP_Error('preseason_no_teams','Brak drużyn dla wybranej ligi lub grupy.',['status'=>422]);
        $allowed=array_fill_keys(array_map(static fn($t)=>(int)$t['id'],$teams),true);
        $raw=is_array($body['selections']??null)?$body['selections']:[];
        $clean=[];
        if ($type==='pre1') {
            $counts=array_fill_keys(self::BRACKETS,0);
            foreach ($raw as $teamId=>$bracket) {
                $teamId=(int)$teamId;$bracket=(string)$bracket;
                if (!isset($allowed[$teamId]) || !in_array($bracket,self::BRACKETS,true)) continue;
                $clean[(string)$teamId]=$bracket;$counts[$bracket]++;
            }
            if (count($clean)!==count($teams)) return new WP_Error('pre1_incomplete','W PRE - FinalRanking wybierz przedział miejsca dla każdej drużyny.',['status'=>422]);
            if (max($counts)>4) return new WP_Error('pre1_limit','W jednym przedziale mogą znaleźć się maksymalnie cztery drużyny.',['status'=>422]);
        } else {
            foreach ($raw as $teamId) { $teamId=(int)$teamId;if(isset($allowed[$teamId]))$clean[]=$teamId; }
            $clean=array_values(array_unique($clean));
            if (!$clean || count($clean)>8) return new WP_Error('pre2_limit','W PRE - PlayOFF wybierz od jednej do maksymalnie ośmiu drużyn.',['status'=>422]);
        }

        global $wpdb;
        $table=DT_DB::table('preseason_predictions');
        $uid=get_current_user_id();$season=(string)(DT_DB::settings()['season']??'');
        $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE user_id=%d AND season=%s AND league_key=%s AND group_key=%s AND prediction_type=%s",$uid,$season,$league,$group,$type));
        if ($exists) return new WP_Error('preseason_submitted','To typowanie zostało już zapisane i nie można go edytować.',['status'=>409]);
        $ok=$wpdb->insert($table,['user_id'=>$uid,'season'=>$season,'league_key'=>$league,'group_key'=>$group,'prediction_type'=>$type,'selections'=>wp_json_encode($clean,JSON_UNESCAPED_UNICODE),'points'=>0,'submitted_at'=>current_time('mysql')],['%d','%s','%s','%s','%s','%s','%f','%s']);
        if (!$ok) return new WP_Error('preseason_save_failed','Nie udało się zapisać typowania. Spróbuj ponownie.',['status'=>500]);
        DT_Logger::log('preseason_prediction_saved','Użytkownik zapisał typowanie '.$type.'.',['league'=>$league,'group'=>$group,'type'=>$type],'notice',$uid);
        return new WP_REST_Response(['ok'=>true,'message'=>'Typowanie '.strtoupper($type).' zostało zapisane.','data'=>self::payload($uid)]);
    }

    private static function payload(int $uid): array {
        global $wpdb;
        $season=(string)(DT_DB::settings()['season']??'');
        $catalog=[];
        foreach (['1lm','plk','2lm'] as $league) {
            if ($league==='2lm') {
                foreach (self::groups() as $group) $catalog[]=['league_key'=>$league,'group_key'=>$group,'teams'=>self::teams($league,$group)];
            } else $catalog[]=['league_key'=>$league,'group_key'=>'','teams'=>self::teams($league,'')];
        }
        $rows=$wpdb->get_results($wpdb->prepare('SELECT league_key,group_key,prediction_type,selections,points,submitted_at FROM '.DT_DB::table('preseason_predictions').' WHERE user_id=%d AND season=%s',$uid,$season),ARRAY_A);
        $submissions=[];
        foreach((array)$rows as $row){$key=$row['league_key'].'|'.$row['group_key'].'|'.$row['prediction_type'];$submissions[$key]=['selections'=>json_decode((string)$row['selections'],true)?:[],'points'=>(float)$row['points'],'submitted_at'=>$row['submitted_at']];}
        $settings=DT_DB::settings();
        return ['season'=>$season,'deadline'=>self::deadline()->format(DateTimeInterface::ATOM),'is_open'=>self::is_open(),'brackets'=>self::BRACKETS,'catalog'=>$catalog,'submissions'=>$submissions,'scoring'=>[
            'pre1_hit'=>(float)($settings['pre1_hit_points']??1),'pre1_perfect'=>(float)($settings['pre1_perfect_bonus']??0),
            'pre2_hit'=>(float)($settings['pre2_hit_points']??1),'pre2_perfect'=>(float)($settings['pre2_perfect_bonus']??0),
        ]];
    }

    private static function groups(): array {
        global $wpdb;$season=(string)(DT_DB::settings()['season']??'');
        return array_values(array_filter(array_map(static fn($v)=>strtoupper(trim((string)$v)),(array)$wpdb->get_col($wpdb->prepare("SELECT DISTINCT group_key FROM ".DT_DB::table('rounds')." WHERE season=%s AND league_key='2lm' AND group_key<>'' ORDER BY group_key",$season)))));
    }

    private static function teams(string $league,string $group): array {
        global $wpdb;$season=(string)(DT_DB::settings()['season']??'');
        $groupSql=$league==='2lm'?$wpdb->prepare(' AND r.group_key=%s',$group):'';
        $sql=$wpdb->prepare("SELECT DISTINCT t.id,t.name,t.logo_url FROM ".DT_DB::table('teams')." t JOIN (SELECT home_team_id team_id,round_id FROM ".DT_DB::table('matches')." UNION SELECT away_team_id team_id,round_id FROM ".DT_DB::table('matches').") x ON x.team_id=t.id JOIN ".DT_DB::table('rounds')." r ON r.id=x.round_id WHERE r.season=%s AND r.league_key=%s $groupSql ORDER BY t.name",$season,$league);
        $rows=$wpdb->get_results($sql,ARRAY_A);
        $positions=self::current_positions($league,$group,$season,(array)$rows);
        foreach((array)$rows as &$row){$row['id']=(int)$row['id'];$row['current_position']=$positions[(int)$row['id']]??null;}unset($row);
        return is_array($rows)?$rows:[];
    }

    private static function current_positions(string $league,string $group,string $season,array $catalogTeams=[]): array {
        global $wpdb;
        $teams=DT_DB::table('teams');$matches=DT_DB::table('matches');$rounds=DT_DB::table('rounds');
        $groupSql=$league==='2lm'?$wpdb->prepare(' AND r.group_key=%s',$group):'';
        $rows=$wpdb->get_results($wpdb->prepare("SELECT m.home_team_id,m.away_team_id,m.score_home,m.score_away,ht.name home_name,at.name away_name FROM $matches m JOIN $rounds r ON r.id=m.round_id JOIN $teams ht ON ht.id=m.home_team_id JOIN $teams at ON at.id=m.away_team_id WHERE r.season=%s AND r.league_key=%s $groupSql AND m.score_home IS NOT NULL AND m.score_away IS NOT NULL",$season,$league),ARRAY_A);
        $table=[];
        foreach((array)$rows as $match){
            $home=(int)$match['home_team_id'];$away=(int)$match['away_team_id'];
            foreach([$home,$away] as $id)if(!isset($table[$id]))$table[$id]=['wins'=>0,'losses'=>0,'for'=>0,'against'=>0,'name'=>''];
            $hs=(int)$match['score_home'];$as=(int)$match['score_away'];
            $table[$home]['for']+=$hs;$table[$home]['against']+=$as;$table[$home]['name']=(string)$match['home_name'];
            $table[$away]['for']+=$as;$table[$away]['against']+=$hs;$table[$away]['name']=(string)$match['away_name'];
            if($hs>$as){$table[$home]['wins']++;$table[$away]['losses']++;}elseif($as>$hs){$table[$away]['wins']++;$table[$home]['losses']++;}
        }
        uasort($table,static fn(array $a,array $b): int=>$b['wins']<=>$a['wins']?:($b['for']-$b['against'])<=>($a['for']-$a['against'])?:$b['for']<=>$a['for']?:strcasecmp($a['name'],$b['name']));
        $positions=[];$place=0;foreach(array_keys($table) as $id)$positions[(int)$id]=++$place;
        $cache=(array)get_option('dt_1lm_standings_cache',[]);$byLeague=is_array($cache['positions_by_league']??null)?$cache['positions_by_league']:[];$official=is_array($byLeague[$league]??null)?$byLeague[$league]:[];
        if(!$official)return $positions;
        $clubKey=static function(string $name): string {
            $normalized=strtolower(remove_accents(wp_strip_all_tags($name)));
            $normalized=trim((string)preg_replace('/\s+/',' ',(string)preg_replace('/[^a-z0-9]+/',' ',$normalized)));
            $known=['polonia 1912 leszno','polonia leszno','wkk','politechnika opolska','sokol lancut','starogard gdanski','spojnia stargard','resovia rzeszow','miasto szkla krosno','lks lodz','lks coolpack lodz','notec inowroclaw','polonia warszawa','gks tychy','basket poznan','decka pelplin','kotwica','polonia bytom'];
            foreach($known as $needle)if(str_contains($normalized,$needle)){if($needle==='polonia leszno')return 'polonia 1912 leszno';if($needle==='lks coolpack lodz')return 'lks lodz';return $needle;}
            return $normalized;
        };
        foreach($catalogTeams as $team){
            $key=$clubKey((string)($team['name']??''));$matched=$official[$key]??null;
            if(!$matched)foreach($official as $officialKey=>$entry){
                $officialKey=(string)$officialKey;
                if($key!==''&&($officialKey===$key||str_contains($key,$officialKey)||str_contains($officialKey,$key))){$matched=$entry;break;}
            }
            if(isset($matched['position']))$positions[(int)$team['id']]=(int)$matched['position'];
        }
        return $positions;
    }

    private static function deadline(): DateTimeImmutable {
        $season=(string)(DT_DB::settings()['season']??'');
        preg_match('/(20\d{2})/',$season,$m);$year=(int)($m[1]??wp_date('Y'));
        return new DateTimeImmutable(sprintf('%04d-10-15 23:59:59',$year),wp_timezone());
    }

    private static function is_open(): bool { return new DateTimeImmutable('now',wp_timezone())<=self::deadline(); }
}
