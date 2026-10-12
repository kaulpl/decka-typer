<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__);
function check($value, $message) { if (!$value) throw new RuntimeException($message); }
require dirname(__DIR__) . '/includes/class-dt-decka-stats.php';

$games='<div class="relative"><a href="/pl/league/1lm/2027/games/37603" class="absolute inset-0"></a><span>WKK Wrocław</span><b>97</b><span>Decka Pelplin</span><b>83</b></div>
<div class="relative"><a href="/pl/league/1lm/2027/games/37611" class="absolute inset-0"></a><span>Decka Pelplin</span><strong>91 : 80</strong><span>Miasto Szkła Krosno</span></div>';
$found=DT_Decka_Stats::find_puls_game($games,['home_name'=>'Decka Pelplin','away_name'=>'Miasto Szkła Krosno','score_home'=>91,'score_away'=>80]);
check($found===['id'=>'37611','path'=>'/pl/league/1lm/2027/games/37611'], 'Find a Puls Basketu game whose overlay link has no text');
check(DT_Decka_Stats::find_puls_game($games,['home_name'=>'Decka Pelplin','away_name'=>'Inny Klub','score_home'=>91,'score_away'=>80])===null, 'Reject a different opponent even when score matches');

$html='<!doctype html><html><body>
<table aria-label="Wynik kwart"><tr><th>Drużyna</th><th>Q1</th><th>Q2</th><th>Q3</th><th>Q4</th><th>Razem</th></tr><tr><td>Decka Pelplin</td><td>23</td><td>23</td><td>15</td><td>30</td><td>91</td></tr><tr><td>Miasto Szkła Krosno</td><td>23</td><td>16</td><td>27</td><td>14</td><td>80</td></tr></table>
<table aria-label="Decka Pelplin Box Score"><tr><th>#</th><th>ZAWODNIK</th><th>CZAS</th><th>PKT</th><th>ZB</th><th>AST</th><th>Z GRY</th><th>ZA 2</th><th>ZA 3</th><th>ZA 1</th><th>ZB A</th><th>ZB O</th><th>PRZ</th><th>BLK</th><th>STR</th><th>F</th><th>FW</th><th>EVAL</th><th>+/-</th></tr>
<tr><td>7</td><td>Jan Pierwszy</td><td>31:00</td><td>21</td><td>6</td><td>5</td><td>8/12</td><td>6/8</td><td>2/4</td><td>3/4</td><td>2</td><td>4</td><td>3</td><td>1</td><td>2</td><td>2</td><td>4</td><td>27</td><td>12</td></tr>
<tr><td>12</td><td>Adam Drugi</td><td>29:00</td><td>18</td><td>8</td><td>2</td><td>7/13</td><td>5/8</td><td>2/5</td><td>2/2</td><td>3</td><td>5</td><td>1</td><td>2</td><td>1</td><td>3</td><td>2</td><td>24</td><td>8</td></tr>
<tr><td>4</td><td>Piotr Trzeci</td><td>24:00</td><td>9</td><td>3</td><td>7</td><td>3/8</td><td>2/4</td><td>1/4</td><td>2/2</td><td>1</td><td>2</td><td>4</td><td>0</td><td>2</td><td>2</td><td>3</td><td>19</td><td>6</td></tr>
<tr><td>RAZEM</td><td>200:00</td><td>91</td><td>36</td><td>22</td><td>31/60</td><td>24/42</td><td>7/18</td><td>22/31</td><td>10</td><td>26</td><td>8</td><td>7</td><td>17</td><td>25</td><td>23</td><td>104</td><td>-</td></tr></table>
<table aria-label="Decka Pelplin Advanced Statistics"><tr><th>PKT ZE STRAT</th><th>PKT W POMALOWANYM</th><th>CELNE W POMALOWANYM</th><th>PRÓBY W POMALOWANYM</th><th>PKT 2 SZANSY</th><th>PKT Z KONTRY</th><th>PKT ŁAWKI</th></tr><tr><td>15</td><td>44</td><td>22</td><td>36</td><td>11</td><td>25</td><td>26</td></tr></table>
</body></html>';
$stats=DT_Decka_Stats::parse_puls_game($html);
check(is_array($stats) && $stats['source']==='pulsbasketu', 'Parse Puls Basketu Decka box score');
check($stats['team']['two']===['made'=>24,'attempted'=>42,'percent'=>57.1], 'Calculate 2PT percentage');
check($stats['team']['field']['made']===31 && $stats['team']['reb']===36 && $stats['team']['eval']===104, 'Read team totals');
check(count($stats['leaders'])===3 && $stats['leaders'][0]['name']==='Jan Pierwszy', 'Select the top three players by EVAL');
check($stats['quarters']['decka']===[23,23,15,30] && $stats['quarters']['opponent']===[23,16,27,14], 'Read quarter scores');
check($stats['advanced']['paint_points']===44 && $stats['advanced']['fast_break']===25, 'Read advanced team statistics');
check($stats['shots']===[], 'Do not invent shot coordinates unavailable in page HTML');

$admin=file_get_contents(dirname(__DIR__).'/includes/class-dt-admin.php');
$graphics=file_get_contents(dirname(__DIR__).'/assets/js/admin-artur-graphics.js');
$class=file_get_contents(dirname(__DIR__).'/includes/class-dt-decka-stats.php');
check(str_contains($admin,'data-dt-decka-stat-graphic') && str_contains($admin,'Puls Basketu') && !str_contains($admin,'data-dt-livestats-url'), 'Expose automatic Puls Basketu control without a FIBA URL field');
check(str_contains($graphics,'gameDetails') && str_contains($graphics,'PRZEBIEG MECZU') && str_contains($graphics,'KLUCZOWE LICZBY DECKI'), 'Render quarters and advanced statistics when coordinates are unavailable');
check(!str_contains($graphics,'findLiveStatsGame') && !str_contains($graphics,'liveStatsPayload'), 'Remove browser-side FIBA identifier scanning');
check(str_contains($class,'find_puls_game') && str_contains($class,'parse_puls_game'), 'Find and parse Puls Basketu games on the server');
echo "Decka match statistics from Puls Basketu: OK\n";
