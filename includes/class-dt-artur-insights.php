<?php
if (!defined('ABSPATH')) exit;

/** Lazy-loaded match notes for the administrator's "Kolejka z Arturem" screen. */
class DT_Artur_Insights {
    public static function ajax(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'],403);
        check_ajax_referer('dt_artur_insights','nonce');
        $matchId=absint($_POST['match_id']??0);$mode=sanitize_key($_POST['mode']??'');
        if(!$matchId || !in_array($mode,['summary','preview'],true))wp_send_json_error(['message'=>'Nieprawidłowe spotkanie.'],400);
        wp_send_json_success(['html'=>self::cached_html($matchId,$mode)]);
    }

    private static function cached_html(int $matchId,string $mode): string {
        $key='dt_artur_insights_'.DT_VERSION.'_'.$mode.'_'.$matchId;$html=get_transient($key);
        if(!is_string($html)){$html=$mode==='summary'?self::summary($matchId):self::preview($matchId);set_transient($key,$html,$mode==='summary'?DAY_IN_SECONDS:6*HOUR_IN_SECONDS);}
        return $html;
    }

    public static function ajax_warm_start(): void {
        if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Brak uprawnień.'],403);
        check_ajax_referer('dt_artur_insights','nonce');
        $decoded=json_decode(wp_unslash((string)($_POST['items']??'')),true);$items=[];
        foreach(is_array($decoded)?$decoded:[] as $item){$id=absint($item['match_id']??0);$mode=sanitize_key($item['mode']??'');if($id&&in_array($mode,['summary','preview'],true))$items[$mode.'-'.$id]=['match_id'=>$id,'mode'=>$mode];if(count($items)>=40)break;}
        if(!$items)wp_send_json_error(['message'=>'Nie znaleziono meczów do przygotowania.'],400);
        $jobId=wp_generate_uuid4();$job=['items'=>array_values($items),'total'=>count($items),'done'=>0,'errors'=>0,'state'=>'queued','updated'=>time()];
        set_transient('dt_artur_warm_'.$jobId,$job,2*HOUR_IN_SECONDS);
        wp_schedule_single_event(time()+1,'dt_artur_warm_insights',[$jobId]);spawn_cron();
        wp_send_json_success(['job_id'=>$jobId,'total'=>$job['total']]);
    }

    public static function ajax_warm_status(): void {
        if(!current_user_can('manage_options'))wp_send_json_error(['message'=>'Brak uprawnień.'],403);
        check_ajax_referer('dt_artur_insights','nonce');$jobId=sanitize_text_field(wp_unslash((string)($_POST['job_id']??'')));$job=get_transient('dt_artur_warm_'.$jobId);
        if(!is_array($job))wp_send_json_error(['message'=>'Proces wygasł albo nie istnieje.'],404);
        wp_send_json_success(['total'=>(int)$job['total'],'done'=>(int)$job['done'],'errors'=>(int)$job['errors'],'state'=>(string)$job['state']]);
    }

    public static function process_warm_job(string $jobId): void {
        $key='dt_artur_warm_'.sanitize_text_field($jobId);$job=get_transient($key);if(!is_array($job)||empty($job['items']))return;
        $job['state']='running';$item=array_shift($job['items']);
        try{self::cached_html((int)$item['match_id'],(string)$item['mode']);}catch(Throwable $e){$job['errors']=(int)$job['errors']+1;}
        $job['done']=(int)$job['done']+1;$job['updated']=time();$job['state']=$job['items']?'running':'complete';set_transient($key,$job,2*HOUR_IN_SECONDS);
        if($job['items'])wp_schedule_single_event(time()+1,'dt_artur_warm_insights',[$jobId]);
    }

    private static function match(int $id): ?array {
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare("SELECT m.*,r.season,r.league_key,r.group_key,h.name home_name,a.name away_name FROM ".DT_DB::table('matches')." m JOIN ".DT_DB::table('rounds')." r ON r.id=m.round_id JOIN ".DT_DB::table('teams')." h ON h.id=m.home_team_id JOIN ".DT_DB::table('teams')." a ON a.id=m.away_team_id WHERE m.id=%d",$id),ARRAY_A);
        return is_array($row)?$row:null;
    }

    private static function fetch_stats(array $match): array {
        $url=esc_url_raw((string)($match['source_url']??''));
        if(!$url)return [];
        $cacheKey='dt_artur_box_'.DT_VERSION.'_'.(int)($match['id']??0).'_'.substr(md5($url),0,10);
        $cached=get_transient($cacheKey);if(is_array($cached))return $cached;
        $response=wp_remote_get($url,['timeout'=>18,'redirection'=>4,'headers'=>['User-Agent'=>'TypujKosza.pl/'.DT_VERSION]]);
        if(is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200)return [];
        $html=(string)wp_remote_retrieve_body($response);if($html==='')return [];
        $stats=['home'=>DT_Decka_Stats::parse_pzkosz_game($html,true),'away'=>DT_Decka_Stats::parse_pzkosz_game($html,false)];
        if($stats['home'] || $stats['away'])set_transient($cacheKey,$stats,7*DAY_IN_SECONDS);
        return $stats;
    }

    private static function pct(array $shot): string {
        $made=(int)($shot['made']??0);$attempted=(int)($shot['attempted']??0);
        return $attempted?sprintf('%d/%d · %.1f%%',$made,$attempted,100*$made/$attempted):'0/0 · 0%';
    }

    private static function summary(int $matchId): string {
        $m=self::match($matchId);if(!$m)return self::empty('Nie znaleziono meczu.');
        $stats=self::fetch_stats($m);$home=$stats['home']??null;$away=$stats['away']??null;
        ob_start();
        echo '<div class="dt-artur-detail-head"><strong>Statystyki meczu</strong><span>Dane z oficjalnego protokołu PZKosz</span></div>';
        if(!$home || !$away){echo self::empty('Oficjalny protokół nie zawiera jeszcze pełnych statystyk. Wynik i typ pozostają dostępne powyżej.');return (string)ob_get_clean();}
        self::comparison((string)$m['home_name'],(string)$m['away_name'],$home['team']??[],$away['team']??[]);
        echo '<div class="dt-artur-leader-columns">';self::leaders((string)$m['home_name'],$home['leaders']??[]);self::leaders((string)$m['away_name'],$away['leaders']??[]);echo '</div>';
        self::facts($m,$home,$away);
        return (string)ob_get_clean();
    }

    private static function comparison(string $homeName,string $awayName,array $h,array $a): void {
        $rows=['Punkty'=>['points','num'],'Zbiórki'=>['reb','num'],'Asysty'=>['ast','num'],'Przechwyty'=>['steals','num'],'Bloki'=>['blocks','num'],'Straty'=>['turnovers','num'],'Z gry'=>['field','shot'],'Za 2'=>['two','shot'],'Za 3'=>['three','shot'],'Za 1'=>['free','shot'],'EVAL'=>['eval','num']];
        echo '<div class="dt-artur-team-table"><div class="is-head"><b>'.esc_html($homeName).'</b><span>STATYSTYKA</span><b>'.esc_html($awayName).'</b></div>';
        foreach($rows as $label=>[$key,$type]){$hv=$type==='shot'?self::pct((array)($h[$key]??[])):(string)($h[$key]??'—');$av=$type==='shot'?self::pct((array)($a[$key]??[])):(string)($a[$key]??'—');echo '<div><strong>'.esc_html($hv).'</strong><span>'.esc_html($label).'</span><strong>'.esc_html($av).'</strong></div>';}
        echo '</div>';
    }

    private static function leaders(string $team,array $players,string $suffix=''): void {
        echo '<section class="dt-artur-leaders"><h4>TOP 3 · '.esc_html($team).'</h4>'.($suffix?'<small>'.esc_html($suffix).'</small>':'').'<ol>';
        if(!$players)echo '<li class="is-empty">Brak danych zawodników</li>';
        foreach(array_slice($players,0,3) as $p){echo '<li><b>'.esc_html((string)($p['name']??'—')).'</b><span>'.esc_html(self::player_line($p)).'</span></li>';}
        echo '</ol></section>';
    }

    private static function player_line(array $p): string {
        $prefix=isset($p['games'])?'Śr. '.number_format_i18n((float)($p['points']??0),1).' pkt · '.number_format_i18n((float)($p['reb']??0),1).' zb · '.number_format_i18n((float)($p['ast']??0),1).' as':'Pkt '.(int)($p['points']??0).' · Zb '.(int)($p['reb']??0).' · As '.(int)($p['ast']??0).' · EVAL '.(int)($p['eval']??0);
        return $prefix.' · 2P '.self::pct((array)($p['two']??[])).' · 3P '.self::pct((array)($p['three']??[])).' · 1P '.self::pct((array)($p['free']??[]));
    }

    private static function facts(array $m,array $home,array $away): void {
        $facts=[];$hs=(array)($home['team']??[]);$as=(array)($away['team']??[]);
        $diffs=['zbiórkach'=>['reb',false],'asystach'=>['ast',false],'przechwytach'=>['steals',false],'punktach z ławki'=>['bench',true],'punktach z pomalowanego'=>['paint_points',true],'szybkim ataku'=>['fast_break',true]];
        foreach($diffs as $label=>[$key,$advanced]){$ha=$advanced?(int)($home['advanced'][$key]??0):(int)($hs[$key]??0);$aa=$advanced?(int)($away['advanced'][$key]??0):(int)($as[$key]??0);if(abs($ha-$aa)>=($advanced?5:3))$facts[]=(($ha>$aa)?$m['home_name']:$m['away_name']).' wygrał(a) rywalizację w '.$label.' '.$ha.':'.$aa.'.';}
        usort($facts,static fn(string $a,string $b): int=>strlen($b)<=>strlen($a));
        echo '<section class="dt-artur-facts"><h4>Ciekawostki do programu</h4><ul>';
        foreach(array_slice($facts,0,4) as $fact)echo '<li>'.esc_html($fact).'</li>';
        if(!$facts)echo '<li>W oficjalnym protokole nie ma dodatkowych przewag spełniających próg wyróżnienia.</li>';
        echo '</ul></section>';
    }

    private static function preview(int $matchId): string {
        $m=self::match($matchId);if(!$m)return self::empty('Nie znaleziono meczu.');
        $before=(string)$m['starts_at'];
        ob_start();echo '<div class="dt-artur-detail-head"><strong>Zapowiedź spotkania</strong><span>Forma, terminarz i średnie z całego dostępnego sezonu</span></div>';
        echo '<div class="dt-artur-form-columns">';
        foreach(['home','away'] as $side){$teamId=(int)$m[$side.'_team_id'];$name=(string)$m[$side.'_name'];$recent=self::recent($teamId,$before,$matchId);$next=self::upcoming($teamId,$before,$matchId,$m);$record=self::team_record($teamId,$m);[$leaders,$games]=self::season_leaders($teamId,$m);echo '<div class="dt-artur-team-column"><h3>'.esc_html($name).' <small>('.esc_html($record).')</small></h3>';self::form($name,$teamId,$recent);self::next_games($name,$teamId,$next);self::leaders($name,$leaders,$games?'Średnie sezonowe z '.$games.' dostępnych protokołów':'Brak protokołów z bieżącego sezonu');echo '</div>';}
        echo '</div>';return (string)ob_get_clean();
    }

    private static function recent(int $teamId,string $before,int $exclude): array {
        global $wpdb;return (array)$wpdb->get_results($wpdb->prepare("SELECT m.*,h.name home_name,a.name away_name FROM ".DT_DB::table('matches')." m JOIN ".DT_DB::table('teams')." h ON h.id=m.home_team_id JOIN ".DT_DB::table('teams')." a ON a.id=m.away_team_id WHERE m.id<>%d AND m.starts_at<%s AND m.score_home IS NOT NULL AND m.score_away IS NOT NULL AND (m.home_team_id=%d OR m.away_team_id=%d) ORDER BY m.starts_at DESC LIMIT 3",$exclude,$before,$teamId,$teamId),ARRAY_A);
    }

    private static function team_record(int $teamId,array $context): string {
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare("SELECT SUM(CASE WHEN (m.home_team_id=%d AND m.score_home>m.score_away) OR (m.away_team_id=%d AND m.score_away>m.score_home) THEN 1 ELSE 0 END) wins,COUNT(*) games FROM ".DT_DB::table('matches')." m JOIN ".DT_DB::table('rounds')." r ON r.id=m.round_id WHERE m.starts_at<%s AND m.score_home IS NOT NULL AND m.score_away IS NOT NULL AND r.season=%s AND r.league_key=%s AND r.group_key=%s AND (m.home_team_id=%d OR m.away_team_id=%d)",$teamId,$teamId,$context['starts_at'],$context['season'],$context['league_key'],$context['group_key'],$teamId,$teamId),ARRAY_A);
        $wins=(int)($row['wins']??0);$games=(int)($row['games']??0);
        return $wins.'-'.max(0,$games-$wins);
    }

    private static function form(string $name,int $teamId,array $rows): void {
        echo '<section class="dt-artur-form"><h4>Ostatnie mecze · '.esc_html($name).'</h4><ol>';if(!$rows)echo '<li class="is-empty">Brak wyników w bazie</li>';
        foreach($rows as $r){$isHome=(int)$r['home_team_id']===$teamId;$own=(int)$r[$isHome?'score_home':'score_away'];$opp=(int)$r[$isHome?'score_away':'score_home'];$opponent=(string)$r[$isHome?'away_name':'home_name'];$won=$own>$opp;echo '<li><span class="'.($won?'is-win':'is-loss').'">'.($won?'W':'P').'</span><b>'.esc_html($opponent).'</b><strong>'.esc_html($own.':'.$opp).'</strong></li>';}
        echo '</ol></section>';
    }

    private static function upcoming(int $teamId,string $after,int $exclude,array $context): array {
        global $wpdb;return (array)$wpdb->get_results($wpdb->prepare("SELECT m.*,h.name home_name,a.name away_name FROM ".DT_DB::table('matches')." m JOIN ".DT_DB::table('rounds')." r ON r.id=m.round_id JOIN ".DT_DB::table('teams')." h ON h.id=m.home_team_id JOIN ".DT_DB::table('teams')." a ON a.id=m.away_team_id WHERE m.id<>%d AND m.starts_at>%s AND m.score_home IS NULL AND m.score_away IS NULL AND r.season=%s AND r.league_key=%s AND r.group_key=%s AND (m.home_team_id=%d OR m.away_team_id=%d) ORDER BY m.starts_at,m.id LIMIT 3",$exclude,$after,$context['season'],$context['league_key'],$context['group_key'],$teamId,$teamId),ARRAY_A);
    }

    private static function next_games(string $name,int $teamId,array $rows): void {
        echo '<section class="dt-artur-upcoming"><h4>3 najbliższe mecze · '.esc_html($name).'</h4><ol>';if(!$rows)echo '<li class="is-empty">Brak kolejnych spotkań w terminarzu</li>';
        foreach($rows as $r){$home=(int)$r['home_team_id']===$teamId;$opponent=(string)$r[$home?'away_name':'home_name'];echo '<li><span class="'.($home?'is-home':'is-away').'">'.($home?'DOM':'WYJAZD').'</span><b>'.esc_html($opponent).'</b><time>'.esc_html(mysql2date('d.m · H:i',(string)$r['starts_at'])).'</time></li>';}
        echo '</ol></section>';
    }

    private static function season_matches(int $teamId,array $context): array {
        global $wpdb;return (array)$wpdb->get_results($wpdb->prepare("SELECT m.* FROM ".DT_DB::table('matches')." m JOIN ".DT_DB::table('rounds')." r ON r.id=m.round_id WHERE m.starts_at<%s AND m.score_home IS NOT NULL AND m.score_away IS NOT NULL AND m.source_url IS NOT NULL AND m.source_url<>'' AND r.season=%s AND r.league_key=%s AND r.group_key=%s AND (m.home_team_id=%d OR m.away_team_id=%d) ORDER BY m.starts_at",$context['starts_at'],$context['season'],$context['league_key'],$context['group_key'],$teamId,$teamId),ARRAY_A);
    }

    private static function season_leaders(int $teamId,array $context): array {
        $players=[];$used=0;$matches=self::season_matches($teamId,$context);
        foreach($matches as $m){$stats=self::fetch_stats($m);$side=(int)$m['home_team_id']===$teamId?'home':'away';$rows=(array)($stats[$side]['players']??[]);if(!$rows)continue;$used++;
            foreach($rows as $p){$key=self::normalize((string)($p['name']??''));if(!$key)continue;if(!isset($players[$key]))$players[$key]=['name'=>$p['name'],'games'=>0,'points'=>0,'reb'=>0,'ast'=>0,'eval'=>0,'two'=>['made'=>0,'attempted'=>0],'three'=>['made'=>0,'attempted'=>0],'free'=>['made'=>0,'attempted'=>0]];$players[$key]['games']++;foreach(['points','reb','ast','eval'] as $mtr)$players[$key][$mtr]+=(float)($p[$mtr]??0);foreach(['two','three','free'] as $shot){$players[$key][$shot]['made']+=(int)($p[$shot]['made']??0);$players[$key][$shot]['attempted']+=(int)($p[$shot]['attempted']??0);}}
        }
        foreach($players as &$p){$g=max(1,(int)$p['games']);foreach(['points','reb','ast','eval'] as $mtr)$p[$mtr]=$p[$mtr]/$g;}unset($p);
        uasort($players,static fn(array $a,array $b): int=>[$b['eval'],$b['points'],$b['games']]<=>[$a['eval'],$a['points'],$a['games']]);
        $minimum=max(1,(int)ceil($used/2));$eligible=array_values(array_filter($players,static fn(array $p): bool=>(int)$p['games']>=$minimum));
        if(count($eligible)<3){foreach($players as $p){if(in_array($p,$eligible,true))continue;$eligible[]=$p;if(count($eligible)>=3)break;}}
        return [array_slice($eligible,0,3),$used];
    }

    private static function normalize(string $value): string {return strtolower(trim(remove_accents($value)));}
    private static function empty(string $message): string {return '<p class="dt-artur-detail-empty">'.esc_html($message).'</p>';}
}
