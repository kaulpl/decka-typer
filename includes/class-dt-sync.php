<?php
if (!defined('ABSPATH')) exit;

class DT_Sync {
    public static function register(): void {
        add_action('dt_sync_schedule', [__CLASS__, 'cron']);
    }

    public static function cron(): void {
        $settings = DT_DB::settings();
        if (!empty($settings['sync_enabled'])) self::run(false);
    }

    public static function run(bool $manual = false): array {
        $settings = DT_DB::settings();
        $url = esc_url_raw((string) $settings['source_url']);
        $out = ['ok'=>false, 'rounds'=>0, 'matches_new'=>0, 'matches_updated'=>0, 'matches_skipped'=>0, 'scores'=>0, 'warnings'=>[]];
        if (!$url) return array_merge($out, ['error'=>'Brak adresu źródłowego.']);

        $response = wp_remote_get($url, [
            'timeout'=>25,
            'redirection'=>5,
            'headers'=>['User-Agent'=>'DeckaTyper/' . DT_VERSION . ' (+' . home_url('/') . ')'],
        ]);
        if (is_wp_error($response)) {
            DT_Logger::log('sync_error', $response->get_error_message(), ['url'=>$url], 'error');
            return array_merge($out, ['error'=>$response->get_error_message()]);
        }
        $code = wp_remote_retrieve_response_code($response);
        $html = wp_remote_retrieve_body($response);
        if ($code !== 200 || strlen($html) < 500) {
            $message = 'Źródło 1LM zwróciło HTTP ' . $code . '.';
            DT_Logger::log('sync_error', $message, ['url'=>$url, 'bytes'=>strlen($html)], 'error');
            return array_merge($out, ['error'=>$message]);
        }

        $parsed = self::parse_schedule($html, (string) $settings['season'], $url);
        if (!$parsed['matches']) {
            $message = 'Nie udało się rozpoznać meczów w terminarzu. Synchronizacja przerwana bez zmian.';
            DT_Logger::log('sync_parse_empty', $message, ['warnings'=>$parsed['warnings']], 'error');
            return array_merge($out, ['error'=>$message, 'warnings'=>$parsed['warnings']]);
        }

        $out = array_merge($out, self::persist($parsed['matches'], (string) $settings['season']));
        $out['warnings'] = array_values(array_unique(array_merge($out['warnings'], $parsed['warnings'])));
        $out['ok'] = true;
        update_option('dt_last_sync', ['at'=>current_time('mysql'), 'result'=>$out]);
        DT_Logger::log('sync_complete', 'Synchronizacja terminarza zakończona.', $out, $manual ? 'notice' : 'info');
        return $out;
    }

    public static function parse_schedule(string $html, string $season, string $baseUrl): array {
        if (!class_exists('DOMDocument')) return self::parse_schedule_regex($html, $season, $baseUrl);

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) return ['matches'=>[], 'warnings'=>['Nie można zbudować dokumentu DOM.']];

        $xp = new DOMXPath($dom);
        $matches = [];
        $warnings = [];
        $seen = [];

        // The official schedule is table-based. Parse complete match rows first so the
        // Emocje TV stream time (usually 5 minutes earlier) cannot be mistaken for tip-off.
        $candidates = [];
        foreach ($xp->query('//tr') as $node) $candidates[] = $node;
        if (count($candidates) < 8) {
            foreach ($xp->query('//li|//article|//*[contains(@class,"match") or contains(@class,"mecz")]') as $node) $candidates[] = $node;
        }

        foreach ($candidates as $node) {
            $parsed = self::parse_match_node($node, $xp, $season, $baseUrl);
            if (!$parsed) continue;
            if (isset($seen[$parsed['external_key']])) continue;
            $seen[$parsed['external_key']] = true;
            $matches[] = $parsed;
        }

        // Fallback for layouts where the row wrapper was not captured: inspect elements
        // containing exactly two team links, but require the official human-readable date.
        if (count($matches) < 8) {
            foreach ($xp->query("//*[.//a[contains(@href,'/druzyny/')]]") as $node) {
                $parsed = self::parse_match_node($node, $xp, $season, $baseUrl, true);
                if (!$parsed || isset($seen[$parsed['external_key']])) continue;
                $seen[$parsed['external_key']] = true;
                $matches[] = $parsed;
            }
        }

        usort($matches, static fn($a,$b)=>[$a['round_no'],$a['starts_at'],$a['home']['name']] <=> [$b['round_no'],$b['starts_at'],$b['home']['name']]);
        if (count($matches) < 50) $warnings[] = 'Parser odczytał tylko ' . count($matches) . ' meczów; sprawdź podgląd po synchronizacji.';
        return ['matches'=>$matches, 'warnings'=>$warnings];
    }

    private static function parse_match_node(DOMNode $node, DOMXPath $xp, string $season, string $baseUrl, bool $strictMinimal = false): ?array {
        $teamAnchors = $xp->query(".//a[contains(@href,'/druzyny/')]", $node);
        $unique = [];
        foreach ($teamAnchors as $anchor) {
            $name = trim(preg_replace('/\\s+/u', ' ', $anchor->textContent));
            if ($name === '') continue;
            $href = trim($anchor->getAttribute('href'));
            $key = $href ?: self::norm($name);
            if (!isset($unique[$key])) $unique[$key] = $anchor;
        }
        if (count($unique) !== 2) return null;
        if ($strictMinimal && strlen(trim((string) $node->textContent)) > 1500) return null;

        $round = self::find_round_number($node);
        if (!$round) return null;
        $dateTime = self::extract_official_date_time((string) $node->textContent);
        if (!$dateTime) return null;

        $anchors = array_values($unique);
        $home = self::team_from_anchor($anchors[0], $xp, $baseUrl);
        $away = self::team_from_anchor($anchors[1], $xp, $baseUrl);
        if (!$home['name'] || !$away['name'] || self::norm($home['name']) === self::norm($away['name'])) return null;

        $score = self::extract_score($node, $xp);
        $key = sha1($season . '|' . $round . '|' . self::norm($home['name']) . '|' . self::norm($away['name']));
        return [
            'season'=>$season,
            'round_no'=>$round,
            'home'=>$home,
            'away'=>$away,
            'starts_at'=>$dateTime['mysql'],
            'start_time_known'=>$dateTime['time_known'] ? 1 : 0,
            'score_home'=>$score ? $score[0] : null,
            'score_away'=>$score ? $score[1] : null,
            'status'=>$score ? 'finished' : 'scheduled',
            'source_url'=>$baseUrl,
            'external_key'=>$key,
        ];
    }

    private static function extract_official_date_time(string $text): ?array {
        $text = trim(preg_replace('/\\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        // 1LM exposes the stream start as ISO, e.g. "2026-09-21 18:55", and the
        // actual match start separately as "21.09.2026 19:00". Always prefer the latter.
        if (preg_match('/\\b(\\d{1,2})\\.(\\d{1,2})\\.(20\\d{2})(?:\\s*(?:,|\\|)?\\s*(?:godz\\.?\\s*)?(\\d{1,2}):(\\d{2}))?/u', $text, $m)) {
            $known = isset($m[4]) && $m[4] !== '';
            $hour = $known ? (int) $m[4] : 0;
            $minute = $known ? (int) $m[5] : 0;
            return [
                'mysql'=>sprintf('%04d-%02d-%02d %02d:%02d:00', (int) $m[3], (int) $m[2], (int) $m[1], $hour, $minute),
                'time_known'=>$known,
            ];
        }

        // ISO is used only when there is no Emocje TV marker. Otherwise it is very
        // likely the broadcast start, not the official tip-off.
        if (stripos($text, 'Emocje TV') === false && preg_match('/\\b(20\\d{2})-(\\d{2})-(\\d{2})\\s+(\\d{1,2}):(\\d{2})\\b/u', $text, $m)) {
            return [
                'mysql'=>sprintf('%04d-%02d-%02d %02d:%02d:00', (int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5]),
                'time_known'=>true,
            ];
        }
        return null;
    }

    private static function extract_score(DOMNode $node, DOMXPath $xp): ?array {
        foreach ($xp->query('.//a|.//span|.//strong|.//td', $node) as $element) {
            $text = trim($element->textContent);
            if (preg_match('/^(\\d{2,3})\\s*:\\s*(\\d{2,3})$/', $text, $m)) {
                $home = (int) $m[1];
                $away = (int) $m[2];
                if ($home >= 20 && $away >= 20 && $home <= 250 && $away <= 250) return [$home, $away];
            }
        }
        $text = preg_replace('/\\s+/u', ' ', (string) $node->textContent);
        if (preg_match_all('/\\b(\\d{2,3})\\s*:\\s*(\\d{2,3})\\b/', $text, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                $home = (int) $m[1];
                $away = (int) $m[2];
                if ($home >= 20 && $away >= 20 && $home <= 250 && $away <= 250) return [$home, $away];
            }
        }
        return null;
    }

    private static function find_round_number(DOMNode $node): int {
        $cursor = $node;
        for ($depth=0; $depth<9 && $cursor; $depth++) {
            for ($sibling=$cursor->previousSibling, $steps=0; $sibling && $steps<80; $sibling=$sibling->previousSibling, $steps++) {
                $text = trim(preg_replace('/\\s+/u', ' ', $sibling->textContent ?? ''));
                if (preg_match('/(?:^|\\s)(\\d{1,2})\\s*kolejka(?:\\s|$)/iu', $text, $m)) return (int) $m[1];
            }
            $cursor = $cursor->parentNode;
        }
        $text = trim(preg_replace('/\\s+/u', ' ', $node->textContent ?? ''));
        if (preg_match('/(?:^|\\s)(\\d{1,2})\\s*kolejka(?:\\s|$)/iu', $text, $m)) return (int) $m[1];
        return 0;
    }

    private static function team_from_anchor(DOMElement $anchor, DOMXPath $xp, string $baseUrl): array {
        $name = trim(preg_replace('/\\s+/u', ' ', $anchor->textContent));
        $href = trim($anchor->getAttribute('href'));
        $externalId = '';
        if (preg_match('~/d/(\\d+)(?:/|$)~', $href, $m)) $externalId = $m[1];
        elseif (preg_match('~[?&](?:id|team)=(\\d+)~', $href, $m)) $externalId = $m[1];

        $logo = '';
        $img = $xp->query('.//img', $anchor)->item(0);
        if ($img instanceof DOMElement) {
            $src = $img->getAttribute('data-src') ?: $img->getAttribute('src');
            if ($src) $logo = self::absolute_url($src, $baseUrl);
        }
        return [
            'name'=>$name,
            'external_id'=>$externalId,
            'source_url'=>self::absolute_url($href, $baseUrl),
            'logo_url'=>$logo,
        ];
    }

    private static function parse_schedule_regex(string $html, string $season, string $baseUrl): array {
        $matches = [];
        $warnings = ['Serwer nie ma rozszerzenia DOM; użyto zapasowego parsera HTML.'];
        $parts = preg_split('~<h[1-6][^>]*>.*?(\\d{1,2})\\s*kolejka.*?</h[1-6]>~isu', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!$parts || count($parts) < 3) return ['matches'=>[], 'warnings'=>array_merge($warnings, ['Nie znaleziono nagłówków kolejek.'])];

        for ($i=1; $i<count($parts)-1; $i+=2) {
            $round = (int) $parts[$i];
            $section = $parts[$i+1];
            $rows = [];
            if (preg_match_all('~<tr\\b[^>]*>.*?</tr>~isu', $section, $found)) $rows = $found[0];
            foreach ($rows as $row) {
                if (!preg_match_all('~<a\\b[^>]*href=["\\\']([^"\\\']*druzyny[^"\\\']*)["\\\'][^>]*>(.*?)</a>~isu', $row, $teamMatches, PREG_SET_ORDER) || count($teamMatches) < 2) continue;
                $clean = static fn($value)=>trim(preg_replace('/\\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                $home = ['name'=>$clean($teamMatches[0][2]), 'external_id'=>'', 'source_url'=>self::absolute_url($teamMatches[0][1], $baseUrl), 'logo_url'=>''];
                $away = ['name'=>$clean($teamMatches[1][2]), 'external_id'=>'', 'source_url'=>self::absolute_url($teamMatches[1][1], $baseUrl), 'logo_url'=>''];
                $text = html_entity_decode(strip_tags(preg_replace('~<(?:br|/p|/td|/div)>~i', ' ', $row)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $dateTime = self::extract_official_date_time($text);
                if (!$dateTime || !$home['name'] || !$away['name']) continue;
                $score = null;
                if (preg_match_all('/\\b(\\d{2,3})\\s*:\\s*(\\d{2,3})\\b/', $text, $scores, PREG_SET_ORDER)) {
                    foreach ($scores as $s) {
                        if ((int) $s[1] >= 20 && (int) $s[2] >= 20) { $score=[(int) $s[1], (int) $s[2]]; break; }
                    }
                }
                $key = sha1($season . '|' . $round . '|' . self::norm($home['name']) . '|' . self::norm($away['name']));
                $matches[$key] = [
                    'season'=>$season, 'round_no'=>$round, 'home'=>$home, 'away'=>$away,
                    'starts_at'=>$dateTime['mysql'], 'start_time_known'=>$dateTime['time_known'] ? 1 : 0,
                    'score_home'=>$score ? $score[0] : null, 'score_away'=>$score ? $score[1] : null,
                    'status'=>$score ? 'finished' : 'scheduled', 'source_url'=>$baseUrl, 'external_key'=>$key,
                ];
            }
        }
        $matches = array_values($matches);
        usort($matches, static fn($a,$b)=>[$a['round_no'],$a['starts_at'],$a['home']['name']] <=> [$b['round_no'],$b['starts_at'],$b['home']['name']]);
        if (count($matches) < 8) $warnings[] = 'Zapasowy parser odczytał tylko ' . count($matches) . ' meczów.';
        return ['matches'=>$matches, 'warnings'=>$warnings];
    }

    private static function persist(array $matches, string $season): array {
        global $wpdb;
        $now = current_time('mysql');
        $out = ['rounds'=>0, 'matches_new'=>0, 'matches_updated'=>0, 'matches_skipped'=>0, 'scores'=>0, 'warnings'=>[]];
        $roundIds = [];

        foreach ($matches as $match) {
            $roundNo = (int) $match['round_no'];
            if (!isset($roundIds[$roundNo])) {
                $roundTable = DT_DB::table('rounds');
                $round = $wpdb->get_row($wpdb->prepare("SELECT * FROM $roundTable WHERE season=%s AND round_no=%d", $season, $roundNo));
                if (!$round) {
                    $wpdb->insert($roundTable, [
                        'season'=>$season,
                        'round_no'=>$roundNo,
                        'title'=>$roundNo . '. kolejka',
                        'status'=>'draft',
                        'source'=>'1lm',
                        'external_key'=>sha1($season . '|round|' . $roundNo),
                        'last_synced_at'=>$now,
                        'created_at'=>$now,
                        'updated_at'=>$now,
                    ]);
                    $roundId = (int) $wpdb->insert_id;
                    $out['rounds']++;
                } else {
                    $roundId = (int) $round->id;
                    $wpdb->update($roundTable, ['last_synced_at'=>$now], ['id'=>$roundId], ['%s'], ['%d']);
                }
                $roundIds[$roundNo] = $roundId;
            }
            $roundId = $roundIds[$roundNo];

            $homeId = self::upsert_team($match['home'], $now);
            $awayId = self::upsert_team($match['away'], $now);
            if (!$homeId || !$awayId) {
                $out['warnings'][] = 'Pominięto mecz z nierozpoznaną drużyną.';
                continue;
            }

            $table = DT_DB::table('matches');
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE external_key=%s", $match['external_key']));
            if ($existing && (int) $existing->manual_lock === 1) {
                $wpdb->update($table, ['last_synced_at'=>$now], ['id'=>(int) $existing->id], ['%s'], ['%d']);
                $out['matches_skipped']++;
                continue;
            }

            $startsAt = $match['starts_at'];
            $timeKnown = (int) $match['start_time_known'];
            if ($existing && !$timeKnown && (int) $existing->start_time_known === 1) {
                $startsAt = $existing->starts_at;
                $timeKnown = 1;
            }
            $featured = stripos($match['home']['name'], 'Decka Pelplin') !== false || stripos($match['away']['name'], 'Decka Pelplin') !== false ? 1 : 0;
            $hash = sha1(wp_json_encode([$homeId, $awayId, $startsAt, $timeKnown, $match['score_home'], $match['score_away'], $match['status']]));
            $data = [
                'round_id'=>$roundId,
                'source_url'=>$match['source_url'],
                'home_team_id'=>$homeId,
                'away_team_id'=>$awayId,
                'starts_at'=>$startsAt,
                'start_time_known'=>$timeKnown,
                'score_home'=>$match['score_home'],
                'score_away'=>$match['score_away'],
                'status'=>$match['status'],
                'featured'=>$featured,
                'source_hash'=>$hash,
                'last_synced_at'=>$now,
                'updated_at'=>$now,
            ];

            if (!$existing) {
                $data['external_key'] = $match['external_key'];
                $data['manual_lock'] = 0;
                $data['created_at'] = $now;
                $wpdb->insert($table, $data);
                $id = (int) $wpdb->insert_id;
                $out['matches_new']++;
                if ($id && $match['score_home'] !== null && $match['score_away'] !== null) $out['scores'] += DT_Scoring::recalc_match($id);
            } else {
                $changed = $existing->source_hash !== $hash;
                $hadScore = $existing->score_home !== null && $existing->score_away !== null;
                $wpdb->update($table, $data, ['id'=>(int) $existing->id]);
                if ($changed) $out['matches_updated']++;
                if ($match['score_home'] !== null && $match['score_away'] !== null && ($changed || !$hadScore)) {
                    $out['scores'] += DT_Scoring::recalc_match((int) $existing->id);
                }
            }
        }
        return $out;
    }

    private static function upsert_team(array $team, string $now): int {
        global $wpdb;
        $table = DT_DB::table('teams');
        $name = sanitize_text_field((string) ($team['name'] ?? ''));
        if (!$name) return 0;
        $slug = sanitize_title($name);
        $external = sanitize_text_field((string) ($team['external_id'] ?? ''));
        $existing = null;
        if ($external !== '') $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE external_id=%s LIMIT 1", $external));
        if (!$existing) $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE slug=%s LIMIT 1", $slug));

        $data = [
            'external_id'=>$external ?: null,
            'name'=>$name,
            'short_name'=>$name,
            'slug'=>$slug,
            'source_url'=>esc_url_raw((string) ($team['source_url'] ?? '')),
            'updated_at'=>$now,
        ];
        if (!empty($team['logo_url'])) $data['logo_url'] = esc_url_raw((string) $team['logo_url']);
        if ($existing) {
            $wpdb->update($table, $data, ['id'=>(int) $existing->id]);
            return (int) $existing->id;
        }
        $data['logo_url'] = $data['logo_url'] ?? '';
        $data['created_at'] = $now;
        $wpdb->insert($table, $data);
        return (int) $wpdb->insert_id;
    }

    private static function absolute_url(string $url, string $base): string {
        $url = trim($url);
        if ($url === '') return '';
        if (preg_match('~^https?://~i', $url)) return esc_url_raw($url);
        $parts = wp_parse_url($base);
        if (!$parts || empty($parts['host'])) return esc_url_raw($url);
        $scheme = $parts['scheme'] ?? 'https';
        if (str_starts_with($url, '//')) return esc_url_raw($scheme . ':' . $url);
        if (str_starts_with($url, '/')) return esc_url_raw($scheme . '://' . $parts['host'] . $url);
        $path = isset($parts['path']) ? dirname($parts['path']) : '';
        return esc_url_raw($scheme . '://' . $parts['host'] . rtrim($path, '/') . '/' . ltrim($url, '/'));
    }

    private static function norm(string $value): string {
        $value = strtolower(remove_accents(trim($value)));
        return preg_replace('/[^a-z0-9]+/', '-', $value);
    }
}
