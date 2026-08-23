<?php

declare(strict_types=1);

/**
 * Web本命①の「過信側」を、場名ではなく point-in-time の場1C強さと
 * 1号艇の一次/二次順位で診断する。
 *
 * 対象:
 *   - 正式対象レース
 *   - 現行Web本命 = 1号艇
 *
 * 比較:
 *   - ①頭: 実1着艇が1号艇か
 *   - 次点頭: 現行の相手順位（A3/A4反映）で最上位の非kiru艇が実1着か
 *
 * 場1C強さ:
 *   各開催日の前日まで、直近180日の同場1C実1着率。
 *   同日の結果は使わない。
 *
 * 目的:
 *   - 「一次4位以下・二次1位」のような①過信仮説が24場共通で成立するかを見る
 *   - ①勝率が低いだけでなく、実際に次点艇へ替えた方が良い領域があるかを見る
 *   - 場名固定の除外ルールを作る前に一般化可能性を診断する
 *
 * 注意:
 *   - <50 / 50-55 / 55-60 / 60+ は診断帯であり、本番閾値ではない
 *   - この出力から場除外・率閾値・順位条件を追加しない
 *   - 次点艇はあくまで既存ロジックから事前に決まる候補で、結果情報は使わない
 *
 * Usage:
 * php analysis/analyze_point_in_time_web1_overtrust.php \
 *   analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *   analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv
 */

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php {$argv[0]} DATASET_CSV BOATS_CSV\n");
    exit(1);
}

[$script, $datasetPath, $boatsPath] = $argv;
foreach ([$datasetPath, $boatsPath] as $p) {
    if (!is_file($p)) {
        throw new RuntimeException("必要ファイルがありません: {$p}");
    }
}

const LOOKBACK_DAYS = 180;
const SPLIT_DATE = '2026-02-15';

function readCsvAssoc(string $path): array
{
    $fp = fopen($path, 'rb');
    if ($fp === false) throw new RuntimeException("CSVを開けません: {$path}");
    $header = fgetcsv($fp);
    if ($header === false) {
        fclose($fp);
        return [];
    }
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

function rankBand(int $rank): string
{
    return $rank >= 1 && $rank <= 3 ? (string)$rank : '4+';
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

/**
 * Web頭①時の現行相手順位を再現。
 * A4を先に、A3を後に適用するため、両方該当時は③が①直後になる。
 */
function currentOpponentRankForWeb1(array $row, array $baseRank): array
{
    $rank = $baseRank;
    $a3 = sampleOk($row, 3) && attack($row, 3) >= 15.0;
    $a4 = sampleOk($row, 4) && attack($row, 4) >= 20.0;
    if ($a4) $rank = promoteToSecond($rank, 1, 4);
    if ($a3) $rank = promoteToSecond($rank, 1, 3);
    return $rank;
}

function emptyStat(): array
{
    return [
        'n'=>0,
        'one_win'=>0,
        'alt_win'=>0,
        'hist_n_sum'=>0,
        'hist_rate_sum'=>0.0,
    ];
}

function addStat(array &$s, array $r): void
{
    $s['n']++;
    $s['one_win'] += (int)($r['_one_win'] === 1);
    $s['alt_win'] += (int)($r['_alt_win'] === 1);
    $s['hist_n_sum'] += (int)$r['_hist_n'];
    $s['hist_rate_sum'] += (float)$r['_hist_rate'];
}

function printCompactStat(array $s): string
{
    if ($s['n'] <= 0) return '-';
    $one = pct($s['one_win'], $s['n']);
    $alt = pct($s['alt_win'], $s['n']);
    return sprintf('%4d ①%5.1f 次%5.1f 差%+5.1f', $s['n'], $one, $alt, $alt - $one);
}

function periodKey(string $date): string
{
    return $date < SPLIT_DATE ? 'OLD6M' : 'RECENT6M';
}

$dataset = array_values(array_filter(readCsvAssoc($datasetPath), 'formal'));
$boatRows = readCsvAssoc($boatsPath);

// race => lane => boat row
$boatsByRace = [];
foreach ($boatRows as $b) {
    $rc = trim((string)($b['race_code'] ?? ''));
    $lane = inum($b, 'lane_number');
    if ($rc === '' || $lane < 1 || $lane > 6) continue;
    $boatsByRace[$rc][$lane] = $b;
}

// 日単位で処理し、同日結果を履歴特徴へ混ぜない。
$byDate = [];
foreach ($dataset as $row) {
    $date = trim((string)($row['race_date'] ?? ''));
    if ($date !== '') $byDate[$date][] = $row;
}
ksort($byDate);

// venue => [['date'=>..., 'one_win'=>0/1], ...]
$history = [];
$enriched = [];

foreach ($byDate as $date => $rowsToday) {
    $todayTs = strtotime($date . ' 00:00:00');
    $cutoffTs = strtotime('-' . LOOKBACK_DAYS . ' days', $todayTs);

    foreach ($rowsToday as $row) {
        if (inum($row, 'honmei_head') !== 1) continue;

        $venue = trim((string)($row['stadium_name'] ?? ''));
        $rc = trim((string)($row['race_code'] ?? ''));
        if ($venue === '' || $rc === '') continue;
        $byLane = $boatsByRace[$rc] ?? [];
        if (count($byLane) !== 6 || !isset($byLane[1])) continue;

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
        $histRate = 100.0 * $histWins / $histN;

        // CSV final_rank を基準順位として並べる。
        $rankRows = array_values($byLane);
        usort($rankRows, static function(array $a, array $b): int {
            $ra = inum($a, 'final_rank', 99);
            $rb = inum($b, 'final_rank', 99);
            if ($ra !== $rb) return $ra <=> $rb;
            return inum($a, 'lane_number', 99) <=> inum($b, 'lane_number', 99);
        });
        $baseRank = array_map(static fn(array $b): int => inum($b, 'lane_number'), $rankRows);

        // Web頭①なら、本来①が順位先頭のレースだけを扱う。
        if (($baseRank[0] ?? 0) !== 1) continue;
        $currentRank = currentOpponentRankForWeb1($row, $baseRank);

        // 次点 = 現行相手順位で最上位の非kiru艇。
        $alt = 0;
        foreach ($currentRank as $lane) {
            if ($lane === 1) continue;
            if (inum($byLane[$lane] ?? [], 'kiru') === 1) continue;
            $alt = $lane;
            break;
        }
        if ($alt < 2 || $alt > 6) continue;

        $lane1 = $byLane[1];
        $actual1 = inum($row, 'actual_1st');

        $row['_hist_n'] = $histN;
        $row['_hist_rate'] = $histRate;
        $row['_strength_band'] = strengthBand($histRate);
        $row['_first_band'] = rankBand(inum($lane1, 'first_rank', 99));
        $row['_second_band'] = rankBand(inum($lane1, 'second_rank', 99));
        $row['_alt_lane'] = $alt;
        $row['_one_win'] = $actual1 === 1 ? 1 : 0;
        $row['_alt_win'] = $actual1 === $alt ? 1 : 0;
        $row['_period'] = periodKey($date);
        $enriched[] = $row;
    }

    // 当日の全正式レース結果を、当日特徴計算後に履歴へ追加。
    foreach ($rowsToday as $row) {
        $venue = trim((string)($row['stadium_name'] ?? ''));
        if ($venue === '') continue;
        $history[$venue][] = [
            'date'=>$date,
            'one_win'=>inum($row, 'actual_1st_course') === 1 ? 1 : 0,
        ];
    }
}

if (!$enriched) {
    throw new RuntimeException('分析可能なWeb本命①レースがありません');
}

$bands = ['<50','50-55','55-60','60+'];
$rankBands = ['1','2','3','4+'];
$matrix = [];
$focus = [];
$overall = emptyStat();

foreach ($enriched as $r) {
    addStat($overall, $r);
    $sb = $r['_strength_band'];
    $fb = $r['_first_band'];
    $sec = $r['_second_band'];
    if (!isset($matrix[$sb][$fb][$sec])) $matrix[$sb][$fb][$sec] = emptyStat();
    addStat($matrix[$sb][$fb][$sec], $r);

    // 過信仮説の境界を見るため、一次3位/4+を全二次帯・全場強さ帯で期間分割。
    if (in_array($fb, ['3','4+'], true)) {
        $pk = $r['_period'];
        if (!isset($focus[$sb][$fb][$sec][$pk])) $focus[$sb][$fb][$sec][$pk] = emptyStat();
        addStat($focus[$sb][$fb][$sec][$pk], $r);
    }
}

$dates = array_map(static fn(array $r): string => (string)$r['race_date'], $enriched);
sort($dates);
$start = $dates[0] ?? '-';
$end = $dates ? $dates[count($dates)-1] : '-';

$overallOne = pct($overall['one_win'], $overall['n']);
$overallAlt = pct($overall['alt_win'], $overall['n']);

echo str_repeat('=', 196) . "\n";
echo "時点別 場1C強さ × Web本命① 過信側診断\n";
echo str_repeat('=', 196) . "\n";
echo "期間: {$start} ～ {$end} / Web本命①・履歴付き分析対象={$overall['n']}R\n";
echo "場1C強さ: 各開催日の前日まで直近" . LOOKBACK_DAYS . "日。同日結果不使用。\n";
echo "次点: 現行相手順位（A3/A4反映）で最上位の非kiru艇。\n";
printf("全体: ①頭=%5.2f%% / 次点頭=%5.2f%% / 次点-①=%+5.2fpt\n", $overallOne, $overallAlt, $overallAlt - $overallOne);
echo "※ 各セルは『N / ①実1着率 / 次点艇実1着率 / 次点-①』。率帯・順位帯は診断であり本番条件ではない。\n\n";

echo "【場1C強さ帯 × 1号艇一次順位 × 二次順位】\n";
foreach ($bands as $sb) {
    echo "\n◆ 場1C強さ {$sb}\n";
    printf("%-8s", '一次\\二次');
    foreach ($rankBands as $sec) printf(" | %-30s", $sec);
    echo "\n" . str_repeat('-', 148) . "\n";
    foreach ($rankBands as $fb) {
        printf("%-8s", $fb);
        foreach ($rankBands as $sec) {
            $s = $matrix[$sb][$fb][$sec] ?? emptyStat();
            printf(" | %-30s", printCompactStat($s));
        }
        echo "\n";
    }
}

echo "\n" . str_repeat('=', 196) . "\n";
echo "【一次3位 / 4位以下：OLD6M vs RECENT6M】\n";
echo "①を下げる仮説の境界確認。全二次帯をそのまま表示し、ここでは条件抽出しない。\n";
echo str_repeat('=', 196) . "\n";

foreach ($bands as $sb) {
    echo "\n◆ 場1C強さ {$sb}\n";
    foreach (['3','4+'] as $fb) {
        echo "  一次 {$fb}\n";
        foreach ($rankBands as $sec) {
            $old = $focus[$sb][$fb][$sec]['OLD6M'] ?? emptyStat();
            $recent = $focus[$sb][$fb][$sec]['RECENT6M'] ?? emptyStat();
            printf(
                "    二次 %-2s | OLD %-30s | RECENT %-30s\n",
                $sec,
                printCompactStat($old),
                printCompactStat($recent)
            );
        }
    }
}

echo "\n判断ポイント:\n";
echo "1. ①実1着率が低いだけでは過信ルールにしない。次点艇の実1着率が①を上回る領域があるかを見る。\n";
echo "2. 特に一次4位以下×二次1位で、弱イン帯ほど『次点-①』がプラスになるかを見る。\n";
echo "3. OLD6M/RECENT6Mで方向が揃わない領域は一般化候補にしない。\n";
echo "4. 次点が①を上回らないなら、①過信問題があっても単純な頭差し替えでは解決しない。\n";
echo "5. この出力から場除外・場1C閾値・順位閾値を追加せず、本番ロジックも変更しない。\n";
