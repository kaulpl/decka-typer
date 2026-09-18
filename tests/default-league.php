<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__);
require dirname(__DIR__) . '/includes/class-dt-rest.php';

function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
$select = new ReflectionMethod(DT_REST::class, 'pick_current_round');
$rounds = [
    ['id'=>1, 'league_key'=>'1lm', 'is_open'=>false],
    ['id'=>2, 'league_key'=>'plk', 'is_open'=>true],
    ['id'=>3, 'league_key'=>'1lm', 'is_open'=>false],
];
check($select->invoke(null, $rounds)['id'] === 3, 'Latest visible 1LM round wins over open PLK');
$rounds[0]['is_open'] = true;
check($select->invoke(null, $rounds)['id'] === 1, 'Open 1LM round wins over closed 1LM');
check($select->invoke(null, [['id'=>2, 'league_key'=>'plk', 'is_open'=>true]])['id'] === 2, 'Other leagues available when no 1LM round is visible');
check($select->invoke(null, []) === null, 'No rounds leaves selection empty');
echo "Default 1LM selection: OK\n";
