<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__);
define('ARRAY_A', 'ARRAY_A');
function check($value, $message) { if (!$value) throw new RuntimeException($message); }
function wp_timezone() { return new DateTimeZone('Europe/Warsaw'); }
class DT_DB { public static function table(string $name): string { return 'dt_' . $name; } }
class DT_Bonus { public static function match_ids(): array { return [10,20,30]; } }

$now = new DateTimeImmutable('now', wp_timezone());
$closed1 = $now->modify('-3 days')->setTime(18,0);
$closedPlk = $now->modify('-2 days')->setTime(18,0);
$future = $now->modify('+3 days')->setTime(18,0);
$futureOpen = $now->modify('+1 day')->setTime(18,0);
$date = static fn(DateTimeImmutable $value): string => $value->format('Y-m-d H:i:s');
$closed1Utc = $closed1->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$closedPlkUtc = $closedPlk->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

$wpdb = new class($closed1Utc,$closedPlkUtc,$date($closed1),$date($closedPlk),$date($future),$date($futureOpen)) {
    public string $users = 'wp_users';
    public array $queries = [];
    public array $cutoffs = [];
    public array $rounds;
    private array $arguments = [];
    private string $firstUtc;
    private string $secondUtc;
    public function __construct(string $firstUtc,string $secondUtc,string $closed1,string $closedPlk,string $future,string $futureOpen) {
        $this->firstUtc=$firstUtc; $this->secondUtc=$secondUtc;
        $row=static fn($id,$league,$group,$number,$open,$close,$matches,$submitted,$resolved,$hits): array=>[
            'round_id'=>$id,'league_key'=>$league,'group_key'=>$group,'round_no'=>$number,'opens_at'=>$open,
            'closes_at'=>$close,'first_match'=>$close,'eligible_matches'=>$matches,'submitted'=>$submitted,
            'resolved'=>$resolved,'hits'=>$hits,
        ];
        $this->rounds=[
            $row(1,'1lm','',1,null,$closed1,8,12,10,6),
            $row(2,'1lm','',2,$closed1,$future,8,14,0,0),
            $row(3,'plk','',1,null,$closedPlk,8,20,10,5),
            $row(4,'2lm','A',1,null,$closed1,7,9,4,1),
            $row(5,'2lm','B',1,$closed1,$future,8,11,0,0),
            $row(6,'2lm','C',1,$futureOpen,$future,8,0,0,0),
        ];
    }
    public function prepare($query,...$args) { $this->queries[]=['sql'=>$query,'args'=>$args]; $this->arguments=$args; return $query; }
    public function get_results($query,$mode=null) {
        if (str_contains($query,'bonus_resolved')) return [
            ['league_key'=>'1lm','round_id'=>1,'bonus_resolved'=>3,'bonus_hits'=>2],
            ['league_key'=>'2lm','round_id'=>4,'bonus_resolved'=>2,'bonus_hits'=>1],
            ['league_key'=>'2lm','round_id'=>6,'bonus_resolved'=>1,'bonus_hits'=>1],
        ];
        return $this->rounds;
    }
    public function get_var($query) {
        $cutoff=(string)$this->arguments[0]; $this->cutoffs[]=$cutoff;
        if ($cutoff===$this->firstUtc) return 5;
        if ($cutoff===$this->secondUtc) return 6;
        $delta=abs(time()-(new DateTimeImmutable($cutoff,new DateTimeZone('UTC')))->getTimestamp());
        check($delta<60,'Open-round user cutoff is the current moment in UTC');
        return 7;
    }
};

require dirname(__DIR__) . '/includes/class-dt-admin.php';
$stats=(new ReflectionMethod(DT_Admin::class,'prediction_dashboard_stats'))->invoke(null,'2026/27');
check($stats['1lm']['eligible_matches']===16,'1LM counts matches across both rounds');
check($stats['1lm']['possible']===96,'1LM: 8 matches × 5 users plus 8 × 7');
check($stats['1lm']['submitted']===26 && $stats['1lm']['utilization']===27.1,'1LM participation is separate');
check($stats['plk']['possible']===48 && $stats['plk']['submitted']===20,'PLK has its own denominator');
check($stats['2lm']['eligible_matches']===15 && $stats['2lm']['possible']===91,'2LM groups summed; future unopened round excluded');
check(count($stats['2lm']['rounds'])===2 && $stats['2lm']['rounds'][1]['group']==='B','2LM group breakdown remains visible');
check($stats['1lm']['accuracy']===60.0 && $stats['2lm']['accuracy']===25.0,'Accuracy split per league');
check($stats['1lm']['bonus_accuracy']===66.7 && $stats['2lm']['bonus_accuracy']===50.0,'BONUS split and unopened round excluded');
check(in_array($closed1Utc,$wpdb->cutoffs,true),'Closed round uses historic UTC closing time');
check(in_array($closedPlkUtc,$wpdb->cutoffs,true),'Each closed league uses its own deadline');
check(str_contains($wpdb->queries[0]['sql'],"r.status IN ('open','closed')"),'Only open and closed rounds counted');
check(str_contains($wpdb->queries[0]['sql'],'m.start_time_known=1'),'Only matches with known tipoff count');
check(str_contains($wpdb->queries[1]['sql'],'user_registered<=%s'),'Registration cohort bounded by round cutoff');
echo "Admin prediction dashboard: OK\n";
