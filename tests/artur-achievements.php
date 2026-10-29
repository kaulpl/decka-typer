<?php
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__);
function remove_accents($value){return $value;}
require dirname(__DIR__).'/includes/class-dt-artur-insights.php';
function verify($condition,$message){if(!$condition)throw new RuntimeException($message);}

$top=new ReflectionMethod(DT_Artur_Insights::class,'game_top_players');
$players=[
    ['name'=>'EVAL 30','eval'=>30,'points'=>18,'reb'=>4,'ast'=>2,'steals'=>1,'blocks'=>0],
    ['name'=>'EVAL 25','eval'=>25,'points'=>20,'reb'=>5,'ast'=>4,'steals'=>0,'blocks'=>0],
    ['name'=>'EVAL 25 mniej punktów','eval'=>25,'points'=>17,'reb'=>6,'ast'=>3,'steals'=>1,'blocks'=>0],
    ['name'=>'Triple bonus','eval'=>20,'points'=>12,'reb'=>11,'ast'=>10,'steals'=>2,'blocks'=>1],
    ['name'=>'Jedna kategoria','eval'=>19,'points'=>15,'reb'=>9,'ast'=>9,'steals'=>1,'blocks'=>0],
];
$result=$top->invoke(null,$players);
verify(count($result)===4,'Triple-double outside the EVAL TOP 3 is appended as a bonus');
verify($result[1]['name']==='EVAL 25','Points break an EVAL tie');
verify(($result[3]['achievement']??'')==='TRIPLE-DOUBLE' && ($result[3]['achievement_detail']??'')==='punkty, zbiórki, asysty','Triple-double uses three of the five single-game categories');
verify(!isset($result[4]),'One category at 10 is not a double-double');

$compare=new ReflectionMethod(DT_Artur_Insights::class,'comparison_classes');
verify($compare->invoke(null,12.0,12.0,false)===['is-even','is-even'],'Equal team statistics are marked as tied');
verify($compare->invoke(null,8.0,12.0,true)===['is-better','is-worse'],'Fewer turnovers are better');
echo "Artur achievements: OK\n";
