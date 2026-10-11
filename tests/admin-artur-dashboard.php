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
$source=file_get_contents(dirname(__DIR__).'/includes/class-dt-admin.php');
$graphics=file_get_contents(dirname(__DIR__).'/assets/js/admin-artur-graphics.js');
check(str_contains($source,'resolved_count') && str_contains($source,'$nextRounds'), 'Default columns use latest resolved and next round');
check(str_contains($source,'Pobierz podsumowanie 16:9') && str_contains($source,'Pobierz zapowiedź 16:9') && str_contains($source,'Pobierz trafienia BONUS 16:9'), 'All infographic actions are available');
check(str_contains($graphics,'1920') && str_contains($graphics,'1080') && str_contains($graphics,'logoUrl'), 'Infographics are 16:9 and include the TypujKosza logo');
check(str_contains($graphics,"rr(ctx,1360,48,500,118,24,C.white)"), 'TypujKosza logo has a white backing panel');
check(!str_contains($graphics,'Typuje:') && !str_contains($graphics,'1920 × 1080  •'), 'Generated graphics do not contain the old footer');
echo "Admin Artur dashboard: OK\n";
