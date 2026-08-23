<?php

declare(strict_types=1);

/**
 * 時点別の場1C強さ × 1号艇一次/二次順位 分析。
 *
 * 各レースについて、その開催日より前の180日だけを使い、
 * 同一場の「1C実1着率」を point-in-time で計算する。
 * 同日の先行レース結果も使わないため、未来/当日結果リークを避ける。
 *
 * 目的:
 * - 場名を直接ハードコードせず、「その時点でインが強い場か」を連続特徴として見る
 * - 1号艇 first_rank / second_rank と組み合わせた時の実1着率を確認する
 * - 現行Webが①を本命にする頻度・精度との関係を見る
 *
 * Usage:
 * php analysis/analyze_point_in_time_stadium_lane1_strength.php \
 *   analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *   analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv
 */

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php {$argv[0]} DATASET_CSV BOATS_CSV\n");
    exit(1);
}

[$script, $datasetPath, $boatsPath] = $argv;
foreach ([$datasetPath, $boatsPath] as $p) {
    if (!is_file($p)) throw new RuntimeException("必要ファイルがありません: {$p}");
}

const LOOKBACK_DAYS = 180;

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

function pct(int $n, int $d): float
{
    return $d > 0 ? 100.0 * $n / $d : 0.0;
}

function formal(array $row): bool
{
    return inum($row, 'result_top3_course_complete') === 1
        && inum($row, 'result_boat_match') === 1;
}

function pearson(array $xs, array $ys): float
{
    $n = count($xs);
    if ($n < 2 || $n !== count($ys)) return 0.0;
    $mx = array_sum($xs) / $n;
    $my = array_sum($ys) / $n;
    $num = $dx2 = $dy2 = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $dx = $xs[$i] - $mx;
        $dy = $ys[$i] - $my;
        $num += $dx * $dy;
        $dx2 += $dx * $dx;
        $dy2 += $dy * $dy;
    }
    return ($dx2 > 0.0 && $dy2 > 0.0) ? $num / sqrt($dx2 * $dy2) : 0.0;
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

function emptyStat(): array
{
    return [
        'n'=>0,
        'one_win'=>0,
        'web1'=>0,
        'web1_win'=>0,
        'hist_n_sum'=>0,
        'hist_rate_sum'=>0.0,
    ];
}

function addStat(array &$s, array $row): void
{
    $s['n']++;
    $oneWin = inum($row, '_one_win') === 1;
    $web1 = inum($row, '_web1') === 1;
    $s['one_win'] += (int)$oneWin;
    $s['web1'] += (int)$web1;
    $s['web1_win'] += (int)($oneWin && $web1);
    $s['hist_n_sum'] += inum($row, '_hist_n');
    $s['hist_rate_sum'] += (float)$row['_hist_rate'];
}

$dataset = array_values(array_filter(readCsvAssoc($datasetPath), 'formal'));
$boatRows = readCsvAssoc($boatsPath);

$lane1ByRace = [];
foreach ($boatRows as $b) {
    if (inum($b, 'lane_number') !== 1) continue;
    $rc = trim((string)($b['race_code'] ?? ''));
    if ($rc === '') continue;
    $lane1ByRace[$rc] = [
        'first_rank'=>inum($b, 'first_rank', 99),
        'second_rank'=>inum($b, 'second_rank', 99),
    ];
}

usort($dataset, static function(array $a, array $b): int {
    $da = (string)($a['race_date'] ?? '');
    $db = (string)($b['race_date'] ?? '');
    if ($da !== $db) return $da <=> $db;
    return (string)($a['race_code'] ?? '') <=> (string)($b['race_code'] ?? '');
});

// venue => [['date'=>Y-m-d,'one_win'=>0/1], ...]
$history = [];
$enriched = [];

// 同日結果を当日の特徴に混ぜないため、日単位で処理。
$byDate = [];
foreach ($dataset as $row) {
    $date = trim((string)($row['race_date'] ?? ''));
    if ($date !== '') $byDate[$date][] = $row;
}
ksort($byDate);

foreach ($byDate as $date => $rowsToday) {
    $todayTs = strtotime($date . ' 00:00:00');
    $cutoffTs = strtotime('-' . LOOKBACK_DAYS . ' days', $todayTs);

    foreach ($rowsToday as $row) {
        $venue = trim((string)($row['stadium_name'] ?? ''));
        $rc = trim((string)($row['race_code'] ?? ''));
        if ($venue === '' || $rc === '' || !isset($lane1ByRace[$rc])) continue;

        $hist = $history[$venue] ?? [];
        $n = 0;
        $wins = 0;
        foreach ($hist as $h) {
            $ts = strtotime($h['date'] . ' 00:00:00');
            if ($ts < $cutoffTs || $ts >= $todayTs) continue;
            $n++;
            $wins += (int)$h['one_win'];
        }
        if ($n <= 0) continue;

        $rate = 100.0 * $wins / $n;
        $row['_hist_n'] = $n;
        $row['_hist_rate'] = $rate;
        $row['_strength_band'] = strengthBand($rate);
        $row['_first_band'] = rankBand($lane1ByRace[$rc]['first_rank']);
        $row['_second_band'] = rankBand($lane1ByRace[$rc]['second_rank']);
        $row['_one_win'] = inum($row, 'actual_1st_course') === 1 ? 1 : 0;
        $row['_web1'] = inum($row, 'honmei_head') === 1 ? 1 : 0;
        $enriched[] = $row;
    }

    // 今日の結果は全レース特徴計算後に履歴へ追加。
    foreach ($rowsToday as $row) {
        $venue = trim((string)($row['stadium_name'] ?? ''));
        if ($venue === '') continue;
        $history[$venue][] = [
            'date'=>$date,
            'one_win'=>inum($row, 'actual_1st_course') === 1 ? 1 : 0,
        ];
    }
}

if (!$enriched) throw new RuntimeException('分析可能な履歴付きレースがありません');

$xs = $yWin = $yWeb1 = [];
foreach ($enriched as $r) {
    $xs[] = (float)$r['_hist_rate'];
    $yWin[] = (float)$r['_one_win'];
    $yWeb1[] = (float)$r['_web1'];
}

$bands = ['<50','50-55','55-60','60+'];
$overall = [];
$matrix = [];
foreach ($bands as $b) $overall[$b] = emptyStat();

foreach ($enriched as $r) {
    $sb = $r['_strength_band'];
    addStat($overall[$sb], $r);
    $fb = $r['_first_band'];
    $sec = $r['_second_band'];
    if (!isset($matrix[$sb][$fb][$sec])) $matrix[$sb][$fb][$sec] = emptyStat();
    addStat($matrix[$sb][$fb][$sec], $r);
}

$dates = array_map(static fn($r) => (string)$r['race_date'], $enriched);
sort($dates);
$start = $dates[0] ?? '-';
$end = $dates[count($dates)-1] ?? '-';

echo str_repeat('=', 168) . "\n";
echo "時点別 場1C強さ × 1号艇一次/二次順位 分析\n";
echo str_repeat('=', 168) . "\n";
echo "期間: {$start} ～ {$end} / 分析対象=" . count($enriched) . "R\n";
echo "場1C強さ: 各開催日の前日まで、直近" . LOOKBACK_DAYS . "日だけの同場1C実1着率。同日結果は不使用。\n";
echo "相関: 場1C強さ×実1C勝ち r=" . sprintf('%+.3f', pearson($xs, $yWin))
    . " / 場1C強さ×Web本命① r=" . sprintf('%+.3f', pearson($xs, $yWeb1)) . "\n";
echo "※ 率帯は診断表示用。ここから本番閾値を決めない。\n\n";

echo "【場1C強さ帯】\n";
printf("%-8s %8s %10s %10s %12s %12s %12s\n", '帯','N','実1C勝率','Web①率','Web①精度','平均履歴N','平均場1C率');
echo str_repeat('-', 90) . "\n";
foreach ($bands as $b) {
    $s = $overall[$b];
    $avgN = $s['n'] ? $s['hist_n_sum'] / $s['n'] : 0.0;
    $avgRate = $s['n'] ? $s['hist_rate_sum'] / $s['n'] : 0.0;
    printf("%-8s %8d %9.2f%% %9.2f%% %11.2f%% %11.1f %11.2f%%\n",
        $b, $s['n'], pct($s['one_win'],$s['n']), pct($s['web1'],$s['n']),
        pct($s['web1_win'],$s['web1']), $avgN, $avgRate
    );
}

$rankBands = ['1','2','3','4+'];
echo "\n" . str_repeat('=', 168) . "\n";
echo "【場1C強さ帯 × 1号艇一次順位 × 二次順位】 実1C勝率\n";
echo str_repeat('=', 168) . "\n";
foreach ($bands as $sb) {
    echo "\n◆ 場1C強さ {$sb}\n";
    printf("%-8s", '一次\\二次');
    foreach ($rankBands as $sec) printf(" %18s", $sec);
    echo "\n" . str_repeat('-', 84) . "\n";
    foreach ($rankBands as $fb) {
        printf("%-8s", $fb);
        foreach ($rankBands as $sec) {
            $s = $matrix[$sb][$fb][$sec] ?? emptyStat();
            if ($s['n'] === 0) {
                printf(" %18s", '-');
            } else {
                printf(" %7d/%6.1f%%", $s['n'], pct($s['one_win'],$s['n']));
            }
        }
        echo "\n";
    }
}

echo "\n判断ポイント:\n";
echo "1. 場名ではなく、時点別の場1C強さが実1C勝率と素直に連動するか。\n";
echo "2. 同じ一次/二次順位でも、場1C強さ帯で実1C勝率が変わるか。\n";
echo "3. 特に一次1位・二次低位で、強イン場ほど1号艇が残るならR1の一般化候補。\n";
echo "4. 一次4位以下・二次1位で、弱イン場ほど1号艇が落ちるなら戸田型過信の一般化候補。\n";
echo "5. この段階では率帯・順位条件を本番ルール化しない。\n";
