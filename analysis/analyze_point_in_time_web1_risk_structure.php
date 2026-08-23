<?php

declare(strict_types=1);

/**
 * Web本命①を単純に次点へ差し替えるのではなく、
 * 「①本命のまま危険度を判定できるか」を point-in-time 場1C強さと
 * 1号艇の一次/二次順位で診断する。
 *
 * 見るもの:
 * - Web本命①の失敗率
 * - ①敗戦時に①が2/3着へ残る率 / 3着外へ飛ぶ率
 * - ①敗戦時の勝者実進入コース分布
 * - 一次3位×二次1位、一次4位以下×二次1位が、同じ場1C強さ帯の
 *   Web本命①全体よりどれだけ失敗率が高いか
 * - OLD6M / RECENT6M の方向一致
 *
 * 場1C強さ:
 * 各開催日の前日まで直近180日、同場の実1C勝率。同日結果不使用。
 *
 * 注意:
 * - <50 / 50-55 / 55-60 / 60+ は固定診断帯で、本番閾値ではない
 * - 結果を見て場除外や閾値を追加しない
 * - winner course / ①残りは結果ラベルであり説明用。予測条件には使わない
 *
 * Usage:
 * php analysis/analyze_point_in_time_web1_risk_structure.php \
 *   analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *   analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv
 */

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php {$argv[0]} DATASET_CSV BOATS_CSV\n");
    exit(1);
}

[, $datasetPath, $boatsPath] = $argv;
foreach ([$datasetPath, $boatsPath] as $p) {
    if (!is_file($p)) throw new RuntimeException("必要ファイルがありません: {$p}");
}

const LOOKBACK_DAYS = 180;
const SPLIT_DATE = '2026-02-15';

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

function formal(array $row): bool
{
    return inum($row, 'result_top3_course_complete') === 1
        && inum($row, 'result_boat_match') === 1;
}

function pct(int $n, int $d): float
{
    return $d > 0 ? 100.0 * $n / $d : 0.0;
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

function periodKey(string $date): string
{
    return $date < SPLIT_DATE ? 'OLD6M' : 'RECENT6M';
}

function emptyStat(): array
{
    return [
        'n'=>0,
        'one_win'=>0,
        'one_remain'=>0,
        'one_out'=>0,
        'winner_course'=>[2=>0,3=>0,4=>0,5=>0,6=>0],
    ];
}

function addStat(array &$s, array $r): void
{
    $s['n']++;
    if ($r['_one_win']) {
        $s['one_win']++;
        return;
    }
    if ($r['_one_remain']) $s['one_remain']++;
    else $s['one_out']++;
    $wc = (int)$r['_winner_course'];
    if ($wc >= 2 && $wc <= 6) $s['winner_course'][$wc]++;
}

function failN(array $s): int
{
    return max(0, $s['n'] - $s['one_win']);
}

function fmtStat(array $s): string
{
    $n = $s['n'];
    if ($n <= 0) return '-';
    $fail = failN($s);
    $remain = pct($s['one_remain'], $fail);
    $out = pct($s['one_out'], $fail);
    return sprintf(
        'N=%4d ①勝%5.1f 失敗%5.1f | 敗戦時①残%5.1f 飛%5.1f',
        $n, pct($s['one_win'],$n), pct($fail,$n), $remain, $out
    );
}

function fmtWinnerCourses(array $s): string
{
    $fail = failN($s);
    if ($fail <= 0) return '勝者c2-6: -';
    $parts = [];
    foreach ([2,3,4,5,6] as $c) {
        $parts[] = sprintf('c%d %4.1f', $c, pct($s['winner_course'][$c] ?? 0, $fail));
    }
    return '勝者 ' . implode(' / ', $parts);
}

$dataset = array_values(array_filter(readCsvAssoc($datasetPath), 'formal'));
$boatRows = readCsvAssoc($boatsPath);

$boatsByRace = [];
foreach ($boatRows as $b) {
    $rc = trim((string)($b['race_code'] ?? ''));
    $lane = inum($b, 'lane_number');
    if ($rc === '' || $lane < 1 || $lane > 6) continue;
    $boatsByRace[$rc][$lane] = $b;
}

$byDate = [];
foreach ($dataset as $row) {
    $date = trim((string)($row['race_date'] ?? ''));
    if ($date !== '') $byDate[$date][] = $row;
}
ksort($byDate);

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

        // Web本命①とfinal_rank先頭①が一致するレースに限定。
        $rankRows = array_values($byLane);
        usort($rankRows, static function(array $a, array $b): int {
            $ra = inum($a, 'final_rank', 99);
            $rb = inum($b, 'final_rank', 99);
            if ($ra !== $rb) return $ra <=> $rb;
            return inum($a, 'lane_number', 99) <=> inum($b, 'lane_number', 99);
        });
        if (inum($rankRows[0] ?? [], 'lane_number') !== 1) continue;

        $histN = 0;
        $histWins = 0;
        foreach (($history[$venue] ?? []) as $h) {
            $ts = strtotime($h['date'] . ' 00:00:00');
            if ($ts < $cutoffTs || $ts >= $todayTs) continue;
            $histN++;
            $histWins += (int)$h['one_win'];
        }
        if ($histN <= 0) continue;
        $histRate = 100.0 * $histWins / $histN;

        $lane1 = $byLane[1];
        $a1 = inum($row, 'actual_1st');
        $a2 = inum($row, 'actual_2nd');
        $a3 = inum($row, 'actual_3rd');
        $oneWin = $a1 === 1;
        $oneRemain = !$oneWin && ($a2 === 1 || $a3 === 1);

        $row['_strength_band'] = strengthBand($histRate);
        $row['_first_band'] = rankBand(inum($lane1, 'first_rank', 99));
        $row['_second_band'] = rankBand(inum($lane1, 'second_rank', 99));
        $row['_period'] = periodKey($date);
        $row['_one_win'] = $oneWin;
        $row['_one_remain'] = $oneRemain;
        $row['_winner_course'] = inum($row, 'actual_1st_course');
        $enriched[] = $row;
    }

    // 当日結果は特徴計算後に履歴へ入れる。
    foreach ($rowsToday as $row) {
        $venue = trim((string)($row['stadium_name'] ?? ''));
        if ($venue === '') continue;
        $history[$venue][] = [
            'date'=>$date,
            'one_win'=>inum($row, 'actual_1st_course') === 1 ? 1 : 0,
        ];
    }
}

if (!$enriched) throw new RuntimeException('分析対象がありません');

$bands = ['<50','50-55','55-60','60+'];
$periods = ['OLD6M','RECENT6M'];

$baseline = [];
$focus = [];
$overall = emptyStat();
foreach ($enriched as $r) {
    addStat($overall, $r);
    $sb = $r['_strength_band'];
    $pk = $r['_period'];
    if (!isset($baseline[$sb][$pk])) $baseline[$sb][$pk] = emptyStat();
    addStat($baseline[$sb][$pk], $r);

    $fb = $r['_first_band'];
    $sec = $r['_second_band'];
    if ($sec === '1' && in_array($fb, ['3','4+'], true)) {
        if (!isset($focus[$sb][$fb][$pk])) $focus[$sb][$fb][$pk] = emptyStat();
        addStat($focus[$sb][$fb][$pk], $r);
    }
}

$dates = array_map(static fn(array $r): string => (string)$r['race_date'], $enriched);
sort($dates);
$start = $dates[0] ?? '-';
$end = $dates ? $dates[count($dates)-1] : '-';

echo str_repeat('=', 188) . "\n";
echo "時点別 場1C強さ × Web本命① 危険構造診断\n";
echo str_repeat('=', 188) . "\n";
echo "期間: {$start} ～ {$end} / Web本命①・履歴付き分析対象={$overall['n']}R\n";
echo "場1C強さ: 前日まで直近" . LOOKBACK_DAYS . "日。同日結果不使用。\n";
echo "①敗戦時の『①残』= 1号艇が実2/3着、『飛』= 実3着外。winner courseは説明用結果ラベル。\n";
echo "全体: " . fmtStat($overall) . " | " . fmtWinnerCourses($overall) . "\n\n";

echo "【同じ場1C強さ帯のWeb本命①全体 vs 一次3/4+×二次1】\n";
echo "※ Δ失敗 = focus失敗率 - 同帯同期間Web本命①全体の失敗率。プラスほど①過信方向。\n\n";

foreach ($bands as $sb) {
    echo "◆ 場1C強さ {$sb}\n";
    foreach ($periods as $pk) {
        $base = $baseline[$sb][$pk] ?? emptyStat();
        $baseFail = pct(failN($base), $base['n']);
        printf("  %-8s BASE  %s\n", $pk, fmtStat($base));
        foreach (['3','4+'] as $fb) {
            $s = $focus[$sb][$fb][$pk] ?? emptyStat();
            if ($s['n'] <= 0) {
                printf("           P%-2s×S1 -\n", $fb);
                continue;
            }
            $focusFail = pct(failN($s), $s['n']);
            printf(
                "           P%-2s×S1 %s | Δ失敗=%+5.1fpt | %s\n",
                $fb,
                fmtStat($s),
                $focusFail - $baseFail,
                fmtWinnerCourses($s)
            );
        }
    }
    echo str_repeat('-', 188) . "\n";
}

echo "\n判断ポイント:\n";
echo "1. まずP4+×S1の失敗率が同帯BASEよりOLD/RECENTとも高いかを見る。\n";
echo "2. 次に①敗戦時の『残る/飛ぶ』が場1C強さで安定して変わるかを見る。\n";
echo "3. 勝者c2-6は説明用。結果から頭候補ルールを作らない。\n";
echo "4. 危険度が再現しても①の単純頭差し替えは行わない。警報・買い方側の材料として扱う。\n";
echo "5. この診断から閾値を動かさず、候補が見えたら固定して前方検証する。\n";
