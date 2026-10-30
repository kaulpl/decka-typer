<?php
if(PHP_SAPI!=='cli')exit;
function verify_scoring($condition,$message){if(!$condition)throw new RuntimeException($message);}
$php=file_get_contents(dirname(__DIR__).'/includes/class-dt-frontend.php');
$js=file_get_contents(dirname(__DIR__).'/assets/js/frontend.js');
$css=file_get_contents(dirname(__DIR__).'/assets/css/frontend.css');
$mobile=file_get_contents(dirname(__DIR__).'/assets/css/mobile-nav.css');
verify_scoring(str_contains($php,'data-scoring-open')&&str_contains($php,'Jak punktujemy?'),'Navigation exposes the scoring information button');
verify_scoring(str_contains($php,"['points_winner']")&&str_contains($php,'DT_Bonus::points()')&&str_contains($php,"['perfect_round_bonus']"),'Modal reads regular, bonus and perfect-round points from settings');
verify_scoring(str_contains($php,"['pre1_hit_points']")&&str_contains($php,"['pre2_perfect_bonus']"),'Modal contains every PRE scoring setting');
verify_scoring(str_contains($php,'$winner+$bonus')&&str_contains($php,'punkty podstawowe i dodatkową premię'),'Bonus total and its additive rule are explicit');
verify_scoring(str_contains($js,'scoringModal?.showModal()')&&str_contains($js,'data-scoring-close'),'Scoring dialog opens and closes without navigation');
verify_scoring(str_contains($css,'.dt-scoring-grid')&&str_contains($css,'.dt-scoring-pre'),'Scoring dialog has dedicated responsive presentation');
verify_scoring(str_contains($mobile,'repeat(5, minmax(0, 1fr))'),'Mobile navigation accommodates the fifth action');
echo "Scoring modal: OK\n";
