<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__);

function check($value, $message) { if (!$value) throw new RuntimeException($message); }
class DT_DB { public static function table(string $name): string { return 'dt_' . $name; } }
class DT_Bonus { public static function match_ids(): array { return [10,20]; } }

$wpdb = new class {
    public array $queries=[];
    public function prepare($query, ...$args) { $this->queries[]=['sql'=>$query,'args'=>$args]; return $query; }
    public function get_row($query) {
        if (str_contains($query,'bonus_resolved')) return (object)['bonus_resolved'=>40,'bonus_hits'=>18];
        return (object)['eligible_matches'=>8,'submitted'=>520,'resolved'=>500,'hits'=>300];
    }
};

require dirname(__DIR__) . '/includes/class-dt-admin.php';
$method = new ReflectionMethod(DT_Admin::class, 'prediction_dashboard_stats');
$stats = $method->invoke(null, '2026/27', 100);

check($stats['eligible_matches']===8, 'Eligible open and closed matches counted');
check($stats['possible']===800, 'Possible predictions multiply matches by registered users');
check($stats['submitted']===520 && $stats['utilization']===65.0, 'Prediction utilization calculated');
check($stats['hits']===300 && $stats['resolved']===500 && $stats['accuracy']===60.0, 'Resolved prediction accuracy calculated');
check($stats['bonus_hits']===18 && $stats['bonus_resolved']===40 && $stats['bonus_accuracy']===45.0, 'Bonus accuracy calculated');
check(str_contains($wpdb->queries[0]['sql'], "r.status IN ('open','closed')"), 'Only open and closed rounds are eligible');
check(str_contains($wpdb->queries[0]['sql'], 'm.score_home IS NOT NULL'), 'Accuracy uses settled matches');
check($wpdb->queries[1]['args']===[10,20,'2026/27'], 'Bonus match ids and season prepared safely');

$source=file_get_contents(dirname(__DIR__) . '/includes/class-dt-admin.php');
check(str_contains($source,"prediction_dashboard_item('Wykorzystanie typów'"),'Utilization card rendered');
check(str_contains($source,"prediction_dashboard_item('Skuteczność'"),'Accuracy card rendered');
check(str_contains($source,"prediction_dashboard_item('Mecze BONUS'"),'Bonus card rendered');
echo "Admin prediction dashboard: OK\n";
