<?php
declare(strict_types=1);

/**
 * STEP7 → STEP9 bridge
 *
 * 期間分割で再現した攻め率 formation を、現行Webの本命買い目へ
 * 「頭は原則そのまま・相手順位だけ昇格」で接続したときに的中率が改善するか確認する。
 *
 * 対象ルール（各ルールは独立評価）:
 *   A3: Web本命1 × 3C攻め>=閾値 → 3を2着候補側へ昇格
 *   A4: Web本命1 × 4C攻め>=閾値 → 4を2着候補側へ昇格
 *   A5: Web本命1 × 5C攻め>=閾値 → 5を2着候補側へ昇格
 *   H3: Web本命3 × 3C攻め>=閾値 → 1を2着候補側へ昇格（3-1-*）
 *   H4: Web本命4 × 4C攻め>=閾値 → 1を2着候補側へ昇格（4-1-*）
 *   H45: Web本命4 × 4C攻め>=閾値 → 5を2着候補側へ昇格（4-5-*）
 *
 * 5号艇本命は既に本番で⑤⑥本命→②③④の頭補正が入ったため、
 * 今回は旧CSVの honmei=5 を新しい相手ルール候補には使わない。
 *
 * 条件:
 * - point-in-time 6month kimarite
 * - attack = makuri + makurizashi
 * - sample_n >= 10
 * - 閾値 15%, 20%
 * - kiru は現行判定を維持
 * - actual_* は評価ラベルのみ
 *
 * Usage:
 * php analysis/validate_attack_chain_web_bet_bridge.php \
 *   analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *   analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv
 */

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php {$argv[0]} DATASET_CSV BOATS_CSV\n");
    exit(1);
}

$datasetPath = $argv[1];
$boatsPath = $argv[2];
foreach ([$datasetPath, $boatsPath] as $p) {
    if (!is_file($p)) throw new RuntimeException("CSVがありません: {$p}");
}

function readCsvAssoc(string $path): array
{
    $fp = fopen($path, 'rb');
    if ($fp === false) throw new RuntimeException("CSVを開けません: {$path}");
    $header = fgetcsv($fp);
    if ($header === false) { fclose($fp); return []; }
    if (isset($header[0])) $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $rows = [];
    while (($cols = fgetcsv($fp)) !== false) {
        if (count($cols) !== count($header)) continue;
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

function attack(array $row, int $course): float
{
    return fnum($row, "c{$course}_6m_makuri") + fnum($row, "c{$course}_6m_makurizashi");
}

function sampleOk(array $row, int $course): bool
{
    return inum($row, "c{$course}_6m_sample_n") >= 10;
}

function pct(int $n, int $d): float
{
    return $d > 0 ? 100.0 * $n / $d : 0.0;
}

function promoteToSecond(array $rankBoats, int $head, int $target): array
{
    $headPos = array_search($head, $rankBoats, true);
    $targetPos = array_search($target, $rankBoats, true);
    if ($headPos === false || $targetPos === false || $head === $target) return $rankBoats;

    array_splice($rankBoats, (int)$targetPos, 1);
    $headPos = array_search($head, $rankBoats, true);
    array_splice($rankBoats, (int)$headPos + 1, 0, [$target]);
    return array_values($rankBoats);
}

function buildBet(array $rankBoats, array $kiru, int $head): array
{
    $aite = [];
    $third = [];
    foreach ($rankBoats as $boat) {
        $boat = (int)$boat;
        if ($boat === $head) continue;
        if (($kiru[$boat] ?? false) === true) continue;
        $third[] = $boat;
        if (count($aite) < 3) $aite[] = $boat;
    }
    $aiteSorted = $aite;
    $thirdSorted = $third;
    sort($aiteSorted);
    sort($thirdSorted);
    return [
        'aite_set' => $aiteSorted,
        'third_set' => $thirdSorted,
        'kai' => $head . '-' . implode('', $aiteSorted) . '-' . implode('', $thirdSorted),
    ];
}

function betHit(array $row, array $bet, int $head): bool
{
    return inum($row, 'actual_1st') === $head
        && in_array(inum($row, 'actual_2nd'), $bet['aite_set'], true)
        && in_array(inum($row, 'actual_3rd'), $bet['third_set'], true);
}

function rankAndKiru(array $boats): ?array
{
    if (count($boats) !== 6) return null;
    usort($boats, static function(array $a, array $b): int {
        $ra = inum($a, 'final_rank');
        $rb = inum($b, 'final_rank');
        return $ra === $rb
            ? inum($a, 'lane_number') <=> inum($b, 'lane_number')
            : $ra <=> $rb;
    });
    $rank = [];
    $kiru = [];
    foreach ($boats as $b) {
        $lane = inum($b, 'lane_number');
        $rank[] = $lane;
        $kiru[$lane] = inum($b, 'kiru') === 1;
    }
    return [$rank, $kiru];
}

$rules = [
    'A3 honmei1→3昇格' => ['head' => 1, 'course' => 3, 'target' => 3],
    'A4 honmei1→4昇格' => ['head' => 1, 'course' => 4, 'target' => 4],
    'A5 honmei1→5昇格' => ['head' => 1, 'course' => 5, 'target' => 5],
    'H3 honmei3→1昇格' => ['head' => 3, 'course' => 3, 'target' => 1],
    'H4 honmei4→1昇格' => ['head' => 4, 'course' => 4, 'target' => 1],
    'H45 honmei4→5昇格' => ['head' => 4, 'course' => 4, 'target' => 5],
];

function evaluateRule(array $datasetRows, array $boatsByRace, array $rule, float $threshold, ?string $start, ?string $end): array
{
    $s = [
        'eligible' => 0, 'reconstruct' => 0, 'rank_changed' => 0, 'aite_changed' => 0,
        'base_hit' => 0, 'new_hit' => 0,
        'changed_base_hit' => 0, 'changed_new_hit' => 0,
        'actual_head' => 0, 'base_second_cov' => 0, 'new_second_cov' => 0,
    ];

    $head = (int)$rule['head'];
    $course = (int)$rule['course'];
    $target = (int)$rule['target'];

    foreach ($datasetRows as $row) {
        $date = (string)($row['race_date'] ?? '');
        if ($start !== null && $date < $start) continue;
        if ($end !== null && $date > $end) continue;
        if (inum($row, 'result_top3_course_complete') !== 1 || inum($row, 'result_boat_match') !== 1) continue;
        if (inum($row, 'honmei_head') !== $head) continue;
        if (!sampleOk($row, $course) || attack($row, $course) < $threshold) continue;

        $raceCode = trim((string)($row['race_code'] ?? ''));
        $rk = rankAndKiru($boatsByRace[$raceCode] ?? []);
        if ($rk === null) continue;
        [$rank, $kiru] = $rk;
        if (($rank[0] ?? 0) !== $head) continue;

        $baseBet = buildBet($rank, $kiru, $head);
        $newRank = promoteToSecond($rank, $head, $target);
        $newBet = buildBet($newRank, $kiru, $head);

        $s['eligible']++;
        if ($baseBet['kai'] === trim((string)($row['honmei_kai'] ?? ''))) $s['reconstruct']++;
        if ($newRank !== $rank) $s['rank_changed']++;

        $changed = $newBet['aite_set'] !== $baseBet['aite_set'];
        if ($changed) $s['aite_changed']++;

        $baseHit = betHit($row, $baseBet, $head);
        $newHit = betHit($row, $newBet, $head);
        if ($baseHit) $s['base_hit']++;
        if ($newHit) $s['new_hit']++;
        if ($changed) {
            if ($baseHit) $s['changed_base_hit']++;
            if ($newHit) $s['changed_new_hit']++;
        }

        if (inum($row, 'actual_1st') === $head) {
            $s['actual_head']++;
            $a2 = inum($row, 'actual_2nd');
            if (in_array($a2, $baseBet['aite_set'], true)) $s['base_second_cov']++;
            if (in_array($a2, $newBet['aite_set'], true)) $s['new_second_cov']++;
        }
    }
    return $s;
}

function printResult(string $label, array $s): void
{
    $n = $s['eligible'];
    $c = $s['aite_changed'];
    $h = $s['actual_head'];
    $basePct = pct($s['base_hit'], $n);
    $newPct = pct($s['new_hit'], $n);

    echo sprintf(
        "%-16s N=%5d  再構成=%5.1f%%  相手変更=%4d  買い目 %5.2f→%5.2f%% (%+4d / %+.3fpt)",
        $label, $n, pct($s['reconstruct'], $n), $c,
        $basePct, $newPct, $s['new_hit'] - $s['base_hit'], $newPct - $basePct
    ) . PHP_EOL;

    echo sprintf(
        "  変更Rのみ         N=%5d  %5.2f→%5.2f%% (%+4d / %+.2fpt)",
        $c, pct($s['changed_base_hit'], $c), pct($s['changed_new_hit'], $c),
        $s['changed_new_hit'] - $s['changed_base_hit'],
        pct($s['changed_new_hit'], $c) - pct($s['changed_base_hit'], $c)
    ) . PHP_EOL;

    echo sprintf(
        "  実際に頭的中時2着カバー N=%5d  %5.2f→%5.2f%% (%+4d / %+.2fpt)",
        $h, pct($s['base_second_cov'], $h), pct($s['new_second_cov'], $h),
        $s['new_second_cov'] - $s['base_second_cov'],
        pct($s['new_second_cov'], $h) - pct($s['base_second_cov'], $h)
    ) . PHP_EOL;
}

$datasetRows = readCsvAssoc($datasetPath);
$boatRows = readCsvAssoc($boatsPath);
$boatsByRace = [];
foreach ($boatRows as $b) {
    $raceCode = trim((string)($b['race_code'] ?? ''));
    if ($raceCode !== '') $boatsByRace[$raceCode][] = $b;
}

$periods = [
    '前半6か月' => ['2025-08-15', '2026-02-14'],
    '後半6か月' => ['2026-02-15', '2026-08-14'],
    '全期間' => [null, null],
];

echo str_repeat('=', 150) . PHP_EOL;
echo "STEP7→STEP9 攻め率出目候補 × 現行Web買い目 接続検証" . PHP_EOL;
echo str_repeat('=', 150) . PHP_EOL;
echo "注意: 各ルールは独立評価。kiru維持、頭固定、相手順位の昇格だけを検証。" . PHP_EOL;
echo "      ⑤本命は現行本番で頭補正済みのため対象外。" . PHP_EOL;

foreach ([15.0, 20.0] as $threshold) {
    echo PHP_EOL . str_repeat('=', 150) . PHP_EOL;
    echo sprintf("【攻め率 >= %.0f%%】", $threshold) . PHP_EOL;
    echo str_repeat('=', 150) . PHP_EOL;

    foreach ($periods as $periodName => [$start, $end]) {
        echo PHP_EOL . "--- {$periodName} ---" . PHP_EOL;
        foreach ($rules as $name => $rule) {
            $s = evaluateRule($datasetRows, $boatsByRace, $rule, $threshold, $start, $end);
            printResult($name, $s);
        }
    }
}

echo PHP_EOL . str_repeat('=', 150) . PHP_EOL;
echo "検証完了: 前後半とも改善するルールだけを次の買い目候補として残す。" . PHP_EOL;
echo str_repeat('=', 150) . PHP_EOL;
