<?php
/**
 * 荒れサイン役割・強いペアの未使用前方検証。
 *
 * ここまでの探索（2025-08-15〜2026-08-31）で候補化した定義を固定し、
 * 2026-09-01以降の未使用期間だけで再確認する。
 *
 * 重要:
 * - このスクリプトでは閾値を調整しない。
 * - 単独サイン / 強いペア / 固定スコア閾値を同時に確認する。
 * - 9/1以降の結果を見て定義を微調整せず、採用/保留/棄却の判断材料にする。
 *
 * Usage:
 *   php analysis/validate_payout_signal_roles_untouched.php KIMARITE_CSV [KIMARITE_CSV ...]
 */

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

const VPS_START = '20260901';
const VPS_MIN_SAMPLE_N = 10;

function vpsUsage(): never
{
    fwrite(STDERR, "使用方法:\n  php analysis/validate_payout_signal_roles_untouched.php KIMARITE_CSV [KIMARITE_CSV ...]\n");
    exit(1);
}
if ($argc < 2) vpsUsage();

function vpsPct(int $num, int $den): float
{
    return $den > 0 ? 100.0 * $num / $den : 0.0;
}

function vpsNum($v): ?float
{
    $s = trim((string)$v);
    return ($s !== '' && is_numeric($s)) ? (float)$s : null;
}

function vpsFmtDate(?string $ymd): string
{
    if ($ymd === null || !preg_match('/^\d{8}$/', $ymd)) return '-';
    return substr($ymd,0,4) . '-' . substr($ymd,4,2) . '-' . substr($ymd,6,2);
}

function vpsLoadRows(string $path): array
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
        foreach (['sample_n','win','sashi','makuri'] as $suffix) {
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
        if ($date < VPS_START) continue;

        $actual = trim((string)($data[$idx['actual_1st']] ?? ''));
        if (!preg_match('/^[1-6]$/',$actual)) continue;

        $n1 = (int)($data[$idx['c1_1y_sample_n']] ?? 0);
        $nige = vpsNum($data[$idx['c1_1y_nige']] ?? null);
        if ($n1 < VPS_MIN_SAMPLE_N || $nige === null) continue;

        $attacks=[]; $sashis=[]; $makuris=[]; $wins=[];
        for ($c=2; $c<=6; $c++) {
            $n=(int)($data[$idx["c{$c}_1y_sample_n"]] ?? 0);
            if ($n < VPS_MIN_SAMPLE_N) continue;
            $win=vpsNum($data[$idx["c{$c}_1y_win"]] ?? null);
            $sashi=vpsNum($data[$idx["c{$c}_1y_sashi"]] ?? null);
            $makuri=vpsNum($data[$idx["c{$c}_1y_makuri"]] ?? null);
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

function vpsLoadPayouts(PDO $pdo,array $raceCodes): array
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

function vpsWebBothNon1(array $r): bool
{
    return $r['honmei_head'] !== null && $r['taikou_head'] !== null
        && $r['honmei_head'] !== 1 && $r['taikou_head'] !== 1;
}

function vpsSignals(array $r): array
{
    $medium=[
        'イン逃げ50未満' => $r['nige'] < 50.0,
        'Web本命対抗とも非1' => vpsWebBothNon1($r),
        '序盤1-4R' => $r['race_no'] >= 1 && $r['race_no'] <= 4,
        '捲り最大20-29' => $r['makuri_max'] >= 20.0 && $r['makuri_max'] < 30.0,
        '攻め20+が2艇' => $r['attack_count20'] === 2,
    ];

    $strongAttack =
        ($r['attack_max'] >= 30.0 && $r['attack_max'] < 40.0)
        || ($r['makuri_max'] >= 15.0 && $r['makuri_max'] < 20.0)
        || ($r['sashi_max'] >= 25.0);

    $high=[
        'イン逃げ40未満' => $r['nige'] < 40.0,
        'Web本命対抗とも非1' => vpsWebBothNon1($r),
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

function vpsTargetHit(int $payout,string $target): bool
{
    return match($target) {
        'medium' => $payout >= 5000 && $payout < 10000,
        'high' => $payout >= 10000 && $payout < 20000,
        'big' => $payout >= 20000,
        default => false,
    };
}

function vpsMetrics(array $rows,string $target): array
{
    $n=count($rows); $hit=0; $non1=0; $ge5=0;
    foreach ($rows as $r) {
        $p=(int)$r['payout'];
        if (vpsTargetHit($p,$target)) $hit++;
        if ($p>=5000) $ge5++;
        if ((int)$r['actual_1st']!==1) $non1++;
    }
    return [
        'n'=>$n,
        'rate'=>vpsPct($hit,$n),
        'non1'=>vpsPct($non1,$n),
        'ge5'=>vpsPct($ge5,$n),
    ];
}

function vpsRowsBySignal(array $rows,string $target,string $signal): array
{
    return array_values(array_filter($rows, static function(array $r) use($target,$signal): bool {
        $s=vpsSignals($r);
        return (bool)($s[$target][$signal] ?? false);
    }));
}

function vpsRowsByPair(array $rows,string $target,string $a,string $b): array
{
    return array_values(array_filter($rows, static function(array $r) use($target,$a,$b): bool {
        $s=vpsSignals($r)[$target];
        return (bool)($s[$a] ?? false) && (bool)($s[$b] ?? false);
    }));
}

function vpsRowsByScore(array $rows,string $target,int $minScore): array
{
    return array_values(array_filter($rows, static function(array $r) use($target,$minScore): bool {
        $flags=vpsSignals($r)[$target];
        $score=0;
        foreach ($flags as $v) if ($v) $score++;
        return $score >= $minScore;
    }));
}

$all=[];
foreach (array_slice($argv,1) as $path) {
    try { $part=vpsLoadRows($path); }
    catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
    foreach ($part as $code=>$r) $all[$code]=$r;
}
ksort($all);
if (!$all) {
    fwrite(STDERR,"2026-09-01以降の分析可能データがありません。\n");
    exit(1);
}

try {
    $pdo=getPDO();
    $pq=vpsLoadPayouts($pdo,array_keys($all));
} catch (Throwable $e) {
    fwrite(STDERR,"払戻取得失敗: {$e->getMessage()}\n");
    exit(1);
}

$valid=[]; $missing=0;
foreach ($all as $code=>$r) {
    if (!isset($pq[$code]) || !($pq[$code]['valid'] ?? false)) { $missing++; continue; }
    $r['payout']=(int)$pq[$code]['payout'];
    $valid[$code]=$r;
}
if (!$valid) {
    fwrite(STDERR,"払戻まで含めた有効データがありません。\n");
    exit(1);
}

$dateMin=null; $dateMax=null;
foreach ($valid as $r) {
    $dateMin=$dateMin===null || $r['date']<$dateMin ? $r['date'] : $dateMin;
    $dateMax=$dateMax===null || $r['date']>$dateMax ? $r['date'] : $dateMax;
}

$singles=[
    'medium'=>[
        ['イン逃げ50未満','安定主力'],
        ['Web本命対抗とも非1','増幅'],
        ['序盤1-4R','増幅'],
        ['捲り最大20-29','増幅'],
        ['攻め20+が2艇','希少観察'],
    ],
    'high'=>[
        ['イン逃げ40未満','土台/増幅'],
        ['Web本命対抗とも非1','増幅'],
        ['序盤1-4R','増幅'],
        ['強い攻め兆候','増幅'],
        ['外コース勝率40以上','希少条件付き'],
        ['攻め上位差3pt未満','補助'],
    ],
    'big'=>[
        ['イン逃げ60-69','組合せ'],
        ['後半9-12R','組合せ'],
        ['捲り最大15-19','増幅'],
        ['外コース勝率15未満','補助'],
        ['攻め20+が2艇','希少観察'],
    ],
];

$pairs=[
    'medium'=>[
        ['イン逃げ50未満','Web本命対抗とも非1'],
        ['イン逃げ50未満','捲り最大20-29'],
        ['イン逃げ50未満','序盤1-4R'],
        ['Web本命対抗とも非1','序盤1-4R'],
        ['序盤1-4R','捲り最大20-29'],
    ],
    'high'=>[
        ['イン逃げ40未満','序盤1-4R'],
        ['Web本命対抗とも非1','序盤1-4R'],
        ['Web本命対抗とも非1','強い攻め兆候'],
        ['イン逃げ40未満','強い攻め兆候'],
        ['イン逃げ40未満','Web本命対抗とも非1'],
        ['Web本命対抗とも非1','攻め上位差3pt未満'],
        ['強い攻め兆候','外コース勝率40以上'],
    ],
    'big'=>[
        ['イン逃げ60-69','捲り最大15-19'],
        ['後半9-12R','捲り最大15-19'],
        ['イン逃げ60-69','後半9-12R'],
        ['後半9-12R','外コース勝率15未満'],
        ['イン逃げ60-69','外コース勝率15未満'],
    ],
];

$scoreCuts=[
    'medium'=>[2,3],
    'high'=>[2,3,4],
    'big'=>[2,3],
];
$labels=['medium'=>'中配当 5,000〜9,999円','high'=>'高配当 10,000〜19,999円','big'=>'大穴 20,000円以上'];

$line=str_repeat('=',154);
echo $line."\n";
echo "荒れサイン役割・強いペア 未使用前方検証（定義固定）\n";
echo "期間       : ".vpsFmtDate($dateMin)." ～ ".vpsFmtDate($dateMax)."\n";
echo "分析可能   : ".number_format(count($valid))."R\n";
echo "払戻等除外 : ".number_format($missing)."R\n";
echo "重要       : 2026-08-31までに固定した定義を変更せず確認\n";
echo $line."\n";

foreach (['medium','high','big'] as $target) {
    $base=vpsMetrics(array_values($valid),$target);
    echo "\n【{$labels[$target]}】 母体 N={$base['n']} 率=".sprintf('%5.2f',$base['rate'])."% 非1頭=".sprintf('%5.2f',$base['non1'])."%\n";

    echo "\n-- 固定サイン --\n";
    printf("%-30s %-14s %7s %9s %9s %9s %9s\n",'サイン','役割','N','対象率','母体差','非1頭','5千+');
    echo str_repeat('-',110)."\n";
    foreach ($singles[$target] as [$signal,$role]) {
        $m=vpsMetrics(vpsRowsBySignal(array_values($valid),$target,$signal),$target);
        printf("%-30s %-14s %7d %8.2f%% %+8.2fpt %8.2f%% %8.2f%%\n",
            $signal,$role,$m['n'],$m['rate'],$m['rate']-$base['rate'],$m['non1'],$m['ge5']);
    }

    echo "\n-- 固定ペア --\n";
    printf("%-56s %7s %9s %9s %9s %9s\n",'ペア','N','対象率','母体差','非1頭','5千+');
    echo str_repeat('-',120)."\n";
    foreach ($pairs[$target] as [$a,$b]) {
        $m=vpsMetrics(vpsRowsByPair(array_values($valid),$target,$a,$b),$target);
        printf("%-56s %7d %8.2f%% %+8.2fpt %8.2f%% %8.2f%%\n",
            "{$a} × {$b}",$m['n'],$m['rate'],$m['rate']-$base['rate'],$m['non1'],$m['ge5']);
    }

    echo "\n-- 固定スコア閾値 --\n";
    foreach ($scoreCuts[$target] as $cut) {
        $m=vpsMetrics(vpsRowsByScore(array_values($valid),$target,$cut),$target);
        printf("Score>=%d  N=%5d  対象率=%6.2f%%  母体差=%+6.2fpt  非1頭=%6.2f%%  5千+=%6.2f%%\n",
            $cut,$m['n'],$m['rate'],$m['rate']-$base['rate'],$m['non1'],$m['ge5']);
    }
}

echo "\n{$line}\n";
echo "【判断ルール】\n";
echo "1. ここでは閾値を再調整しない。再現した候補だけ採用側へ進める。\n";
echo "2. Nが小さい希少サイン/ペアは、率が高くても『観察継続』扱い。\n";
echo "3. 中配当はイン崩壊寄り、高配当は複数要因併発、大穴はヒモ荒れ寄りという構造も再確認する。\n";
echo "4. TOP/レース詳細へ実装する定義は、この未使用前方検証後に共通判定へ切り出す。\n";
echo "{$line}\n";
