<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__);
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
require dirname(__DIR__) . '/includes/class-dt-live-scores.php';

$html = '<div class="livedesktop"><div class="section-title"><h2>teraz gramy</h2></div><table><tr class="match"><td class="info">1LM</td><td class="matchdate">1 kolejka 18.09.2026 18:00</td><td class="team1">WKK Wrocław</td><td class="result">66 : 80</td><td class="team2">Noteć Inowrocław</td><td class="matchtime">K4 &nbsp;00:50:20</td></tr></table><div class="section-title"><h2>zakończone dzisiaj</h2></div><table><tr class="match"><td class="info">1LM</td><td class="matchdate">18.09.2026 15:00</td><td class="team1">Zakończony</td><td class="result">77 : 66</td><td class="team2">Goście</td><td class="matchtime">K4 &nbsp;00:00:00</td></tr></table></div>';
$items = DT_Live_Scores::parse($html);
check(count($items) === 1, 'Finished matches must not be marked live');
check($items[0]['home_score'] === 66 && $items[0]['away_score'] === 80, 'Read informational score');
check($items[0]['quarter'] === 4 && $items[0]['clock'] === '00:50', 'Read quarter and remaining clock');
check($items[0]['day'] === '2026-09-18', 'Read date for matching the correct fixture');
check(DT_Live_Scores::parse('<div><h2>zakończone dzisiaj</h2><table><tr class="match"><td class="result">7 : 8</td></tr></table></div>') === [], 'No active games means no LIVE badge');
echo "Live score parsing: OK\n";
