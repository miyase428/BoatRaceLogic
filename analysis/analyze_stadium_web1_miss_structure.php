<?php

declare(strict_types=1);

/**
 * 場別 現行Web本命①の敗戦構造。
 *
 * 目的:
 * - 現行Webが①を本命にしたレースに限定し、①敗戦時に何号艇/何コースが勝つかを見る
 * - 勝ち艇が現行最終順位の何位だったかを確認する
 * - ①が2・3着に残るか、圏外まで落ちるかを場別に比較する
 * - 戸田/江戸川と、多摩川/下関などWeb相性が良い場の「見落とし方」の差を診断する
 *
 * 注意:
 * - ここでは補正・閾値・買い目変更は行わない
 * - 現行本番で頭が①になるのは honmei_head=1 のケース。
 *   ⑤⑥頭補正は2/3/4のみを選ぶため①にはならない。
 * - A3/A4/H3は相手順位補正のみなので、①本命判定には影響しない。
 *
 * Usage:
 * php analysis/analyze_stadium_web1_miss_structure.php \
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

function attack(array $row, int $course): float
{
    return fnum($row, "c{$course}_6m_makuri") + fnum($row, "c{$course}_6m_makurizashi");
}

function emptyStat(): array
{
    return [
        'all'=>0,
        'web1'=>0,
        'miss'=>0,
        'winner_boat'=>array_fill(1, 6, 0),
        'winner_course'=>array_fill(1, 6, 0),
        'winner_rank'=>array_fill(1, 6, 0),
        'tech'=>[],
        'one_2nd'=>0,
        'one_3rd'=>0,
        'one_out'=>0,
        'winner_attack_sum'=>array_fill(1, 6, 0.0),
        'winner_attack_n'=>array_fill(1, 6, 0),
        'winner_attack15'=>array_fill(1, 6, 0),
        'winner_attack20'=>array_fill(1, 6, 0),
    ];
}

function addTech(array &$s, string $tech): void
{
    if ($tech === '') $tech = '不明';
    $s['tech'][$tech] = ($s['tech'][$tech] ?? 0) + 1;
}

function distText(array $counts, int $d, array $keys): string
{
    $parts = [];
    foreach ($keys as $k) {
        $parts[] = sprintf('%d:%4.1f', $k, pct((int)($counts[$k] ?? 0), $d));
    }
    return implode('/', $parts);
}

function topTechText(array $tech, int $d): string
{
    arsort($tech);
    $parts = [];
    foreach (array_slice($tech, 0, 4, true) as $k => $n) {
        $parts[] = sprintf('%s %.1f', $k, pct((int)$n, $d));
    }
    return implode('/', $parts);
}

$dataset = readCsvAssoc($datasetPath);
$boatRows = readCsvAssoc($boatsPath);

$rankByRaceBoat = [];
foreach ($boatRows as $b) {
    $rc = trim((string)($b['race_code'] ?? ''));
    $boat = inum($b, 'lane_number');
    if ($rc === '' || $boat < 1 || $boat > 6) continue;
    $rankByRaceBoat[$rc][$boat] = inum($b, 'final_rank', 99);
}

$stats = [];
$formalN = 0;
$dates = [];

foreach ($dataset as $row) {
    if (!formal($row)) continue;
    $formalN++;

    $venue = trim((string)($row['stadium_name'] ?? ''));
    if ($venue === '') continue;
    if (!isset($stats[$venue])) $stats[$venue] = emptyStat();
    $s =& $stats[$venue];
    $s['all']++;

    $date = trim((string)($row['race_date'] ?? ''));
    if ($date !== '') $dates[] = $date;

    // 現行Web本命①。
    if (inum($row, 'honmei_head') !== 1) {
        unset($s);
        continue;
    }
    $s['web1']++;

    // ①艇が勝てば敗戦分析対象外。
    if (inum($row, 'actual_1st') === 1) {
        unset($s);
        continue;
    }
    $s['miss']++;

    $winnerBoat = inum($row, 'actual_1st');
    $winnerCourse = inum($row, 'actual_1st_course');
    $rc = trim((string)($row['race_code'] ?? ''));

    if ($winnerBoat >= 1 && $winnerBoat <= 6) {
        $s['winner_boat'][$winnerBoat]++;
        $wr = (int)($rankByRaceBoat[$rc][$winnerBoat] ?? 99);
        if ($wr >= 1 && $wr <= 6) $s['winner_rank'][$wr]++;
    }
    if ($winnerCourse >= 1 && $winnerCourse <= 6) {
        $s['winner_course'][$winnerCourse]++;
        if ($winnerCourse >= 3 && $winnerCourse <= 5) {
            $a = attack($row, $winnerCourse);
            $s['winner_attack_sum'][$winnerCourse] += $a;
            $s['winner_attack_n'][$winnerCourse]++;
            if ($a >= 15.0) $s['winner_attack15'][$winnerCourse]++;
            if ($a >= 20.0) $s['winner_attack20'][$winnerCourse]++;
        }
    }

    addTech($s, trim((string)($row['winner_technique'] ?? '')));

    if (inum($row, 'actual_2nd') === 1) {
        $s['one_2nd']++;
    } elseif (inum($row, 'actual_3rd') === 1) {
        $s['one_3rd']++;
    } else {
        $s['one_out']++;
    }

    unset($s);
}
// $s を参照変数のまま残すと、後続 foreach の値代入で最後に参照していた場を上書きする。
unset($s);

uasort($stats, static function(array $a, array $b): int {
    $ra = pct($a['miss'], $a['web1']);
    $rb = pct($b['miss'], $b['web1']);
    return $rb <=> $ra;
});

sort($dates);
$start = $dates[0] ?? '-';
$end = $dates ? $dates[count($dates)-1] : '-';

echo str_repeat('=', 186) . "\n";
echo "場別 現行Web本命①の敗戦構造（1年）\n";
echo str_repeat('=', 186) . "\n";
echo "期間: {$start} ～ {$end} / 正式対象: {$formalN}R / " . count($stats) . "場\n";
echo "Web①敗率 = Web本命①のうち①艇が1着でなかった率。勝ち艇順位は補正前CSVの final_rank。\n";
echo "勝ち艇/勝ちC欄 = 2/3/4/5/6 の構成比(%)。①残り = ①艇が2着または3着。\n\n";

echo str_repeat('=', 186) . "\n";
echo "24場比較（Web本命①の敗率順）\n";
echo str_repeat('=', 186) . "\n";
printf("%-3s %-8s %6s %8s %8s %-29s %-29s %-29s %8s %8s %-28s\n",
    '順','場','Web①N','①敗','敗率','勝ち艇 2/3/4/5/6','勝ちC 2/3/4/5/6','勝ち艇順位 2/3/4/5/6','①残り','①圏外','決まり手TOP');
echo str_repeat('-', 186) . "\n";

$i = 1;
foreach ($stats as $venue => $s) {
    $m = $s['miss'];
    $remain = $s['one_2nd'] + $s['one_3rd'];
    printf(
        "%3d %-8s %6d %8d %7.2f%% %-29s %-29s %-29s %7.2f%% %7.2f%% %-28s\n",
        $i++, $venue, $s['web1'], $m, pct($m, $s['web1']),
        distText($s['winner_boat'], $m, [2,3,4,5,6]),
        distText($s['winner_course'], $m, [2,3,4,5,6]),
        distText($s['winner_rank'], $m, [2,3,4,5,6]),
        pct($remain, $m), pct($s['one_out'], $m), topTechText($s['tech'], $m)
    );
}

$focus = ['徳山','下関','多摩川','大村','津','宮島','戸田','江戸川'];
echo "\n" . str_repeat('=', 150) . "\n";
echo "重点8場 詳細\n";
echo str_repeat('=', 150) . "\n";
foreach ($focus as $venue) {
    if (!isset($stats[$venue])) continue;
    $s = $stats[$venue];
    $m = $s['miss'];
    $remain = $s['one_2nd'] + $s['one_3rd'];
    echo str_repeat('-', 150) . "\n";
    printf("【%s】 全体N=%d / Web本命①=%d / ①敗=%d (%.2f%%)\n",
        $venue, $s['all'], $s['web1'], $m, pct($m, $s['web1']));
    printf("  勝ち艇     : %s\n", distText($s['winner_boat'], $m, [2,3,4,5,6]));
    printf("  勝ちコース : %s\n", distText($s['winner_course'], $m, [2,3,4,5,6]));
    printf("  勝ち艇順位 : %s\n", distText($s['winner_rank'], $m, [2,3,4,5,6]));
    printf("  決まり手   : %s\n", topTechText($s['tech'], $m));
    printf("  ①残り     : 2着 %.1f%% / 3着 %.1f%% / 合計 %.1f%% / 圏外 %.1f%%\n",
        pct($s['one_2nd'],$m), pct($s['one_3rd'],$m), pct($remain,$m), pct($s['one_out'],$m));
    foreach ([3,4,5] as $c) {
        $n = $s['winner_attack_n'][$c];
        if ($n <= 0) continue;
        printf("  %dC勝ち時攻め率: N=%d 平均=%.2f / >=15 %.1f%% / >=20 %.1f%%\n",
            $c, $n,
            $s['winner_attack_sum'][$c] / $n,
            pct($s['winner_attack15'][$c], $n),
            pct($s['winner_attack20'][$c], $n)
        );
    }
}

echo "\n判断ポイント:\n";
echo "1. 戸田/江戸川でWeb①敗戦時の勝ち艇が2～4位に集中するなら、頭候補自体は近くにいる可能性がある。\n";
echo "2. 勝ち艇順位4位以下が多ければ、単純な次点繰上げでは救いにくい。\n";
echo "3. ①残り率が高い場は、将来の穴目警報で『①残り』を独立シグナル化できる可能性がある。\n";
echo "4. 3～5C勝ち時の事前攻め率が高い場では、場×決まり手条件を頭候補診断へ繋げる余地がある。\n";
echo "5. この段階では補正・閾値・本番ロジック変更は行わない。\n";
