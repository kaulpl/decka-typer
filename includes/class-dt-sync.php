<?php
if (!defined('ABSPATH')) exit;

class DT_Sync {
    public static function register(): void {
        add_action('dt_sync_schedule', [__CLASS__, 'cron']);
    }

    public static function cron(): void {
        $s = DT_DB::settings();
        if (!empty($s['sync_enabled'])) self::run(false);
    }

    public static function run(bool $manual = false): array {
        $s = DT_DB::settings();
        $url = esc_url_raw($s['source_url']);
        $out = ['ok'=>false, 'rounds'=>0, 'matches_new'=>0, 'matches_updated'=>0, 'matches_skipped'=>0, 'scores'=>0, 'warnings'=>[]];
        if (!$url) return array_merge($out, ['error'=>'Brak adresu źródłowego.']);

        $response = wp_remote_get($url, [
            'timeout' => 25,
            'redirection' => 5,
            'headers' => ['User-Agent'=>'DeckaTyper/' . DT_VERSION . ' (+'.home_url('/').')'],
        ]);
        if (is_wp_error($response)) {
            DT_Logger::log('sync_error', $response->get_error_message(), ['url'=>$url], 'error');
            return array_merge($out, ['error'=>$response->get_error_message()]);
        }
        $code = wp_remote_retrieve_response_code($response);
        $html = wp_remote_retrieve_body($response);
        if ($code !== 200 || strlen($html) < 500) {
            $msg = 'Źródło 1LM zwróciło HTTP ' . $code . '.';
            DT_Logger::log('sync_error', $msg, ['url'=>$url,'bytes'=>strlen($html)], 'error');
            return array_merge($out, ['error'=>$msg]);
        }

        $parsed = self::parse_schedule($html, (string)$s['season'], $url);
        if (!$parsed['matches']) {
            $msg = 'Nie udało się rozpoznać meczów w terminarzu. Synchronizacja przerwana bez zmian.';
            DT_Logger::log('sync_parse_empty', $msg, ['warnings'=>$parsed['warnings']], 'error');
            return array_merge($out, ['error'=>$msg, 'warnings'=>$parsed['warnings']]);
        }

        $out = array_merge($out, self::persist($parsed['matches'], (string)$s['season']));
        $out['warnings'] = array_values(array_unique(array_merge($out['warnings'], $parsed['warnings'])));
        $out['ok'] = true;
        update_option('dt_last_sync', ['at'=>current_time('mysql'), 'result'=>$out]);
        DT_Logger::log('sync_complete', 'Synchronizacja terminarza zakończona.', $out, $manual ? 'notice' : 'info');
        return $out;
    }

    public static function parse_schedule(string $html, string $season, string $base_url): array {
        $matches = [];
        $warnings = [];
        if (!class_exists('DOMDocument')) {
            return self::parse_schedule_regex($html, $season, $base_url);
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) return ['matches'=>[], 'warnings'=>['Nie można zbudować dokumentu DOM.']];
        $xp = new DOMXPath($dom);

        $nodes = $xp->query("//*[.//a[contains(@href,'/druzyny/') or contains(@href,'/druzyny/d/')]]");
        $seen = [];
        foreach ($nodes as $node) {
            $teamAnchors = $xp->query(".//a[contains(@href,'/druzyny/') or contains(@href,'/druzyny/d/')]", $node);
            $unique = [];
            foreach ($teamAnchors as $a) {
                $name = trim(preg_replace('/\s+/u', ' ', $a->textContent));
                $href = trim($a->getAttribute('href'));
                if ($name !== '' && !isset($unique[$href ?: $name])) $unique[$href ?: $name] = $a;
            }
            if (count($unique) !== 2) continue;

            $minimal = true;
            foreach ($node->childNodes as $child) {
                if (!($child instanceof DOMElement)) continue;
                $ca = $xp->query(".//a[contains(@href,'/druzyny/') or contains(@href,'/druzyny/d/')]", $child);
                if ($ca->length >= 2 && self::extract_date_time($child->textContent)) { $minimal = false; break; }
            }
            if (!$minimal) continue;

            $dt = self::extract_date_time($node->textContent);
            if (!$dt) continue;
            $round = self::find_round_number($node);
            if (!$round) continue;

            $a = array_values($unique);
            $home = self::team_from_anchor($a[0], $base_url);
            $away = self::team_from_anchor($a[1], $base_url);
            if (!$home['name'] || !$away['name'] || $home['name'] === $away['name']) continue;

            $score = self::extract_score($node, $xp);
            $matchUrl = self::extract_match_url($node, $xp, $base_url);
            $key = sha1($season . '|' . $round . '|' . self::norm($home['name']) . '|' . self::norm($away['name']));
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            $matches[] = [
                'season'=>$season,
                'round_no'=>$round,
                'home'=>$home,
                'away'=>$away,
                'starts_at'=>$dt['mysql'],
                'start_time_known'=>$dt['time_known'] ? 1 : 0,
                'score_home'=>$score ? $score[0] : null,
                'score_away'=>$score ? $score[1] : null,
                'status'=>$score ? 'finished' : 'scheduled',
                'source_url'=>$matchUrl ?: $base_url,
                'external_key'=>$key,
            ];
        }

        if (count($matches) < 8) {
            foreach ($xp->query('//tr|//li|//article') as $node) {
                $round = self::find_round_number($node);
                if (!$round) continue;
                $teamAnchors = $xp->query(".//a[contains(@href,'/druzyny/') or contains(@href,'/druzyny/d/')]", $node);
                if ($teamAnchors->length < 2) continue;
                $dt = self::extract_date_time($node->textContent);
                if (!$dt) continue;
                $home = self::team_from_anchor($teamAnchors->item(0), $base_url);
                $away = self::team_from_anchor($teamAnchors->item(1), $base_url);
                $key = sha1($season . '|' . $round . '|' . self::norm($home['name']) . '|' . self::norm($away['name']));
                if (isset($seen[$key])) continue;
                $score = self::extract_score($node, $xp);
                $seen[$key] = true;
                $matches[] = [
                    'season'=>$season,'round_no'=>$round,'home'=>$home,'away'=>$away,
                    'starts_at'=>$dt['mysql'],'start_time_known'=>$dt['time_known']?1:0,
                    'score_home'=>$score?$score[0]:null,'score_away'=>$score?$score[1]:null,
                    'status'=>$score?'finished':'scheduled','source_url'=>$base_url,'external_key'=>$key,
                ];
            }
        }

        usort($matches, static fn($a,$b) => [$a['round_no'],$a['starts_at'],$a['home']['name']] <=> [$b['round_no'],$b['starts_at'],$b['home']['name']]);
        if (count($matches) < 50) $warnings[] = 'Parser odczytał tylko ' . count($matches) . ' meczów; sprawdź podgląd po synchronizacji.';
        return ['matches'=>$matches, 'warnings'=>$warnings];
    }

    private static function parse_schedule_regex(string $html, string $season, string $base_url): array {
        $matches=[]; $warnings=['Serwer nie ma rozszerzenia DOM; użyto zapasowego parsera HTML.'];
        $parts=preg_split('~<h[1-6][^>]*>.*?(\d{1,2})\s*kolejka.*?</h[1-6]>~isu',$html,-1,PREG_SPLIT_DELIM_CAPTURE);
        if (!$parts || count($parts)<3) return ['matches'=>[], 'warnings'=>array_merge($warnings,['Nie znaleziono nagłówków kolejek.'])];
        for($i=1;$i<count($parts)-1;$i+=2){
            $round=(int)$parts[$i]; $section=$parts[$i+1];
            $rows=[];
            if(preg_match_all('~<tr\b[^>]*>.*?</tr>~isu',$section,$rm)) $rows=$rm[0];
            if(!$rows && preg_match_all('~<(?:li|article)\b[^>]*>.*?</(?:li|article)>~isu',$section,$rm)) $rows=$rm[0];
            if(!$rows){
                preg_match_all('~<a\b[^>]*href=["\']([^"\']*druzyny[^"\']*)["\'][^>]*>(.*?)</a>~isu',$section,$am,PREG_OFFSET_CAPTURE);
                for($j=0;$j+1<count($am[0]);$j+=2){
                    $start=$am[0][$j][1];
                    $end=($j+2<count($am[0]))?$am[0][$j+2][1]:strlen($section);
                    $rows[]=substr($section,$start,$end-$start);
                }
            }
            foreach($rows as $row){
                if(!preg_match_all('~<a\b[^>]*href=["\']([^"\']*druzyny[^"\']*)["\'][^>]*>(.*?)</a>~isu',$row,$tm,PREG_SET_ORDER) || count($tm)<2) continue;
                $cleanName=static function($v){ return trim(preg_replace('/\s+/u',' ',html_entity_decode(strip_tags($v),ENT_QUOTES|ENT_HTML5,'UTF-8'))); };
                $home=['name'=>$cleanName($tm[0][2]),'external_id'=>'','source_url'=>self::absolute_url($tm[0][1],$base_url),'logo_url'=>''];
                $away=['name'=>$cleanName($tm[1][2]),'external_id'=>'','source_url'=>self::absolute_url($tm[1][1],$base_url),'logo_url'=>''];
                if(preg_match('~/d/(\d+)/~',$tm[0][1],$x))$home['external_id']=$x[1];
                if(preg_match('~/d/(\d+)/~',$tm[1][1],$x))$away['external_id']=$x[1];
                if(!$home['name']||!$away['name'])continue;
                $text=html_entity_decode(strip_tags(preg_replace('~<(?:br|/p|/td|/div)>~i',' ', $row)),ENT_QUOTES|ENT_HTML5,'UTF-8');
                $dt=self::extract_date_time($text); if(!$dt)continue;
                $score=null;
                if(preg_match_all('/\b(\d{2,3})\s*:\s*(\d{2,3})\b/',$text,$sm,PREG_SET_ORDER)){
                    foreach($sm as $x){$a=(int)$x[1];$b=(int)$x[2];if($a>=20&&$b>=20){$score=[$a,$b];break;}}
                }
                $key=sha1($season.'|'.$round.'|'.self::norm($home['name']).'|'.self::norm($away['name']));
                $matches[$key]=['season'=>$season,'round_no'=>$round,'home'=>$home,'away'=>$away,'starts_at'=>$dt['mysql'],'start_time_known'=>$dt['time_known']?1:0,'score_home'=>$score?$score[0]:null,'score_away'=>$score?$score[1]:null,'status'=>$score?'finished':'scheduled','source_url'=>$base_url,'external_key'=>$key];
            }
        }
        $matches=array_values($matches);
        usort($matches,static fn($a,$b)=>[$a['round_no'],$a['starts_at'],$a['home']['name']]<=>[$b['round_no'],$b['starts_at'],$b['home']['name']]);
        if(count($matches)<8)$warnings[]='Zapasowy parser odczytał tylko '.count($matches).' meczów.';
        return ['matches'=>$matches,'warnings'=>$warnings];
    }

    private static function find_round_number(DOMNode $node): int {
        $cursor = $node;
        for ($depth=0; $depth<8 && $cursor; $depth++) {
            for ($sib=$cursor->previousSibling, $steps=0; $sib && $steps<60; $sib=$sib->previousSibling, $steps++) {
                $text = trim(preg_replace('/\s+/u', ' ', $sib->textContent ?? ''));
                if (preg_match('/(?:^|\s)(\d{1,2})\s*kolejka(?:\s|$)/iu', $text, $m)) return (int)$m[1];
            }
            $cursor = $cursor->parentNode;
        }
        $text = trim(preg_replace('/\s+/u', ' ', $node->textContent ?? ''));
        if (preg_match('/(?:^|\s)(\d{1,2})\s*kolejka(?:\s|$)/iu', $text, $m)) return (int)$m[1];
        return 0;
    }

    private static function extract_date_time(string $text): ?array {
        $text = preg_replace('/\s+/u', ' ', $text);
        if (preg_match('/\b(\d{1,2})\.(\d{1,2})\.(20\d{2})(?:\s*(?:,|-)??\s*(?:godz\.?\s*)?(\d{1,2}):(\d{2}))?/u', $text, $m)) {
            $h = isset($m[4]) && $m[4] !== '' ? (int)$m[4] : 0;
            $i = isset($m[5]) && $m[5] !== '' ? (int)$m[5] : 0;
            $known = isset($m[4]) && $m[4] !== '';
            return ['mysql'=>sprintf('%04d-%02d-%02d %02d:%02d:00',(int)$m[3],(int)$m[2],(int)$m[1],$h,$i),'time_known'=>$known];
        }
        if (preg_match('/\b(20\d{2})-(\d{2})-(\d{2})\s+(\d{1,2}):(\d{2})\b/u', $text, $m)) {
            return ['mysql'=>sprintf('%04d-%02d-%02d %02d:%02d:00',(int)$m[1],(int)$m[2],(int)$m[3],(int)$m[4],(int)$m[5]),'time_known'=>true];
        }
        return null;
    }

    private static function extract_score(DOMNode $node, DOMXPath $xp): ?array {
        foreach ($xp->query('.//a|.//span|.//strong', $node) as $el) {
            $t = trim($el->textContent);
            if (preg_match('/^(\d{1,3})\s*:\s*(\d{1,3})$/', $t, $m)) {
                $a=(int)$m[1]; $b=(int)$m[2];
                if ($a >= 20 && $b >= 20) return [$a,$b];
            }
        }
        $text = preg_replace('/\s+/u',' ', $node->textContent);
        if (preg_match_all('/\b(\d{2,3})\s*:\s*(\d{2,3})\b/', $text, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $a=(int)$m[1]; $b=(int)$m[2];
                if ($a >= 20 && $b >= 20) return [$a,$b];
            }
        }
        return null;
    }

    private static function extract_match_url(DOMNode $node, DOMXPath $xp, string $base): string {
        foreach ($xp->query('.//a[@href]', $node) as $a) {
            $href = $a->getAttribute('href');
            $text = trim($a->textContent);
            if (preg_match('/^(?:--:--|\d{1,3}\s*:\s*\d{1,3})$/', $text) || preg_match('~/mecz|/game|/spotkanie~i', $href)) {
                return self::absolute_url($href, $base);
            }
        }
        return '';
    }

    private static function team_from_anchor(DOMElement $a, string $base): array {
        $name = trim(preg_replace('/\s+/u',' ', $a->textContent));
        $href = self::absolute_url($a->getAttribute('href'), $base);
        $external = $a->getAttribute('href');
        $id = '';
        if (preg_match('~/d/(\d+)/~', $external, $m)) $id = $m[1];
        $logo = '';
        foreach ($a->getElementsByTagName('img') as $img) {
            $src = $img->getAttribute('src') ?: $img->getAttribute('data-src');
            if ($src) { $logo = self::absolute_url($src, $base); break; }
        }
        return ['name'=>$name,'external_id'=>$id,'source_url'=>$href,'logo_url'=>$logo];
    }

    private static function absolute_url(string $href, string $base): string {
        if (!$href) return '';
        if (preg_match('~^https?://~i', $href)) return esc_url_raw($href);
        $p = wp_parse_url($base);
        if (!$p || empty($p['host'])) return esc_url_raw($href);
        $scheme = $p['scheme'] ?? 'https';
        if (str_starts_with($href, '//')) return esc_url_raw($scheme . ':' . $href);
        if (str_starts_with($href, '/')) return esc_url_raw($scheme . '://' . $p['host'] . $href);
        $path = isset($p['path']) ? dirname($p['path']) : '';
        return esc_url_raw($scheme . '://' . $p['host'] . rtrim($path,'/') . '/' . ltrim($href,'/'));
    }

    private static function norm(string $s): string {
        $s = strtolower(remove_accents(trim($s)));
        return preg_replace('/[^a-z0-9]+/', '-', $s);
    }

    private static function persist(array $matches, string $season): array {
        global $wpdb;
        $now = current_time('mysql');
        $out = ['rounds'=>0,'matches_new'=>0,'matches_updated'=>0,'matches_skipped'=>0,'scores'=>0,'warnings'=>[]];
        $roundIds = [];

        foreach ($matches as $m) {
            $roundNo = (int)$m['round_no'];
            if (!isset($roundIds[$roundNo])) {
                $roundTable = DT_DB::table('rounds');
                $round = $wpdb->get_row($wpdb->prepare("SELECT * FROM $roundTable WHERE season=%s AND round_no=%d", $season, $roundNo));
                if (!$round) {
                    $wpdb->insert($roundTable, [
                        'season'=>$season,'round_no'=>$roundNo,'title'=>$roundNo . '. kolejka','status'=>'published','source'=>'1lm',
                        'external_key'=>sha1($season.'|'.$roundNo),'last_synced_at'=>$now,'created_at'=>$now,'updated_at'=>$now,
                    ], ['%s','%d','%s','%s','%s','%s','%s','%s','%s']);
                    $roundId=(int)$wpdb->insert_id;
                    $out['rounds']++;
                } else {
                    $roundId=(int)$round->id;
                    $wpdb->update($roundTable, ['last_synced_at'=>$now,'updated_at'=>$now], ['id'=>$roundId], ['%s','%s'], ['%d']);
                }
                $roundIds[$roundNo]=$roundId;
            }

            $homeId = self::upsert_team($m['home']);
            $awayId = self::upsert_team($m['away']);
            if (!$homeId || !$awayId) { $out['warnings'][]='Pominięto mecz z nierozpoznaną drużyną.'; continue; }

            $table = DT_DB::table('matches');
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE external_key=%s", $m['external_key']));
            $hash = sha1(wp_json_encode([$homeId,$awayId,$m['starts_at'],$m['score_home'],$m['score_away'],$m['status']]));
            if ($existing && (int)$existing->manual_lock === 1) {
                $wpdb->update($table, ['last_synced_at'=>$now], ['id'=>(int)$existing->id], ['%s'], ['%d']);
                $out['matches_skipped']++;
                continue;
            }
            $startsAt=$m['starts_at'];
            if(!(int)$m['start_time_known'] && $startsAt){
                $settings=DT_DB::settings();
                $fallback=preg_match('/^\d{2}:\d{2}$/',(string)$settings['unknown_time_lock']) ? $settings['unknown_time_lock'] : '00:00';
                $startsAt=substr($startsAt,0,10).' '.$fallback.':00';
            }
            $data = [
                'round_id'=>$roundIds[$roundNo], 'external_key'=>$m['external_key'], 'source_url'=>$m['source_url'],
                'home_team_id'=>$homeId,'away_team_id'=>$awayId,'starts_at'=>$startsAt,'start_time_known'=>(int)$m['start_time_known'],
                'score_home'=>$m['score_home'],'score_away'=>$m['score_away'],'status'=>$m['status'],'source_hash'=>$hash,
                'last_synced_at'=>$now,'updated_at'=>$now,
            ];
            if (!$existing) {
                $data['created_at']=$now;
                $wpdb->insert($table, $data);
                $matchId=(int)$wpdb->insert_id;
                $out['matches_new']++;
            } else {
                $matchId=(int)$existing->id;
                if ($existing->source_hash !== $hash) {
                    $wpdb->update($table, $data, ['id'=>$matchId]);
                    $out['matches_updated']++;
                } else {
                    $wpdb->update($table, ['last_synced_at'=>$now], ['id'=>$matchId]);
                }
            }
            if ($m['score_home'] !== null && $m['score_away'] !== null) {
                $out['scores'] += DT_Scoring::recalc_match($matchId);
            }
        }
        return $out;
    }

    private static function upsert_team(array $team): int {
        global $wpdb;
        $table = DT_DB::table('teams');
        $name = sanitize_text_field((string)$team['name']);
        $slug = sanitize_title($name);
        $row = null;
        if (!empty($team['external_id'])) $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE external_id=%s", $team['external_id']));
        if (!$row) $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE slug=%s", $slug));
        $now=current_time('mysql');
        $data=['name'=>$name,'slug'=>$slug,'external_id'=>$team['external_id']?:null,'source_url'=>esc_url_raw($team['source_url']?:''),'updated_at'=>$now];
        if (!empty($team['logo_url'])) $data['logo_url']=$team['logo_url'];
        if ($row) {
            $wpdb->update($table,$data,['id'=>(int)$row->id]);
            return (int)$row->id;
        }
        $data['short_name']=self::short_team_name($name);
        $data['created_at']=$now;
        $wpdb->insert($table,$data);
        return (int)$wpdb->insert_id;
    }

    private static function short_team_name(string $name): string {
        $parts=preg_split('/\s+/u', trim($name));
        if (count($parts)<=2) return $name;
        return implode(' ', array_slice($parts,-2));
    }
}
