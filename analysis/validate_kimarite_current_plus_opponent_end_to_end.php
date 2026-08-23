<?php
declare(strict_types=1);

/**
 * 現在本番済みの⑤⑥本命kimarite頭補正と、STEP9候補の相手補正
 * A3/A4/H3 を同時に通した時の本命買い目への最終影響を確認する。
 *
 * 比較:
 *   BASE    : clean CSV時点の現行Web
 *   CURRENT : BASE + 本番済み⑤⑥本命→②③④ kimarite頭補正
 *   NEW     : CURRENT + A3/A4/H3 相手補正
 *
 * 相手補正の固定候補:
 *   A3: 事前Web本命1 × 3C攻め>=15% → 3を2着候補側へ昇格
 *   A4: 事前Web本命1 × 4C攻め>=20% → 4を2着候補側へ昇格
 *   H3: 事前Web本命3 × 3C攻め>=15% → 1を2着候補側へ昇格
 *
 * 重要:
 * - H3は⑤⑥頭補正後に新しく本命3になったレースには拡張しない。
 * - A3/A4も「事前Web本命1」でのみ発動する。
 * - ⑤⑥頭補正は config/kimarite_head_model.php の凍結モデルをそのまま使用する。
 * - kiruはCSVの現行判定を維持する。
 * - actual_* は評価ラベルとしてのみ使用する。
 *
 * Usage:
 * php analysis/validate_kimarite_current_plus_opponent_end_to_end.php \
 *   analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *   analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv
 */

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php {$argv[0]} DATASET_CSV BOATS_CSV\n");
    exit(1);
}

$datasetPath = $argv[1];
$boatsPath = $argv[2];
$modelPath = dirname(__DIR__) . '/config/kimarite_head_model.php';

foreach ([$datasetPath, $boatsPath, $modelPath] as $p) {
    if (!is_file($p)) {
        throw new RuntimeException("必要ファイルがありません: {$p}");
    }
}

$model = require $modelPath;
if (!is_array($model) || empty($model['courses'])) {
    throw new RuntimeException("kimarite頭補正モデルの形式が不正です: {$modelPath}");
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

function inum(array $row, string $key, int $default = 0): int
{
    $v = $row[$key] ?? null;
    return is_numeric($v) ? (int)$v : $default;
}

function fnum(array $row, string $key, float $default = 0.0): float
{
    $v = $row[$key] ?? null;
    return is_numeric($v) ? (float)$v : $default;
}

function pct(int $n, int $d): float
{
    return $d > 0 ? 100.0 * $n / $d : 0.0;
}

function formal(array $row): bool
{
    return inum($row, 'result_top3_course_complete') === 1
        && inum($row, 'result_boat_match') === 1;
}

function sampleOk(array $row, int $course): bool
{
    return inum($row, "c{$course}_6m_sample_n") >= 10;
}

function attack(array $row, int $course): float
{
    return fnum($row, "c{$course}_6m_makuri")
        + fnum($row, "c{$course}_6m_makurizashi");
}

function headFeature(array $row, int $course): float
{
    if ($course === 2) return fnum($row, 'c2_6m_sashi');
    return attack($row, $course);
}

function featureBand(float $v): string
{
    if ($v < 5.0) return '0-5';
    if ($v < 10.0) return '5-10';
    if ($v < 15.0) return '10-15';
    if ($v < 20.0) return '15-20';
    if ($v < 25.0) return '20-25';
    return '25+';
}

function modelScore(array $row, int $course, array $model): float
{
    $cm = $model['courses'][$course] ?? [];
    $base = (float)($cm['base_p'] ?? 0.0);
    $minSample = (int)($model['min_sample'] ?? 10);
    $sampleN = inum($row, "c{$course}_6m_sample_n");
    if ($sampleN < $minSample) return $base;

    $band = featureBand(headFeature($row, $course));
    $br = $cm['bands'][$band] ?? null;
    return is_array($br) && array_key_exists('p', $br)
        ? (float)$br['p']
        : $base;
}

function chooseHead(array $row, array $model): int
{
    $bestCourse = 2;
    $bestScore = -INF;
    foreach ([2, 3, 4] as $course) {
        $score = modelScore($row, $course, $model);
        if ($score > $bestScore || ($score === $bestScore && $course < $bestCourse)) {
            $bestCourse = $course;
            $bestScore = $score;
        }
    }
    return $bestCourse;
}

function rankAndKiru(array $boats): ?array
{
    if (count($boats) !== 6) return null;
    usort($boats, static function(array $a, array $b): int {
        $ra = inum($a, 'final_rank', 99);
        $rb = inum($b, 'final_rank', 99);
        return $ra === $rb
            ? inum($a, 'lane_number', 99) <=> inum($b, 'lane_number', 99)
            : $ra <=> $rb;
    });

    $rank = [];
    $kiru = [];
    foreach ($boats as $b) {
        $lane = inum($b, 'lane_number');
        if ($lane < 1 || $lane > 6) return null;
        $rank[] = $lane;
        $kiru[$lane] = inum($b, 'kiru') === 1;
    }
    return [$rank, $kiru];
}

function moveToFront(array $rank, int $target): array
{
    $rank = array_values(array_filter($rank, static fn(int $b): bool => $b !== $target));
    array_unshift($rank, $target);
    return array_values($rank);
}

function promoteToSecond(array $rank, int $head, int $target): array
{
    if ($head === $target) return $rank;
    $p = array_search($target, $rank, true);
    if ($p === false) return $rank;
    array_splice($rank, (int)$p, 1);
    $hp = array_search($head, $rank, true);
    if ($hp === false) return $rank;
    array_splice($rank, (int)$hp + 1, 0, [$target]);
    return array_values($rank);
}

function buildBet(array $rank, array $kiru, int $head): array
{
    $aite = [];
    $third = [];
    foreach ($rank as $boat) {
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
        'aite' => $aiteSorted,
        'third' => $thirdSorted,
        'kai' => $head . '-' . implode('', $aiteSorted) . '-' . implode('', $thirdSorted),
    ];
}

function betHit(array $row, array $bet, int $head): bool
{
    return inum($row, 'actual_1st_course') === $head
        && in_array(inum($row, 'actual_2nd_course'), $bet['aite'], true)
        && in_array(inum($row, 'actual_3rd_course'), $bet['third'], true);
}

function emptyStat(): array
{
    return [
        'n' => 0,
        'base_hit' => 0,
        'current_hit' => 0,
        'new_hit' => 0,
        'opp_changed' => 0,
        'opp_current_hit' => 0,
        'opp_new_hit' => 0,
        'head_override' => 0,
        'a3' => 0,
        'a4' => 0,
        'h3' => 0,
    ];
}

function addStat(array &$s, bool $baseHit, bool $currentHit, bool $newHit, bool $oppChanged, bool $headOverride, bool $a3, bool $a4, bool $h3): void
{
    $s['n']++;
    $s['base_hit'] += (int)$baseHit;
    $s['current_hit'] += (int)$currentHit;
    $s['new_hit'] += (int)$newHit;
    $s['head_override'] += (int)$headOverride;
    $s['a3'] += (int)$a3;
    $s['a4'] += (int)$a4;
    $s['h3'] += (int)$h3;
    if ($oppChanged) {
        $s['opp_changed']++;
        $s['opp_current_hit'] += (int)$currentHit;
        $s['opp_new_hit'] += (int)$newHit;
    }
}

function printStat(string $label, array $s): void
{
    $n = $s['n'];
    $c = $s['opp_changed'];
    echo "\n" . str_repeat('-', 132) . "\n";
    echo "【{$label}】 N={$n}\n";
    echo str_repeat('-', 132) . "\n";
    echo sprintf("⑤⑥頭補正発動 : %d\n", $s['head_override']);
    echo sprintf("A3/A4/H3発動   : %d / %d / %d\n", $s['a3'], $s['a4'], $s['h3']);
    echo sprintf("相手候補集合変更: %d\n", $c);
    echo "\n本命買い目的中率\n";
    echo sprintf("  BASE    : %d / %d (%6.2f%%)\n", $s['base_hit'], $n, pct($s['base_hit'], $n));
    echo sprintf("  CURRENT : %d / %d (%6.2f%%)  BASE差=%+.3fpt\n",
        $s['current_hit'], $n, pct($s['current_hit'], $n),
        pct($s['current_hit'], $n) - pct($s['base_hit'], $n));
    echo sprintf("  NEW     : %d / %d (%6.2f%%)  CURRENT差=%+d件 / %+.3fpt\n",
        $s['new_hit'], $n, pct($s['new_hit'], $n),
        $s['new_hit'] - $s['current_hit'],
        pct($s['new_hit'], $n) - pct($s['current_hit'], $n));
    if ($c > 0) {
        echo "\n相手候補が実際に変わったレースだけ（CURRENT→NEW）\n";
        echo sprintf("  CURRENT : %d / %d (%6.2f%%)\n", $s['opp_current_hit'], $c, pct($s['opp_current_hit'], $c));
        echo sprintf("  NEW     : %d / %d (%6.2f%%)  差=%+d件 / %+.2fpt\n",
            $s['opp_new_hit'], $c, pct($s['opp_new_hit'], $c),
            $s['opp_new_hit'] - $s['opp_current_hit'],
            pct($s['opp_new_hit'], $c) - pct($s['opp_current_hit'], $c));
    }
}

$datasetRows = readCsvAssoc($datasetPath);
$boatRows = readCsvAssoc($boatsPath);
$boatsByRace = [];
foreach ($boatRows as $b) {
    $rc = trim((string)($b['race_code'] ?? ''));
    if ($rc !== '') $boatsByRace[$rc][] = $b;
}

$all = emptyStat();
$front = emptyStat();
$back = emptyStat();
$monthly = [];
$reconstructN = 0;
$reconstructMatch = 0;

foreach ($datasetRows as $row) {
    if (!formal($row)) continue;
    $rc = trim((string)($row['race_code'] ?? ''));
    $rk = rankAndKiru($boatsByRace[$rc] ?? []);
    if ($rk === null) continue;
    [$baseRank, $kiru] = $rk;

    $originalHead = inum($row, 'honmei_head');
    if (($baseRank[0] ?? 0) !== $originalHead) continue;

    $baseBet = buildBet($baseRank, $kiru, $originalHead);
    $reconstructN++;
    if ($baseBet['kai'] === trim((string)($row['honmei_kai'] ?? ''))) {
        $reconstructMatch++;
    }

    // CURRENT: 本番済み⑤⑥頭補正。
    $currentHead = $originalHead;
    $currentRank = $baseRank;
    $headOverride = false;
    if ($originalHead === 5 || $originalHead === 6) {
        $currentHead = chooseHead($row, $model);
        $currentRank = moveToFront($baseRank, $currentHead);
        $headOverride = true;
    }
    $currentBet = buildBet($currentRank, $kiru, $currentHead);

    // NEW: 今回固定した相手補正。トリガーは必ず「事前Web本命」で判定。
    $newHead = $currentHead;
    $newRank = $currentRank;
    $a3 = false;
    $a4 = false;
    $h3 = false;

    if ($originalHead === 1) {
        $a3 = sampleOk($row, 3) && attack($row, 3) >= 15.0;
        $a4 = sampleOk($row, 4) && attack($row, 4) >= 20.0;

        // A3の方が強いため、同時発動時は最後に3を2番手へ置く。
        if ($a4) $newRank = promoteToSecond($newRank, $newHead, 4);
        if ($a3) $newRank = promoteToSecond($newRank, $newHead, 3);
    } elseif ($originalHead === 3) {
        $h3 = sampleOk($row, 3) && attack($row, 3) >= 15.0;
        if ($h3) $newRank = promoteToSecond($newRank, $newHead, 1);
    }

    $newBet = buildBet($newRank, $kiru, $newHead);
    $oppChanged = $newBet['aite'] !== $currentBet['aite'];

    $baseHit = betHit($row, $baseBet, $originalHead);
    $currentHit = betHit($row, $currentBet, $currentHead);
    $newHit = betHit($row, $newBet, $newHead);

    $date = trim((string)($row['race_date'] ?? ''));
    $target =& ($date < '2026-02-15' ? $front : $back);
    addStat($all, $baseHit, $currentHit, $newHit, $oppChanged, $headOverride, $a3, $a4, $h3);
    addStat($target, $baseHit, $currentHit, $newHit, $oppChanged, $headOverride, $a3, $a4, $h3);
    unset($target);

    $month = substr($date, 0, 7);
    if ($month !== '') {
        if (!isset($monthly[$month])) $monthly[$month] = emptyStat();
        addStat($monthly[$month], $baseHit, $currentHit, $newHit, $oppChanged, $headOverride, $a3, $a4, $h3);
    }
}

echo str_repeat('=', 132) . PHP_EOL;
echo "STEP9 現在本番⑤⑥頭補正 + A3/A4/H3 相手補正 エンドツーエンド統合検証" . PHP_EOL;
echo str_repeat('=', 132) . PHP_EOL;
echo "モデルversion : " . (string)($model['version'] ?? '') . PHP_EOL;
echo "固定候補      : A3=本命1×3C攻め>=15%, A4=本命1×4C攻め>=20%, H3=事前本命3×3C攻め>=15%" . PHP_EOL;
echo "H3制約        : ⑤⑥頭補正で新しく本命3になったレースへは拡張しない" . PHP_EOL;
echo sprintf("買い目再構成一致: %d / %d (%.2f%%)\n", $reconstructMatch, $reconstructN, pct($reconstructMatch, $reconstructN));

printStat('前半6か月', $front);
printStat('後半6か月', $back);
printStat('全期間', $all);

echo "\n" . str_repeat('-', 132) . PHP_EOL;
echo "月次 CURRENT → NEW（相手補正の純増だけを見る）" . PHP_EOL;
echo str_repeat('-', 132) . PHP_EOL;
echo "月       N      CURRENT   NEW       差件   差pt   相手変更\n";
foreach ($monthly as $month => $s) {
    $n = $s['n'];
    echo sprintf(
        "%-7s %6d   %6.2f%%  %6.2f%%   %+4d  %+.3f   %5d\n",
        $month, $n,
        pct($s['current_hit'], $n), pct($s['new_hit'], $n),
        $s['new_hit'] - $s['current_hit'],
        pct($s['new_hit'], $n) - pct($s['current_hit'], $n),
        $s['opp_changed']
    );
}

echo str_repeat('=', 132) . PHP_EOL;
echo "統合検証完了: CURRENT→NEWの増分が前後半・月次で維持されるか確認する。" . PHP_EOL;
echo str_repeat('=', 132) . PHP_EOL;
