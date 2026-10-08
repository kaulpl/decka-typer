<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__);
require dirname(__DIR__) . '/includes/class-dt-admin.php';
function check($value, $message) { if (!$value) throw new RuntimeException($message); }
$state = new ReflectionMethod(DT_Admin::class, 'artur_pick_state');
check($state->invoke(null, 10, 10, 20, 84, 76) === 'hit', 'Home winner hit');
check($state->invoke(null, 10, 10, 20, 70, 78) === 'miss', 'Away result misses home pick');
check($state->invoke(null, 20, 10, 20, 70, 78) === 'hit', 'Away winner hit');
check($state->invoke(null, 20, 10, 20, null, null) === 'pending', 'Unresolved game waits');
check($state->invoke(null, 0, 10, 20, 70, 78) === 'missing', 'Missing prediction');
echo "Admin Artur dashboard: OK\n";
