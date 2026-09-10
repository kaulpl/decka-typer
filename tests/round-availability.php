<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__);
define('DT_VERSION', 'test');

function current_time($format) { return '2026-09-10 12:00:00'; }
function check($value, $message) { if (!$value) throw new RuntimeException($message); }

$wpdb = new class {
    public string $last_query = '';
    public function prepare($query, ...$args) {
        foreach ($args as $arg) $query = preg_replace('/%s/', "'" . addslashes((string)$arg) . "'", $query, 1);
        return $query;
    }
    public function query($query) { $this->last_query = $query; return 3; }
};

require dirname(__DIR__) . '/includes/class-dt-db.php';

check(DT_DB::sync_round_availability() === 3, 'Updated row count returned');
$sql = preg_replace('/\s+/', ' ', $wpdb->last_query);
check(str_contains($sql, 'MIN(starts_at) AS first_match'), 'Earliest known match drives schedule');
check(str_contains($sql, 'WHERE start_time_known=1'), 'Unknown placeholder times ignored');
check(str_contains($sql, 'DATE_SUB(schedule.first_match, INTERVAL 7 DAY)'), 'Round opens seven days before first match');
check(!str_contains($sql, 'last_match'), 'Last match cannot extend round availability');
check(str_contains($sql, 'ELSE schedule.first_match END'), 'Automatic round closes at first match');
check(str_contains($sql, 'r.closes_at>schedule.first_match'), 'Changed earlier first match clamps manual deadline');

$admin = file_get_contents(dirname(__DIR__) . '/includes/class-dt-admin.php');
check(str_contains($admin, "'default_close'=>self::html_datetime(\$r->first_known_match)"), 'Open dialog always defaults to current first match');
check(str_contains($admin, 'MAX(CASE WHEN m.start_time_known=1 THEN m.starts_at END) last_known_match'), 'Admin range uses known dates only');

echo "Round availability: OK\n";
