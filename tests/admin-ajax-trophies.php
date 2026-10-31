<?php
if(PHP_SAPI!=='cli')exit;
$admin=(string)file_get_contents(__DIR__.'/../includes/class-dt-admin.php');
$ranking=(string)file_get_contents(__DIR__.'/../includes/class-dt-ranking-view.php');
$adminJs=(string)file_get_contents(__DIR__.'/../assets/js/admin.js');
$rankingJs=(string)file_get_contents(__DIR__.'/../assets/js/ranking-view.js');
function verify_admin_feature(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);echo "OK: $message\n";}
verify_admin_feature(str_contains($admin,"meta_key='dt_ranking_name'")&&str_contains($admin,'Nick</th>'),'Users table displays and searches ranking nicknames');
verify_admin_feature(str_contains($admin,'dt-admin-live-filter')&&str_contains($adminJs,'liveLoad'),'Users and predictions use dynamic AJAX replacement');
verify_admin_feature(str_contains($admin,'value="dt_add_trophy"')&&str_contains($admin,'function add_trophy'),'Administrator can award trophies');
verify_admin_feature(str_contains($admin,'Imię i nazwisko</th><th>E-mail</th><th>Nick</th>')&&!str_contains($admin,"dt-muted\">@'.esc_html"),'User identity fields are separated and login suffix is hidden');
verify_admin_feature(str_contains($admin,'name="delta" value="1"')&&str_contains($admin,'name="delta" value="-1"')&&str_contains($admin,'max(0,'),'Trophy count supports +1 and -1 without becoming negative');
verify_admin_feature(str_contains($ranking,"['trophies']")&&str_contains($rankingJs,'trophyIcons(r.trophies)'),'Ranking receives and displays trophy counts');
verify_admin_feature(!str_contains($rankingJs,'<small>${Number(r.predictions||0)} typów</small>'),'Prediction count was removed from below the ranking nickname');
verify_admin_feature(str_contains($rankingJs,'Extended_Pictographic')&&str_contains($rankingJs,'cleanNick(r.display_name)'),'Ranking strips emoji from displayed nicknames');
