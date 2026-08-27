<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__);
define('ARRAY_A', 'ARRAY_A');

function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$value)); }
function sanitize_text_field($value) { return trim(strip_tags((string)$value)); }
function check($value, $message) { if (!$value) throw new RuntimeException($message); }

class WP_REST_Request {
    public function __construct(private array $params) {}
    public function get_param($key) { return $this->params[$key] ?? null; }
}
class WP_REST_Response { public function __construct(public array $data) {} }
class DT_DB {
    public static function table(string $name): string { return 'dt_' . $name; }
    public static function settings(): array { return ['season'=>'2026/27','perfect_round_bonus'=>0]; }
}

$wpdb = new class {
    public string $users='wp_users';
    public string $usermeta='wp_usermeta';
    public array $queries=[];
    public function prepare($query, ...$args) {
        foreach ($args as $arg) {
            $replacement=is_int($arg)?(string)$arg:"'".addslashes((string)$arg)."'";
            $query=preg_replace('/%[sd]/', $replacement, $query, 1);
        }
        return $query;
    }
    public function get_col($query): array {
        $this->queries[]=$query;
        if (str_contains($query,'DISTINCT season')) return ['2026/27'];
        return [];
    }
    public function get_results($query, $format=null): array {
        $this->queries[]=$query;
        if (str_contains($query,'COUNT(DISTINCT um.user_id) supporters')) return [
            ['id'=>'8','name'=>'Decka Pelplin','short_name'=>'Decka','supporters'=>'4'],
            ['id'=>'15','name'=>'Spójnia Stargard','short_name'=>'Spójnia','supporters'=>'2'],
        ];
        return [];
    }
};

require dirname(__DIR__) . '/includes/class-dt-ranking-view.php';

$response=DT_Ranking_View::ranking(new WP_REST_Request([
    'scope'=>'season','season'=>'2026/27','league'=>'clubs','favorite_team_id'=>15,
]));
check($response->data['league']==='clubs','Club mode preserved');
check($response->data['favorite_team_id']===15,'Selected favorite club preserved');
check(count($response->data['favorite_teams'])===2,'Only clubs selected by users returned');
check($response->data['favorite_teams'][0]['supporters']===4,'Supporter count normalized');
check(in_array(['key'=>'clubs','name'=>'KLUBY'],$response->data['leagues'],true),'KLUBY option returned');
$rankingQueries=array_values(array_filter($wpdb->queries,static fn($sql)=>str_contains($sql,'FROM wp_users u')||str_contains($sql,'SELECT x.user_id,COUNT(*) perfect_rounds')));
check(count($rankingQueries)>=2,'Ranking and perfect-round queries executed');
foreach ($rankingQueries as $sql) {
    check(str_contains($sql,"fum.meta_key='dt_favorite_team_id'"),'Favorite club meta filter applied');
    check(str_contains($sql,'CAST(fum.meta_value AS UNSIGNED)=15'),'Selected club id applied');
}

$fallback=DT_Ranking_View::ranking(new WP_REST_Request([
    'scope'=>'season','season'=>'2026/27','league'=>'clubs','favorite_team_id'=>999,
]));
check($fallback->data['favorite_team_id']===8,'Unknown club replaced with first available club');
echo "Club ranking: OK\n";
