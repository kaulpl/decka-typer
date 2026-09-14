<?php
if (PHP_SAPI !== 'cli') exit;

$source = file_get_contents(dirname(__DIR__) . '/includes/class-dt-admin.php');
function check($value, $message) { if (!$value) throw new RuntimeException($message); }

check(str_contains($source, '$total=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}")'), 'Pagination counts every WordPress user');
check(!str_contains($source, "WHERE EXISTS(SELECT 1 FROM " . DT_DB::table('predictions') . " ppp"), 'Main user list has no prediction activity filter');
check(!str_contains($source, "EXISTS(SELECT 1 FROM " . DT_DB::table('round_submissions') . " sss"), 'Main user list has no submission activity filter');
check(str_contains($source, 'ORDER BY points DESC,u.display_name LIMIT ".(int)$perPage." OFFSET ".(int)(($dtPage-1)*$perPage)'), 'User query remains paginated in SQL');
check(str_contains($source, '$perPage=25'), 'Page size remains bounded');
check(str_contains($source, "self::pagination($total,$perPage,$dtPage,['page'=>'decka-typer-users'])"), 'Pagination controls use complete total');

echo "Complete admin user list: OK\n";
