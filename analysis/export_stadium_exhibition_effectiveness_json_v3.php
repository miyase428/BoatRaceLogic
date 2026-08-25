<?php

declare(strict_types=1);

/**
 * 場別 展示・ST効き方 JSON exporter V3
 *
 * - exhibition_live / race_result_detail は LATERAL + LIMIT 1 で1選手1行に固定
 * - stadium_master / exhibition_avg_6m はメインSQLにJOINしない
 * - exhibition_avg_6m は24場分だけ別取得
 * - exhibition_live の並び順は実在する created_date のみを使用
 * - 展示STは現行 tenji_api.php の ST_BAND と同じ
 * - 直線欠損場でも他4項目は集計する
 *
 * Usage:
 *   php analysis/export_stadium_exhibition_effectiveness_json_v3.php 2025-08-15 2026-08-14
 */

require_once __DIR__ . '/../common/db_connect.php';

$from = $argv[1] ?? '2025-08-15';
$to   = $argv[2] ?? '2026-08-14';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)
    || $from > $to) {
    fwrite(STDERR, "使用方法: php {$argv[0]} YYYY-MM-DD YYYY-MM-DD\n");
    exit(1);
}

$affinityPath = __DIR__ . '/../config/stadium_affinity.json';
$affinityJson = is_file($affinityPath) ? file_get_contents($affinityPath) : false;
$affinity = is_string($affinityJson) ? json_decode($affinityJson, true) : null;
if (!is_array($affinity)) {
    throw new RuntimeException('stadium_affinity.json を読み込めません。');
}

$nameByCode = [];
foreach (($affinity['stadiums'] ?? []) as $code => $row) {
    if (is_array($row)) {
        $nameByCode[(string)$code] = trim((string)($row['name'] ?? $code));
    }
}

$items = [
    'exhibition' => '展示タイム',
    'st' => '展示ST',
    'lap' => '周回',
    'mawari' => '周り足',
    'straight' => '直線',
];

function exNum(mixed $value): ?float
{
    return ($value !== null && $value !== '' && is_numeric($value)) ? (float)$value : null;
}

function exRank(mixed $value): ?int
{
    $s = trim((string)($value ?? ''));
    return preg_match('/^[1-6]$/', $s) ? (int)$s : null;
}

function exScore(float $diff): int
{
    if ($diff <= -0.10) return 5;
    if ($diff <= -0.05) return 4;
    if ($diff <=  0.05) return 3;
    if ($diff <=  0.10) return 2;
    return 1;
}

function stScore(float $st): int
{
    if ($st <= 0.00) return 3;
    if ($st <= 0.12) return 5;
    if ($st <= 0.20) return 3;
    if ($st <= 0.30) return 2;
    return 1;
}

function lapScore(float $diff): int
{
    if ($diff <= -0.30) return 5;
    if ($diff <= -0.10) return 4;
    if ($diff <=  0.10) return 3;
    if ($diff <=  0.30) return 2;
    return 1;
}

function mawariScore(float $diff): int
{
    if ($diff <= -0.20) return 5;
    if ($diff <= -0.05) return 4;
    if ($diff <=  0.05) return 3;
    if ($diff <=  0.20) return 2;
    return 1;
}

function straightScore(float $diff): int
{
    if ($diff <= -0.04) return 5;
    if ($diff <= -0.01) return 4;
    if ($diff <=  0.01) return 3;
    if ($diff <=  0.04) return 2;
    return 1;
}

function emptyBucketV3(): array
{
    return ['n' => 0, 'first' => 0, 'top3' => 0];
}

function emptyItemV3(): array
{
    return ['valid_n' => 0, 'good' => emptyBucketV3(), 'bad' => emptyBucketV3()];
}

function ensurePlaceV3(array &$stats, string $place, array $items): void
{
    if (isset($stats[$place])) return;
    $stats[$place] = [];
    foreach ($items as $key => $_name) {
        $stats[$place][$key] = emptyItemV3();
    }
}

function addScoreV3(array &$stats, string $place, string $item, int $score, int $rank, array $items): void
{
    ensurePlaceV3($stats, $place, $items);
    $stats[$place][$item]['valid_n']++;

    $bucket = $score >= 4 ? 'good' : ($score <= 2 ? 'bad' : null);
    if ($bucket === null) return;

    $stats[$place][$item][$bucket]['n']++;
    if ($rank === 1) $stats[$place][$item][$bucket]['first']++;
    if ($rank <= 3) $stats[$place][$item][$bucket]['top3']++;
}

function pctV3(int $n, int $d): ?float
{
    return $d > 0 ? round($n / $d * 100.0, 2) : null;
}

function finalizeItemV3(array $raw, ?array $overall = null): array
{
    $good = $raw['good'] ?? emptyBucketV3();
    $bad = $raw['bad'] ?? emptyBucketV3();

    $goodFirst = pctV3((int)$good['first'], (int)$good['n']);
    $badFirst = pctV3((int)$bad['first'], (int)$bad['n']);
    $goodTop3 = pctV3((int)$good['top3'], (int)$good['n']);
    $badTop3 = pctV3((int)$bad['top3'], (int)$bad['n']);

    $firstGap = ($goodFirst !== null && $badFirst !== null) ? round($goodFirst - $badFirst, 2) : null;
    $top3Gap = ($goodTop3 !== null && $badTop3 !== null) ? round($goodTop3 - $badTop3, 2) : null;

    $out = [
        'valid_n' => (int)($raw['valid_n'] ?? 0),
        'good_n' => (int)$good['n'],
        'bad_n' => (int)$bad['n'],
        'good_first_rate' => $goodFirst,
        'bad_first_rate' => $badFirst,
        'first_gap' => $firstGap,
        'good_top3_rate' => $goodTop3,
        'bad_top3_rate' => $badTop3,
        'top3_gap' => $top3Gap,
    ];

    if ($overall !== null) {
        $out['vs_all'] = [
            'first_gap' => ($firstGap !== null && ($overall['first_gap'] ?? null) !== null)
                ? round($firstGap - (float)$overall['first_gap'], 2) : null,
            'top3_gap' => ($top3Gap !== null && ($overall['top3_gap'] ?? null) !== null)
                ? round($top3Gap - (float)$overall['top3_gap'], 2) : null,
        ];
    }

    return $out;
}

function avg6V3(array $rows, string $key): ?float
{
    if (count($rows) !== 6) return null;
    $values = [];
    foreach ($rows as $row) {
        $v = exNum($row[$key] ?? null);
        if ($v === null) return null;
        $values[] = $v;
    }
    return array_sum($values) / 6.0;
}

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 場6か月展示平均は場ごとに1回だけ取得する。
$avgByCode = [];
$stmtAvg = $pdo->prepare(<<<SQL
SELECT avg_exhibition_time_6m
FROM boat_race.exhibition_avg_6m
WHERE stadium_name = :stadium_name
  AND avg_exhibition_time_6m IS NOT NULL
LIMIT 1
SQL);

foreach ($nameByCode as $code => $stadiumName) {
    $stmtAvg->execute([':stadium_name' => $stadiumName]);
    $avg = exNum($stmtAvg->fetchColumn());
    if ($avg !== null && $avg > 0) {
        $avgByCode[$code] = $avg;
    }
}

// JOIN先の重複に影響されないよう、各選手について最大1行だけ取得する。
$sql = <<<SQL
SELECT
    re.race_code,
    re.race_date,
    re.lane_number,
    rrd.rank,
    el.exhibition_time,
    el.start_timing,
    el.lap_time,
    el.around_time,
    el.straight_time
FROM boat_race.race_entry re
JOIN LATERAL (
    SELECT
        x.exhibition_time,
        x.start_timing,
        x.lap_time,
        x.around_time,
        x.straight_time
    FROM boat_race.exhibition_live x
    WHERE x.race_code = re.race_code
      AND x.player_id = re.player_id
    ORDER BY x.created_date DESC NULLS LAST
    LIMIT 1
) el ON TRUE
LEFT JOIN LATERAL (
    SELECT x.rank
    FROM boat_race.race_result_detail x
    WHERE x.race_code = re.race_code
      AND x.player_id = re.player_id
    LIMIT 1
) rrd ON TRUE
WHERE re.race_date BETWEEN :from_date AND :to_date
ORDER BY re.race_code, re.lane_number
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute([':from_date' => $from, ':to_date' => $to]);

$stats = [];
$overallRaw = [];
ensurePlaceV3($overallRaw, 'ALL', $items);
$venueRaces = [];
$processed = 0;
$skipped = 0;
$skip = ['not_6_rows' => 0, 'invalid_lane_or_rank' => 0, 'duplicate_lane' => 0];
$currentCode = null;
$raceRows = [];

$processRace = static function (array $rows) use (
    &$stats, &$overallRaw, &$venueRaces, &$processed, &$skipped, &$skip, $items, $avgByCode
): void {
    if (count($rows) !== 6) {
        $skipped++;
        $skip['not_6_rows']++;
        return;
    }

    $raceCode = (string)$rows[0]['race_code'];
    $place = substr($raceCode, 8, 3);
    $lanes = [];
    $ranks = [];

    foreach ($rows as $row) {
        $lane = is_numeric($row['lane_number'] ?? null) ? (int)$row['lane_number'] : 0;
        $rank = exRank($row['rank'] ?? null);
        if ($lane < 1 || $lane > 6 || $rank === null) {
            $skipped++;
            $skip['invalid_lane_or_rank']++;
            return;
        }
        $lanes[] = $lane;
        $ranks[] = $rank;
    }

    if (count(array_unique($lanes)) !== 6) {
        $skipped++;
        $skip['duplicate_lane']++;
        return;
    }

    ensurePlaceV3($stats, $place, $items);
    $avgLap = avg6V3($rows, 'lap_time');
    $avgMawari = avg6V3($rows, 'around_time');
    $avgStraight = avg6V3($rows, 'straight_time');
    $allEx = avg6V3($rows, 'exhibition_time') !== null;
    $allSt = avg6V3($rows, 'start_timing') !== null;
    $venueAvg = $avgByCode[$place] ?? null;

    $processed++;
    $venueRaces[$place] = ($venueRaces[$place] ?? 0) + 1;

    foreach ($rows as $i => $row) {
        $scores = [];
        if ($allEx && $venueAvg !== null) {
            $scores['exhibition'] = exScore((float)$row['exhibition_time'] - $venueAvg);
        }
        if ($allSt) {
            $scores['st'] = stScore((float)$row['start_timing']);
        }
        if ($avgLap !== null) {
            $scores['lap'] = lapScore((float)$row['lap_time'] - $avgLap);
        }
        if ($avgMawari !== null) {
            $scores['mawari'] = mawariScore((float)$row['around_time'] - $avgMawari);
        }
        if ($avgStraight !== null) {
            $scores['straight'] = straightScore((float)$row['straight_time'] - $avgStraight);
        }

        foreach ($scores as $item => $score) {
            addScoreV3($stats, $place, $item, $score, $ranks[$i], $items);
            addScoreV3($overallRaw, 'ALL', $item, $score, $ranks[$i], $items);
        }
    }
};

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $code = (string)$row['race_code'];
    if ($currentCode !== null && $code !== $currentCode) {
        $processRace($raceRows);
        $raceRows = [];
    }
    $currentCode = $code;
    $raceRows[] = $row;
}
if ($raceRows !== []) {
    $processRace($raceRows);
}

$overallItems = [];
foreach ($items as $key => $name) {
    $overallItems[$key] = finalizeItemV3($overallRaw['ALL'][$key]);
    $overallItems[$key]['name'] = $name;
}

$stadiums = [];
foreach ($stats as $code => $rawItems) {
    $finalItems = [];
    foreach ($items as $key => $name) {
        $finalItems[$key] = finalizeItemV3($rawItems[$key] ?? emptyItemV3(), $overallItems[$key]);
        $finalItems[$key]['name'] = $name;
    }

    $ranking = array_keys($finalItems);
    usort($ranking, static function (string $a, string $b) use ($finalItems): int {
        $ga = $finalItems[$a]['top3_gap'];
        $gb = $finalItems[$b]['top3_gap'];
        if ($ga === null && $gb === null) return 0;
        if ($ga === null) return 1;
        if ($gb === null) return -1;
        return (float)$gb <=> (float)$ga;
    });

    $stadiums[$code] = [
        'name' => $nameByCode[$code] ?? $code,
        'races' => (int)($venueRaces[$code] ?? 0),
        'ranking' => $ranking,
        'items' => $finalItems,
    ];
}
ksort($stadiums, SORT_STRING);

$output = [
    'meta' => [
        'label' => '過去1年',
        'start_date' => $from,
        'end_date' => $to,
        'generated_at' => date(DATE_ATOM),
        'generated_from' => basename(__FILE__),
        'method' => '現行二次評価の良評価(4〜5点)と悪評価(1〜2点)の実着率差',
        'ranking_metric' => '3連対率差（良 - 悪）',
        'processed_races' => $processed,
        'skipped_races' => $skipped,
        'skip_reasons' => $skip,
        'stadium_count' => count($stadiums),
        'venue_avg_count' => count($avgByCode),
        'overall_items' => $overallItems,
        'note' => '展示タイムは場6か月平均との差。周回/周り足/直線はレース6艇平均との差。STは現行ST_BAND。表示専用。',
    ],
    'stadiums' => $stadiums,
];

$outputPath = __DIR__ . '/../config/stadium_exhibition_effectiveness.local.json';
$json = json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if (!is_string($json) || file_put_contents($outputPath, $json . PHP_EOL) === false) {
    throw new RuntimeException('JSON出力に失敗しました。');
}

echo str_repeat('=', 60) . PHP_EOL;
echo '場別 展示・ST効き方 JSON出力完了 V3' . PHP_EOL;
echo str_repeat('=', 60) . PHP_EOL;
echo "対象期間   : {$from} ～ {$to}" . PHP_EOL;
echo "処理レース : {$processed}R" . PHP_EOL;
echo "スキップ   : {$skipped}R" . PHP_EOL;
echo "  6行不足  : {$skip['not_6_rows']}R" . PHP_EOL;
echo "  着順等   : {$skip['invalid_lane_or_rank']}R" . PHP_EOL;
echo "  枠重複   : {$skip['duplicate_lane']}R" . PHP_EOL;
echo '場数       : ' . count($stadiums) . PHP_EOL;
echo '展示平均場 : ' . count($avgByCode) . PHP_EOL;
echo '基準       : 良評価4〜5点 vs 悪評価1〜2点' . PHP_EOL;
echo 'ST基準     : 現行ST_BAND（0.00〜0.12=5点）' . PHP_EOL;
echo '順位       : 3連対率差（良 - 悪）' . PHP_EOL;
echo "出力       : {$outputPath}" . PHP_EOL;
if (count($stadiums) < 24) {
    echo '注意       : 24場未満です。スキップ理由を確認してください。' . PHP_EOL;
}
