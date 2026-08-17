<?php
declare(strict_types=1);
require_once __DIR__ . '/../common/db_connect.php';

$from=$argv[1]??'2026-06-15'; $to=$argv[2]??'2026-07-14';
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)||$from>$to){fwrite(STDERR,"日付指定エラー\n");exit(1);} 
$features=json_decode((string)file_get_contents(__DIR__.'/../theories/new_sam/features.json'),true);
$pdo=getPDO();

const INTS=['-0.6未満','-0.6--0.4','-0.4--0.2','-0.2-0.0','0.0-0.2','0.2-0.4','0.4-0.6','0.6以上'];
const ZONES=['GOOD','NEUTRAL','BAD'];
function iv(float $x):string{return $x<-.6?INTS[0]:($x<-.4?INTS[1]:($x<-.2?INTS[2]:($x<0?INTS[3]:($x<.2?INTS[4]:($x<.4?INTS[5]:($x<.6?INTS[6]:INTS[7]))))));}
function zn(float $x):string{return $x<-.2?'GOOD':($x<.2?'NEUTRAL':'BAD');}
function bucket():array{return ['n'=>0,'f'=>0,'t3'=>0,'sum'=>0.0,'mix'=>[]];}
function model():array{$z=[];$i=[];foreach(ZONES as $x)$z[$x]=bucket();foreach(INTS as $x)$i[$x]=bucket();return ['z'=>$z,'i'=>$i];}
function add(array &$b,string $v,int $c,float $r):void{$b['n']++;if($r===1.0)$b['f']++;if($r<=3)$b['t3']++;$b['sum']+=$r;$b['mix'][$v][$c]=($b['mix'][$v][$c]??0)+1;}
function baseAdd(array &$b,float $r):void{$b['n']=($b['n']??0)+1;if($r===1.0)$b['f']=($b['f']??0)+1;if($r<=3)$b['t3']=($b['t3']??0)+1;}
function expRate(array $b,array $base,string $m):float{if(!$b['n'])return 0;$s=0;foreach($b['mix'] as $v=>$cs)foreach($cs as $c=>$n){$x=$base[$v][$c]??null;if($x&&$x['n'])$s+=$n*(($x[$m]??0)/$x['n']*100);}return $s/$b['n'];}
function sumB(array $b,array $base):array{$n=$b['n'];$f=$n?$b['f']/$n*100:0;$t=$n?$b['t3']/$n*100:0;return ['n'=>$n,'f'=>$f,'t3'=>$t,'lf'=>$f-expRate($b,$base,'f'),'lt'=>$t-expRate($b,$base,'t3'),'avg'=>$n?$b['sum']/$n:0];}
function judge(array $m,array $base):string{$g=sumB($m['z']['GOOD'],$base);$n=sumB($m['z']['NEUTRAL'],$base);$b=sumB($m['z']['BAD'],$base);if(min($g['n'],$n['n'],$b['n'])<50)return 'SMALL';$fo=$g['lf']>$n['lf']&&$n['lf']>$b['lf'];$to=$g['lt']>$n['lt']&&$n['lt']>$b['lt'];$sg=$g['lt']>0&&$b['lt']<0;return $fo&&$to&&$sg?'OK':(($fo||$to)&&$sg?'MIXED':'NG');}
function mono(array $m,array $base,string $k):int{$a=[];foreach(INTS as $x){$s=sumB($m['i'][$x],$base);$a[]=$k==='f'?$s['lf']:$s['lt'];}$ok=0;for($i=0;$i<7;$i++)if($a[$i]>=$a[$i+1])$ok++;return $ok;}
function ms(array $m,array $base):array{$g=sumB($m['z']['GOOD'],$base);$b=sumB($m['z']['BAD'],$base);return ['j'=>judge($m,$base),'g'=>$g,'b'=>$b,'sf'=>$g['lf']-$b['lf'],'st'=>$g['lt']-$b['lt'],'mf'=>mono($m,$base,'f'),'mt'=>mono($m,$base,'t3')];}
function rankJ(string $j):int{return ['OK'=>3,'MIXED'=>2,'NG'=>1,'SMALL'=>0][$j]??0;}
function win(array $a,array $s):string{if($a['j']==='SMALL'||$s['j']==='SMALL')return 'HOLD';if(rankJ($a['j'])!==rankJ($s['j']))return rankJ($a['j'])>rankJ($s['j'])?'AROUND':'STRAIGHT';$da=$a['sf']+$a['st'];$ds=$s['sf']+$s['st'];return abs($da-$ds)<1?'TIE':($da>$ds?'AROUND':'STRAIGHT');}
function cur(array $f):string{return in_array('around_time',$f,true)?'AROUND':(in_array('straight_time',$f,true)?'STRAIGHT':'OTHER');}

$rq=$pdo->prepare("SELECT DISTINCT race_code FROM boat_race.race_entry WHERE race_date BETWEEN :f AND :t ORDER BY race_code");$rq->execute([':f'=>$from,':t'=>$to]);$races=$rq->fetchAll(PDO::FETCH_COLUMN);
$eq=$pdo->prepare("SELECT entry_course,exhibition_time,lap_time,around_time,straight_time FROM boat_race.exhibition_live WHERE race_code=:r ORDER BY entry_course");
$resq=$pdo->prepare("SELECT el.entry_course,rrd.rank FROM boat_race.exhibition_live el JOIN boat_race.race_entry re ON re.race_code=el.race_code AND re.player_id=el.player_id LEFT JOIN boat_race.race_result_detail rrd ON rrd.race_code=re.race_code AND rrd.player_id=re.player_id WHERE el.race_code=:r ORDER BY el.entry_course");

$all=['AROUND'=>model(),'STRAIGHT'=>model()];$vs=[];$base=[];$cnt=[];$skip=['not_6_exhibition'=>0,'missing_any_feature'=>0,'missing_result'=>0];$processed=0;
foreach($races as $rc){$v=substr((string)$rc,8,3);if(!isset($features[$v]))continue;$eq->execute([':r'=>$rc]);$er=$eq->fetchAll(PDO::FETCH_ASSOC);if(count($er)!==6){$skip['not_6_exhibition']++;continue;}
  $raw=['AROUND'=>[],'STRAIGHT'=>[]];$bad=false;foreach($er as $x){foreach(['exhibition_time','lap_time','around_time','straight_time'] as $k)if($x[$k]===null||$x[$k]===''||!is_numeric($x[$k])){$bad=true;break 2;}$c=(int)$x['entry_course'];$raw['AROUND'][$c]=(float)$x['exhibition_time']+(float)$x['lap_time']+(float)$x['around_time'];$raw['STRAIGHT'][$c]=(float)$x['exhibition_time']+(float)$x['lap_time']+(float)$x['straight_time'];}
  if($bad||count($raw['AROUND'])!==6||count($raw['STRAIGHT'])!==6){$skip['missing_any_feature']++;continue;}
  $resq->execute([':r'=>$rc]);$rr=$resq->fetchAll(PDO::FETCH_ASSOC);if(count($rr)!==6){$skip['missing_result']++;continue;}$rank=[];$has=false;$bad=false;foreach($rr as $x){$c=(int)$x['entry_course'];$r=$x['rank'];if($r===null||$r==='')$rank[$c]=5.5;elseif(in_array((string)$r,['1','2','3','4','5','6'],true)){$rank[$c]=(float)$r;$has=true;}else{$bad=true;break;}}
  if($bad||!$has||count($rank)!==6){$skip['missing_result']++;continue;}
  if(!isset($vs[$v])){$vs[$v]=['AROUND'=>model(),'STRAIGHT'=>model()];$cnt[$v]=0;}$cnt[$v]++;$processed++;
  foreach($rank as $c=>$r){if(!isset($base[$v][$c]))$base[$v][$c]=['n'=>0,'f'=>0,'t3'=>0];baseAdd($base[$v][$c],$r);} 
  foreach(['AROUND','STRAIGHT'] as $mode){$avg=array_sum($raw[$mode])/6;foreach($raw[$mode] as $c=>$sr){$d=$sr-$avg;$z=zn($d);$i=iv($d);add($all[$mode]['z'][$z],$v,$c,$rank[$c]);add($all[$mode]['i'][$i],$v,$c,$rank[$c]);add($vs[$v][$mode]['z'][$z],$v,$c,$rank[$c]);add($vs[$v][$mode]['i'][$i],$v,$c,$rank[$c]);}}
}
ksort($vs);

echo "========================================\nSUM理論 features比較 v2（player_id結果対応）\n========================================\n";
echo "期間       : {$from} ～ {$to}\n対象レース : ".count($races)."\n共通処理R  : {$processed}\n結果対応   : exhibition→race_entry→result を player_id 結合\n着外処理   : rank NULL/空 = 5.5\n";
echo "\n【除外・スキップ参考】\n";foreach($skip as $k=>$n)printf("%-24s : %d\n",$k,$n);
echo "\n【全場 共通サンプル比較】\n";foreach(['AROUND','STRAIGHT'] as $mode){$m=ms($all[$mode],$base);printf("%-8s %-5s GOODlf=%+6.2f GOODlt=%+6.2f BADlf=%+6.2f BADlt=%+6.2f 幅=%5.2f/%5.2f 単調=%d/7,%d/7\n",$mode,$m['j'],$m['g']['lf'],$m['g']['lt'],$m['b']['lf'],$m['b']['lt'],$m['sf'],$m['st'],$m['mf'],$m['mt']);}
echo "\n【場別 AROUND vs STRAIGHT】\n";foreach($vs as $v=>$mods){$a=ms($mods['AROUND'],$base);$s=ms($mods['STRAIGHT'],$base);$w=win($a,$s);$c=cur($features[$v]);$d=($w==='TIE'||$w==='HOLD')?'現行維持寄り':($w===$c?'現行維持候補':'変更候補');echo "\n[$v] current=$c races={$cnt[$v]} winner=$w => $d\n";foreach(['AROUND'=>$a,'STRAIGHT'=>$s] as $mode=>$m)printf("  %-8s %-5s GOOD %+6.2f/%+6.2f BAD %+6.2f/%+6.2f 幅 %5.2f/%5.2f 単調 %d/7,%d/7\n",$mode,$m['j'],$m['g']['lf'],$m['g']['lt'],$m['b']['lf'],$m['b']['lt'],$m['sf'],$m['st'],$m['mf'],$m['mt']);}
echo "\n========================================\n見るポイント\n========================================\n・前版よりmissing_resultが大幅に減るか\n・開催/展示データがある場が広く場別表示されるか\n・2期間とも同じwinnerならfeatures変更/維持の有力候補\n・期間で割れる場合は現行維持を基本とする\n========================================\n";
