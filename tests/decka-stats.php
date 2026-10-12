<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__);
function check($value, $message) { if (!$value) throw new RuntimeException($message); }
require dirname(__DIR__) . '/includes/class-dt-decka-stats.php';

check(DT_Decka_Stats::season_year('2026/27')==='2027', 'Use the ending year for a shortened season label');
check(DT_Decka_Stats::season_year('2026/2027')==='2027', 'Use the ending year for a full season label');

$games='<div class="relative"><div><a href="/pl/league/1lm/2027/games/37603" class="absolute inset-0"></a></div><span>WKK Wrocław</span><b>97</b><span>Decka Pelplin</span><b>83</b></div>
<div class="relative"><div><a href="/pl/league/1lm/2027/games/37611" class="absolute inset-0"></a></div><span>Decka Pelplin</span><strong>91 : 80</strong><span>Miasto Szkła Krosno</span></div>';
$found=DT_Decka_Stats::find_puls_game($games,['home_name'=>'Decka Pelplin','away_name'=>'Miasto Szkła Krosno','score_home'=>91,'score_away'=>80]);
check($found===['id'=>'37611','path'=>'/pl/league/1lm/2027/games/37611'], 'Find a Puls Basketu game whose overlay link has no text');
check(DT_Decka_Stats::find_puls_game($games,['home_name'=>'Decka Pelplin','away_name'=>'Miasto Szkła Krosno','score_home'=>0,'score_away'=>0])['id']==='37611', 'Match a unique team pair even when the local score is stale');
check(DT_Decka_Stats::find_puls_game($games,['home_name'=>'Decka Pelplin','away_name'=>'Inny Klub','score_home'=>91,'score_away'=>80])===null, 'Reject a different opponent even when score matches');

$schedule='<table><tr class="tt-game"><td><a href="/mecz/223908/decka-pelplin---miasto-szkla-krosno.html">Decka Pelplin 91 : 80 Miasto Szkła Krosno</a></td></tr></table>';
check(DT_Decka_Stats::find_pzkosz_game_url($schedule,['home_name'=>'Decka Pelplin','away_name'=>'Miasto Szkła Krosno','score_home'=>91,'score_away'=>80],'https://1lm.pzkosz.pl/terminarz-i-wyniki.html')==='https://1lm.pzkosz.pl/mecz/223908/decka-pelplin---miasto-szkla-krosno.html', 'Discover the official match URL from an older schedule URL');

$html='<!doctype html><html><body>
<table aria-label="Wynik kwart"><tr><th>Drużyna</th><th>Q1</th><th>Q2</th><th>Q3</th><th>Q4</th><th>Razem</th></tr><tr><td>Decka Pelplin</td><td>23</td><td>23</td><td>15</td><td>30</td><td>91</td></tr><tr><td>Miasto Szkła Krosno</td><td>23</td><td>16</td><td>27</td><td>14</td><td>80</td></tr></table>
<table aria-label="Decka Pelplin Box Score"><tr><th>#</th><th>ZAWODNIK</th><th>CZAS</th><th>PKT</th><th>ZB</th><th>AST</th><th>Z GRY</th><th>ZA 2</th><th>ZA 3</th><th>ZA 1</th><th>ZB A</th><th>ZB O</th><th>PRZ</th><th>BLK</th><th>STR</th><th>F</th><th>FW</th><th>EVAL</th><th>+/-</th></tr>
<tr><td>7</td><td>Jan Pierwszy</td><td>31:00</td><td>21</td><td>6</td><td>5</td><td>8/12</td><td>6/8</td><td>2/4</td><td>3/4</td><td>2</td><td>4</td><td>3</td><td>1</td><td>2</td><td>2</td><td>4</td><td>27</td><td>12</td></tr>
<tr><td>12</td><td>Adam Drugi</td><td>29:00</td><td>18</td><td>8</td><td>2</td><td>7/13</td><td>5/8</td><td>2/5</td><td>2/2</td><td>3</td><td>5</td><td>1</td><td>2</td><td>1</td><td>3</td><td>2</td><td>24</td><td>8</td></tr>
<tr><td>4</td><td>Piotr Trzeci</td><td>24:00</td><td>9</td><td>3</td><td>7</td><td>3/8</td><td>2/4</td><td>1/4</td><td>2/2</td><td>1</td><td>2</td><td>4</td><td>0</td><td>2</td><td>2</td><td>3</td><td>19</td><td>6</td></tr>
<tr><td></td><td>RAZEM</td><td>200:00</td><td>91</td><td>36</td><td>22</td><td>31/60 (51.7%)</td><td>24/42 (57.1%)</td><td>7/18 (38.9%)</td><td>22/31 (71.0%)</td><td>10</td><td>26</td><td>8</td><td>7</td><td>17</td><td>25</td><td>23</td><td>104</td><td>-</td></tr></table>
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

$pzkosz='<!doctype html><html><body><div><span>23:23 | 23:16 | 15:27 | 30:14</span></div>
<h3><a>Decka Pelplin</a></h3><table class="statystyki splitTable"><tr><th>NR</th><th>S5</th><th>Zawodnik</th><th>Pkt</th></tr>
<tr class="row_stat"><td>21</td><td>*</td><td>Jayden Coke-Domingue</td><td>22</td><td>37:52</td><td>7/10</td><td>70</td><td>1/5</td><td>20</td><td>8/15</td><td>53.3</td><td>5/7</td><td>71.4</td><td>0</td><td>3</td><td>3</td><td>6</td><td>4</td><td>5</td><td>4</td><td>3</td><td>1</td><td>0</td><td>22</td><td>13</td></tr>
<tr class="row_stat"><td>0</td><td>*</td><td>Samajae Haynes-Jones</td><td>18</td><td>33:23</td><td>4/13</td><td>30.8</td><td>1/3</td><td>33.3</td><td>5/16</td><td>31.3</td><td>7/7</td><td>100</td><td>2</td><td>4</td><td>6</td><td>10</td><td>4</td><td>4</td><td>2</td><td>1</td><td>0</td><td>0</td><td>22</td><td>11</td></tr>
<tr class="row_stat"><td>13</td><td></td><td>Tobiasz Dydak</td><td>10</td><td>20:52</td><td>3/5</td><td>60</td><td>0/0</td><td>0</td><td>3/5</td><td>60</td><td>4/6</td><td>66.7</td><td>4</td><td>3</td><td>7</td><td>2</td><td>1</td><td>4</td><td>2</td><td>2</td><td>2</td><td>0</td><td>17</td><td>-1</td></tr>
<tr class="sum"><td>Suma</td><td>91</td><td>200:00</td><td>24/42</td><td>57.1</td><td>7/18</td><td>38.9</td><td>31/60</td><td>51.7</td><td>22/31</td><td>71</td><td>10</td><td>26</td><td>36</td><td>22</td><td>25</td><td>23</td><td>17</td><td>8</td><td>7</td><td>5</td><td>104</td><td></td></tr></table></body></html>';
$official=DT_Decka_Stats::parse_pzkosz_game($pzkosz,true);
check(is_array($official) && $official['source']==='pzkosz', 'Parse official server-rendered PZKosz fallback');
check($official['team']['field']['made']===31 && $official['team']['reb']===36 && $official['team']['steals']===8 && $official['team']['turnovers']===17, 'Read official team totals');
check($official['leaders'][0]['name']==='Jayden Coke-Domingue' && $official['leaders'][0]['eval']===22, 'Read official player leaders');
check($official['quarters']['decka']===[23,23,15,30] && $official['quarters']['opponent']===[23,16,27,14], 'Read official quarter score');
$officialRaw=DT_Decka_Stats::parse_pzkosz_raw($pzkosz,true);
check(is_array($officialRaw) && $officialRaw['team']['points']===91 && $officialRaw['team']['eval']===104, 'Parse a PZKosz box score without relying on the full-page DOM');
check($officialRaw['leaders'][0]['name']==='Jayden Coke-Domingue' && $officialRaw['quarters']['decka']===[23,23,15,30], 'Read leaders and quarters through the raw HTML fallback');
$noStartingFive=str_replace('<td>*</td><td>Jayden Coke-Domingue</td>','<td>Jayden Coke-Domingue</td>',$pzkosz);
$noStartingFiveStats=DT_Decka_Stats::parse_pzkosz_raw($noStartingFive,true);
check($noStartingFiveStats['leaders'][0]['name']==='Jayden Coke-Domingue' && $noStartingFiveStats['leaders'][0]['points']===22 && $noStartingFiveStats['leaders'][0]['eval']===22, 'Map a player dynamically when PZKosz omits the S5 column');
$advancedOfficial=str_replace('</body>','<table><tr><th></th><th>Decka Pelplin</th><th>Miasto Szkła Krosno</th></tr><tr><th>Punkty ze strat</th><td>15</td><td>14</td></tr><tr><th>Punkty w "pomalowanym"</th><td>44 (22/36) 61.1</td><td>42</td></tr><tr><th>Punkty drugiej szansy</th><td>11</td><td>7</td></tr><tr><th>Punkty szybkiego ataku</th><td>25</td><td>16</td></tr><tr><th>Punkty z ławki</th><td>26</td><td>34</td></tr></table></body>',$pzkosz);
$advancedStats=DT_Decka_Stats::parse_pzkosz_raw($advancedOfficial,true);
check($advancedStats['advanced']['turnover_points']===15 && $advancedStats['advanced']['paint_points']===44 && $advancedStats['advanced']['fast_break']===25 && $advancedStats['advanced']['bench']===26, 'Read official PZKosz advanced team statistics');
$largeOfficial=str_repeat('<script>window.__page_padding="'.str_repeat('x',1000).'";</script>',1800).$pzkosz;
$largeRaw=DT_Decka_Stats::parse_pzkosz_raw($largeOfficial,true);
check(is_array($largeRaw) && $largeRaw['team']['field']['made']===31, 'Extract the box score from a full-size PZKosz page without a whole-document regular expression');
check(DT_Decka_Stats::pzkosz_raw_diagnostics($largeOfficial)==='tabele=1; sumy=1; pelne=1', 'Report exact raw table diagnostics');
$classless=str_replace('class="statystyki splitTable"','data-layout="box-score"',$pzkosz);
check(DT_Decka_Stats::parse_pzkosz_raw($classless,true)['team']['points']===91, 'Recognize a complete PZKosz box score without relying on its CSS class');
$encoded=htmlspecialchars($pzkosz,ENT_QUOTES|ENT_HTML5,'UTF-8');
check(DT_Decka_Stats::parse_pzkosz_raw($encoded,true)['team']['eval']===104, 'Decode a box score returned as escaped HTML');
$shortSum=str_replace('<td>5</td><td>104</td><td></td>','<td>104</td>',$pzkosz);
$shortRaw=DT_Decka_Stats::parse_pzkosz_raw($shortSum,true);
check($shortRaw['team']['points']===91 && $shortRaw['team']['eval']===104 && $shortRaw['team']['field']['made']===31, 'Parse a shorter PZKosz total row without BO and +/- cells');
$unclosed=str_replace(['</td>','</th>','</tr>'],['','',''],$pzkosz);
$unclosedRaw=DT_Decka_Stats::parse_pzkosz_raw($unclosed,true);
check($unclosedRaw['team']['points']===91 && $unclosedRaw['team']['eval']===104 && $unclosedRaw['leaders'][0]['name']==='Jayden Coke-Domingue', 'Parse malformed PZKosz rows without closing cell and row tags');
$textOnly=preg_replace('~<tr class="sum">.*?</tr>~s','<div>Suma 91 200:00 24/42 57.1 7/18 38.9 31/60 51.7 22/31 71 10 26 36 22 25 23 17 8 7 5 104</div>',$pzkosz);
$textOnlyRaw=DT_Decka_Stats::parse_pzkosz_raw($textOnly,true);
check($textOnlyRaw['team']['points']===91 && $textOnlyRaw['team']['reb']===36 && $textOnlyRaw['team']['eval']===104, 'Parse PZKosz totals directly from visible text without any sum-row cells');
$awayHtml=str_replace('<h3><a>Decka Pelplin</a></h3>','<table class="statystyki splitTable"><tr><td>Suma</td></tr></table><h3><a>Decka Pelplin</a></h3>',$pzkosz);
$officialAway=DT_Decka_Stats::parse_pzkosz_game($awayHtml,false);
check(is_array($officialAway) && $officialAway['team']['points']===91, 'Choose the second PZKosz box score when Decka is the away team');

$admin=file_get_contents(dirname(__DIR__).'/includes/class-dt-admin.php');
$graphics=file_get_contents(dirname(__DIR__).'/assets/js/admin-artur-graphics.js');
$class=file_get_contents(dirname(__DIR__).'/includes/class-dt-decka-stats.php');
check(str_contains($admin,'data-dt-decka-stat-graphic') && !str_contains($admin,'Puls Basketu') && !str_contains($admin,'data-dt-livestats-url'), 'Expose the automatic official-statistics control without old source labels');
check(str_contains($graphics,'gameDetails') && str_contains($graphics,'PRZEBIEG MECZU') && str_contains($graphics,'KLUCZOWE LICZBY DECKI'), 'Render quarters and advanced statistics when coordinates are unavailable');
check(!str_contains($graphics,'Puls Basketu'), 'Do not print Puls Basketu labels in the generated graphic or status');
check(!str_contains($graphics,'findLiveStatsGame') && !str_contains($graphics,'liveStatsPayload'), 'Remove browser-side FIBA identifier scanning');
check(str_contains($class,'find_puls_game') && str_contains($class,'parse_puls_game'), 'Find and parse Puls Basketu games on the server');
check(str_contains($class,'find_pzkosz_game_url') && str_contains($class,'STAT-MECZ-3A') && str_contains($class,'STAT-MECZ-3D'), 'Discover an official PZKosz detail URL and expose precise diagnostic stages');
echo "Decka match statistics from Puls Basketu: OK\n";
