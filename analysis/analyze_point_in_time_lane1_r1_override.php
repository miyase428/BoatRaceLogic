<?php

declare(strict_types=1);

/**
 * 時点別の場1C強さ帯ごとに、R1一般化候補を直接比較する。
 *
 * 対象:
 * - 現行Web本命 != 1
 * - 1号艇の一次順位 = 1
 *
 * 比較:
 * - CURRENT: 現行本番（⑤⑥頭補正 + A3/A4/H3相手補正を再構成）
 * - NEW: 頭を1号艇へ戻す
 *
 * 場1C強さ:
 * - 各開催日の前日まで
 * - 直近180日の同場1C実1着率
 * - 同日結果は不使用
 *
 * 率帯 <50 / 50-55 / 55-60 / 60+ は前STEPの診断帯をそのまま使用。
 * この出力から新しい閾値を決めない。
 *
 * Usage:
 * php analysis/analyze_point_in_time_lane1_r1_override.php \
 *   analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *   analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv
 */

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php {$argv[0]} DATASET_CSV BOATS_CSV\n");
    exit(1);
}

[$script, $datasetPath, $boatsPath] = $argv;
$modelPath = dirname(__DIR__) . '/config/kimarite_head_model.php';
foreach ([$datasetPath, $boatsPath, $modelPath] as $p) {
    if (!is_file($p)) throw new RuntimeException("必要ファイルがありません: {$p}");
}
$model = require $modelPath;
if (!is_array($model) || empty($model['courses'])) {
    throw new RuntimeException("kimarite頭補正モデルの形式が不正です: {$modelPath}");
}

const LOOKBACK_DAYS = 180;
const OLD_END = '2026-02-14';

function readCsvAssoc(string $path): array
{
    $fp = fopen($path, 'rb');
    if ($fp === false) throw new RuntimeException("CSVを開けません: {$path}");
    $header = fgetcsv($fp);
    if ($header === false) { fclose($fp); return []; }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
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

function strengthBand(float $rate): string
{
    if ($rate < 50.0) return '<50';
    if ($rate < 55.0) return '50-55';
    if ($rate < 60.0) return '55-60';
    return '60+';
}

function sampleOk(array $row, int $course): bool
{
    return inum($row, "c{$course}_6m_sample_n") >= 10;
}

function attack(array $row, int $course): float
{
    return fnum($row, "c{$course}_6m_makuri") + fnum($row, "c{$course}_6m_makurizashi");
}

function headFeature(array $row, int $course): float
{
    return $course === 2 ? fnum($row, 'c2_6m_sashi') : attack($row, $course);
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
    if (inum($row, "c{$course}_6m_sample_n") < $minSample) return $base;
    $band = featureBand(headFeature($row, $course));
    $br = $cm['bands'][$band] ?? null;
    return is_array($br) && array_key_exists('p', $br) ? (float)$br['p'] : $base;
}

function chooseHead(array $row, array $model): int
{
    $best = 2;
    $bestScore = -INF;
    foreach ([2,3,4] as $course) {
        $s = modelScore($row, $course, $model);
        if ($s > $bestScore || ($s === $bestScore && $course < $best)) {
            $best = $course;
            $bestScore = $s;
        }
    }
    return $best;
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
    $byLane = [];
    foreach ($boats as $b) {
        $lane = inum($b, 'lane_number');
        if ($lane < 1 || $lane > 6) return null;
        $rank[] = $lane;
        $kiru[$lane] = inum($b, 'kiru') === 1;
        $byLane[$lane] = $b;
    }
    return [$rank, $kiru, $byLane];
}

function moveToFront(array $rank, int $target): array
{
    $rank = array_values(array_filter($rank, static fn(int $b): bool => $b !== $target));
    array_unshift($rank, $target);
    return $rank;
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
    return ['aite'=>$aite, 'third'=>$third];
}

function betHit(array $row, array $bet, int $head): bool
{
    return inum($row, 'actual_1st') === $head
        && in_array(inum($row, 'actual_2nd'), $bet['aite'], true)
        && in_array(inum($row, 'actual_3rd'), $bet['third'], true);
}

function currentPrediction(array $row, array $baseRank, array $kiru, array $model): array
{
    $originalHead = inum($row, 'honmei_head');
    $head = $originalHead;
    $rank = $baseRank;

    if ($originalHead === 5 || $originalHead === 6) {
        $head = chooseHead($row, $model);
        $rank = moveToFront($rank, $head);
    }

    if ($originalHead === 1) {
        $a3 = sampleOk($row, 3) && attack($row, 3) >= 15.0;
        $a4 = sampleOk($row, 4) && attack($row, 4) >= 20.0;
        if ($a4) $rank = promoteToSecond($rank, $head, 4);
        if ($a3) $rank = promoteToSecond($rank, $head, 3);
    } elseif ($originalHead === 3) {
        $h3 = sampleOk($row, 3) && attack($row, 3) >= 15.0;
        if ($h3) $rank = promoteToSecond($rank, $head, 1);
    }

    return [$head, $rank, buildBet($rank, $kiru, $head)];
}

function emptyStat(): array
{
    return ['n'=>0,'cur_head'=>0,'new_head'=>0,'cur_bet'=>0,'new_bet'=>0,'rate_sum'=>0.0,'hist_n_sum'=>0];
}

function addStat(array &$s, bool $curHeadHit, bool $newHeadHit, bool $curBetHit, bool $newBetHit, float $rate, int $histN): void
{
    $s['n']++;
    $s['cur_head'] += (int)$curHeadHit;
    $s['new_head'] += (int)$newHeadHit;
    $s['cur_bet'] += (int)$curBetHit;
    $s['new_bet'] += (int)$newBetHit;
    $s['rate_sum'] += $rate;
    $s['hist_n_sum'] += $histN;
}

function printStat(string $label, array $s): void
{
    $n = $s['n'];
    $avgRate = $n ? $s['rate_sum'] / $n : 0.0;
    $avgHistN = $n ? $s['hist_n_sum'] / $n : 0.0;
    printf(
        "%-9s N=%5d | 頭 %6.2f%%→%6.2f%% %+6.2fpt (%+4d) | 買い目 %6.2f%%→%6.2f%% %+6.2fpt (%+4d) | 平均場1C=%5.2f%% 履歴N=%6.1f\n",
        $label,
        $n,
        pct($s['cur_head'],$n), pct($s['new_head'],$n), pct($s['new_head'],$n)-pct($s['cur_head'],$n), $s['new_head']-$s['cur_head'],
        pct($s['cur_bet'],$n), pct($s['new_bet'],$n), pct($s['new_bet'],$n)-pct($s['cur_bet'],$n), $s['new_bet']-$s['cur_bet'],
        $avgRate, $avgHistN
    );
}

$dataset = array_values(array_filter(readCsvAssoc($datasetPath), 'formal'));
$boatRows = readCsvAssoc($boatsPath);
$boatsByRace = [];
foreach ($boatRows as $b) {
    $rc = trim((string)($b['race_code'] ?? ''));
    if ($rc !== '') $boatsByRace[$rc][] = $b;
}

usort($dataset, static function(array $a, array $b): int {
    $da = (string)($a['race_date'] ?? '');
    $db = (string)($b['race_date'] ?? '');
    if ($da !== $db) return $da <=> $db;
    return (string)($a['race_code'] ?? '') <=> (string)($b['race_code'] ?? '');
});

$byDate = [];
foreach ($dataset as $row) {
    $date = trim((string)($row['race_date'] ?? ''));
    if ($date !== '') $byDate[$date][] = $row;
}
ksort($byDate);

$bands = ['<50','50-55','55-60','60+'];
$periods = ['OLD6M','RECENT6M','ALL1Y'];
$stats = [];
foreach ($bands as $band) {
    foreach ($periods as $p) $stats[$band][$p] = emptyStat();
}
$history = [];
$formalWithHistory = 0;
$candidateN = 0;

foreach ($byDate as $date => $rowsToday) {
    $todayTs = strtotime($date . ' 00:00:00');
    $cutoffTs = strtotime('-' . LOOKBACK_DAYS . ' days', $todayTs);

    foreach ($rowsToday as $row) {
        $venue = trim((string)($row['stadium_name'] ?? ''));
        $rc = trim((string)($row['race_code'] ?? ''));
        if ($venue === '' || $rc === '') continue;

        $hist = $history[$venue] ?? [];
        $histN = 0;
        $histWins = 0;
        foreach ($hist as $h) {
            $ts = strtotime($h['date'] . ' 00:00:00');
            if ($ts < $cutoffTs || $ts >= $todayTs) continue;
            $histN++;
            $histWins += (int)$h['one_win'];
        }
        if ($histN <= 0) continue;
        $formalWithHistory++;
        $rate = 100.0 * $histWins / $histN;
        $band = strengthBand($rate);

        $rk = rankAndKiru($boatsByRace[$rc] ?? []);
        if ($rk === null) continue;
        [$baseRank, $kiru, $byLane] = $rk;
        $originalHead = inum($row, 'honmei_head');
        if (($baseRank[0] ?? 0) !== $originalHead) continue;

        [$curHead, $curRank, $curBet] = currentPrediction($row, $baseRank, $kiru, $model);
        $lane1 = $byLane[1] ?? null;
        if (!is_array($lane1)) continue;
        if ($curHead === 1 || inum($lane1, 'first_rank', 99) !== 1) continue;
        $candidateN++;

        $newHead = 1;
        $newRank = moveToFront($curRank, 1);
        $newBet = buildBet($newRank, $kiru, $newHead);

        $actual1 = inum($row, 'actual_1st');
        $curHeadHit = $actual1 === $curHead;
        $newHeadHit = $actual1 === $newHead;
        $curBetHit = betHit($row, $curBet, $curHead);
        $newBetHit = betHit($row, $newBet, $newHead);

        $period = $date <= OLD_END ? 'OLD6M' : 'RECENT6M';
        addStat($stats[$band][$period], $curHeadHit, $newHeadHit, $curBetHit, $newBetHit, $rate, $histN);
        addStat($stats[$band]['ALL1Y'], $curHeadHit, $newHeadHit, $curBetHit, $newBetHit, $rate, $histN);
    }

    // 同日の結果は全レースの特徴計算後に追加。
    foreach ($rowsToday as $row) {
        $venue = trim((string)($row['stadium_name'] ?? ''));
        if ($venue === '') continue;
        $history[$venue][] = [
            'date'=>$date,
            'one_win'=>inum($row, 'actual_1st_course') === 1 ? 1 : 0,
        ];
    }
}

echo str_repeat('=', 178) . "\n";
echo "時点別 場1C強さ × R1一般化候補 直接比較\n";
echo str_repeat('=', 178) . "\n";
echo "対象: 現行Web頭≠1 × 1号艇一次1位。NEWは1号艇へ戻す。\n";
echo "場1C強さ: 前日まで直近" . LOOKBACK_DAYS . "日、同日結果不使用。\n";
echo "履歴付き正式対象={$formalWithHistory}R / R1一般化候補={$candidateN}R\n";
echo "※ <50 / 50-55 / 55-60 / 60+ は診断帯。ここから閾値を決めない。\n\n";

foreach ($bands as $band) {
    echo str_repeat('-', 178) . "\n【場1C強さ {$band}】\n";
    foreach ($periods as $p) printStat($p, $stats[$band][$p]);
}

echo "\n判断ポイント:\n";
echo "1. 強い場1C帯ほど、1号艇へ戻す改善幅が大きくなるか。\n";
echo "2. OLD6M / RECENT6M の両方で頭・買い目が改善するか。\n";
echo "3. 率帯はまだ候補抽出用の診断。結果を見て境界を動かさない。\n";
echo "4. 再現する帯があっても、この1年内比較だけで本番化しない。次に未使用前方期間で固定検証する。\n";
