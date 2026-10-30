<?php
$php=(string)file_get_contents(__DIR__.'/../includes/class-dt-preseason.php');
$js=(string)file_get_contents(__DIR__.'/../assets/js/my-coupons.js');
$css=(string)file_get_contents(__DIR__.'/../assets/css/my-coupons.css');
function verify_coupon(bool $ok,string $message): void { if(!$ok){fwrite(STDERR,"FAIL: $message\n");exit(1);}echo "OK: $message\n"; }
verify_coupon(str_contains($php,"'current_position'")&&str_contains($php,'current_positions('),'PRE catalog contains current team positions');
verify_coupon(str_contains($php,"positions_by_league")&&str_contains($php,"m.score_home IS NOT NULL"),'Official standings use a completed-match fallback');
verify_coupon(str_contains($js,'(Aktualnie: ${esc(position)}. miejsce)'),'PRE coupons display the current position');
verify_coupon(str_contains($js,"x.is_bonus&&x.result_known&&x.scoring_code==='winner'"),'BONUS highlight requires a resolved winning pick');
verify_coupon(str_contains($css,'.dt-coupon-summary-bonus.is-hit')&&str_contains($css,'.dt-pre-coupon-row strong em'),'New statuses have dedicated visual styles');
