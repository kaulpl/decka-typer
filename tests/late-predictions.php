<?php
if (PHP_SAPI !== 'cli') exit;
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
$root = dirname(__DIR__);
$submission = file_get_contents($root . '/includes/class-dt-submission.php');
$legacy = file_get_contents($root . '/includes/class-dt-rest.php');
$admin = file_get_contents($root . '/includes/class-dt-admin.php');
$rest = file_get_contents($root . '/includes/class-dt-rest.php');

check(substr_count($submission, "'match_started'") >= 2, 'Modern submission must check start time initially and immediately before writing');
check(str_contains($legacy, 'start_time_known') && substr_count($legacy, "'match_started'") >= 2, 'Legacy submission must enforce match start time too');
check(str_contains($admin, 'p.submitted_at>=m.starts_at'), 'Audit must find predictions submitted at or after tip-off');
check(str_contains($admin, 'dt_cancel_late_prediction_'), 'Each cancellation must use a prediction-specific nonce');
check(str_contains($admin, "DT_Scoring::recalc_match"), 'Cancellation must recalculate affected scoring');
check(str_contains($admin, 'late_prediction_cancelled'), 'Cancellation must be recorded in history');
check(str_contains($rest, "perfect_round_bonus") && str_contains($rest, "['earned_points']"), 'Closed-round points must include the perfect-round bonus');
echo "Late prediction safeguards: OK\n";
