<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__);
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
require dirname(__DIR__) . '/includes/class-dt-sync.php';

$current = '{"id":"223466","seasonId":"29","league":{"id":2,"name":"PGE Polska Liga Koszykówki"},"queue":{"id":60435,"name":"1 kolejka"},"homeTeamName":"PGE Start Lublin","guestTeamName":"Stal Ostrów","isFinished":true,"date":"2026-10-03T17:30:00.000Z","dateLocal":"2026-10-03 19:30","quarters":["13:20"],"resultHome":79,"resultGuest":84,"homeTeam":{"id":22,"logoUrl":"home.png"},"guestTeam":{"id":33,"logoUrl":"away.png"}}';
$parsed = DT_Sync::parse_plk_schedule($current, '2026/27', 'https://plk.pl/terminarz');
check(count($parsed['matches']) === 1, 'Current PLK payload should produce a match');
check($parsed['matches'][0]['score_home'] === 79 && $parsed['matches'][0]['score_away'] === 84, 'Current resultHome/resultGuest fields should update the score');
check($parsed['matches'][0]['status'] === 'finished', 'A current PLK result should mark the match finished');

$legacy = '{"id":"123","seasonId":"28","league":{"id":2,"name":"PLK"},"queue":{"id":9,"name":"2 kolejka"},"homeTeamName":"Dom","guestTeamName":"Goście","isFinished":true,"date":"2025-10-03T17:30:00.000Z","dateLocal":"2025-10-03 19:30","homeTeamScore":88,"guestTeamScore":81,"homeTeam":{"id":1,"logoUrl":"h.png"},"guestTeam":{"id":2,"logoUrl":"a.png"}}';
$parsedLegacy = DT_Sync::parse_plk_schedule($legacy, '2025/26', 'https://plk.pl/terminarz');
check($parsedLegacy['matches'][0]['score_home'] === 88 && $parsedLegacy['matches'][0]['score_away'] === 81, 'Legacy score fields must remain supported');
echo "PLK result parsing: OK\n";
