<?php
if (PHP_SAPI !== 'cli') exit;

$source = file_get_contents(dirname(__DIR__) . '/includes/class-dt-admin.php');
function check($value, $message) { if (!$value) throw new RuntimeException($message); }

check(str_contains($source, '$total=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}")'), 'Pagination counts every WordPress user');
check(!str_contains($source, 'WHERE EXISTS(SELECT 1 FROM '), 'Main user list has no activity-only WHERE clause');
check(!str_contains($source, 'sss WHERE sss.user_id=u.ID'), 'Main user list has no submission activity filter');
check(str_contains($source, 'ORDER BY points DESC,u.display_name LIMIT ".(int)$perPage." OFFSET ".(int)(($dtPage-1)*$perPage)'), 'User query remains paginated in SQL');
check(str_contains($source, '$perPage=25'), 'Page size remains bounded');
check(str_contains($source, "self::pagination($total,$perPage,$dtPage,['page'=>'decka-typer-users'])"), 'Pagination controls use complete total');

echo "Complete admin user list: OK\n";
