<?php
if (!defined('ABSPATH')) exit;

/** Fetches the public PZKosz box score used only to generate an admin infographic. */
class DT_Decka_Stats {
    public static function ajax(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'],403);
        check_ajax_referer('dt_decka_stats','nonce');
        global $wpdb;
        $matchId=absint($_POST['match_id']??0);
        $match=$wpdb->get_row($wpdb->prepare(
            "SELECT m.id,m.external_key,m.starts_at,m.score_home,m.score_away,
                    h.name home_name,h.logo_url home_logo,a.name away_name,a.logo_url away_logo,r.round_no
             FROM ".DT_DB::table('matches')." m
             JOIN ".DT_DB::table('teams')." h ON h.id=m.home_team_id
             JOIN ".DT_DB::table('teams')." a ON a.id=m.away_team_id
             JOIN ".DT_DB::table('rounds')." r ON r.id=m.round_id
             WHERE m.id=%d AND r.league_key='1lm'",
            $matchId
        ),ARRAY_A);
        if(!$match || (stripos((string)$match['home_name'],'Decka Pelplin')===false && stripos((string)$match['away_name'],'Decka Pelplin')===false)) wp_send_json_error(['message'=>'Nie znaleziono meczu Decki.'],404);
        if(!preg_match('/1lm-(\d+)/',(string)$match['external_key'],$id)) wp_send_json_error(['message'=>'Ten mecz nie ma publicznego identyfikatora PZKosz.'],422);
        $url='https://1lm.pzkosz.pl/mecz/'.rawurlencode($id[1]).'/';
        $response=wp_remote_get($url,['timeout'=>20,'redirection'=>4,'headers'=>['User-Agent'=>'TypujKosza/'.DT_VERSION.' (+'.home_url('/').')']]);
        $html=!is_wp_error($response) && (int)wp_remote_retrieve_response_code($response)===200 ? (string)wp_remote_retrieve_body($response) : '';
        $liveInput=esc_url_raw(wp_unslash((string)($_POST['livestats_url']??'')));
        $saved=(array)get_option('dt_livestats_match_ids',[]);
        $liveId=self::livestats_id($liveInput) ?: self::livestats_id($html) ?: (isset($saved[$matchId]) ? (string)$saved[$matchId] : null);
        if($liveId && $liveInput!=='' && (!isset($saved[$matchId]) || (string)$saved[$matchId]!==$liveId)){$saved[$matchId]=$liveId;update_option('dt_livestats_match_ids',$saved,false);}
        $stats=null;
        if($liveId){
            $liveUrl='https://fibalivestats.dcd.shared.geniussports.com/data/'.$liveId.'/data.json';
            $live=wp_remote_get($liveUrl,['timeout'=>20,'redirection'=>3,'headers'=>['User-Agent'=>'Mozilla/5.0 (compatible; TypujKosza/'.DT_VERSION.')','Accept'=>'application/json']]);
            if(!is_wp_error($live) && (int)wp_remote_retrieve_response_code($live)===200){
                $json=json_decode((string)wp_remote_retrieve_body($live),true);
                if(is_array($json))$stats=self::parse_livestats($json);
            }
        }
        if(!$stats && $html!=='')$stats=self::parse_game_html($html);
        if(!$stats && !$liveId) wp_send_json_error(['message'=>'Nie znaleziono identyfikatora LiveStats dla tego meczu. Wklej publiczny adres FIBA LiveStats i spróbuj ponownie.'],422);
        if(!$stats)$stats=['team'=>null,'leaders'=>[],'shots'=>[],'source'=>'browser_livestats','browser_fetch'=>true,'livestats_data_url'=>'https://fibalivestats.dcd.shared.geniussports.com/data/'.$liveId.'/data.json'];
        $logo=static function(string $name,string $fallback): string {
            return class_exists('DT_Team_Logos') ? (DT_Team_Logos::url_for($name) ?: $fallback) : $fallback;
        };
        $stats['match']=[
            'id'=>(int)$match['id'],'round_no'=>(int)$match['round_no'],'starts_at'=>(string)$match['starts_at'],
            'home'=>(string)$match['home_name'],'away'=>(string)$match['away_name'],
            'home_score'=>$match['score_home']===null?null:(int)$match['score_home'],'away_score'=>$match['score_away']===null?null:(int)$match['score_away'],
            'home_logo'=>$logo((string)$match['home_name'],(string)($match['home_logo']??'')),
            'away_logo'=>$logo((string)$match['away_name'],(string)($match['away_logo']??'')),
            'source_url'=>$url,
            'livestats_id'=>$liveId,
        ];
        wp_send_json_success($stats);
    }

    public static function parse_game_html(string $html): ?array {
        if(!class_exists('DOMDocument') || strlen($html)>5000000) return null;
        $previous=libxml_use_internal_errors(true);$dom=new DOMDocument();
        $loaded=$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html,LIBXML_NOERROR|LIBXML_NOWARNING|LIBXML_NONET);
        libxml_clear_errors();libxml_use_internal_errors($previous);if(!$loaded)return null;
        $xp=new DOMXPath($dom);$table=null;
        foreach($xp->query('//h3[contains(translate(normalize-space(.),"DECKA","decka"),"decka pelplin")]') as $heading){
            $table=$xp->query('following::table[1]',$heading)->item(0);if($table)break;
        }
        if(!$table){
            foreach($xp->query('//table[.//a[contains(translate(normalize-space(.),"DECKA","decka"),"decka pelplin")]]') as $candidate){$table=$candidate;break;}
        }
        if(!$table)return null;
        $cell=static fn(DOMNodeList $cells,int $index): string=>trim(preg_replace('/\s+/u',' ',(string)($cells->item($index)?->textContent??'')));
        $rows=[];$team=null;
        foreach($xp->query('.//tr',$table) as $row){
            $cells=$xp->query('./td',$row);if($cells->length<13)continue;
            $first=trim((string)$cells->item(0)->textContent);
            if($first==='Suma'){
                $team=['points'=>(int)$cell($cells,1),'two'=>self::shot($cell($cells,3),$cell($cells,4)),'three'=>self::shot($cell($cells,5),$cell($cells,6)),'field'=>self::shot($cell($cells,7),$cell($cells,8)),'free'=>self::shot($cell($cells,9),$cell($cells,10)),'oreb'=>(int)$cell($cells,11),'dreb'=>(int)$cell($cells,12),'reb'=>(int)$cell($cells,13),'ast'=>(int)$cell($cells,14),'fouls'=>(int)$cell($cells,15),'steals'=>(int)$cell($cells,17),'turnovers'=>(int)$cell($cells,18),'blocks'=>(int)$cell($cells,19),'eval'=>(int)$cell($cells,21)];
                continue;
            }
            $player=$xp->query('.//a[contains(@href,"/zawodnicy/p/")]',$row)->item(0);if(!$player)continue;
            $rows[]=['number'=>$first,'name'=>trim(preg_replace('/\s+/u',' ',(string)$player->textContent)),'points'=>(int)$cell($cells,3),'reb'=>(int)$cell($cells,15),'ast'=>(int)$cell($cells,16),'steals'=>(int)$cell($cells,19),'eval'=>(int)$cell($cells,23)];
        }
        if(!$team)return null;
        usort($rows,static fn(array $a,array $b): int=>[$b['eval'],$b['points'],$b['reb']]<=>[$a['eval'],$a['points'],$a['reb']]);
        return ['team'=>$team,'leaders'=>array_slice($rows,0,3)];
    }

    public static function parse_livestats(array $data): ?array {
        $teams=$data['tm']??null;if(!is_array($teams))return null;$decka=null;
        foreach($teams as $team){if(!is_array($team))continue;$name=(string)($team['name']??$team['nameInternational']??$team['shortName']??'');if(stripos($name,'decka')!==false || stripos($name,'pelplin')!==false){$decka=$team;break;}}
        if(!$decka)return null;
        $shot=static function(array $team,string $made,string $attempted): array {
            $m=(int)($team[$made]??0);$a=(int)($team[$attempted]??0);return ['made'=>$m,'attempted'=>$a,'percent'=>$a?round($m*100/$a,1):0.0];
        };
        $teamStats=['points'=>(int)($decka['tot_sPoints']??$decka['score']??0),'two'=>$shot($decka,'tot_sTwoPointersMade','tot_sTwoPointersAttempted'),'three'=>$shot($decka,'tot_sThreePointersMade','tot_sThreePointersAttempted'),'field'=>$shot($decka,'tot_sFieldGoalsMade','tot_sFieldGoalsAttempted'),'free'=>$shot($decka,'tot_sFreeThrowsMade','tot_sFreeThrowsAttempted'),'oreb'=>(int)($decka['tot_sReboundsOffensive']??0),'dreb'=>(int)($decka['tot_sReboundsDefensive']??0),'reb'=>(int)($decka['tot_sReboundsTotal']??0),'ast'=>(int)($decka['tot_sAssists']??0),'fouls'=>(int)($decka['tot_sFoulsPersonal']??0),'steals'=>(int)($decka['tot_sSteals']??0),'turnovers'=>(int)($decka['tot_sTurnovers']??0),'blocks'=>(int)($decka['tot_sBlocks']??0),'eval'=>(int)($decka['tot_eff_1']??$decka['tot_eff1']??0)];
        $players=[];foreach((array)($decka['pl']??[]) as $player){if(!is_array($player))continue;$name=trim((string)($player['name']??trim((string)($player['firstName']??'').' '.(string)($player['familyName']??''))));if($name==='')continue;$players[]=['number'=>(string)($player['shirtNumber']??$player['num']??''),'name'=>$name,'points'=>(int)($player['sPoints']??0),'reb'=>(int)($player['sReboundsTotal']??0),'ast'=>(int)($player['sAssists']??0),'steals'=>(int)($player['sSteals']??0),'eval'=>(int)($player['eff_1']??$player['sEfficiency']??0)];}
        usort($players,static fn(array $a,array $b): int=>[$b['eval'],$b['points'],$b['reb']]<=>[$a['eval'],$a['points'],$a['reb']]);
        $success=[];foreach((array)($data['pbp']??[]) as $event)if(is_array($event)&&isset($event['actionNumber']))$success[(string)$event['actionNumber']]=(int)($event['success']??0)===1;
        $shots=[];foreach((array)($decka['shot']??[]) as $item){if(!is_array($item)||!isset($item['x'],$item['y']))continue;$action=(string)($item['actionNumber']??'');$made=$success[$action]??((int)($item['success']??$item['made']??0)===1);$shots[]=['x'=>(float)$item['x'],'y'=>(float)$item['y'],'made'=>$made?1:0,'points'=>(int)($item['p']??$item['points']??2)];}
        return ['team'=>$teamStats,'leaders'=>array_slice($players,0,3),'shots'=>$shots,'source'=>'livestats'];
    }

    private static function livestats_id(string $value): ?string {
        if(preg_match('~(?:/u/POL/|/data/)(\d+)(?:/|$)~i',$value,$m))return $m[1];
        return null;
    }

    private static function shot(string $madeAttempted,string $percent): array {
        $made=0;$attempted=0;if(preg_match('/(\d+)\s*\/\s*(\d+)/',$madeAttempted,$m)){ $made=(int)$m[1];$attempted=(int)$m[2]; }
        return ['made'=>$made,'attempted'=>$attempted,'percent'=>(float)str_replace(',','.',$percent)];
    }
}
