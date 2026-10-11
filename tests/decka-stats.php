<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__);
function check($value, $message) { if (!$value) throw new RuntimeException($message); }
require dirname(__DIR__) . '/includes/class-dt-decka-stats.php';

$data = [
    'tm' => [[
        'name' => 'Decka Pelplin',
        'tot_sPoints' => 81,
        'tot_sTwoPointersMade' => 20,
        'tot_sTwoPointersAttempted' => 34,
        'tot_sThreePointersMade' => 9,
        'tot_sThreePointersAttempted' => 25,
        'tot_sFieldGoalsMade' => 29,
        'tot_sFieldGoalsAttempted' => 59,
        'tot_sFreeThrowsMade' => 14,
        'tot_sFreeThrowsAttempted' => 17,
        'tot_sReboundsTotal' => 37,
        'tot_sAssists' => 19,
        'tot_sSteals' => 8,
        'tot_sTurnovers' => 11,
        'tot_sBlocks' => 3,
        'tot_eff_1' => 96,
        'pl' => [
            ['shirtNumber'=>'7','name'=>'Jan Pierwszy','sPoints'=>21,'sReboundsTotal'=>6,'sAssists'=>5,'eff_1'=>27],
            ['shirtNumber'=>'12','name'=>'Adam Drugi','sPoints'=>18,'sReboundsTotal'=>8,'sAssists'=>2,'eff_1'=>24],
            ['shirtNumber'=>'4','name'=>'Piotr Trzeci','sPoints'=>9,'sReboundsTotal'=>3,'sAssists'=>7,'eff_1'=>19],
            ['shirtNumber'=>'9','name'=>'Rezerwowy','sPoints'=>11,'sReboundsTotal'=>2,'sAssists'=>1,'eff_1'=>10],
        ],
        'shot' => [
            ['actionNumber'=>101,'x'=>25.5,'y'=>72.0,'p'=>2],
            ['actionNumber'=>102,'x'=>81.0,'y'=>38.5,'p'=>3],
        ],
    ]],
    'pbp' => [
        ['actionNumber'=>101,'success'=>1],
        ['actionNumber'=>102,'success'=>0],
    ],
];

$stats = DT_Decka_Stats::parse_livestats($data);
check(is_array($stats) && $stats['source'] === 'livestats', 'Recognize Decka in the LiveStats feed');
check($stats['team']['two'] === ['made'=>20,'attempted'=>34,'percent'=>58.8], 'Calculate 2PT percentage');
check($stats['team']['field']['made'] === 29 && $stats['team']['reb'] === 37, 'Read team box score');
check(count($stats['leaders']) === 3 && $stats['leaders'][0]['name'] === 'Jan Pierwszy', 'Select the top three players by EVAL');
check($stats['shots'][0]['x'] === 25.5 && $stats['shots'][0]['made'] === 1, 'Match a made shot to play-by-play');
check($stats['shots'][1]['points'] === 3 && $stats['shots'][1]['made'] === 0, 'Match a missed three-pointer');
check(DT_Decka_Stats::parse_livestats(['tm'=>[['name'=>'Inny klub']]]) === null, 'Reject feeds without Decka');

$admin=file_get_contents(dirname(__DIR__).'/includes/class-dt-admin.php');
$graphics=file_get_contents(dirname(__DIR__).'/assets/js/admin-artur-graphics.js');
check(str_contains($admin,'data-dt-decka-stat-graphic') && str_contains($admin,'data-dt-livestats-url'), 'Expose match and optional LiveStats controls');
check(str_contains($graphics,'MAPA RZUTÓW DECKI') && str_contains($graphics,'3 NAJLEPSZYCH ZAWODNIKÓW'), 'Render shot map and player leaders');
check(str_contains($graphics,'liveStatsPayload') && str_contains($graphics,'payload.browser_fetch'), 'Fall back to a browser-side LiveStats request when the host is blocked');
$class=file_get_contents(dirname(__DIR__).'/includes/class-dt-decka-stats.php');
check(str_contains($class,"get_option('dt_livestats_match_ids'") && str_contains($class,"'browser_fetch'=>true"), 'Remember a supplied LiveStats ID and return browser fallback metadata');
echo "Decka match statistics: OK\n";
