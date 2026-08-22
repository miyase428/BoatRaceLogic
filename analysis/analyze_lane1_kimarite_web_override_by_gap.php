<?php
declare(strict_types=1);

/**
 * 現行Webが1号艇を本命にしたレースについて、
 * kimarite相手差し替えルール R1 / R2 を個別に適用し、
 * 現行Webの final3 差ごとに買い目影響を分析する。
 *
 * R1: 3C攻め < 10% かつ 4C攻め >= 10% → 4を3より上へ
 * R2: 4C攻め <  5% かつ 5C攻め >= 10% → 5を4より上へ
 *
 * 目的:
 * - R1/R2のどちらが悪化原因か切り分ける
 * - kimariteが「final3差が小さい時のタイブレーク」として使えるか確認する
 *
 * Usage:
 * php analysis/analyze_lane1_kimarite_web_override_by_gap.php \
 *   analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *   analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv
 */

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php {$argv[0]} DATASET_CSV BOATS_CSV\n");
    exit(1);
}

$datasetPath = $argv[1];
$boatsPath = $argv[2];

if (!is_file($datasetPath)) {
    throw new RuntimeException("dataset CSVがありません: {$datasetPath}");
}
if (!is_file($boatsPath)) {
    throw new RuntimeException("boats CSVがありません: {$boatsPath}");
}

function readCsvAssoc(string $path): array
{
    $fp = fopen($path, 'rb');
    if ($fp === false) {
        throw new RuntimeException("CSVを開けません: {$path}");
    }

    $header = fgetcsv($fp);
    if ($header === false) {
        fclose($fp);
        return [];
    }
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    }

    $rows = [];
    while (($cols = fgetcsv($fp)) !== false) {
        if (count($cols) !== count($header)) {
            continue;
        }
        $rows[] = array_combine($header, $cols);
    }
    fclose($fp);
    return $rows;
}

function inum(array $row, string $key): int
{
    $v = $row[$key] ?? '';
    return is_numeric($v) ? (int)$v : 0;
}

function fnum(array $row, string $key): float
{
    $v = $row[$key] ?? '';
    return is_numeric($v) ? (float)$v : 0.0;
}

function pct(int $n, int $d): float
{
    return $d > 0 ? 100.0 * $n / $d : 0.0;
}

function attack(array $row, int $course): float
{
    return fnum($row, "c{$course}_6m_makuri")
        + fnum($row, "c{$course}_6m_makurizashi");
}

function sampleOk(array $row, int $course): bool
{
    return inum($row, "c{$course}_6m_sample_n") >= 10;
}

function moveBefore(array $list, int $move, int $before): array
{
    $pMove = array_search($move, $list, true);
    $pBefore = array_search($before, $list, true);
    if ($pMove === false || $pBefore === false || $pMove < $pBefore) {
        return $list;
    }

    array_splice($list, $pMove, 1);
    $pBefore = array_search($before, $list, true);
    array_splice($list, (int)$pBefore, 0, [$move]);
    return array_values($list);
}

function buildBet(array $rankBoats, array $kiru, int $head = 1): array
{
    $aite = [];
    $third = [];
    foreach ($rankBoats as $boat) {
        $boat = (int)$boat;
        if ($boat === $head) continue;
        if (($kiru[$boat] ?? false) === true) continue;

        $third[] = $boat;
        if (count($aite) < 3) {
            $aite[] = $boat;
        }
    }

    $aiteSet = $aite;
    $thirdSet = $third;
    sort($aiteSet);
    sort($thirdSet);

    return [
        'aite_set' => $aiteSet,
        'third_set' => $thirdSet,
    ];
}

function betHit(array $row, array $bet): bool
{
    $a1 = inum($row, 'actual_1st');
    $a2 = inum($row, 'actual_2nd');
    $a3 = inum($row, 'actual_3rd');

    return $a1 === 1
        && in_array($a2, $bet['aite_set'], true)
        && in_array($a3, $bet['third_set'], true);
}

function gapBand(float $gap): string
{
    if ($gap < 0.5) return '<0.5';
    if ($gap < 1.0) return '0.5-1';
    if ($gap < 2.0) return '1-2';
    if ($gap < 3.0) return '2-3';
    if ($gap < 5.0) return '3-5';
    return '5+';
}

function initStats(): array
{
    return [
        'trigger' => 0,
        'rank_changed' => 0,
        'aite_changed' => 0,
        'base_hit' => 0,
        'new_hit' => 0,
        'changed_base_hit' => 0,
        'changed_new_hit' => 0,
        'actual_head1_changed' => 0,
        'base_second_cov' => 0,
        'new_second_cov' => 0,
        'bands' => [],
    ];
}

function addBand(array &$bands, string $band, bool $baseHit, bool $newHit, bool $actualHead1, bool $baseCov, bool $newCov): void
{
    if (!isset($bands[$band])) {
        $bands[$band] = [
            'n' => 0,
            'base_hit' => 0,
            'new_hit' => 0,
            'actual_head1' => 0,
            'base_cov' => 0,
            'new_cov' => 0,
        ];
    }

    $bands[$band]['n']++;
    if ($baseHit) $bands[$band]['base_hit']++;
    if ($newHit) $bands[$band]['new_hit']++;
    if ($actualHead1) {
        $bands[$band]['actual_head1']++;
        if ($baseCov) $bands[$band]['base_cov']++;
        if ($newCov) $bands[$band]['new_cov']++;
    }
}

function evaluateRule(array $raceRows, array $boatsByRace, int $ruleNo, ?string $start, ?string $end): array
{
    $s = initStats();

    foreach ($raceRows as $row) {
        $date = (string)($row['race_date'] ?? '');
        if ($start !== null && $date < $start) continue;
        if ($end !== null && $date > $end) continue;

        if (inum($row, 'result_top3_course_complete') !== 1
            || inum($row, 'result_boat_match') !== 1
            || inum($row, 'honmei_head') !== 1) {
            continue;
        }

        $trigger = false;
        $move = 0;
        $before = 0;

        if ($ruleNo === 1) {
            $trigger = sampleOk($row, 3)
                && sampleOk($row, 4)
                && attack($row, 3) < 10.0
                && attack($row, 4) >= 10.0;
            $move = 4;
            $before = 3;
        } else {
            $trigger = sampleOk($row, 4)
                && sampleOk($row, 5)
                && attack($row, 4) < 5.0
                && attack($row, 5) >= 10.0;
            $move = 5;
            $before = 4;
        }

        if (!$trigger) continue;
        $s['trigger']++;

        $raceCode = trim((string)($row['race_code'] ?? ''));
        $boats = $boatsByRace[$raceCode] ?? [];
        if (count($boats) !== 6) continue;

        usort($boats, static function (array $a, array $b): int {
            $ra = inum($a, 'final_rank');
            $rb = inum($b, 'final_rank');
            if ($ra === $rb) {
                return inum($a, 'lane_number') <=> inum($b, 'lane_number');
            }
            return $ra <=> $rb;
        });

        $rankBoats = array_map(static fn(array $b): int => inum($b, 'lane_number'), $boats);
        if (($rankBoats[0] ?? 0) !== 1) continue;

        $byLane = [];
        $kiru = [];
        foreach ($boats as $b) {
            $lane = inum($b, 'lane_number');
            $byLane[$lane] = $b;
            $kiru[$lane] = inum($b, 'kiru') === 1;
        }
        if (!isset($byLane[$move], $byLane[$before])) continue;

        $newRank = moveBefore($rankBoats, $move, $before);
        if ($newRank === $rankBoats) continue;
        $s['rank_changed']++;

        $baseBet = buildBet($rankBoats, $kiru, 1);
        $newBet = buildBet($newRank, $kiru, 1);

        $aiteChanged = $baseBet['aite_set'] !== $newBet['aite_set'];
        if (!$aiteChanged) continue;
        $s['aite_changed']++;

        $baseHit = betHit($row, $baseBet);
        $newHit = betHit($row, $newBet);
        if ($baseHit) $s['base_hit']++;
        if ($newHit) $s['new_hit']++;
        if ($baseHit) $s['changed_base_hit']++;
        if ($newHit) $s['changed_new_hit']++;

        $actualHead1 = inum($row, 'actual_1st') === 1;
        $baseCov = false;
        $newCov = false;
        if ($actualHead1) {
            $s['actual_head1_changed']++;
            $a2 = inum($row, 'actual_2nd');
            $baseCov = in_array($a2, $baseBet['aite_set'], true);
            $newCov = in_array($a2, $newBet['aite_set'], true);
            if ($baseCov) $s['base_second_cov']++;
            if ($newCov) $s['new_second_cov']++;
        }

        // 現行Webが上に置いていた艇(before)と、繰り上げる艇(move)のfinal3差。
        $gap = fnum($byLane[$before], 'final3') - fnum($byLane[$move], 'final3');
        if ($gap < 0.0) $gap = 0.0;
        addBand($s['bands'], gapBand($gap), $baseHit, $newHit, $actualHead1, $baseCov, $newCov);
    }

    return $s;
}

function printRule(string $label, array $s): void
{
    echo "\n" . str_repeat('-', 126) . "\n";
    echo "{$label}\n";
    echo str_repeat('-', 126) . "\n";
    echo sprintf("発動=%d / 順位変化=%d / 2着候補集合変化=%d\n",
        $s['trigger'], $s['rank_changed'], $s['aite_changed']);

    $n = $s['aite_changed'];
    if ($n > 0) {
        echo sprintf("買い目的中: 現行 %d/%d (%.2f%%) → 修正 %d/%d (%.2f%%)  差=%+d件 / %+.2fpt\n",
            $s['changed_base_hit'], $n, pct($s['changed_base_hit'], $n),
            $s['changed_new_hit'], $n, pct($s['changed_new_hit'], $n),
            $s['changed_new_hit'] - $s['changed_base_hit'],
            pct($s['changed_new_hit'], $n) - pct($s['changed_base_hit'], $n));
    }

    $h = $s['actual_head1_changed'];
    if ($h > 0) {
        echo sprintf("①実1着時2着カバー: 現行 %d/%d (%.2f%%) → 修正 %d/%d (%.2f%%)  差=%+d件 / %+.2fpt\n",
            $s['base_second_cov'], $h, pct($s['base_second_cov'], $h),
            $s['new_second_cov'], $h, pct($s['new_second_cov'], $h),
            $s['new_second_cov'] - $s['base_second_cov'],
            pct($s['new_second_cov'], $h) - pct($s['base_second_cov'], $h));
    }

    echo "\nfinal3差別（2着候補集合が変わったレースのみ）\n";
    echo "帯            N    現行的中   修正的中    差pt   純増    ①1着N   2着カバー差\n";
    echo str_repeat('-', 94) . "\n";

    $order = ['<0.5', '0.5-1', '1-2', '2-3', '3-5', '5+'];
    foreach ($order as $band) {
        $b = $s['bands'][$band] ?? null;
        if ($b === null || $b['n'] === 0) continue;

        $hitBase = pct($b['base_hit'], $b['n']);
        $hitNew = pct($b['new_hit'], $b['n']);
        $covDiff = $b['actual_head1'] > 0
            ? pct($b['new_cov'], $b['actual_head1']) - pct($b['base_cov'], $b['actual_head1'])
            : 0.0;

        echo sprintf("%-8s %6d   %7.2f%%   %7.2f%%  %+6.2f  %+5d   %6d    %+7.2fpt\n",
            $band,
            $b['n'],
            $hitBase,
            $hitNew,
            $hitNew - $hitBase,
            $b['new_hit'] - $b['base_hit'],
            $b['actual_head1'],
            $covDiff
        );
    }
}

$raceRows = readCsvAssoc($datasetPath);
$boatRows = readCsvAssoc($boatsPath);

$boatsByRace = [];
foreach ($boatRows as $b) {
    $raceCode = trim((string)($b['race_code'] ?? ''));
    if ($raceCode === '') continue;
    $boatsByRace[$raceCode][] = $b;
}

$periods = [
    ['全1年', null, null],
    ['前半6か月', '2025-08-15', '2026-02-14'],
    ['後半6か月', '2026-02-15', '2026-08-14'],
];

echo "\n" . str_repeat('=', 126) . "\n";
echo "現行Web本命① × kimarite相手差し替え R1/R2・final3差別診断\n";
echo str_repeat('=', 126) . "\n";
echo "R1: 3C攻め<10% × 4C攻め>=10% → 4を3より上へ\n";
echo "R2: 4C攻め<5%  × 5C攻め>=10% → 5を4より上へ\n";
echo "対象: 現行Web本命① / 正式結果完備 / 関係コースsample_n>=10\n";

foreach ($periods as [$label, $start, $end]) {
    echo "\n" . str_repeat('=', 126) . "\n";
    echo "【{$label}】\n";
    echo str_repeat('=', 126) . "\n";

    $r1 = evaluateRule($raceRows, $boatsByRace, 1, $start, $end);
    $r2 = evaluateRule($raceRows, $boatsByRace, 2, $start, $end);

    printRule('R1 ③弱→④上げ', $r1);
    printRule('R2 ④弱→⑤上げ', $r2);
}

echo "\n" . str_repeat('=', 126) . "\n";
echo "診断完了\n";
echo "見る点: 後半6か月でR1/R2のどちらが悪化しているか、final3差が小さい帯だけならプラスが残るか\n";
echo str_repeat('=', 126) . "\n";
