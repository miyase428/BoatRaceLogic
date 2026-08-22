<?php

declare(strict_types=1);

/**
 * 万舟・高配当 条件候補 ホールドアウト検証
 *
 * STEP1（2026-06-15〜2026-08-14）で発見した条件候補を固定したまま、
 * 未使用期間 2026-02-17〜2026-06-14 で再現するか確認する。
 * 条件側は事前情報のみ。払戻・実着順は答え側にのみ使用。
 *
 * Usage:
 *   php analysis/validate_high_payout_conditions_holdout.php \
 *     analysis/output/trifecta_payouts_20260215_20260814.csv \
 *     analysis/output/final_prediction_races_20260215_20260814.csv \
 *     analysis/output/final_prediction_boats_20260215_20260814.csv
 */

if ($argc !== 4) {
    fwrite(STDERR, "Usage: php analysis/validate_high_payout_conditions_holdout.php <payout.csv> <races.csv> <boats.csv>\n");
    exit(1);
}

const MANSHU = 10000;
const PAYOUT_30K = 30000;
const PAYOUT_100K = 100000;

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
    $bestLane = null;
    $bestRank = 999;
    foreach ($boats as $lane => $row) {
        $r = $row[$rankKey] ?? null;
        if (!is_numeric($r)) continue;
        $ri = (int)$r;
        if ($ri < $bestRank) {
            $bestRank = $ri;
            $bestLane = (int)$lane;
        }
    }
    return $bestLane;
}

function countOuterTop(array $boats, string $rankKey, int $maxRank): int
{
    $n = 0;
    foreach ([4, 5, 6] as $lane) {
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

function deriveFeatures(array $boats): array
{
    $pTop = topLaneByRank($boats, 'first_rank');
    $sTop = topLaneByRank($boats, 'second_rank');
    $fTop = topLaneByRank($boats, 'final_rank');

    $p1Rank = rankOf($boats, 'first_rank', 1) ?? 99;
    $s1Rank = rankOf($boats, 'second_rank', 1) ?? 99;
    $f1Rank = rankOf($boats, 'final_rank', 1) ?? 99;

    $pVals = array_values(scoreSorted($boats, 'first_total_score'));
    $sVals = array_values(scoreSorted($boats, 'second_score'));
    $fVals = array_values(scoreSorted($boats, 'final3'));

    return [
        '1号艇一次4位以下' => $p1Rank >= 4,
        '1号艇二次4位以下' => $s1Rank >= 4,
        '1号艇最終4位以下' => $f1Rank >= 4,
        '一次1位が4～6号艇' => $pTop !== null && $pTop >= 4,
        '二次1位が4～6号艇' => $sTop !== null && $sTop >= 4,
        '最終1位が4～6号艇' => $fTop !== null && $fTop >= 4,
        '一次TOP3に外枠2艇以上' => countOuterTop($boats, 'first_rank', 3) >= 2,
        '最終TOP3に外枠2艇以上' => countOuterTop($boats, 'final_rank', 3) >= 2,
        '一次1位-2位差2以下' => isset($pVals[0], $pVals[1]) && abs($pVals[0] - $pVals[1]) <= 2.0,
        '二次1位-2位差2以下' => isset($sVals[0], $sVals[1]) && abs($sVals[0] - $sVals[1]) <= 2.0,
        '最終1位-2位差1以下' => isset($fVals[0], $fVals[1]) && abs($fVals[0] - $fVals[1]) <= 1.0,
        '最終1位-3位差3以下' => isset($fVals[0], $fVals[2]) && abs($fVals[0] - $fVals[2]) <= 3.0,
    ];
}

function classifyRough(array $r): string
{
    if ((int)$r['payout'] < MANSHU) return '通常';
    if ((int)$r['actual1'] === (int)$r['honmei']) return '相手荒れ型';

    foreach ([(int)$r['actual2'], (int)$r['actual3']] as $lane) {
        $row = $r['boats'][$lane] ?? [];
        $rank = isset($row['final_rank']) && is_numeric($row['final_rank']) ? (int)$row['final_rank'] : 99;
        $kiru = (int)($row['kiru'] ?? 0) === 1;
        if ($rank >= 5 || $kiru) return '全体荒れ型';
    }
    return '頭荒れ型';
}

function summarize(array $races): array
{
    $s = ['n'=>0,'10k'=>0,'30k'=>0,'100k'=>0,'mate'=>0,'head'=>0,'all'=>0];
    foreach ($races as $r) {
        $s['n']++;
        $p = (int)$r['payout'];
        if ($p >= MANSHU) {
            $s['10k']++;
            $type = classifyRough($r);
            if ($type === '相手荒れ型') $s['mate']++;
            elseif ($type === '頭荒れ型') $s['head']++;
            elseif ($type === '全体荒れ型') $s['all']++;
        }
        if ($p >= PAYOUT_30K) $s['30k']++;
        if ($p >= PAYOUT_100K) $s['100k']++;
    }
    return $s;
}

function matches(array $race, array $conditions): bool
{
    foreach ($conditions as $c) {
        if (empty($race['features'][$c])) return false;
    }
    return true;
}

$payoutCsv = $argv[1];
$raceCsv = $argv[2];
$boatCsv = $argv[3];

$candidates = [
    'A 大型安定型' => [
        '1号艇一次4位以下',
        '一次TOP3に外枠2艇以上',
        '一次1位-2位差2以下',
        '最終1位-2位差1以下',
    ],
    'B 最終外枠進出型' => [
        '1号艇一次4位以下',
        '最終TOP3に外枠2艇以上',
        '一次1位-2位差2以下',
        '最終1位-2位差1以下',
    ],
    'C 外枠二重型' => [
        '1号艇一次4位以下',
        '一次TOP3に外枠2艇以上',
        '最終TOP3に外枠2艇以上',
        '二次1位-2位差2以下',
    ],
    'D 外頭接戦型' => [
        '1号艇最終4位以下',
        '一次1位が4～6号艇',
        '一次1位-2位差2以下',
        '最終1位-3位差3以下',
    ],
];

try {
    $payoutRows = readCsvAssoc($payoutCsv, ['race_code','trifecta_payout']);
    $payouts = [];
    foreach ($payoutRows as $row) {
        $code = trim((string)$row['race_code']);
        if ($code !== '' && is_numeric($row['trifecta_payout'])) $payouts[$code] = (int)$row['trifecta_payout'];
    }

    $boatRows = readCsvAssoc($boatCsv, [
        'race_code','lane_number','first_total_score','first_rank','second_score','second_rank','final3','final_rank','kiru'
    ]);
    $boatsByRace = [];
    foreach ($boatRows as $row) {
        $code = trim((string)$row['race_code']);
        $lane = (int)$row['lane_number'];
        if ($code !== '' && $lane >= 1 && $lane <= 6) $boatsByRace[$code][$lane] = $row;
    }

    $raceRows = readCsvAssoc($raceCsv, [
        'race_code','race_date','honmei_head','actual_1st','actual_2nd','actual_3rd'
    ]);

    $races = [];
    foreach ($raceRows as $row) {
        $code = trim((string)$row['race_code']);
        $date = trim((string)$row['race_date']);
        $boats = $boatsByRace[$code] ?? [];
        $payout = $payouts[$code] ?? null;
        $honmei = (int)$row['honmei_head'];
        $a1 = (int)$row['actual_1st'];
        $a2 = (int)$row['actual_2nd'];
        $a3 = (int)$row['actual_3rd'];
        if ($code === '' || $date === '' || count($boats) !== 6 || $payout === null || $honmei < 1 || $honmei > 6 || $a1 < 1 || $a1 > 6 || $a2 < 1 || $a2 > 6 || $a3 < 1 || $a3 > 6) continue;

        $races[] = [
            'race_code'=>$code,'race_date'=>$date,'honmei'=>$honmei,
            'actual1'=>$a1,'actual2'=>$a2,'actual3'=>$a3,
            'payout'=>$payout,'boats'=>$boats,'features'=>deriveFeatures($boats),
        ];
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$periodDefs = [
    'H1 未使用前半' => ['2026-02-17','2026-04-15'],
    'H2 未使用後半' => ['2026-04-16','2026-06-14'],
    'HOLDOUT 未使用4か月' => ['2026-02-17','2026-06-14'],
    'DISCOVERY 探索済み2か月' => ['2026-06-15','2026-08-14'],
    'ALL 6か月' => ['2026-02-17','2026-08-14'],
];

$periods = [];
foreach ($periodDefs as $label => [$start,$end]) {
    $periods[$label] = array_values(array_filter($races, static fn(array $r): bool => $r['race_date'] >= $start && $r['race_date'] <= $end));
}

echo str_repeat('=', 150) . PHP_EOL;
echo "万舟・高配当 条件候補 ホールドアウト検証" . PHP_EOL;
echo str_repeat('=', 150) . PHP_EOL;
echo "STEP1で発見した条件は固定。未使用4か月を最優先で判定。" . PHP_EOL . PHP_EOL;

foreach ($periods as $label => $rows) {
    $s = summarize($rows);
    echo sprintf(
        "%-24s %6dR / 万舟 %6s / 3万以上 %6s / 10万以上 %6s\n",
        $label,
        $s['n'],
        fmtPct(pct($s['10k'],$s['n'])),
        fmtPct(pct($s['30k'],$s['n'])),
        fmtPct(pct($s['100k'],$s['n']))
    );
}

echo PHP_EOL;

foreach ($candidates as $name => $conditions) {
    echo str_repeat('-', 150) . PHP_EOL;
    echo "【{$name}】" . PHP_EOL;
    echo implode(' + ', $conditions) . PHP_EOL;
    echo str_repeat('-', 150) . PHP_EOL;
    echo sprintf("%-24s %7s %9s %8s %9s %9s %24s\n", '期間','N','万舟率','Lift','3万以上','10万以上','万舟内訳 相手/頭/全体');

    foreach ($periods as $label => $rows) {
        $base = summarize($rows);
        $matched = array_values(array_filter($rows, static fn(array $r): bool => matches($r, $conditions)));
        $s = summarize($matched);
        $rate = pct($s['10k'],$s['n']);
        $baseRate = pct($base['10k'],$base['n']);
        $lift = $baseRate > 0 ? $rate / $baseRate : 0.0;
        $breakdown = $s['10k'] > 0
            ? sprintf('%d/%d/%d', $s['mate'], $s['head'], $s['all'])
            : '-';
        echo sprintf(
            "%-24s %7d %9s %8s %9s %9s %24s\n",
            $label,
            $s['n'],
            fmtPct($rate),
            fmtLift($lift),
            fmtPct(pct($s['30k'],$s['n'])),
            fmtPct(pct($s['100k'],$s['n'])),
            $breakdown
        );
    }
    echo PHP_EOL;
}

echo str_repeat('=', 150) . PHP_EOL;
echo "見方" . PHP_EOL;
echo str_repeat('=', 150) . PHP_EOL;
echo "・最優先は HOLDOUT 未使用4か月。Discoveryより数字が下がってもLift>1が残るかを見る。" . PHP_EOL;
echo "・H1/H2の両方でLift>1なら時間方向の再現性がより強い。" . PHP_EOL;
echo "・Nが十分あり、万舟率だけでなく3万以上率も上がる候補を優先。" . PHP_EOL;
echo "・この段階では警報ロジックや閾値はまだ確定しない。" . PHP_EOL;
