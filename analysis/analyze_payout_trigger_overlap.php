<?php
/**
 * 中配当・高配当・大穴の「きっかけ重なり」探索。
 *
 * 単変量探索で探索期間/前方期間の両方で母体超えした候補を、
 * 同系統の二重加点を避けながらファミリー化し、
 * 「何個該当したか」で配当帯率が上がるかを見る。
 *
 * 注意:
 * - このスコア自体も探索段階。2026-08-15〜08-31を候補確認に使っている。
 * - 最終固定後は 2026-09-01以降など、さらに未使用期間で再確認する。
 *
 * Usage:
 *   php analysis/analyze_payout_trigger_overlap.php \
 *     analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *     analysis/output/kimarite_analysis_dataset_20260815_20260822.csv \
 *     analysis/output/kimarite_analysis_dataset_20260823_20260831.csv
 */

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

const PTO_HOLDOUT_START = '20260815';
const PTO_MIN_SAMPLE_N = 10;

function ptoUsage(): never
{
    fwrite(STDERR, "使用方法:\n  php analysis/analyze_payout_trigger_overlap.php KIMARITE_CSV [KIMARITE_CSV ...]\n");
    exit(1);
}

if ($argc < 2) ptoUsage();

function ptoPct(int $num, int $den): float
{
    return $den > 0 ? 100.0 * $num / $den : 0.0;
}

function ptoMedian(array $values): float
{
    if (!$values) return 0.0;
    sort($values, SORT_NUMERIC);
    $n = count($values);
    $m = intdiv($n, 2);
    return ($n % 2 === 1)
        ? (float)$values[$m]
        : ((float)$values[$m - 1] + (float)$values[$m]) / 2.0;
}

function ptoNum($v): ?float
{
    $s = trim((string)$v);
    return ($s !== '' && is_numeric($s)) ? (float)$s : null;
}

function ptoFmtDate(?string $ymd): string
{
    if ($ymd === null || !preg_match('/^\d{8}$/', $ymd)) return '-';
    return substr($ymd,0,4) . '-' . substr($ymd,4,2) . '-' . substr($ymd,6,2);
}

function ptoLoadRows(string $path): array
{
    if (!is_file($path)) throw new RuntimeException("CSVがありません: {$path}");
    $fh = fopen($path, 'rb');
    if ($fh === false) throw new RuntimeException("CSVを開けません: {$path}");

    $header = fgetcsv($fh);
    if ($header === false) {
        fclose($fh);
        throw new RuntimeException("CSVヘッダを読めません: {$path}");
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $idx = array_flip($header);

    $required = ['race_code','actual_1st','c1_1y_sample_n','c1_1y_nige'];
    for ($c=2; $c<=6; $c++) {
        foreach (['sample_n','win','sashi','makuri','makurizashi'] as $suffix) {
            $required[] = "c{$c}_1y_{$suffix}";
        }
    }
    foreach ($required as $col) {
        if (!array_key_exists($col,$idx)) {
            fclose($fh);
            throw new RuntimeException("必要列がありません {$col}: {$path}");
        }
    }

    $hasHonmei = array_key_exists('honmei_head',$idx);
    $hasTaikou = array_key_exists('taikou_head',$idx);
    $hasRaceNo = array_key_exists('race_number',$idx);

    $rows = [];
    while (($data=fgetcsv($fh)) !== false) {
        if (count($data) < count($header)) continue;
        $raceCode = trim((string)($data[$idx['race_code']] ?? ''));
        if (!preg_match('/^(\d{8})[A-Z0-9]{3}(0[1-9]|1[0-2])$/',$raceCode,$m)) continue;
        $date = $m[1];

        $actual = trim((string)($data[$idx['actual_1st']] ?? ''));
        if (!preg_match('/^[1-6]$/',$actual)) continue;

        $n1 = (int)($data[$idx['c1_1y_sample_n']] ?? 0);
        $nige = ptoNum($data[$idx['c1_1y_nige']] ?? null);
        if ($n1 < PTO_MIN_SAMPLE_N || $nige === null) continue;

        $attacks=[]; $sashis=[]; $makuris=[]; $wins=[];
        for ($c=2; $c<=6; $c++) {
            $n=(int)($data[$idx["c{$c}_1y_sample_n"]] ?? 0);
            if ($n < PTO_MIN_SAMPLE_N) continue;
            $win=ptoNum($data[$idx["c{$c}_1y_win"]] ?? null);
            $sashi=ptoNum($data[$idx["c{$c}_1y_sashi"]] ?? null);
            $makuri=ptoNum($data[$idx["c{$c}_1y_makuri"]] ?? null);
            if ($win===null || $sashi===null || $makuri===null) continue;
            $attacks[$c]=$sashi+$makuri;
            $sashis[$c]=$sashi;
            $makuris[$c]=$makuri;
            $wins[$c]=$win;
        }
        if (count($attacks) < 2) continue;

        arsort($attacks,SORT_NUMERIC);
        $vals=array_values($attacks);
        $attackMax=(float)$vals[0];
        $attackSecond=(float)$vals[1];
        $attackGap=$attackMax-$attackSecond;
        $count20=0;
        foreach ($attacks as $a) if ($a>=20.0) $count20++;

        $raceNo=(int)$m[2];
        if ($hasRaceNo) {
            $raw=trim((string)($data[$idx['race_number']] ?? ''));
            if (ctype_digit($raw)) $raceNo=(int)$raw;
        }

        $honmei=null; $taikou=null;
        if ($hasHonmei) {
            $h=trim((string)($data[$idx['honmei_head']] ?? ''));
            if (preg_match('/^[1-6]$/',$h)) $honmei=(int)$h;
        }
        if ($hasTaikou) {
            $t=trim((string)($data[$idx['taikou_head']] ?? ''));
            if (preg_match('/^[1-6]$/',$t)) $taikou=(int)$t;
        }

        $rows[$raceCode]=[
            'race_code'=>$raceCode,
            'date'=>$date,
            'race_no'=>$raceNo,
            'actual_1st'=>(int)$actual,
            'nige'=>$nige,
            'attack_max'=>$attackMax,
            'attack_gap'=>$attackGap,
            'attack_count20'=>$count20,
            'sashi_max'=>max($sashis),
            'makuri_max'=>max($makuris),
            'outer_win_max'=>max($wins),
            'honmei_head'=>$honmei,
            'taikou_head'=>$taikou,
        ];
    }
    fclose($fh);
    return $rows;
}

function ptoLoadPayouts(PDO $pdo,array $raceCodes): array
{
    $out=[];
    foreach (array_chunk($raceCodes,500) as $chunk) {
        if (!$chunk) continue;
        $ph=implode(',',array_fill(0,count($chunk),'?'));
        $sql="
WITH payout AS (
  SELECT race_code, MAX(COALESCE(trifecta_payout,0))::numeric AS trifecta_payout
  FROM boat_race.race_payouts
  WHERE race_code IN ({$ph})
  GROUP BY race_code
), quality AS (
  SELECT race_code,
         COUNT(*) FILTER (WHERE TRIM(rank)='1')::int AS r1,
         COUNT(*) FILTER (WHERE TRIM(rank)='2')::int AS r2,
         COUNT(*) FILTER (WHERE TRIM(rank)='3')::int AS r3
  FROM boat_race.race_result_detail
  WHERE race_code IN ({$ph})
  GROUP BY race_code
)
SELECT p.race_code,p.trifecta_payout,
       COALESCE(q.r1,0) AS r1,COALESCE(q.r2,0) AS r2,COALESCE(q.r3,0) AS r3
FROM payout p LEFT JOIN quality q ON q.race_code=p.race_code
";
        $stmt=$pdo->prepare($sql);
        $stmt->execute(array_merge($chunk,$chunk));
        while ($r=$stmt->fetch(PDO::FETCH_ASSOC)) {
            $code=trim((string)$r['race_code']);
            $p=(int)($r['trifecta_payout'] ?? 0);
            $normal=((int)$r['r1']===1 && (int)$r['r2']===1 && (int)$r['r3']===1);
            $out[$code]=['payout'=>$p,'valid'=>($p>0 && $normal)];
        }
    }
    return $out;
}

function ptoWebBothNon1(array $r): bool
{
    return $r['honmei_head'] !== null && $r['taikou_head'] !== null
        && $r['honmei_head'] !== 1 && $r['taikou_head'] !== 1;
}

/**
 * 1ファミリー1点を基本とし、単変量探索で両期間とも母体超えした兆候だけを採用。
 */
function ptoSignals(array $r): array
{
    $medium=[
        'イン逃げ50未満' => $r['nige'] < 50.0,
        'Web本命対抗とも非1' => ptoWebBothNon1($r),
        '序盤1-4R' => $r['race_no'] >= 1 && $r['race_no'] <= 4,
        '捲り最大20-29' => $r['makuri_max'] >= 20.0 && $r['makuri_max'] < 30.0,
        '攻め20+が2艇' => $r['attack_count20'] === 2,
    ];

    // 差し/捲り/差捲最大は相関しやすいため「強い攻め兆候」で1点にまとめる。
    $strongAttack =
        ($r['attack_max'] >= 30.0 && $r['attack_max'] < 40.0)
        || ($r['makuri_max'] >= 15.0 && $r['makuri_max'] < 20.0)
        || ($r['sashi_max'] >= 25.0);

    $high=[
        'イン逃げ40未満' => $r['nige'] < 40.0,
        'Web本命対抗とも非1' => ptoWebBothNon1($r),
        '序盤1-4R' => $r['race_no'] >= 1 && $r['race_no'] <= 4,
        '強い攻め兆候' => $strongAttack,
        '外コース勝率40以上' => $r['outer_win_max'] >= 40.0,
        '攻め上位差3pt未満' => $r['attack_gap'] < 3.0,
    ];

    $big=[
        'イン逃げ60-69' => $r['nige'] >= 60.0 && $r['nige'] < 70.0,
        '後半9-12R' => $r['race_no'] >= 9 && $r['race_no'] <= 12,
        '攻め20+が2艇' => $r['attack_count20'] === 2,
        '捲り最大15-19' => $r['makuri_max'] >= 15.0 && $r['makuri_max'] < 20.0,
        '外コース勝率15未満' => $r['outer_win_max'] < 15.0,
    ];

    return ['medium'=>$medium,'high'=>$high,'big'=>$big];
}

function ptoScore(array $flags): int
{
    $s=0;
    foreach ($flags as $v) if ($v) $s++;
    return $s;
}

function ptoTargetHit(int $payout,string $target): bool
{
    return match ($target) {
        'medium' => $payout >= 5000 && $payout < 10000,
        'high' => $payout >= 10000 && $payout < 20000,
        'big' => $payout >= 20000,
        default => false,
    };
}

function ptoMetrics(array $rows,string $target): array
{
    $n=count($rows); $hit=0; $ge5=0; $non1=0; $payouts=[];
    foreach ($rows as $r) {
        $p=(int)$r['payout'];
        $payouts[]=$p;
        if (ptoTargetHit($p,$target)) $hit++;
        if ($p>=5000) $ge5++;
        if ((int)$r['actual_1st']!==1) $non1++;
    }
    return [
        'n'=>$n,
        'hit'=>$hit,
        'rate'=>ptoPct($hit,$n),
        'ge5'=>ptoPct($ge5,$n),
        'non1'=>ptoPct($non1,$n),
        'avg'=>$n ? array_sum($payouts)/$n : 0.0,
        'median'=>ptoMedian($payouts),
    ];
}

function ptoRowsByExactScore(array $rows,string $target,int $score): array
{
    return array_values(array_filter($rows,static fn(array $r): bool => (int)$r["{$target}_score"] === $score));
}

function ptoRowsByMinScore(array $rows,string $target,int $score): array
{
    return array_values(array_filter($rows,static fn(array $r): bool => (int)$r["{$target}_score"] >= $score));
}

function ptoPrintOne(string $label,array $m,float $base): void
{
    printf("%-8s N=%6d  対象率=%6.2f%%  母体差=%+6.2fpt  5千+=%6.2f%%  非1頭=%6.2f%%  平均=%8.0f円  中央=%7.0f円\n",
        $label,$m['n'],$m['rate'],$m['rate']-$base,$m['ge5'],$m['non1'],$m['avg'],$m['median']);
}

$all=[];
foreach (array_slice($argv,1) as $path) {
    try { $rows=ptoLoadRows($path); }
    catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
    foreach ($rows as $code=>$r) $all[$code]=$r;
}
if (!$all) { fwrite(STDERR,"分析可能な行がありません\n"); exit(1); }
ksort($all);

try {
    $pdo=getPDO();
    $payouts=ptoLoadPayouts($pdo,array_keys($all));
} catch (Throwable $e) {
    fwrite(STDERR,"払戻取得失敗: ".$e->getMessage()."\n");
    exit(1);
}

$valid=[]; $missing=0; $special=0;
foreach ($all as $code=>$r) {
    if (!isset($payouts[$code])) { $missing++; continue; }
    if (!($payouts[$code]['valid'] ?? false)) {
        if ((int)($payouts[$code]['payout'] ?? 0)<=0) $missing++; else $special++;
        continue;
    }
    $r['payout']=(int)$payouts[$code]['payout'];
    $signals=ptoSignals($r);
    foreach (['medium','high','big'] as $target) {
        $r["{$target}_flags"]=$signals[$target];
        $r["{$target}_score"]=ptoScore($signals[$target]);
    }
    $valid[$code]=$r;
}

$train=[]; $forward=[];
foreach ($valid as $code=>$r) {
    if ($r['date'] < PTO_HOLDOUT_START) $train[$code]=$r;
    else $forward[$code]=$r;
}

$dateMin=null; $dateMax=null;
foreach ($valid as $r) {
    $dateMin=$dateMin===null || $r['date']<$dateMin ? $r['date'] : $dateMin;
    $dateMax=$dateMax===null || $r['date']>$dateMax ? $r['date'] : $dateMax;
}

$outPath=__DIR__.'/output/payout_trigger_overlap_'.($dateMin ?? 'start').'_'.($dateMax ?? 'end').'.csv';
$out=fopen($outPath,'wb');
if ($out!==false) {
    fputcsv($out,['race_code','date','payout','actual_1st','medium_score','high_score','big_score','medium_flags','high_flags','big_flags']);
    foreach ($valid as $r) {
        $flagText=[];
        foreach (['medium','high','big'] as $t) {
            $on=[];
            foreach ($r["{$t}_flags"] as $name=>$v) if ($v) $on[]=$name;
            $flagText[$t]=implode('|',$on);
        }
        fputcsv($out,[$r['race_code'],$r['date'],$r['payout'],$r['actual_1st'],$r['medium_score'],$r['high_score'],$r['big_score'],$flagText['medium'],$flagText['high'],$flagText['big']]);
    }
    fclose($out);
}

$line=str_repeat('=',136);
echo $line."\n";
echo "中配当・高配当・大穴 きっかけ重なり探索\n";
echo "期間         : ".ptoFmtDate($dateMin)." ～ ".ptoFmtDate($dateMax)."\n";
echo "探索期間     : ～ 2026-08-14\n";
echo "前方確認     : 2026-08-15 ～ 2026-08-31相当（候補選定にも使用済み）\n";
echo "分析可能     : ".number_format(count($valid))."R\n";
echo "払戻欠損     : {$missing}R / 特殊除外: {$special}R\n";
echo "出力CSV      : {$outPath}\n";
echo $line."\n\n";

echo "【採用したきっかけファミリー】\n";
$dummy=['nige'=>65.0,'honmei_head'=>2,'taikou_head'=>3,'race_no'=>10,'makuri_max'=>17.0,'attack_count20'=>2,'attack_max'=>35.0,'sashi_max'=>26.0,'outer_win_max'=>14.0,'attack_gap'=>2.0];
$defs=ptoSignals($dummy);
foreach (['medium'=>'中配当','high'=>'高配当','big'=>'大穴'] as $t=>$jp) {
    echo "{$jp}: ".implode(' / ',array_keys($defs[$t]))."\n";
}

echo "\n";

$targetNames=['medium'=>'中配当 5,000～9,999円','high'=>'高配当 10,000～19,999円','big'=>'大穴 20,000円以上'];
$maxScores=['medium'=>5,'high'=>6,'big'=>5];

foreach ($targetNames as $target=>$title) {
    $baseTrain=ptoMetrics($train,$target);
    $baseForward=ptoMetrics($forward,$target);
    echo $line."\n";
    echo "【{$title}】\n";
    printf("母体 探索N=%d 率=%.2f%% / 前方N=%d 率=%.2f%%\n",
        $baseTrain['n'],$baseTrain['rate'],$baseForward['n'],$baseForward['rate']);

    echo "\n-- スコアちょうど（何個該当したか） --\n";
    printf("%-8s | %-58s | %-58s\n",'Score','探索期間','前方期間');
    echo str_repeat('-',136)."\n";
    for ($s=0; $s<=$maxScores[$target]; $s++) {
        $a=ptoMetrics(ptoRowsByExactScore($train,$target,$s),$target);
        $b=ptoMetrics(ptoRowsByExactScore($forward,$target,$s),$target);
        printf("%2d個     | N=%6d 率=%6.2f%% 差=%+6.2fpt | N=%6d 率=%6.2f%% 差=%+6.2fpt\n",
            $s,$a['n'],$a['rate'],$a['rate']-$baseTrain['rate'],$b['n'],$b['rate'],$b['rate']-$baseForward['rate']);
    }

    echo "\n-- スコア以上（検索条件にするならこちらを見る） --\n";
    for ($s=1; $s<=$maxScores[$target]; $s++) {
        $a=ptoMetrics(ptoRowsByMinScore($train,$target,$s),$target);
        $b=ptoMetrics(ptoRowsByMinScore($forward,$target,$s),$target);
        echo "Score>={$s}\n";
        ptoPrintOne('探索',$a,$baseTrain['rate']);
        ptoPrintOne('前方',$b,$baseForward['rate']);
    }
    echo "\n";
}

echo "【判断ポイント】\n";
echo "1. スコアが増えるほど対象配当率が概ね上がるか。単発の高率より階段性を重視する。\n";
echo "2. Score>=2 / >=3 などで探索・前方の両方が母体超えなら『複数きっかけ』方式の候補。\n";
echo "3. 中配当・高配当・大穴は別スコア。大穴を中配当スコアの延長として扱わない。\n";
echo "4. ここで閾値を細かく再調整しない。固定候補を作った後、2026-09-01以降の未使用期間で再確認する。\n";
echo $line."\n";
