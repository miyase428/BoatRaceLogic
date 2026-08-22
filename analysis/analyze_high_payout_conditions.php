<?php

declare(strict_types=1);

/**
 * 万舟・高配当 条件探索 STEP1
 *
 * 目的:
 *   イン飛び警報とは別に、「このレースは高配当になりやすい条件が重なっているか」を探す。
 *   条件側にはレース前に分かっている評価値だけを使い、actual_* と払戻は答え側にのみ使う。
 *
 * 分類:
 *   通常        : < 10,000円
 *   万舟        : >= 10,000円
 *   3万以上     : >= 30,000円
 *   10万以上    : >= 100,000円
 *
 * Web基準の荒れ型（万舟のみ）:
 *   相手荒れ型 : actual_1st == honmei_head
 *   頭荒れ型   : actual_1st != honmei_head かつ、実2/3着に最終5-6位・切る艇がいない
 *   全体荒れ型 : actual_1st != honmei_head かつ、実2/3着に最終5-6位または切る艇がいる
 *
 * Usage:
 *   1期間:
 *   php analysis/analyze_high_payout_conditions.php <payout.csv> <races.csv> <boats.csv>
 *
 *   2期間比較:
 *   php analysis/analyze_high_payout_conditions.php <payout.csv> \
 *     <P1_races.csv> <P1_boats.csv> <P2_races.csv> <P2_boats.csv>
 */

if ($argc !== 4 && $argc !== 6) {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  1期間: php analysis/analyze_high_payout_conditions.php <payout.csv> <races.csv> <boats.csv>\n");
    fwrite(STDERR, "  2期間: php analysis/analyze_high_payout_conditions.php <payout.csv> <P1_races.csv> <P1_boats.csv> <P2_races.csv> <P2_boats.csv>\n");
    exit(1);
}

const MANSHU = 10000;
const PAYOUT_30K = 30000;
const PAYOUT_100K = 100000;
const MIN_COMBO_N = 30;
const MIN_PERIOD_N = 10;
const MAX_COMBO_SIZE = 4;

function pct(int $n, int $d): float { return $d > 0 ? ($n / $d) * 100.0 : 0.0; }
function fmtPct(float $v): string { return number_format($v, 2) . '%'; }
function fmtLift(float $v): string { return number_format($v, 2) . 'x'; }

function readCsvAssoc(string $path, array $required): array
{
    if (!is_file($path)) throw new RuntimeException("CSVが見つかりません: {$path}");
    $fp = fopen($path, 'rb');
    if ($fp === false) throw new RuntimeException("CSVを開けません: {$path}");
    $header = fgetcsv($fp);
    if ($header === false) { fclose($fp); throw new RuntimeException("CSVが空です: {$path}"); }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $map = [];
    foreach ($header as $i => $name) $map[(string)$name] = $i;
    foreach ($required as $col) {
        if (!array_key_exists($col, $map)) { fclose($fp); throw new RuntimeException("必要な列がありません: {$col} ({$path})"); }
    }
    $rows = [];
    while (($row = fgetcsv($fp)) !== false) {
        if (count($row) < count($header)) continue;
        $a = [];
        foreach ($map as $name => $i) $a[$name] = $row[$i] ?? '';
        $rows[] = $a;
    }
    fclose($fp);
    return $rows;
}

function loadPayouts(string $path): array
{
    $rows = readCsvAssoc($path, ['race_code', 'trifecta_payout']);
    $map = [];
    foreach ($rows as $row) {
        $code = trim((string)$row['race_code']);
        $p = $row['trifecta_payout'];
        if ($code !== '' && is_numeric($p) && (int)$p > 0) $map[$code] = (int)$p;
    }
    return $map;
}

function num(array $row, string $key): ?float
{
    $v = $row[$key] ?? null;
    return is_numeric($v) ? (float)$v : null;
}

function rankOf(array $boats, string $rankKey, int $lane): ?int
{
    $v = $boats[$lane][$rankKey] ?? null;
    return is_numeric($v) ? (int)$v : null;
}

function topLaneByRank(array $boats, string $rankKey): ?int
{
    $bestLane = null; $bestRank = 999;
    foreach ($boats as $lane => $row) {
        $r = $row[$rankKey] ?? null;
        if (!is_numeric($r)) continue;
        $ri = (int)$r;
        if ($ri < $bestRank) { $bestRank = $ri; $bestLane = (int)$lane; }
    }
    return $bestLane;
}

function countOuterTop(array $boats, string $rankKey, int $maxRank): int
{
    $n = 0;
    foreach ([4,5,6] as $lane) {
        $r = rankOf($boats, $rankKey, $lane);
        if ($r !== null && $r <= $maxRank) $n++;
    }
    return $n;
}

function scoreSorted(array $boats, string $key): array
{
    $arr = [];
    foreach ($boats as $lane => $row) {
        $v = num($row, $key);
        if ($v !== null) $arr[(int)$lane] = $v;
    }
    arsort($arr, SORT_NUMERIC);
    return $arr;
}

function deriveFeatures(array $boats, int $honmei): array
{
    $pTop = topLaneByRank($boats, 'first_rank');
    $sTop = topLaneByRank($boats, 'second_rank');
    $fTop = topLaneByRank($boats, 'final_rank');

    $p1Rank = rankOf($boats, 'first_rank', 1) ?? 99;
    $s1Rank = rankOf($boats, 'second_rank', 1) ?? 99;
    $f1Rank = rankOf($boats, 'final_rank', 1) ?? 99;

    $pScores = scoreSorted($boats, 'first_total_score');
    $sScores = scoreSorted($boats, 'second_score');
    $fScores = scoreSorted($boats, 'final3');

    $pVals = array_values($pScores);
    $sVals = array_values($sScores);
    $fVals = array_values($fScores);

    $lane1P = num($boats[1] ?? [], 'first_total_score');
    $lane1S = num($boats[1] ?? [], 'second_score');
    $pTopScore = $pVals[0] ?? null;
    $sTopScore = $sVals[0] ?? null;

    $kiruCount = 0;
    foreach ($boats as $row) if ((int)($row['kiru'] ?? 0) === 1) $kiruCount++;

    return [
        '本命が1号艇ではない' => $honmei !== 1,
        '1号艇一次4位以下' => $p1Rank >= 4,
        '1号艇二次4位以下' => $s1Rank >= 4,
        '1号艇最終4位以下' => $f1Rank >= 4,
        '一次1位と二次1位が不一致' => $pTop !== null && $sTop !== null && $pTop !== $sTop,
        '一次1位が4～6号艇' => $pTop !== null && $pTop >= 4,
        '二次1位が4～6号艇' => $sTop !== null && $sTop >= 4,
        '最終1位が4～6号艇' => $fTop !== null && $fTop >= 4,
        '一次TOP3に外枠2艇以上' => countOuterTop($boats, 'first_rank', 3) >= 2,
        '最終TOP3に外枠2艇以上' => countOuterTop($boats, 'final_rank', 3) >= 2,
        '1号艇と一次TOP差5以上' => $lane1P !== null && $pTopScore !== null && ($pTopScore - $lane1P) >= 5.0,
        '1号艇と二次TOP差5以上' => $lane1S !== null && $sTopScore !== null && ($sTopScore - $lane1S) >= 5.0,
        '一次1位-2位差2以下' => isset($pVals[0], $pVals[1]) && abs($pVals[0] - $pVals[1]) <= 2.0,
        '二次1位-2位差2以下' => isset($sVals[0], $sVals[1]) && abs($sVals[0] - $sVals[1]) <= 2.0,
        '最終1位-2位差1以下' => isset($fVals[0], $fVals[1]) && abs($fVals[0] - $fVals[1]) <= 1.0,
        '最終1位-3位差3以下' => isset($fVals[0], $fVals[2]) && abs($fVals[0] - $fVals[2]) <= 3.0,
        '切る艇あり' => $kiruCount >= 1,
        '切る艇2艇以上' => $kiruCount >= 2,
    ];
}

function loadPeriod(string $raceCsv, string $boatCsv, array $payouts, string $label): array
{
    $raceRows = readCsvAssoc($raceCsv, [
        'race_code','race_date','stadium_name','honmei_head','actual_1st','actual_2nd','actual_3rd','actual_trifecta'
    ]);
    $boatRows = readCsvAssoc($boatCsv, [
        'race_code','lane_number','first_total_score','first_rank','second_score','second_rank','final3','final_rank','kiru'
    ]);

    $boatsByRace = [];
    foreach ($boatRows as $row) {
        $code = trim((string)$row['race_code']);
        $lane = (int)$row['lane_number'];
        if ($code === '' || $lane < 1 || $lane > 6) continue;
        $boatsByRace[$code][$lane] = $row;
    }

    $races = []; $start = null; $end = null;
    foreach ($raceRows as $row) {
        $code = trim((string)$row['race_code']);
        $honmei = (int)$row['honmei_head'];
        $a1 = (int)$row['actual_1st']; $a2 = (int)$row['actual_2nd']; $a3 = (int)$row['actual_3rd'];
        $boats = $boatsByRace[$code] ?? [];
        $payout = $payouts[$code] ?? null;
        if ($code === '' || $honmei < 1 || $honmei > 6 || $a1 < 1 || $a1 > 6 || $a2 < 1 || $a2 > 6 || $a3 < 1 || $a3 > 6 || count($boats) !== 6 || $payout === null) continue;

        $date = trim((string)$row['race_date']);
        $races[] = [
            'race_code' => $code,
            'race_date' => $date,
            'stadium' => trim((string)$row['stadium_name']),
            'honmei' => $honmei,
            'actual1' => $a1,
            'actual2' => $a2,
            'actual3' => $a3,
            'payout' => (int)$payout,
            'boats' => $boats,
            'features' => deriveFeatures($boats, $honmei),
        ];
        if ($date !== '') {
            if ($start === null || $date < $start) $start = $date;
            if ($end === null || $date > $end) $end = $date;
        }
    }
    return ['label'=>$label,'races'=>$races,'start_date'=>$start,'end_date'=>$end];
}

function summarize(array $races): array
{
    $s = ['n'=>0,'10k'=>0,'30k'=>0,'100k'=>0,'payout_sum'=>0];
    foreach ($races as $r) {
        $p = (int)$r['payout'];
        $s['n']++; $s['payout_sum'] += $p;
        if ($p >= MANSHU) $s['10k']++;
        if ($p >= PAYOUT_30K) $s['30k']++;
        if ($p >= PAYOUT_100K) $s['100k']++;
    }
    return $s;
}

function filterByFeatures(array $races, array $features): array
{
    return array_values(array_filter($races, static function(array $r) use ($features): bool {
        foreach ($features as $f) if (empty($r['features'][$f])) return false;
        return true;
    }));
}

function combinations(array $items, int $k, int $offset = 0, array $prefix = []): array
{
    if ($k === 0) return [$prefix];
    $out = [];
    for ($i = $offset; $i <= count($items) - $k; $i++) {
        foreach (combinations($items, $k - 1, $i + 1, array_merge($prefix, [$items[$i]])) as $c) $out[] = $c;
    }
    return $out;
}

function classifyRough(array $r): string
{
    if ((int)$r['payout'] < MANSHU) return '通常';
    if ((int)$r['actual1'] === (int)$r['honmei']) return '相手荒れ型';

    $roughMate = false;
    foreach ([(int)$r['actual2'], (int)$r['actual3']] as $lane) {
        $row = $r['boats'][$lane] ?? [];
        $rank = isset($row['final_rank']) && is_numeric($row['final_rank']) ? (int)$row['final_rank'] : 99;
        $kiru = (int)($row['kiru'] ?? 0) === 1;
        if ($rank >= 5 || $kiru) { $roughMate = true; break; }
    }
    return $roughMate ? '全体荒れ型' : '頭荒れ型';
}

$payoutCsv = $argv[1];
try {
    $payouts = loadPayouts($payoutCsv);
    $periods = [];
    $periods[] = loadPeriod($argv[2], $argv[3], $payouts, 'P1');
    if ($argc === 6) $periods[] = loadPeriod($argv[4], $argv[5], $payouts, 'P2');
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$allRaces = [];
foreach ($periods as $p) $allRaces = array_merge($allRaces, $p['races']);
$base = summarize($allRaces);
$base10 = pct($base['10k'], $base['n']);

$featureNames = array_keys($allRaces[0]['features'] ?? []);

echo PHP_EOL . str_repeat('=', 154) . PHP_EOL;
echo "万舟・高配当 条件探索 STEP1" . PHP_EOL;
echo str_repeat('=', 154) . PHP_EOL;
foreach ($periods as $p) echo "{$p['label']} : {$p['start_date']} ～ {$p['end_date']} / " . count($p['races']) . "R" . PHP_EOL;
echo "条件側は事前情報のみ。払戻・実着順は答え側にのみ使用。" . PHP_EOL;

echo PHP_EOL . "【全体分布】" . PHP_EOL;
echo sprintf("対象 %dR / 万舟 %s / 3万以上 %s / 10万以上 %s / 平均払戻 %.0f円\n",
    $base['n'], fmtPct(pct($base['10k'],$base['n'])), fmtPct(pct($base['30k'],$base['n'])), fmtPct(pct($base['100k'],$base['n'])), $base['n'] ? $base['payout_sum']/$base['n'] : 0);

$types = ['相手荒れ型'=>0,'頭荒れ型'=>0,'全体荒れ型'=>0];
foreach ($allRaces as $r) {
    $t = classifyRough($r);
    if (isset($types[$t])) $types[$t]++;
}
echo "万舟の荒れ型(Web基準): ";
foreach ($types as $t=>$n) echo "{$t} {$n}R(" . fmtPct(pct($n,$base['10k'])) . ")  ";
echo PHP_EOL;

$single = [];
foreach ($featureNames as $f) {
    $rs = filterByFeatures($allRaces, [$f]);
    $s = summarize($rs);
    if ($s['n'] < MIN_COMBO_N) continue;
    $rate = pct($s['10k'],$s['n']);
    $single[] = ['label'=>$f,'n'=>$s['n'],'rate'=>$rate,'lift'=>$base10>0?$rate/$base10:0,'30k'=>pct($s['30k'],$s['n']),'100k'=>pct($s['100k'],$s['n'])];
}
usort($single, static fn($a,$b) => $b['lift'] <=> $a['lift']);

echo PHP_EOL . "【単独条件：万舟Lift上位】" . PHP_EOL;
echo sprintf("%-36s %7s %10s %8s %10s %10s\n", '条件','N','万舟率','Lift','3万以上','10万以上');
echo str_repeat('-', 96) . PHP_EOL;
foreach (array_slice($single,0,20) as $x) {
    echo sprintf("%-36s %7d %10s %8s %10s %10s\n", $x['label'],$x['n'],fmtPct($x['rate']),fmtLift($x['lift']),fmtPct($x['30k']),fmtPct($x['100k']));
}

$comboRows = [];
for ($k=2; $k<=MAX_COMBO_SIZE; $k++) {
    foreach (combinations($featureNames,$k) as $combo) {
        $rs = filterByFeatures($allRaces,$combo);
        $s = summarize($rs);
        if ($s['n'] < MIN_COMBO_N) continue;
        $rate = pct($s['10k'],$s['n']);
        $row = ['combo'=>$combo,'n'=>$s['n'],'rate'=>$rate,'lift'=>$base10>0?$rate/$base10:0,'30k'=>pct($s['30k'],$s['n']),'periods'=>[],'repro'=>true];
        foreach ($periods as $p) {
            $ps = summarize(filterByFeatures($p['races'],$combo));
            $pr = pct($ps['10k'],$ps['n']);
            $plift = $base10>0?$pr/$base10:0;
            $row['periods'][] = ['n'=>$ps['n'],'rate'=>$pr,'lift'=>$plift];
            if ($ps['n'] < MIN_PERIOD_N || $pr <= $base10) $row['repro'] = false;
        }
        $comboRows[] = $row;
    }
}
usort($comboRows, static function($a,$b) {
    if ($a['repro'] !== $b['repro']) return $a['repro'] ? -1 : 1;
    if ($a['lift'] === $b['lift']) return $b['n'] <=> $a['n'];
    return $b['lift'] <=> $a['lift'];
});

echo PHP_EOL . "【組み合わせ条件：再現候補優先】" . PHP_EOL;
echo "※ 2期間時はP1/P2ともN>=" . MIN_PERIOD_N . "かつ各期間の万舟率が全体平均超えで『再現○』。\n";
$header = sprintf("%-72s %6s %9s %7s %9s %6s", '条件','N','万舟率','Lift','3万以上','再現');
foreach ($periods as $p) $header .= sprintf(" %12s", $p['label'].' N/率');
echo $header . PHP_EOL;
echo str_repeat('-', 154) . PHP_EOL;
foreach (array_slice($comboRows,0,30) as $x) {
    $line = sprintf("%-72s %6d %9s %7s %9s %6s", implode(' + ',$x['combo']),$x['n'],fmtPct($x['rate']),fmtLift($x['lift']),fmtPct($x['30k']),$x['repro']?'○':'△');
    foreach ($x['periods'] as $ps) $line .= sprintf(" %4d/%-7s", $ps['n'], fmtPct($ps['rate']));
    echo $line . PHP_EOL;
}

echo PHP_EOL . "見方:" . PHP_EOL;
echo "・Lift > 1 は全体平均より万舟率が高い。\n";
echo "・Nが小さい高Liftは過適合を疑う。組合せ条件はまず再現○を優先。\n";
echo "・相手荒れ/頭荒れ/全体荒れは現時点ではWeb予想基準の暫定分類。後で個別条件探索へ分岐できる。\n";
echo "・このSTEPでは警報ロジックをまだ作らず、『高配当条件候補の発見』だけを行う。\n";
