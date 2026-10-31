<?php
if (PHP_SAPI !== 'cli') exit;
$source = file_get_contents(dirname(__DIR__) . '/includes/class-dt-admin.php');
function check($value, $message) { if (!$value) throw new RuntimeException($message); }
$start = strpos($source, 'public static function users(): void');
$end = strpos($source, 'public static function notifications(): void', $start);
check($start !== false && $end !== false, 'Users method found');
$usersSource = substr($source, $start, $end - $start);
check(str_contains($usersSource, '$total=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users} u$where")'), 'Pagination counts all matching WordPress users');
check(!str_contains($usersSource, 'sss WHERE sss.user_id=u.ID'), 'Main user list has no submission activity filter');
check(str_contains($usersSource, 'ORDER BY points DESC,u.display_name LIMIT ".(int)$perPage." OFFSET ".(int)(($dtPage-1)*$perPage)'), 'User query remains paginated in SQL');
check(str_contains($usersSource, '$perPage=25'), 'Page size remains bounded');
check(str_contains($usersSource, "['page'=>'decka-typer-users','s'=>" . '$search' . "]"), 'Pagination preserves partial search');
echo "Complete admin user list: OK\n";
