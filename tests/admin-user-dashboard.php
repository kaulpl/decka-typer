<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__);

function wp_timezone() { return new DateTimeZone('Europe/Warsaw'); }
function check($value, $message) { if (!$value) throw new RuntimeException($message); }

$wpdb = new class {
    public string $users = 'wp_users';
    public string $query = '';
    public array $args = [];
    public function prepare($query, ...$args) { $this->query = $query; $this->args = $args; return $query; }
    public function get_row($query) { return (object)['total'=>125,'today'=>3,'week'=>17,'month'=>42]; }
};

require dirname(__DIR__) . '/includes/class-dt-admin.php';
$method = new ReflectionMethod(DT_Admin::class, 'user_registration_stats');
$stats = $method->invoke(null);

check($stats === ['total'=>125,'today'=>3,'week'=>17,'month'=>42], 'Registration counters normalized');
check(str_contains($wpdb->query, 'COUNT(*) total'), 'Total users included');
check(str_contains($wpdb->query, 'user_registered BETWEEN %s AND %s'), 'Period counters use bounded dates');
check(str_contains($wpdb->query, "FROM {$wpdb->users}"), 'WordPress users table used');
check(count($wpdb->args) === 7, 'All time boundaries passed safely through prepare');
foreach ($wpdb->args as $date) check((bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date), 'UTC SQL datetime format');

$source = file_get_contents(dirname(__DIR__) . '/includes/class-dt-admin.php');
check(str_contains($source, "self::user_growth_item('Dzisiaj'"), 'Today widget rendered');
check(str_contains($source, "self::user_growth_item('Ostatnie 7 dni'"), 'Seven-day widget rendered');
check(str_contains($source, "self::user_growth_item('Ten miesiąc'"), 'Month widget rendered');

echo "Admin user dashboard: OK\n";
