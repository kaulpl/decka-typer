<?php
if (!defined('ABSPATH')) exit;

/** Fetches public Puls Basketu statistics used only to generate an admin infographic. */
class DT_Decka_Stats {
    private const PULS_GAMES = 'https://pulsbasketu.com/pl/league/1lm/%s/games';

    public static function ajax(): void {
        if (!current_user_can('manage_options')) wp_send_json_error(['message'=>'Brak uprawnień.'],403);
        check_ajax_referer('dt_decka_stats','nonce');
        global $wpdb;
        $matchId=absint($_POST['match_id']??0);
        $match=$wpdb->get_row($wpdb->prepare(
            "SELECT m.id,m.starts_at,m.score_home,m.score_away,m.source_url,m.external_key,h.name home_name,h.logo_url home_logo,
                    a.name away_name,a.logo_url away_logo,r.round_no,r.season
             FROM ".DT_DB::table('matches')." m
             JOIN ".DT_DB::table('teams')." h ON h.id=m.home_team_id
             JOIN ".DT_DB::table('teams')." a ON a.id=m.away_team_id
             JOIN ".DT_DB::table('rounds')." r ON r.id=m.round_id
             WHERE m.id=%d AND r.league_key='1lm'",
            $matchId
        ),ARRAY_A);
        if(!$match || (stripos((string)$match['home_name'],'Decka Pelplin')===false && stripos((string)$match['away_name'],'Decka Pelplin')===false)) wp_send_json_error(['message'=>'Nie znaleziono meczu Decki.'],404);

        $year=self::season_year((string)$match['season']);
        $gamesUrl=sprintf(self::PULS_GAMES,$year);
        $gamesHtml=self::fetch($gamesUrl);
        if($gamesHtml==='') wp_send_json_error(['message'=>'Nie udało się połączyć z Puls Basketu. Spróbuj ponownie za chwilę.'],502);
        $game=self::find_puls_game($gamesHtml,$match);
        if(!$game) wp_send_json_error(['message'=>'Nie znaleziono pary drużyn tego meczu na liście Puls Basketu (sezon '.$year.').'],422);
        $gameUrl='https://pulsbasketu.com'.$game['path'];
        $stats=self::parse_puls_game(self::fetch($gameUrl));
        // Puls hydrates the box score in JavaScript on some requests. The
        // synchronized match already carries its stable official PZKosz URL,
        // whose server-rendered table is a reliable fallback for the same data.
        $officialUrl='';
        if(!$stats){
            $storedUrl=(string)($match['source_url']??'');
            if(self::is_pzkosz_match_url($storedUrl))$officialUrl=$storedUrl;
            elseif(preg_match('/(?:^|-)1lm-(\d+)$/',(string)($match['external_key']??''),$officialId))$officialUrl='https://1lm.pzkosz.pl/mecz/'.$officialId[1];
            else{
                $scheduleUrl=self::is_pzkosz_url($storedUrl)?$storedUrl:'https://1lm.pzkosz.pl/terminarz-i-wyniki.html';
                $officialUrl=self::find_pzkosz_game_url(self::fetch($scheduleUrl),$match,$scheduleUrl)??'';
                if($officialUrl==='' && $scheduleUrl!=='https://1lm.pzkosz.pl/terminarz-i-wyniki.html'){
                    $scheduleUrl='https://1lm.pzkosz.pl/terminarz-i-wyniki.html';
                    $officialUrl=self::find_pzkosz_game_url(self::fetch($scheduleUrl),$match,$scheduleUrl)??'';
                }
            }
            if($officialUrl!=='')$stats=self::parse_pzkosz_game(self::fetch($officialUrl),stripos((string)$match['home_name'],'Decka Pelplin')!==false);
        }
        if(!$stats){
            $code=$officialUrl===''?'STAT-MECZ-2':'STAT-MECZ-3';
            wp_send_json_error(['message'=>'Nie udało się odczytać pełnych statystyk meczu. Kod: '.$code.'.'],422);
        }

        $logo=static function(string $name,string $fallback): string {
            return class_exists('DT_Team_Logos') ? (DT_Team_Logos::url_for($name) ?: $fallback) : $fallback;
        };
        $stats['match']=[
            'id'=>(int)$match['id'],'round_no'=>(int)$match['round_no'],'starts_at'=>(string)$match['starts_at'],
            'home'=>(string)$match['home_name'],'away'=>(string)$match['away_name'],
            'home_score'=>$match['score_home']===null?null:(int)$match['score_home'],'away_score'=>$match['score_away']===null?null:(int)$match['score_away'],
            'home_logo'=>$logo((string)$match['home_name'],(string)($match['home_logo']??'')),
            'away_logo'=>$logo((string)$match['away_name'],(string)($match['away_logo']??'')),
            'source_url'=>$gameUrl,'puls_game_id'=>$game['id'],
        ];
        wp_send_json_success($stats);
    }

    public static function find_puls_game(string $html,array $match): ?array {
        $dom=self::dom($html);if(!$dom)return null;$xp=new DOMXPath($dom);$best=null;$bestScore=0;
        foreach($xp->query('//a[contains(@href,"/league/1lm/") and contains(@href,"/games/")]') as $link){
            $path=(string)$link->getAttribute('href');
            if(!preg_match('~(/pl/league/1lm/\d+/games/(\d+))~',$path,$m))continue;
            // Puls places the empty overlay inside one `relative` game card.
            // Never climb to the list container, where teams from other games
            // could be incorrectly associated with this link.
            $card=$xp->query('ancestor::*[contains(concat(" ",normalize-space(@class)," ")," relative ")][1]',$link)->item(0);
            if(!$card)$card=$link->parentNode;
            $text=self::norm((string)($card?->textContent??''));
            $homeNameScore=self::name_score((string)$match['home_name'],$text);
            $awayNameScore=self::name_score((string)$match['away_name'],$text);
            if($homeNameScore<0.5 || $awayNameScore<0.5)continue;
            $score=$homeNameScore+$awayNameScore;
            if($text==='')continue;
            $home=$match['score_home']??null;$away=$match['score_away']??null;
            if($home!==null && $away!==null && preg_match('/(?:^|\D)'.preg_quote((string)$home,'/').'\s+'.preg_quote((string)$away,'/').'(?:\D|$)/',$text))$score+=2;
            $date=substr((string)($match['starts_at']??''),0,10);
            if($date!=='' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/',$date,$d) && str_contains($text,$d[3].' '.$d[2].' '.$d[1]))$score+=0.5;
            if($score>$bestScore){$bestScore=$score;$best=['id'=>$m[2],'path'=>$m[1]];}
        }
        return $bestScore>=1.5?$best:null;
    }

    public static function parse_puls_game(string $html): ?array {
        $dom=self::dom($html);if(!$dom)return null;$xp=new DOMXPath($dom);$box=null;
        foreach($xp->query('//table') as $table){
            $label=self::norm($table->getAttribute('aria-label').' '.$table->textContent);
            if((str_contains($label,'decka')||str_contains($label,'pelplin')) && str_contains($label,'eval') && str_contains($label,'zawodnik')){$box=$table;break;}
        }
        if(!$box)return null;
        $players=[];$team=null;
        foreach($xp->query('.//tr',$box) as $row){
            $cells=self::cells($xp,$row);if(count($cells)<17)continue;$first=self::norm($cells[0]);$second=self::norm($cells[1]??'');
            if(in_array($first,['razem','suma'],true) || in_array($second,['razem','suma'],true)){
                // The live Puls table keeps an empty shirt-number cell before
                // RAZEM; older snapshots omitted it. Normalize both layouts.
                $offset=in_array($second,['razem','suma'],true)?1:0;
                $team=['points'=>(int)$cells[2+$offset],'reb'=>(int)$cells[3+$offset],'ast'=>(int)$cells[4+$offset],
                    'field'=>self::made_attempted($cells[5+$offset]),'two'=>self::made_attempted($cells[6+$offset]),'three'=>self::made_attempted($cells[7+$offset]),'free'=>self::made_attempted($cells[8+$offset]),
                    'oreb'=>(int)$cells[9+$offset],'dreb'=>(int)$cells[10+$offset],'steals'=>(int)$cells[11+$offset],'blocks'=>(int)$cells[12+$offset],
                    'turnovers'=>(int)$cells[13+$offset],'fouls'=>(int)$cells[14+$offset],'eval'=>(int)$cells[16+$offset]];
                continue;
            }
            if(count($cells)<18 || !preg_match('/^\d+$/',trim($cells[0])) || trim($cells[1])==='')continue;
            $players[]=['number'=>trim($cells[0]),'name'=>trim($cells[1]),'points'=>(int)$cells[3],'reb'=>(int)$cells[4],
                'ast'=>(int)$cells[5],'steals'=>(int)$cells[12],'eval'=>(int)$cells[17]];
        }
        if(!$team)return null;
        usort($players,static fn(array $a,array $b): int=>[$b['eval'],$b['points'],$b['reb']]<=>[$a['eval'],$a['points'],$a['reb']]);
        return ['team'=>$team,'leaders'=>array_slice($players,0,3),'shots'=>[],'quarters'=>self::quarters($xp),'advanced'=>self::advanced($xp),'source'=>'pulsbasketu'];
    }

    public static function parse_pzkosz_game(string $html,bool $deckaHome): ?array {
        $dom=self::dom($html);if(!$dom)return null;$xp=new DOMXPath($dom);$box=null;
        // The official page always renders the home and away box scores in
        // that order. Selecting by match side is more reliable than coupling
        // the parser to a decorative heading that differs between templates.
        $tables=$xp->query('//table[contains(concat(" ",normalize-space(@class)," ")," statystyki ")]');
        if($tables && $tables->length>=2)$box=$tables->item($deckaHome?0:1);
        elseif($tables && $tables->length===1)$box=$tables->item(0);
        if(!$box)return null;
        $players=[];$team=null;
        foreach($xp->query('.//tr',$box) as $row){
            $cells=self::cells($xp,$row);if(!$cells)continue;$rowClass=$row instanceof DOMElement?$row->getAttribute('class'):'';
            if(str_contains(' '.$rowClass.' ',' sum ') || self::norm($cells[0]??'')==='suma'){
                if(count($cells)<22)continue;
                $team=['points'=>(int)$cells[1],'reb'=>(int)$cells[13],'ast'=>(int)$cells[14],
                    'field'=>self::made_attempted($cells[7]),'two'=>self::made_attempted($cells[3]),'three'=>self::made_attempted($cells[5]),'free'=>self::made_attempted($cells[9]),
                    'oreb'=>(int)$cells[11],'dreb'=>(int)$cells[12],'steals'=>(int)$cells[18],'blocks'=>(int)$cells[19],
                    'turnovers'=>(int)$cells[17],'fouls'=>(int)$cells[15],'eval'=>(int)$cells[21]];
                continue;
            }
            if(count($cells)<24 || !preg_match('/^\d+$/',trim($cells[0])) || trim($cells[2])==='')continue;
            $players[]=['number'=>trim($cells[0]),'name'=>trim($cells[2]),'points'=>(int)$cells[3],'reb'=>(int)$cells[15],
                'ast'=>(int)$cells[16],'steals'=>(int)$cells[20],'eval'=>(int)$cells[23]];
        }
        if(!$team)return null;
        usort($players,static fn(array $a,array $b): int=>[$b['eval'],$b['points'],$b['reb']]<=>[$a['eval'],$a['points'],$a['reb']]);
        return ['team'=>$team,'leaders'=>array_slice($players,0,3),'shots'=>[],'quarters'=>self::pzkosz_quarters($xp,$deckaHome),'advanced'=>[],'source'=>'pzkosz'];
    }

    public static function find_pzkosz_game_url(string $html,array $match,string $baseUrl): ?string {
        $dom=self::dom($html);if(!$dom)return null;$xp=new DOMXPath($dom);$best=null;$bestScore=0;
        foreach($xp->query('//a[contains(@href,"/mecz/")]') as $link){
            $row=$xp->query('ancestor::tr[1]',$link)->item(0);if(!$row)$row=$link->parentNode;
            $text=self::norm((string)($row?->textContent??''));if($text==='')continue;
            $homeScore=self::name_score((string)$match['home_name'],$text);$awayScore=self::name_score((string)$match['away_name'],$text);
            if($homeScore<0.5||$awayScore<0.5)continue;$score=$homeScore+$awayScore;
            $home=$match['score_home']??null;$away=$match['score_away']??null;
            if($home!==null&&$away!==null&&preg_match('/(?:^|\D)'.preg_quote((string)$home,'/').'\s*[:\-]\s*'.preg_quote((string)$away,'/').'(?:\D|$)/',$text))$score+=2;
            if($score>$bestScore){$bestScore=$score;$best=self::absolute_url((string)$link->getAttribute('href'),$baseUrl);}
        }
        return $bestScore>=1.5?$best:null;
    }

    private static function pzkosz_quarters(DOMXPath $xp,bool $deckaHome): array {
        foreach($xp->query('//span|//div') as $node){
            $text=trim(preg_replace('/\s+/u',' ',(string)$node->textContent));
            if(!preg_match('/(\d+)\s*:\s*(\d+)\s*\|\s*(\d+)\s*:\s*(\d+)\s*\|\s*(\d+)\s*:\s*(\d+)\s*\|\s*(\d+)\s*:\s*(\d+)/',$text,$m))continue;
            $home=[(int)$m[1],(int)$m[3],(int)$m[5],(int)$m[7]];$away=[(int)$m[2],(int)$m[4],(int)$m[6],(int)$m[8]];
            return ['decka'=>$deckaHome?$home:$away,'opponent'=>$deckaHome?$away:$home];
        }return [];
    }

    private static function is_pzkosz_match_url(string $url): bool {
        $host=strtolower((string)wp_parse_url($url,PHP_URL_HOST));$path=(string)wp_parse_url($url,PHP_URL_PATH);
        return ($host==='1lm.pzkosz.pl'||$host==='rozgrywki.pzkosz.pl') && preg_match('~/mecz/\d+~',$path)===1;
    }

    private static function is_pzkosz_url(string $url): bool {
        $host=strtolower((string)wp_parse_url($url,PHP_URL_HOST));return $host==='1lm.pzkosz.pl'||$host==='rozgrywki.pzkosz.pl';
    }

    private static function absolute_url(string $href,string $base): string {
        if(preg_match('~^https?://~i',$href))return $href;$parts=parse_url($base);if(!$parts||empty($parts['host']))return $href;
        return ($parts['scheme']??'https').'://'.$parts['host'].'/'.ltrim($href,'/');
    }

    private static function quarters(DOMXPath $xp): array {
        foreach($xp->query('//table') as $table){
            $label=self::norm($table->textContent);if(!str_contains($label,'druzyna')||!str_contains($label,'q1')||!str_contains($label,'razem'))continue;
            $out=[];foreach($xp->query('.//tr',$table) as $row){$c=self::cells($xp,$row);if(count($c)<6)continue;$name=self::norm($c[0]);if(str_contains($name,'decka')||str_contains($name,'pelplin'))$out['decka']=array_map('intval',array_slice($c,1,4));else if(!in_array($name,['druzyna',''],true) && preg_match('/\d/',$c[1]))$out['opponent']=array_map('intval',array_slice($c,1,4));}
            if(isset($out['decka']))return $out;
        }return [];
    }

    private static function advanced(DOMXPath $xp): array {
        foreach($xp->query('//table') as $table){
            $label=self::norm($table->getAttribute('aria-label').' '.$table->textContent);
            if((!str_contains($label,'decka')&&!str_contains($label,'pelplin')) || !str_contains($label,'pkt z kontry'))continue;
            foreach($xp->query('.//tr',$table) as $row){$c=self::cells($xp,$row);if(count($c)<7 || !preg_match('/^\d+$/',trim($c[0])))continue;return ['turnover_points'=>(int)$c[0],'paint_points'=>(int)$c[1],'paint_made'=>(int)$c[2],'paint_attempted'=>(int)$c[3],'second_chance'=>(int)$c[4],'fast_break'=>(int)$c[5],'bench'=>(int)$c[6]];}
        }return [];
    }

    private static function fetch(string $url): string {
        $response=wp_remote_get($url,['timeout'=>25,'redirection'=>5,'headers'=>['User-Agent'=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36','Accept'=>'text/html,application/xhtml+xml','Accept-Language'=>'pl-PL,pl;q=0.9,en;q=0.7']]);
        return !is_wp_error($response) && (int)wp_remote_retrieve_response_code($response)===200 ? (string)wp_remote_retrieve_body($response) : '';
    }

    private static function dom(string $html): ?DOMDocument {
        if(!class_exists('DOMDocument') || $html==='' || strlen($html)>8000000)return null;
        $previous=libxml_use_internal_errors(true);$dom=new DOMDocument();$loaded=$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html,LIBXML_NOERROR|LIBXML_NOWARNING|LIBXML_NONET);libxml_clear_errors();libxml_use_internal_errors($previous);return $loaded?$dom:null;
    }

    private static function cells(DOMXPath $xp,DOMNode $row): array {
        $out=[];foreach($xp->query('./th|./td',$row) as $cell)$out[]=trim(preg_replace('/\s+/u',' ',(string)$cell->textContent));return $out;
    }

    private static function norm(string $value): string {
        $value=html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8');$value=function_exists('remove_accents')?remove_accents($value):strtr($value,['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ż'=>'z','ź'=>'z','Ą'=>'a','Ć'=>'c','Ę'=>'e','Ł'=>'l','Ń'=>'n','Ó'=>'o','Ś'=>'s','Ż'=>'z','Ź'=>'z']);return trim(preg_replace('/[^a-z0-9]+/',' ',strtolower($value)));
    }

    private static function name_score(string $name,string $haystack): float {
        $tokens=array_values(array_filter(explode(' ',self::norm($name)),static fn(string $v): bool=>strlen($v)>2&&!in_array($v,['sa','ssa','ks','mks','azs'],true)));if(!$tokens)return 0;$found=0;foreach($tokens as $token)if(str_contains($haystack,$token))$found++;return $found/count($tokens);
    }

    public static function season_year(string $season): string {
        if(preg_match('/^(20\d{2})\D+(20\d{2})$/',trim($season),$m))return $m[2];
        if(preg_match('/^(20\d{2})\D+(\d{2})$/',trim($season),$m))return substr($m[1],0,2).$m[2];
        if(preg_match('/(20\d{2})/',trim($season),$m))return $m[1];
        return gmdate('Y');
    }

    private static function made_attempted(string $value): array {
        $made=0;$attempted=0;if(preg_match('/(\d+)\s*\/\s*(\d+)/',$value,$m)){$made=(int)$m[1];$attempted=(int)$m[2];}return ['made'=>$made,'attempted'=>$attempted,'percent'=>$attempted?round($made*100/$attempted,1):0.0];
    }
}
