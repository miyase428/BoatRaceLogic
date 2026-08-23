<?php

declare(strict_types=1);

/**
 * 場別に「1号艇を本命にする/外す判断」のズレを確認する。
 *
 * 目的:
 * - 1号艇が勝ったのにWebが1号艇を本命にしなかった = 取りこぼし
 * - 1号艇が負けたのにWebが1号艇を本命にした = 過信
 *
 * ⑤⑥本命のkimarite頭補正は1号艇を新本命にしないため、
 * 1号艇本命判定については dataset の honmei_head をそのまま使える。
 *
 * Usage:
 * php analysis/analyze_stadium_lane1_head_mismatch.php \
 *   analysis/output/kimarite_analysis_dataset_20250815_20260814.csv
 */

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php {$argv[0]} DATASET_CSV\n");
    exit(1);
}

$path = $argv[1];
if (!is_file($path)) {
    throw new RuntimeException("CSVがありません: {$path}");
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

function pct(int $n, int $d): float
{
    return $d > 0 ? 100.0 * $n / $d : 0.0;
}

function formal(array $row): bool
{
    return inum($row, 'result_top3_course_complete') === 1
        && inum($row, 'result_boat_match') === 1;
}

function emptyStat(): array
{
    return [
        'n' => 0,
        'head1' => 0,
        'boat1_win' => 0,
        'course1_win' => 0,
        'caught1' => 0,      // Web本命1 & 1号艇勝ち
        'missed1' => 0,      // Web非1 & 1号艇勝ち
        'overtrust1' => 0,   // Web本命1 & 1号艇負け
        'avoid1' => 0,       // Web非1 & 1号艇負け
        'miss_tech' => [],
        'over_winner_boat' => [],
    ];
}

function addStat(array &$s, array $row): void
{
    $head1 = inum($row, 'honmei_head') === 1;
    $boat1Win = inum($row, 'actual_1st') === 1;
    $course1Win = inum($row, 'actual_1st_course') === 1;

    $s['n']++;
    $s['head1'] += (int)$head1;
    $s['boat1_win'] += (int)$boat1Win;
    $s['course1_win'] += (int)$course1Win;

    if ($head1 && $boat1Win) {
        $s['caught1']++;
    } elseif (!$head1 && $boat1Win) {
        $s['missed1']++;
        $tech = trim((string)($row['winner_technique'] ?? ''));
        if ($tech === '') $tech = '不明';
        $s['miss_tech'][$tech] = ($s['miss_tech'][$tech] ?? 0) + 1;
    } elseif ($head1 && !$boat1Win) {
        $s['overtrust1']++;
        $winner = inum($row, 'actual_1st');
        if ($winner >= 1 && $winner <= 6) {
            $s['over_winner_boat'][$winner] = ($s['over_winner_boat'][$winner] ?? 0) + 1;
        }
    } else {
        $s['avoid1']++;
    }
}

function topKey(array $counts): string
{
    if (!$counts) return '-';
    arsort($counts);
    $parts = [];
    foreach (array_slice($counts, 0, 3, true) as $k => $v) {
        $parts[] = $k . ':' . $v;
    }
    return implode(',', $parts);
}

$rows = readCsvAssoc($path);
$venues = [];
$global = emptyStat();

foreach ($rows as $row) {
    if (!formal($row)) continue;
    $stadium = trim((string)($row['stadium_name'] ?? ''));
    if ($stadium === '') $stadium = '不明';
    if (!isset($venues[$stadium])) $venues[$stadium] = emptyStat();
    addStat($venues[$stadium], $row);
    addStat($global, $row);
}

$rank = [];
foreach ($venues as $stadium => $s) {
    $n = $s['n'];
    $winN = $s['boat1_win'];
    $headN = $s['head1'];
    $rank[] = [
        'stadium' => $stadium,
        's' => $s,
        'capture' => pct($s['caught1'], $winN),
        'precision' => pct($s['caught1'], $headN),
        'miss_rate' => pct($s['missed1'], $n),
        'over_rate' => pct($s['overtrust1'], $n),
        'gap' => pct($s['missed1'], $n) - pct($s['overtrust1'], $n),
    ];
}

usort($rank, static function(array $a, array $b): int {
    // まず「取りこぼし−過信」が大きい順。正なら1号艇を外しすぎ、負なら信頼しすぎ。
    if ($a['gap'] === $b['gap']) return strcmp($a['stadium'], $b['stadium']);
    return $b['gap'] <=> $a['gap'];
});

$gn = $global['n'];
printf("%s\n場別 1号艇本命判断のズレ分析（1年）\n%s\n", str_repeat('=', 170), str_repeat('=', 170));
printf("正式対象: %dR / %d場\n", $gn, count($venues));
printf(
    "全場: 1号艇勝率=%.2f%% / 実1C勝率=%.2f%% / Web本命1率=%.2f%% / 捕捉率=%.2f%% / 本命1精度=%.2f%%\n",
    pct($global['boat1_win'], $gn),
    pct($global['course1_win'], $gn),
    pct($global['head1'], $gn),
    pct($global['caught1'], $global['boat1_win']),
    pct($global['caught1'], $global['head1'])
);
echo "ズレ差 = 取りこぼし率 - 過信率。＋ほど1号艇を外しすぎ、－ほど1号艇を信頼しすぎ。\n\n";

printf("%-4s %-10s %6s %8s %8s %9s %9s %9s %9s %9s %9s %-18s %-18s\n",
    '順','場','N','1艇勝','1C勝','Web本命1','捕捉率','本命1精度','取こぼし','過信','ズレ差','取こぼし決まり手','過信時の勝ち艇'
);
echo str_repeat('-', 170) . "\n";

$i = 1;
foreach ($rank as $r) {
    $s = $r['s'];
    printf("%4d %-10s %6d %7.2f%% %7.2f%% %8.2f%% %8.2f%% %8.2f%% %8.2f%% %8.2f%% %+8.2fpt %-18s %-18s\n",
        $i++,
        $r['stadium'],
        $s['n'],
        pct($s['boat1_win'], $s['n']),
        pct($s['course1_win'], $s['n']),
        pct($s['head1'], $s['n']),
        $r['capture'],
        $r['precision'],
        $r['miss_rate'],
        $r['over_rate'],
        $r['gap'],
        topKey($s['miss_tech']),
        topKey($s['over_winner_boat'])
    );
}

echo "\n" . str_repeat('=', 112) . "\n";
echo "重点8場\n";
echo str_repeat('=', 112) . "\n";
$focus = ['大村','津','宮島','戸田','江戸川','徳山','下関','多摩川'];
foreach ($focus as $stadium) {
    if (!isset($venues[$stadium])) continue;
    $s = $venues[$stadium];
    printf(
        "%-8s N=%4d 1艇勝=%5.1f%% Web1=%5.1f%% 捕捉=%5.1f%% 精度=%5.1f%% 取こぼし=%4d(%5.1f%%) 過信=%4d(%5.1f%%)\n",
        $stadium,
        $s['n'],
        pct($s['boat1_win'], $s['n']),
        pct($s['head1'], $s['n']),
        pct($s['caught1'], $s['boat1_win']),
        pct($s['caught1'], $s['head1']),
        $s['missed1'], pct($s['missed1'], $s['n']),
        $s['overtrust1'], pct($s['overtrust1'], $s['n'])
    );
}

echo "\n判断ポイント:\n";
echo "1. 大村/津/宮島で取りこぼし率が高ければ、1号艇を外しすぎる条件の特定へ進む。\n";
echo "2. 戸田/江戸川で過信率が高ければ、1号艇を本命にしすぎる条件の特定へ進む。\n";
echo "3. ここでは結果を使って補正せず、次STEPの診断対象を決めるだけ。\n";
