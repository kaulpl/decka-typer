<?php
if (!defined('ABSPATH')) exit;

class DT_REST {
    public static function register(): void {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes(): void {
        register_rest_route('decka-typer/v1','/bootstrap',[
            'methods'=>'GET','callback'=>[__CLASS__,'bootstrap'],'permission_callback'=>'__return_true'
        ]);
        register_rest_route('decka-typer/v1','/round/(?P<id>\d+)',[
            'methods'=>'GET','callback'=>[__CLASS__,'round'],'permission_callback'=>'__return_true'
        ]);
        register_rest_route('decka-typer/v1','/prediction',[
            'methods'=>'POST','callback'=>[__CLASS__,'save_prediction'],'permission_callback'=>static fn()=>is_user_logged_in(),
            'args'=>[
                'match_id'=>['required'=>true,'type'=>'integer'],
                'home_score'=>['required'=>true,'type'=>'integer','minimum'=>0,'maximum'=>250],
                'away_score'=>['required'=>true,'type'=>'integer','minimum'=>0,'maximum'=>250],
            ]
        ]);
        register_rest_route('decka-typer/v1','/ranking',[
            'methods'=>'GET','callback'=>[__CLASS__,'ranking'],'permission_callback'=>'__return_true'
        ]);
        register_rest_route('decka-typer/v1','/me',[
            'methods'=>'GET','callback'=>[__CLASS__,'me'],'permission_callback'=>static fn()=>is_user_logged_in()
        ]);
    }

    public static function bootstrap(WP_REST_Request $request): WP_REST_Response {
        global $wpdb;
        $s=DT_DB::settings();
        $now=current_time('mysql');
        $rounds=$wpdb->get_results($wpdb->prepare("SELECT r.*,
            SUM(CASE WHEN m.status='finished' THEN 1 ELSE 0 END) finished_count, COUNT(m.id) match_count,
            MIN(m.starts_at) first_match, MAX(m.starts_at) last_match,
            MIN(CASE WHEN m.status!='finished' AND m.starts_at >= %s THEN m.starts_at END) next_match
            FROM ".DT_DB::table('rounds')." r LEFT JOIN ".DT_DB::table('matches')." m ON m.round_id=r.id
            WHERE r.season=%s GROUP BY r.id ORDER BY r.round_no ASC",$now,$s['season']),ARRAY_A);
        foreach($rounds as &$r){$r['id']=(int)$r['id'];$r['round_no']=(int)$r['round_no'];$r['finished_count']=(int)$r['finished_count'];$r['match_count']=(int)$r['match_count'];}
        $current=self::pick_current_round($rounds);
        $roundData=$current ? self::round_payload((int)$current['id']) : null;
        $ranking=DT_Scoring::ranking($s['season'],10);
        $me=null;
        if (is_user_logged_in()) $me=self::me_payload(get_current_user_id(),$s['season']);
        return new WP_REST_Response([
            'version'=>DT_VERSION,'season'=>$s['season'],'rounds'=>$rounds,'current_round'=>$roundData,
            'ranking'=>$ranking,'me'=>$me,'server_time'=>current_time('mysql'),'scoring'=>[
                'exact'=>(float)$s['points_exact'],'margin'=>(float)$s['points_margin'],'winner'=>(float)$s['points_winner']
            ]
        ]);
    }

    public static function round(WP_REST_Request $request): WP_REST_Response|WP_Error {
        $id=(int)$request['id'];
        $payload=self::round_payload($id);
        return $payload ? new WP_REST_Response($payload) : new WP_Error('not_found','Nie znaleziono kolejki.',['status'=>404]);
    }

    private static function round_payload(int $roundId): ?array {
        global $wpdb;
        $round=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".DT_DB::table('rounds')." WHERE id=%d",$roundId),ARRAY_A);
        if(!$round) return null;
        $matches=$wpdb->get_results($wpdb->prepare("SELECT m.*, h.name home_name,h.short_name home_short,h.logo_url home_logo,
            a.name away_name,a.short_name away_short,a.logo_url away_logo
            FROM ".DT_DB::table('matches')." m
            JOIN ".DT_DB::table('teams')." h ON h.id=m.home_team_id
            JOIN ".DT_DB::table('teams')." a ON a.id=m.away_team_id
            WHERE m.round_id=%d ORDER BY m.starts_at ASC,m.id ASC",$roundId),ARRAY_A);
        $predMap=[];
        if(is_user_logged_in() && $matches){
            $ids=array_map(static fn($m)=>(int)$m['id'],$matches);
            $place=implode(',',array_fill(0,count($ids),'%d'));
            $q=$wpdb->prepare("SELECT * FROM ".DT_DB::table('predictions')." WHERE user_id=%d AND match_id IN ($place)",array_merge([get_current_user_id()],$ids));
            foreach($wpdb->get_results($q,ARRAY_A) as $p)$predMap[(int)$p['match_id']]=$p;
        }
        foreach($matches as &$m){
            $m['id']=(int)$m['id']; $m['round_id']=(int)$m['round_id'];
            $m['score_home']=$m['score_home']===null?null:(int)$m['score_home'];
            $m['score_away']=$m['score_away']===null?null:(int)$m['score_away'];
            $m['start_time_known']=(bool)$m['start_time_known']; $m['manual_lock']=(bool)$m['manual_lock']; $m['featured']=(bool)$m['featured'];
            $m['locked']=self::match_locked($m);
            $p=$predMap[$m['id']]??null;
            $m['prediction']=$p?['home_score'=>(int)$p['home_score'],'away_score'=>(int)$p['away_score'],'points'=>(float)$p['points'],'scoring_code'=>$p['scoring_code']]:null;
            unset($m['source_hash']);
        }
        $round['id']=(int)$round['id'];$round['round_no']=(int)$round['round_no'];$round['matches']=$matches;
        return $round;
    }

    public static function save_prediction(WP_REST_Request $request): WP_REST_Response|WP_Error {
        global $wpdb;
        $matchId=(int)$request['match_id'];$home=(int)$request['home_score'];$away=(int)$request['away_score'];
        if($home===$away) return new WP_Error('draw_not_allowed','W koszykówce typ końcowego wyniku nie może być remisowy.',['status'=>422]);
        $match=$wpdb->get_row($wpdb->prepare("SELECT * FROM ".DT_DB::table('matches')." WHERE id=%d",$matchId),ARRAY_A);
        if(!$match) return new WP_Error('not_found','Nie znaleziono meczu.',['status'=>404]);
        if(self::match_locked($match)) return new WP_Error('locked','Typowanie tego meczu jest już zamknięte.',['status'=>409]);
        $uid=get_current_user_id();$now=current_time('mysql');
        $existing=$wpdb->get_var($wpdb->prepare("SELECT id FROM ".DT_DB::table('predictions')." WHERE user_id=%d AND match_id=%d",$uid,$matchId));
        $data=['home_score'=>$home,'away_score'=>$away,'updated_at'=>$now];
        if($existing){$wpdb->update(DT_DB::table('predictions'),$data,['id'=>(int)$existing],['%d','%d','%s'],['%d']);}
        else{$data+=['user_id'=>$uid,'match_id'=>$matchId,'submitted_at'=>$now];$wpdb->insert(DT_DB::table('predictions'),$data,['%d','%d','%d','%d','%s','%s']);}
        DT_Logger::log('prediction_saved','Zapisano typ.', ['match_id'=>$matchId], 'info',$uid);
        return new WP_REST_Response(['ok'=>true,'match_id'=>$matchId,'home_score'=>$home,'away_score'=>$away,'saved_at'=>$now]);
    }

    public static function ranking(WP_REST_Request $request): WP_REST_Response {
        $s=DT_DB::settings();$roundId=max(0,(int)$request->get_param('round_id'));
        return new WP_REST_Response(['season'=>$s['season'],'round_id'=>$roundId,'ranking'=>DT_Scoring::ranking($s['season'],100,$roundId)]);
    }

    public static function me(WP_REST_Request $request): WP_REST_Response {
        $s=DT_DB::settings();return new WP_REST_Response(self::me_payload(get_current_user_id(),$s['season']));
    }

    private static function me_payload(int $uid,string $season): array {
        global $wpdb;
        $u=get_userdata($uid);
        $row=$wpdb->get_row($wpdb->prepare("SELECT COUNT(p.id) predictions,COALESCE(SUM(p.points),0) points,
            SUM(CASE WHEN p.scoring_code='exact' THEN 1 ELSE 0 END) exact_hits
            FROM ".DT_DB::table('predictions')." p JOIN ".DT_DB::table('matches')." m ON m.id=p.match_id
            JOIN ".DT_DB::table('rounds')." r ON r.id=m.round_id WHERE p.user_id=%d AND r.season=%s",$uid,$season),ARRAY_A);
        $adj=(float)$wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(points),0) FROM ".DT_DB::table('point_adjustments')." WHERE user_id=%d AND season=%s",$uid,$season));
        $ranking=DT_Scoring::ranking($season,500);$rank=null;
        foreach($ranking as $r)if((int)$r['user_id']===$uid){$rank=(int)$r['rank'];break;}
        $history=$wpdb->get_results($wpdb->prepare("SELECT r.round_no,m.starts_at,h.name home_name,a.name away_name,p.home_score,p.away_score,p.points,m.score_home,m.score_away
            FROM ".DT_DB::table('predictions')." p JOIN ".DT_DB::table('matches')." m ON m.id=p.match_id
            JOIN ".DT_DB::table('rounds')." r ON r.id=m.round_id JOIN ".DT_DB::table('teams')." h ON h.id=m.home_team_id
            JOIN ".DT_DB::table('teams')." a ON a.id=m.away_team_id WHERE p.user_id=%d AND r.season=%s
            ORDER BY m.starts_at DESC,p.id DESC LIMIT 100",$uid,$season),ARRAY_A);
        foreach($history as &$h){$h['round_no']=(int)$h['round_no'];$h['home_score']=(int)$h['home_score'];$h['away_score']=(int)$h['away_score'];$h['points']=(float)$h['points'];$h['result_known']=$h['score_home']!==null&&$h['score_away']!==null;unset($h['score_home'],$h['score_away']);}
        return ['user_id'=>$uid,'display_name'=>$u?$u->display_name:'Kibic','avatar'=>get_avatar_url($uid,['size'=>96]),
            'predictions'=>(int)($row['predictions']??0),'points'=>(float)($row['points']??0)+$adj,'exact_hits'=>(int)($row['exact_hits']??0),'rank'=>$rank,'history'=>$history];
    }

    private static function match_locked(array $m): bool {
        if(($m['status']??'')==='finished') return true;
        if(empty($m['starts_at'])) return true;
        $tz=wp_timezone();
        try{$start=new DateTimeImmutable($m['starts_at'],$tz);}catch(Throwable $e){return true;}
        return (new DateTimeImmutable('now',$tz)) >= $start;
    }

    private static function pick_current_round(array $rounds): ?array {
        if(!$rounds)return null;
        $future=array_values(array_filter($rounds,static fn($r)=>!empty($r['next_match'])));
        if($future){
            usort($future,static fn($a,$b)=>strcmp((string)$a['next_match'],(string)$b['next_match']));
            return $future[0];
        }
        foreach($rounds as $r){
            if($r['match_count']>0 && $r['finished_count']<$r['match_count']) return $r;
        }
        return end($rounds) ?: null;
    }
}
