<?php
if (!defined('ABSPATH')) exit;

/** Lazy-loaded match notes for the administrator's "Kolejka z Arturem" screen. */
class DT_Artur_Insights {
    public static function ajax(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'],403);
        check_ajax_referer('dt_artur_insights','nonce');
        $matchId=absint($_POST['match_id']??0);$mode=sanitize_key($_POST['mode']??'');
        if(!$matchId || !in_array($mode,['summary','preview'],true))wp_send_json_error(['message'=>'Nieprawidłowe spotkanie.'],400);
        $key='dt_artur_insights_'.DT_VERSION.'_'.$mode.'_'.$matchId;
        $html=get_transient($key);
        if(!is_string($html)){
            $html=$mode==='summary'?self::summary($matchId):self::preview($matchId);
            set_transient($key,$html,$mode==='summary'?DAY_IN_SECONDS:6*HOUR_IN_SECONDS);
        }
        wp_send_json_success(['html'=>$html]);
    }

    private static function match(int $id): ?array {
        global $wpdb;
        $row=$wpdb->get_row($wpdb->prepare("SELECT m.*,h.name home_name,a.name away_name FROM ".DT_DB::table('matches')." m JOIN ".DT_DB::table('teams')." h ON h.id=m.home_team_id JOIN ".DT_DB::table('teams')." a ON a.id=m.away_team_id WHERE m.id=%d",$id),ARRAY_A);
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
        global $wpdb;$m=self::match($matchId);if(!$m)return self::empty('Nie znaleziono meczu.');
        $before=(string)$m['starts_at'];$history=(array)$wpdb->get_results($wpdb->prepare("SELECT m.*,h.name home_name,a.name away_name FROM ".DT_DB::table('matches')." m JOIN ".DT_DB::table('teams')." h ON h.id=m.home_team_id JOIN ".DT_DB::table('teams')." a ON a.id=m.away_team_id WHERE m.id<>%d AND m.starts_at<%s AND m.score_home IS NOT NULL AND m.score_away IS NOT NULL AND ((m.home_team_id=%d AND m.away_team_id=%d) OR (m.home_team_id=%d AND m.away_team_id=%d)) ORDER BY m.starts_at DESC LIMIT 20",$matchId,$before,$m['home_team_id'],$m['away_team_id'],$m['away_team_id'],$m['home_team_id']),ARRAY_A);
        ob_start();echo '<div class="dt-artur-detail-head"><strong>Zapowiedź spotkania</strong><span>Historia wyłącznie z meczów zsynchronizowanych w TypujKosza.pl</span></div>';
        self::h2h($m,$history);
        echo '<div class="dt-artur-form-columns">';
        foreach(['home','away'] as $side){$teamId=(int)$m[$side.'_team_id'];$name=(string)$m[$side.'_name'];$recent=self::recent($teamId,$before,$matchId);self::form($name,$teamId,$recent);$leaders=self::average_leaders($teamId,$recent);self::leaders($name,$leaders,$recent?'Średnie z '.count($recent).' ostatnich dostępnych protokołów':'Brak wcześniejszych protokołów');}
        echo '</div>';return (string)ob_get_clean();
    }

    private static function h2h(array $m,array $rows): void {
        $hw=0;$aw=0;foreach($rows as $r){$winner=(int)$r['score_home']>(int)$r['score_away']?(int)$r['home_team_id']:(int)$r['away_team_id'];if($winner===(int)$m['home_team_id'])$hw++;elseif($winner===(int)$m['away_team_id'])$aw++;}
        echo '<section class="dt-artur-h2h"><h4>Bilans bezpośredni</h4><div><strong>'.esc_html((string)$m['home_name']).' '.$hw.'</strong><span>'.count($rows).' spotkań</span><strong>'.$aw.' '.esc_html((string)$m['away_name']).'</strong></div>';
        if($rows){echo '<ul>';foreach(array_slice($rows,0,5) as $r)echo '<li><span>'.esc_html(mysql2date('d.m.Y',(string)$r['starts_at'])).'</span><b>'.esc_html($r['home_name'].' '.(int)$r['score_home'].':'.(int)$r['score_away'].' '.$r['away_name']).'</b></li>';echo '</ul>';}else echo '<p>Brak wcześniejszych bezpośrednich spotkań w zsynchronizowanej bazie.</p>';echo '</section>';
    }

    private static function recent(int $teamId,string $before,int $exclude): array {
        global $wpdb;return (array)$wpdb->get_results($wpdb->prepare("SELECT m.*,h.name home_name,a.name away_name FROM ".DT_DB::table('matches')." m JOIN ".DT_DB::table('teams')." h ON h.id=m.home_team_id JOIN ".DT_DB::table('teams')." a ON a.id=m.away_team_id WHERE m.id<>%d AND m.starts_at<%s AND m.score_home IS NOT NULL AND m.score_away IS NOT NULL AND (m.home_team_id=%d OR m.away_team_id=%d) ORDER BY m.starts_at DESC LIMIT 3",$exclude,$before,$teamId,$teamId),ARRAY_A);
    }

    private static function form(string $name,int $teamId,array $rows): void {
        echo '<section class="dt-artur-form"><h4>Ostatnie mecze · '.esc_html($name).'</h4><ol>';if(!$rows)echo '<li class="is-empty">Brak wyników w bazie</li>';
        foreach($rows as $r){$isHome=(int)$r['home_team_id']===$teamId;$own=(int)$r[$isHome?'score_home':'score_away'];$opp=(int)$r[$isHome?'score_away':'score_home'];$opponent=(string)$r[$isHome?'away_name':'home_name'];$won=$own>$opp;echo '<li><span class="'.($won?'is-win':'is-loss').'">'.($won?'W':'P').'</span><b>'.esc_html($opponent).'</b><strong>'.esc_html($own.':'.$opp).'</strong></li>';}
        echo '</ol></section>';
    }

    private static function average_leaders(int $teamId,array $matches): array {
        $players=[];$used=0;
        foreach($matches as $m){$stats=self::fetch_stats($m);$side=(int)$m['home_team_id']===$teamId?'home':'away';$rows=(array)($stats[$side]['leaders']??[]);if(!$rows)continue;$used++;
            foreach($rows as $p){$key=self::normalize((string)($p['name']??''));if(!$key)continue;if(!isset($players[$key]))$players[$key]=['name'=>$p['name'],'games'=>0,'points'=>0,'reb'=>0,'ast'=>0,'eval'=>0,'two'=>['made'=>0,'attempted'=>0],'three'=>['made'=>0,'attempted'=>0],'free'=>['made'=>0,'attempted'=>0]];$players[$key]['games']++;foreach(['points','reb','ast','eval'] as $mtr)$players[$key][$mtr]+=(float)($p[$mtr]??0);foreach(['two','three','free'] as $shot){$players[$key][$shot]['made']+=(int)($p[$shot]['made']??0);$players[$key][$shot]['attempted']+=(int)($p[$shot]['attempted']??0);}}
        }
        foreach($players as &$p){$g=max(1,(int)$p['games']);foreach(['points','reb','ast','eval'] as $mtr)$p[$mtr]=$p[$mtr]/$g;}unset($p);
        uasort($players,static fn(array $a,array $b): int=>[$b['eval'],$b['points']]<=>[$a['eval'],$a['points']]);return array_slice(array_values($players),0,3);
    }

    private static function normalize(string $value): string {return strtolower(trim(remove_accents($value)));}
    private static function empty(string $message): string {return '<p class="dt-artur-detail-empty">'.esc_html($message).'</p>';}
}
