<?php
declare(strict_types=1);

/**
 * 1号艇を現行Webが本命にしたレースに対して、
 * 1逃げ分析から得たkimarite相手差し替えルールを最終順位へ適用し、
 * 現行の本命買い目が実際に改善するかを検証する。
 *
 * 固定ルール（学習側で決定済み）:
 *   R1: 3C攻め < 10% かつ 4C攻め >= 10% → 4を3より上へ
 *   R2: 4C攻め <  5% かつ 5C攻め >= 10% → 5を4より上へ
 * 共通: 関係コースの6month sample_n >= 10
 *
 * 重要:
 * - 条件判定はpoint-in-time kimariteだけを使う。
 * - actual_* は評価ラベルとしてのみ使う。
 * - 頭は現行Webの本命①で固定し、kimariteで頭は変更しない。
 * - kiruは現行判定をそのまま維持し、今回は「順位差し替えのみ」を検証する。
 *
 * Usage:
 * php analysis/validate_lane1_kimarite_web_bet_override.php \
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

    // BOM除去
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

function fnum(array $row, string $key): float
{
    $v = $row[$key] ?? '';
    return is_numeric($v) ? (float)$v : 0.0;
}

function inum(array $row, string $key): int
{
    $v = $row[$key] ?? '';
    return is_numeric($v) ? (int)$v : 0;
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

function applyOverride(array $rankBoats, array $row): array
{
    $rule1 = sampleOk($row, 3)
        && sampleOk($row, 4)
        && attack($row, 3) < 10.0
        && attack($row, 4) >= 10.0;

    $rule2 = sampleOk($row, 4)
        && sampleOk($row, 5)
        && attack($row, 4) < 5.0
        && attack($row, 5) >= 10.0;

    $moveBefore = static function (array $list, int $move, int $before): array {
        $pMove = array_search($move, $list, true);
        $pBefore = array_search($before, $list, true);
        if ($pMove === false || $pBefore === false || $pMove < $pBefore) {
            return $list;
        }

        array_splice($list, $pMove, 1);
        $pBefore = array_search($before, $list, true);
        array_splice($list, (int)$pBefore, 0, [$move]);
        return array_values($list);
    };

    if ($rule1) {
        $rankBoats = $moveBefore($rankBoats, 4, 3);
    }
    if ($rule2) {
        $rankBoats = $moveBefore($rankBoats, 5, 4);
    }
    // 両ルール同時時も 4 > 3 を満たすよう再確認
    if ($rule1) {
        $rankBoats = $moveBefore($rankBoats, 4, 3);
    }

    return [$rankBoats, $rule1, $rule2];
}

function buildBet(array $rankBoats, array $kiru, int $head = 1): array
{
    $aite = [];
    $third = [];

    foreach ($rankBoats as $boat) {
        $boat = (int)$boat;
        if ($boat === $head) {
            continue;
        }
        if (isset($kiru[$boat]) && $kiru[$boat]) {
            continue;
        }
        $third[] = $boat;
        if (count($aite) < 3) {
            $aite[] = $boat;
        }
    }

    $aiteSorted = $aite;
    $thirdSorted = $third;
    sort($aiteSorted);
    sort($thirdSorted);

    return [
        'aite_order' => $aite,
        'aite_set' => $aiteSorted,
        'third_set' => $thirdSorted,
        'kai' => $head . '-' . implode('', $aiteSorted) . '-' . implode('', $thirdSorted),
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

function pct(int $n, int $d): float
{
    return $d > 0 ? 100.0 * $n / $d : 0.0;
}

function evaluatePeriod(array $raceRows, array $boatsByRace, ?string $start, ?string $end): array
{
    $s = [
        'eligible' => 0,
        'reconstruct_match' => 0,
        'rule1' => 0,
        'rule2' => 0,
        'both' => 0,
        'rank_changed' => 0,
        'aite_changed' => 0,
        'base_hit' => 0,
        'new_hit' => 0,
        'changed_base_hit' => 0,
        'changed_new_hit' => 0,
        'actual_head1' => 0,
        'head1_base_second_cov' => 0,
        'head1_new_second_cov' => 0,
    ];

    foreach ($raceRows as $row) {
        $date = (string)($row['race_date'] ?? '');
        if ($start !== null && $date < $start) continue;
        if ($end !== null && $date > $end) continue;

        if (inum($row, 'result_top3_course_complete') !== 1
            || inum($row, 'result_boat_match') !== 1) {
            continue;
        }

        // 実運用トリガー: 現行Webが事前に1号艇を本命としている
        if (inum($row, 'honmei_head') !== 1) {
            continue;
        }

        $raceCode = trim((string)($row['race_code'] ?? ''));
        $boats = $boatsByRace[$raceCode] ?? [];
        if (count($boats) !== 6) {
            continue;
        }

        usort($boats, static function (array $a, array $b): int {
            $ra = inum($a, 'final_rank');
            $rb = inum($b, 'final_rank');
            if ($ra === $rb) {
                return inum($a, 'lane_number') <=> inum($b, 'lane_number');
            }
            return $ra <=> $rb;
        });

        $rankBoats = array_map(static fn(array $b): int => inum($b, 'lane_number'), $boats);
        if (($rankBoats[0] ?? 0) !== 1) {
            // race CSVのhonmei_headと最終順位が食い違う場合は除外
            continue;
        }

        $kiru = [];
        foreach ($boats as $b) {
            $lane = inum($b, 'lane_number');
            $kiru[$lane] = inum($b, 'kiru') === 1;
        }

        $baseBet = buildBet($rankBoats, $kiru, 1);
        [$newRank, $rule1, $rule2] = applyOverride($rankBoats, $row);
        $newBet = buildBet($newRank, $kiru, 1);

        $s['eligible']++;
        if ($baseBet['kai'] === trim((string)($row['honmei_kai'] ?? ''))) {
            $s['reconstruct_match']++;
        }
        if ($rule1) $s['rule1']++;
        if ($rule2) $s['rule2']++;
        if ($rule1 && $rule2) $s['both']++;
        if ($newRank !== $rankBoats) $s['rank_changed']++;

        $aiteChanged = $newBet['aite_set'] !== $baseBet['aite_set'];
        if ($aiteChanged) $s['aite_changed']++;

        $baseHit = betHit($row, $baseBet);
        $newHit = betHit($row, $newBet);
        if ($baseHit) $s['base_hit']++;
        if ($newHit) $s['new_hit']++;
        if ($aiteChanged) {
            if ($baseHit) $s['changed_base_hit']++;
            if ($newHit) $s['changed_new_hit']++;
        }

        if (inum($row, 'actual_1st') === 1) {
            $s['actual_head1']++;
            $a2 = inum($row, 'actual_2nd');
            if (in_array($a2, $baseBet['aite_set'], true)) $s['head1_base_second_cov']++;
            if (in_array($a2, $newBet['aite_set'], true)) $s['head1_new_second_cov']++;
        }
    }

    return $s;
}

function printStats(string $label, array $s): void
{
    $n = $s['eligible'];
    echo "\n" . str_repeat('-', 118) . "\n";
    echo "【{$label}】\n";
    echo str_repeat('-', 118) . "\n";
    echo "現行Web本命① 対象     : {$n}\n";
    echo sprintf("買い目再構成一致       : %d / %d (%.2f%%)\n",
        $s['reconstruct_match'], $n, pct($s['reconstruct_match'], $n));
    echo sprintf("R1発動 ③弱→④上げ     : %d (%.2f%%)\n", $s['rule1'], pct($s['rule1'], $n));
    echo sprintf("R2発動 ④弱→⑤上げ     : %d (%.2f%%)\n", $s['rule2'], pct($s['rule2'], $n));
    echo sprintf("両方発動               : %d (%.2f%%)\n", $s['both'], pct($s['both'], $n));
    echo sprintf("最終順位が変化         : %d (%.2f%%)\n", $s['rank_changed'], pct($s['rank_changed'], $n));
    echo sprintf("2着候補集合が変化      : %d (%.2f%%)\n", $s['aite_changed'], pct($s['aite_changed'], $n));

    echo "\n本命買い目 的中率\n";
    echo sprintf("  現行 : %d / %d (%.2f%%)\n", $s['base_hit'], $n, pct($s['base_hit'], $n));
    echo sprintf("  修正 : %d / %d (%.2f%%)\n", $s['new_hit'], $n, pct($s['new_hit'], $n));
    echo sprintf("  差   : %+d件 / %+.3fpt\n",
        $s['new_hit'] - $s['base_hit'],
        pct($s['new_hit'], $n) - pct($s['base_hit'], $n));

    $c = $s['aite_changed'];
    echo "\n2着候補が実際に変わったレースだけ\n";
    echo sprintf("  現行的中 : %d / %d (%.2f%%)\n", $s['changed_base_hit'], $c, pct($s['changed_base_hit'], $c));
    echo sprintf("  修正的中 : %d / %d (%.2f%%)\n", $s['changed_new_hit'], $c, pct($s['changed_new_hit'], $c));
    echo sprintf("  差       : %+d件 / %+.2fpt\n",
        $s['changed_new_hit'] - $s['changed_base_hit'],
        pct($s['changed_new_hit'], $c) - pct($s['changed_base_hit'], $c));

    $h = $s['actual_head1'];
    echo "\n実際に①1着だったレースの2着候補カバー\n";
    echo sprintf("  現行 : %d / %d (%.2f%%)\n", $s['head1_base_second_cov'], $h, pct($s['head1_base_second_cov'], $h));
    echo sprintf("  修正 : %d / %d (%.2f%%)\n", $s['head1_new_second_cov'], $h, pct($s['head1_new_second_cov'], $h));
    echo sprintf("  差   : %+d件 / %+.3fpt\n",
        $s['head1_new_second_cov'] - $s['head1_base_second_cov'],
        pct($s['head1_new_second_cov'], $h) - pct($s['head1_base_second_cov'], $h));
}

$raceRows = readCsvAssoc($datasetPath);
$boatRows = readCsvAssoc($boatsPath);

$boatsByRace = [];
foreach ($boatRows as $b) {
    $raceCode = trim((string)($b['race_code'] ?? ''));
    if ($raceCode === '') continue;
    $boatsByRace[$raceCode][] = $b;
}

$all = evaluatePeriod($raceRows, $boatsByRace, null, null);
$first = evaluatePeriod($raceRows, $boatsByRace, '2025-08-15', '2026-02-14');
$second = evaluatePeriod($raceRows, $boatsByRace, '2026-02-15', '2026-08-14');

echo "\n" . str_repeat('=', 118) . "\n";
echo "現行Web本命① × kimarite相手差し替え 買い目影響検証\n";
echo str_repeat('=', 118) . "\n";
echo "R1: 3C攻め<10% × 4C攻め>=10% → 4を3より上へ\n";
echo "R2: 4C攻め<5%  × 5C攻め>=10% → 5を4より上へ\n";
echo "共通: 関係コース sample_n>=10 / 頭①・kiru判定は現行のまま\n";
echo "注意: 今回は順位差し替えだけ。切る艇保護や頭変更は行わない。\n";

printStats('全1年', $all);
printStats('前半6か月', $first);
printStats('後半6か月', $second);

echo "\n" . str_repeat('=', 118) . "\n";
echo "検証完了\n";
echo "判定: 後半でも本命買い目/2着候補カバーが改善し、再構成一致率が十分高ければWeb実装候補へ\n";
echo str_repeat('=', 118) . "\n";
