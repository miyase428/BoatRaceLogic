<?php
declare(strict_types=1);

/**
 * STEP9 candidate combination validation
 *
 * 前段の独立ルール検証で前半/後半とも改善した候補だけを固定して、
 * 同時適用時の重複・買い目影響・月次安定性を確認する。
 *
 * 凍結候補:
 *   A3: 事前Web本命1 × 3C攻め>=15% → 3を2着候補側へ昇格
 *   A4: 事前Web本命1 × 4C攻め>=20% → 4を2着候補側へ昇格
 *   H3: 事前Web本命3 × 3C攻め>=15% → 1を2着候補側へ昇格
 *
 * 重要:
 * - A5 / H4 / H45 はこの段階では採用しない。
 * - 条件は point-in-time 6month kimarite、sample_n>=10。
 * - kiru は現行判定を維持する。
 * - actual_* は評価ラベルにのみ使う。
 * - 「事前Web本命」は dataset の honmei_head を使う。
 *   既に本番投入済みの⑤⑥本命→②③④頭補正で新たに本命3になったレースへ
 *   H3を広げない。将来本番実装する場合も pre-kimarite head=3 をガード条件にする。
 * - A3/A4同時発動時は両方を2着候補側へ昇格する。
 *   2着候補は集合で評価するため、優先順が実際の買い目集合を変えるかも併記する。
 *
 * Usage:
 * php analysis/validate_attack_chain_web_bet_candidates_combined.php \
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
    if (!is_file($p)) {
        throw new RuntimeException("CSVがありません: {$p}");
    }
}

function readCsvAssoc(string $path): array
{
    $fp = fopen($path, 'rb');
    if ($fp === false) throw new RuntimeException("CSVを開けません: {$path}");
    $header = fgetcsv($fp);
    if ($header === false) { fclose($fp); return []; }
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    }
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
    if ($head === $target) return $rankBoats;
    $targetPos = array_search($target, $rankBoats, true);
    if ($targetPos === false) return $rankBoats;

    array_splice($rankBoats, (int)$targetPos, 1);
    $headPos = array_search($head, $rankBoats, true);
    if ($headPos === false) return $rankBoats;
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
    usort($boats, static function (array $a, array $b): int {
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

function newStats(): array
{
    return [
        'eligible' => 0,
        'reconstruct' => 0,
        'a3' => 0,
        'a4' => 0,
        'h3' => 0,
        'a3a4' => 0,
        'any_rule' => 0,
        'aite_changed' => 0,
        'priority_diff' => 0,
        'base_hit' => 0,
        'new_hit' => 0,
        'changed_base_hit' => 0,
        'changed_new_hit' => 0,
        'actual_head' => 0,
        'base_second_cov' => 0,
        'new_second_cov' => 0,
    ];
}

function addStats(array &$dst, array $src): void
{
    foreach ($dst as $k => $_) {
        $dst[$k] += $src[$k] ?? 0;
    }
}

function evaluatePeriod(array $datasetRows, array $boatsByRace, ?string $start, ?string $end): array
{
    $s = newStats();
    $byMonth = [];

    foreach ($datasetRows as $row) {
        $date = (string)($row['race_date'] ?? '');
        if ($start !== null && $date < $start) continue;
        if ($end !== null && $date > $end) continue;
        if (inum($row, 'result_top3_course_complete') !== 1 || inum($row, 'result_boat_match') !== 1) continue;

        // 現行本番の⑤⑥頭補正との定義混在を避けるため、
        // この候補群の正式対象は「事前Web本命が1または3」のレースだけ。
        $head = inum($row, 'honmei_head');
        if ($head !== 1 && $head !== 3) continue;

        $raceCode = trim((string)($row['race_code'] ?? ''));
        $rk = rankAndKiru($boatsByRace[$raceCode] ?? []);
        if ($rk === null) continue;
        [$rank, $kiru] = $rk;
        if (($rank[0] ?? 0) !== $head) continue;

        $baseBet = buildBet($rank, $kiru, $head);
        $newRank = $rank;

        $a3 = $head === 1 && sampleOk($row, 3) && attack($row, 3) >= 15.0;
        $a4 = $head === 1 && sampleOk($row, 4) && attack($row, 4) >= 20.0;
        $h3 = $head === 3 && sampleOk($row, 3) && attack($row, 3) >= 15.0;

        // A3/A4同時時: まず4、次に3を上げ、最終的に3を頭直後に置く。
        // 両方とも2着候補集合へ入ることが目的。
        if ($a4) $newRank = promoteToSecond($newRank, 1, 4);
        if ($a3) $newRank = promoteToSecond($newRank, 1, 3);
        if ($h3) $newRank = promoteToSecond($newRank, 3, 1);

        $newBet = buildBet($newRank, $kiru, $head);

        // A3/A4の優先順を逆にしても買い目集合が同じか確認。
        $priorityDiff = false;
        if ($a3 && $a4) {
            $reverseRank = $rank;
            $reverseRank = promoteToSecond($reverseRank, 1, 3);
            $reverseRank = promoteToSecond($reverseRank, 1, 4);
            $reverseBet = buildBet($reverseRank, $kiru, $head);
            $priorityDiff = $reverseBet['aite_set'] !== $newBet['aite_set'];
        }

        $one = newStats();
        $one['eligible'] = 1;
        if ($baseBet['kai'] === trim((string)($row['honmei_kai'] ?? ''))) $one['reconstruct'] = 1;
        if ($a3) $one['a3'] = 1;
        if ($a4) $one['a4'] = 1;
        if ($h3) $one['h3'] = 1;
        if ($a3 && $a4) $one['a3a4'] = 1;
        if ($a3 || $a4 || $h3) $one['any_rule'] = 1;
        if ($priorityDiff) $one['priority_diff'] = 1;

        $changed = $newBet['aite_set'] !== $baseBet['aite_set'];
        if ($changed) $one['aite_changed'] = 1;

        $baseHit = betHit($row, $baseBet, $head);
        $newHit = betHit($row, $newBet, $head);
        if ($baseHit) $one['base_hit'] = 1;
        if ($newHit) $one['new_hit'] = 1;
        if ($changed) {
            if ($baseHit) $one['changed_base_hit'] = 1;
            if ($newHit) $one['changed_new_hit'] = 1;
        }

        if (inum($row, 'actual_1st') === $head) {
            $one['actual_head'] = 1;
            $a2 = inum($row, 'actual_2nd');
            if (in_array($a2, $baseBet['aite_set'], true)) $one['base_second_cov'] = 1;
            if (in_array($a2, $newBet['aite_set'], true)) $one['new_second_cov'] = 1;
        }

        addStats($s, $one);
        $month = substr($date, 0, 7);
        if (!isset($byMonth[$month])) $byMonth[$month] = newStats();
        addStats($byMonth[$month], $one);
    }

    ksort($byMonth);
    return [$s, $byMonth];
}

function printStats(string $label, array $s): void
{
    $n = $s['eligible'];
    $c = $s['aite_changed'];
    $h = $s['actual_head'];
    $basePct = pct($s['base_hit'], $n);
    $newPct = pct($s['new_hit'], $n);

    echo PHP_EOL . str_repeat('-', 132) . PHP_EOL;
    echo "【{$label}】" . PHP_EOL;
    echo str_repeat('-', 132) . PHP_EOL;
    echo sprintf("対象（事前Web本命1/3） : %d\n", $n);
    echo sprintf("買い目再構成一致       : %d / %d (%.2f%%)\n", $s['reconstruct'], $n, pct($s['reconstruct'], $n));
    echo sprintf("A3発動                  : %d\n", $s['a3']);
    echo sprintf("A4発動                  : %d\n", $s['a4']);
    echo sprintf("H3発動                  : %d\n", $s['h3']);
    echo sprintf("A3+A4同時               : %d\n", $s['a3a4']);
    echo sprintf("何らかの候補ルール発動 : %d\n", $s['any_rule']);
    echo sprintf("2着候補集合が変化       : %d\n", $c);
    echo sprintf("A3/A4優先順で集合差     : %d\n", $s['priority_diff']);

    echo PHP_EOL . "本命買い目 的中率" . PHP_EOL;
    echo sprintf("  現行 : %d / %d (%.2f%%)\n", $s['base_hit'], $n, $basePct);
    echo sprintf("  修正 : %d / %d (%.2f%%)\n", $s['new_hit'], $n, $newPct);
    echo sprintf("  差   : %+d件 / %+.3fpt\n", $s['new_hit'] - $s['base_hit'], $newPct - $basePct);

    echo PHP_EOL . "2着候補が実際に変わったレースだけ" . PHP_EOL;
    echo sprintf("  現行 : %d / %d (%.2f%%)\n", $s['changed_base_hit'], $c, pct($s['changed_base_hit'], $c));
    echo sprintf("  修正 : %d / %d (%.2f%%)\n", $s['changed_new_hit'], $c, pct($s['changed_new_hit'], $c));
    echo sprintf("  差   : %+d件 / %+.2fpt\n",
        $s['changed_new_hit'] - $s['changed_base_hit'],
        pct($s['changed_new_hit'], $c) - pct($s['changed_base_hit'], $c)
    );

    echo PHP_EOL . "実際に頭的中時の2着候補カバー" . PHP_EOL;
    echo sprintf("  現行 : %d / %d (%.2f%%)\n", $s['base_second_cov'], $h, pct($s['base_second_cov'], $h));
    echo sprintf("  修正 : %d / %d (%.2f%%)\n", $s['new_second_cov'], $h, pct($s['new_second_cov'], $h));
    echo sprintf("  差   : %+d件 / %+.2fpt\n",
        $s['new_second_cov'] - $s['base_second_cov'],
        pct($s['new_second_cov'], $h) - pct($s['base_second_cov'], $h)
    );
}

function printMonthly(array $byMonth): void
{
    echo PHP_EOL . "月次（事前Web本命1/3 全対象に対する買い目的中差）" . PHP_EOL;
    echo "月       対象   発動   相手変更   現行     修正     差件   差pt" . PHP_EOL;
    echo str_repeat('-', 88) . PHP_EOL;
    foreach ($byMonth as $month => $s) {
        $n = $s['eligible'];
        $base = pct($s['base_hit'], $n);
        $new = pct($s['new_hit'], $n);
        echo sprintf(
            "%-7s %6d %6d %8d %7.2f%% %7.2f%% %+6d %+7.3f\n",
            $month,
            $n,
            $s['any_rule'],
            $s['aite_changed'],
            $base,
            $new,
            $s['new_hit'] - $s['base_hit'],
            $new - $base
        );
    }
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

echo str_repeat('=', 132) . PHP_EOL;
echo "STEP9 攻め率相手補正候補 組み合わせ検証" . PHP_EOL;
echo str_repeat('=', 132) . PHP_EOL;
echo "固定候補: A3=本命1×3C攻め>=15%, A4=本命1×4C攻め>=20%, H3=事前本命3×3C攻め>=15%" . PHP_EOL;
echo "除外     : A5 / H4 / H45" . PHP_EOL;
echo "注意     : H3は⑤⑥頭補正後に新しく本命3になったレースへ拡張しない。" . PHP_EOL;

foreach ($periods as $name => [$start, $end]) {
    [$stats, $monthly] = evaluatePeriod($datasetRows, $boatsByRace, $start, $end);
    printStats($name, $stats);
    if ($name === '全期間') printMonthly($monthly);
}

echo PHP_EOL . str_repeat('=', 132) . PHP_EOL;
echo "検証完了: 組み合わせ後も前半/後半で改善し、月次で大崩れしないかを確認する。" . PHP_EOL;
echo str_repeat('=', 132) . PHP_EOL;
