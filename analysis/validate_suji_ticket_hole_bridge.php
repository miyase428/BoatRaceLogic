<?php

declare(strict_types=1);

/**
 * STEP9.5 筋舟券 pre-race候補を、現行Web本命買い目とは別枠の「穴保険」として追加した時の効果を検証する。
 *
 * 現行側は、凍結済み⑤⑥頭補正 + A3/A4/H3相手補正まで再現する。
 * 穴側は本命を置き換えず、別チケットとしてのみ追加する。
 *
 * 候補（探索結果を固定して接続）:
 *   S2M15 : 2Cまくり>=15%      -> 2-{3,4}-*
 *   S2S15 : 2C差し>=15%        -> 2-1-*
 *   S3M15 : 3Cまくり>=15%      -> 3-{1,4,5}-*
 *   S3Z10 : 3Cまくり差し>=10%  -> 3-1-*
 *   S4M15A: 4Cまくり>=15%      -> 4-1-*
 *   S4M15B: 4Cまくり>=15%      -> 4-5-*
 *   S4Z10 : 4Cまくり差し>=10%  -> 4-1-*
 *   S5Z15 : 5Cまくり差し>=15%  -> 5-1-*  （芦屋6R型の形成）
 *   S516  : 同条件             -> 5-1-6 （芦屋6R型exact）
 *
 * 穴チケットは2方式を併記する。
 *   CUT維持: 現行kiruを穴買い目にも適用
 *   CUT無視: 穴シナリオだけはkiruを無視
 *
 * 払戻CSVは任意。指定時だけ100円/点ROIを計算する。
 * 既存の6か月払戻CSVを渡した場合、ROI母体は払戻が存在するレースだけになる。
 *
 * Usage:
 * php analysis/validate_suji_ticket_hole_bridge.php \
 *   analysis/output/kimarite_analysis_dataset_20250815_20260814.csv \
 *   analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv \
 *   [analysis/output/trifecta_payouts_20260215_20260814.csv]
 */

if ($argc < 3 || $argc > 4) {
    fwrite(STDERR, "Usage: php {$argv[0]} DATASET_CSV BOATS_CSV [PAYOUT_CSV]\n");
    exit(1);
}

$datasetPath = $argv[1];
$boatsPath = $argv[2];
$payoutPath = $argv[3] ?? null;
$modelPath = dirname(__DIR__) . '/config/kimarite_head_model.php';

foreach ([$datasetPath, $boatsPath, $modelPath] as $p) {
    if (!is_file($p)) {
        throw new RuntimeException("必要ファイルがありません: {$p}");
    }
}
if ($payoutPath !== null && !is_file($payoutPath)) {
    throw new RuntimeException("払戻CSVがありません: {$payoutPath}");
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

function feature(array $row, int $course, string $kind): float
{
    return fnum($row, "c{$course}_6m_{$kind}");
}

function attack(array $row, int $course): float
{
    return feature($row, $course, 'makuri') + feature($row, $course, 'makurizashi');
}

function headFeature(array $row, int $course): float
{
    if ($course === 2) return feature($row, 2, 'sashi');
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
    if (inum($row, "c{$course}_6m_sample_n") < $minSample) return $base;
    $band = featureBand(headFeature($row, $course));
    $br = $cm['bands'][$band] ?? null;
    return is_array($br) && array_key_exists('p', $br) ? (float)$br['p'] : $base;
}

function chooseHead(array $row, array $model): int
{
    $best = 2;
    $scoreBest = -INF;
    foreach ([2, 3, 4] as $course) {
        $score = modelScore($row, $course, $model);
        if ($score > $scoreBest || ($score === $scoreBest && $course < $best)) {
            $best = $course;
            $scoreBest = $score;
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

function buildCurrentBet(array $rank, array $kiru, int $head): array
{
    $aite = [];
    $third = [];
    foreach ($rank as $boat) {
        if ($boat === $head) continue;
        if (($kiru[$boat] ?? false) === true) continue;
        $third[] = $boat;
        if (count($aite) < 3) $aite[] = $boat;
    }
    return expandFormation($head, $aite, $third);
}

function expandFormation(int $head, array $seconds, array $thirds): array
{
    $set = [];
    foreach ($seconds as $s) {
        foreach ($thirds as $t) {
            $s = (int)$s;
            $t = (int)$t;
            if ($s < 1 || $s > 6 || $t < 1 || $t > 6) continue;
            if ($s === $head || $t === $head || $t === $s) continue;
            $set["{$head}-{$s}-{$t}"] = true;
        }
    }
    return $set;
}

function actualTrifecta(array $row): string
{
    return inum($row, 'actual_1st') . '-' . inum($row, 'actual_2nd') . '-' . inum($row, 'actual_3rd');
}

function ruleDefs(): array
{
    return [
        'S2M15'  => ['course'=>2, 'kind'=>'makuri',      'threshold'=>15.0, 'head'=>2, 'seconds'=>[3,4],   'label'=>'2Cまくり>=15 → 2-{3,4}-*'],
        'S2S15'  => ['course'=>2, 'kind'=>'sashi',       'threshold'=>15.0, 'head'=>2, 'seconds'=>[1],     'label'=>'2C差し>=15 → 2-1-*'],
        'S3M15'  => ['course'=>3, 'kind'=>'makuri',      'threshold'=>15.0, 'head'=>3, 'seconds'=>[1,4,5], 'label'=>'3Cまくり>=15 → 3-{1,4,5}-*'],
        'S3Z10'  => ['course'=>3, 'kind'=>'makurizashi', 'threshold'=>10.0, 'head'=>3, 'seconds'=>[1],     'label'=>'3Cまくり差し>=10 → 3-1-*'],
        'S4M15A' => ['course'=>4, 'kind'=>'makuri',      'threshold'=>15.0, 'head'=>4, 'seconds'=>[1],     'label'=>'4Cまくり>=15 → 4-1-*'],
        'S4M15B' => ['course'=>4, 'kind'=>'makuri',      'threshold'=>15.0, 'head'=>4, 'seconds'=>[5],     'label'=>'4Cまくり>=15 → 4-5-*'],
        'S4Z10'  => ['course'=>4, 'kind'=>'makurizashi', 'threshold'=>10.0, 'head'=>4, 'seconds'=>[1],     'label'=>'4Cまくり差し>=10 → 4-1-*'],
        'S5Z15'  => ['course'=>5, 'kind'=>'makurizashi', 'threshold'=>15.0, 'head'=>5, 'seconds'=>[1],     'label'=>'5Cまくり差し>=15 → 5-1-*'],
        'S516'   => ['course'=>5, 'kind'=>'makurizashi', 'threshold'=>15.0, 'head'=>5, 'seconds'=>[1],     'third_fixed'=>[6], 'label'=>'5Cまくり差し>=15 → 5-1-6'],
    ];
}

function ruleTriggered(array $row, array $def): bool
{
    $c = (int)$def['course'];
    if (!sampleOk($row, $c)) return false;
    return feature($row, $c, (string)$def['kind']) >= (float)$def['threshold'];
}

function holeTickets(array $def, array $kiru, bool $ignoreCut): array
{
    $head = (int)$def['head'];
    $seconds = array_map('intval', $def['seconds']);
    $thirds = isset($def['third_fixed'])
        ? array_map('intval', $def['third_fixed'])
        : [1,2,3,4,5,6];

    if (!$ignoreCut) {
        if (($kiru[$head] ?? false) === true) return [];
        $seconds = array_values(array_filter($seconds, static fn(int $b): bool => !($kiru[$b] ?? false)));
        $thirds = array_values(array_filter($thirds, static fn(int $b): bool => !($kiru[$b] ?? false)));
    }
    return expandFormation($head, $seconds, $thirds);
}

function emptyStat(): array
{
    return [
        'trigger'=>0, 'points'=>0, 'hit'=>0, 'rescue'=>0, 'overlap'=>0,
        'payout_races'=>0, 'payout_points'=>0, 'return'=>0,
    ];
}

function addRuleStat(array &$s, array $tickets, string $actual, bool $currentHit, ?int $payout): void
{
    $s['trigger']++;
    $points = count($tickets);
    $s['points'] += $points;
    $hit = isset($tickets[$actual]);
    $s['hit'] += (int)$hit;
    $s['rescue'] += (int)($hit && !$currentHit);
    $s['overlap'] += (int)($hit && $currentHit);
    if ($payout !== null) {
        $s['payout_races']++;
        $s['payout_points'] += $points;
        if ($hit) $s['return'] += $payout;
    }
}

function printStats(string $title, array $stats, array $defs): void
{
    echo "\n" . str_repeat('=', 154) . "\n{$title}\n" . str_repeat('=', 154) . "\n";
    echo sprintf("%-8s %-34s %7s %8s %8s %8s %8s %9s %11s\n",
        'ID','ルール','発動R','平均点','穴的中','救済','重複','救済率','100円/点ROI');
    echo str_repeat('-', 154) . "\n";
    foreach ($defs as $id => $def) {
        $s = $stats[$id] ?? emptyStat();
        $tr = max(1, (int)$s['trigger']);
        $avgPts = $s['points'] / $tr;
        $rescueRate = pct((int)$s['rescue'], (int)$s['trigger']);
        $roi = $s['payout_points'] > 0 ? 100.0 * $s['return'] / (100 * $s['payout_points']) : null;
        echo sprintf("%-8s %-34s %7d %8.2f %8d %8d %8d %8.3f%% %10s\n",
            $id, $def['label'], $s['trigger'], $avgPts, $s['hit'], $s['rescue'], $s['overlap'], $rescueRate,
            $roi === null ? '-' : sprintf('%.2f%%', $roi));
    }
}

$datasetRows = readCsvAssoc($datasetPath);
$boatRows = readCsvAssoc($boatsPath);
$boatsByRace = [];
foreach ($boatRows as $b) {
    $rc = trim((string)($b['race_code'] ?? ''));
    if ($rc !== '') $boatsByRace[$rc][] = $b;
}

$payouts = [];
if ($payoutPath !== null) {
    foreach (readCsvAssoc($payoutPath) as $p) {
        $rc = trim((string)($p['race_code'] ?? ''));
        $v = $p['trifecta_payout'] ?? null;
        if ($rc !== '' && is_numeric($v)) $payouts[$rc] = (int)$v;
    }
}

$defs = ruleDefs();
$periodNames = ['front'=>'前半6か月', 'back'=>'後半6か月', 'all'=>'全期間'];
$stats = [];
$combined = [];
foreach (array_keys($periodNames) as $p) {
    foreach (['keep'=>'CUT維持', 'ignore'=>'CUT無視'] as $mode => $_) {
        foreach ($defs as $id => $_def) $stats[$p][$mode][$id] = emptyStat();
        $combined[$p][$mode] = emptyStat();
    }
}

$formalN = 0;
$currentHitN = 0;

foreach ($datasetRows as $row) {
    if (!formal($row)) continue;
    $rc = trim((string)($row['race_code'] ?? ''));
    $rk = rankAndKiru($boatsByRace[$rc] ?? []);
    if ($rk === null) continue;
    [$baseRank, $kiru] = $rk;

    $originalHead = inum($row, 'honmei_head');
    if (($baseRank[0] ?? 0) !== $originalHead) continue;

    // CURRENT = ⑤⑥頭補正 + A3/A4/H3相手補正。
    $currentHead = $originalHead;
    $currentRank = $baseRank;
    if ($originalHead === 5 || $originalHead === 6) {
        $currentHead = chooseHead($row, $model);
        $currentRank = moveToFront($baseRank, $currentHead);
    }
    if ($originalHead === 1) {
        $a3 = sampleOk($row, 3) && attack($row, 3) >= 15.0;
        $a4 = sampleOk($row, 4) && attack($row, 4) >= 20.0;
        if ($a4) $currentRank = promoteToSecond($currentRank, $currentHead, 4);
        if ($a3) $currentRank = promoteToSecond($currentRank, $currentHead, 3);
    } elseif ($originalHead === 3) {
        $h3 = sampleOk($row, 3) && attack($row, 3) >= 15.0;
        if ($h3) $currentRank = promoteToSecond($currentRank, $currentHead, 1);
    }

    $currentTickets = buildCurrentBet($currentRank, $kiru, $currentHead);
    $actual = actualTrifecta($row);
    $currentHit = isset($currentTickets[$actual]);

    $formalN++;
    $currentHitN += (int)$currentHit;
    $date = trim((string)($row['race_date'] ?? ''));
    $periodKeys = ['all', $date < '2026-02-15' ? 'front' : 'back'];
    $payout = $payouts[$rc] ?? null;

    foreach (['keep'=>false, 'ignore'=>true] as $mode => $ignoreCut) {
        $union = [];
        $anyTriggered = false;

        foreach ($defs as $id => $def) {
            if (!ruleTriggered($row, $def)) continue;
            $anyTriggered = true;
            $tickets = holeTickets($def, $kiru, $ignoreCut);
            foreach ($tickets as $k => $_) $union[$k] = true;
            foreach ($periodKeys as $pk) {
                addRuleStat($stats[$pk][$mode][$id], $tickets, $actual, $currentHit, $payout);
            }
        }

        if ($anyTriggered) {
            foreach ($periodKeys as $pk) {
                addRuleStat($combined[$pk][$mode], $union, $actual, $currentHit, $payout);
            }
        }
    }
}

echo str_repeat('=', 154) . PHP_EOL;
echo "STEP9.5 筋舟券 → 現行Web穴保険買い目 接続検証" . PHP_EOL;
echo str_repeat('=', 154) . PHP_EOL;
echo "正式対象      : {$formalN}" . PHP_EOL;
echo sprintf("CURRENT的中   : %d / %d (%.2f%%)\n", $currentHitN, $formalN, pct($currentHitN, $formalN));
echo "CURRENT       : ⑤⑥頭補正 + A3/A4/H3相手補正まで再現" . PHP_EOL;
echo "穴側          : CURRENTは変更せず、別枠チケットとして追加" . PHP_EOL;
echo "芦屋6R型      : S5Z15=5-1-* / S516=5-1-6" . PHP_EOL;
echo "ROI           : " . ($payoutPath === null ? '払戻CSV未指定のため未計算' : '払戻CSVに存在するレースだけで100円/点計算') . PHP_EOL;

foreach ($periodNames as $pk => $label) {
    printStats("{$label} / CUT維持", $stats[$pk]['keep'], $defs);
    printStats("{$label} / CUT無視", $stats[$pk]['ignore'], $defs);

    foreach (['keep'=>'CUT維持', 'ignore'=>'CUT無視'] as $mode => $modeLabel) {
        $s = $combined[$pk][$mode];
        $avg = $s['trigger'] > 0 ? $s['points'] / $s['trigger'] : 0.0;
        $roi = $s['payout_points'] > 0 ? 100.0 * $s['return'] / (100 * $s['payout_points']) : null;
        echo sprintf("\n【%s / %s / 全候補UNION】 発動R=%d 平均追加点=%.2f 穴的中=%d 救済=%d 重複=%d 救済率=%.3f%% ROI=%s\n",
            $label, $modeLabel, $s['trigger'], $avg, $s['hit'], $s['rescue'], $s['overlap'], pct($s['rescue'], $s['trigger']),
            $roi === null ? '-' : sprintf('%.2f%%', $roi));
    }
}

echo "\n" . str_repeat('=', 154) . PHP_EOL;
echo "判断ポイント" . PHP_EOL;
echo "1. S5Z15が前半/後半とも救済を出すか。S516はexactのため件数よりROI・再現性を重視。" . PHP_EOL;
echo "2. CUT維持で芦屋6R型を落とすならCUT無視との差を見る。穴シナリオだけcutを別扱いする根拠になる。" . PHP_EOL;
echo "3. UNIONは候補を全部足した場合の上限確認。点数過多なら次STEPでルールを絞る。" . PHP_EOL;
echo "4. この結果だけでは本番投入しない。安定候補を固定してからホールドアウトへ進む。" . PHP_EOL;
echo str_repeat('=', 154) . PHP_EOL;
