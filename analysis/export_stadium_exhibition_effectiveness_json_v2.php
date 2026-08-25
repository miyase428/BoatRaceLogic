<?php

declare(strict_types=1);

/**
 * 場ごとの展示5項目が実着にどれだけ効いているかを画面表示用JSONへ出力する V2。
 *
 * V1で stadium_master / exhibition_avg_6m 等のJOINにより1レース6行を超えるケースが
 * 大量発生したため、V2では以下の方針に変更する。
 *
 * - 場名・場6か月展示平均はメインSQLへJOINしない
 * - 場6か月展示平均は24場ぶんだけ事前取得
 * - exhibition_live は LATERAL + LIMIT 1 で1選手1行に固定
 * - race_result_detail も LATERAL + LIMIT 1 で1選手1行に固定
 * - ST評価は現行 tenji_api.php の ST_BAND と同じ
 *
 * 良評価 = 4〜5点 / 悪評価 = 1〜2点 とし、
 * 良評価艇と悪評価艇の1着率・3連対率の差を場別に比較する。
 *
 * Usage:
 *   php analysis/export_stadium_exhibition_effectiveness_json_v2.php 2025-08-15 2026-08-14
 *
 * 出力:
 *   config/stadium_exhibition_effectiveness.local.json
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
    if (!is_array($row)) {
        continue;
    }
    $nameByCode[(string)$code] = trim((string)($row['name'] ?? $code));
}

$items = [
    'exhibition' => '展示タイム',
    'st' => '展示ST',
    'lap' => '周回',
    'mawari' => '周り足',
    'straight' => '直線',
];

function numOrNull(mixed $value): ?float
{
    return ($value !== null && $value !== '' && is_numeric($value))
        ? (float)$value
        : null;
}

function rankOrNull(mixed $value): ?int
{
    if ($value === null) {
        return null;
    }
    $s = trim((string)$value);
    return preg_match('/^[1-6]$/', $s) ? (int)$s : null;
}

function calcExhibitionScore(float $diff): int
{
    if ($diff <= -0.10) return 5;
    if ($diff <= -0.05) return 4;
    if ($diff <=  0.05) return 3;
    if ($diff <=  0.10) return 2;
    return 1;
}

/** 現行 tenji_api.php と同じ ST_BAND */
function calcStScore(float $st): int
{
    if ($st <= 0.00) return 3;
    if ($st <= 0.12) return 5;
    if ($st <= 0.20) return 3;
    if ($st <= 0.30) return 2;
    return 1;
}

function calcLapScore(float $diff): int
{
    if ($diff <= -0.30) return 5;
    if ($diff <= -0.10) return 4;
    if ($diff <=  0.10) return 3;
    if ($diff <=  0.30) return 2;
    return 1;
}

function calcMawariScore(float $diff): int
{
    if ($diff <= -0.20) return 5;
    if ($diff <= -0.05) return 4;
    if ($diff <=  0.05) return 3;
    if ($diff <=  0.20) return 2;
    return 1;
}

function calcStraightScore(float $diff): int
{
    if ($diff <= -0.04) return 5;
    if ($diff <= -0.01) return 4;
    if ($diff <=  0.01) return 3;
    if ($diff <=  0.04) return 2;
    return 1;
}

function emptyBucket(): array
{
    return ['n' => 0, 'first' => 0, 'top3' => 0];
}

function emptyItemStat(): array
{
    return [
        'valid_n' => 0,
        'good' => emptyBucket(),
        'bad' => emptyBucket(),
    ];
}

function ensurePlace(array &$stats, string $placeCode, array $items): void
{
    if (isset($stats[$placeCode])) {
        return;
    }
    $stats[$placeCode] = [];
    foreach ($items as $key => $_name) {
        $stats[$placeCode][$key] = emptyItemStat();
    }
}

function addScore(
    array &$stats,
    string $placeCode,
    string $item,
    int $score,
    int $rank,
    array $items
): void {
    ensurePlace($stats, $placeCode, $items);
    $stats[$placeCode][$item]['valid_n']++;

    $bucket = $score >= 4 ? 'good' : ($score <= 2 ? 'bad' : null);
    if ($bucket === null) {
        return;
    }

    $stats[$placeCode][$item][$bucket]['n']++;
    if ($rank === 1) {
        $stats[$placeCode][$item][$bucket]['first']++;
    }
    if ($rank >= 1 && $rank <= 3) {
        $stats[$placeCode][$item][$bucket]['top3']++;
    }
}

function rate(int $n, int $d): ?float
{
    return $d > 0 ? round(100.0 * $n / $d, 2) : null;
}

function finalizeItem(array $raw, ?array $overall = null): array
{
    $good = $raw['good'] ?? emptyBucket();
    $bad  = $raw['bad'] ?? emptyBucket();

    $goodFirst = rate((int)$good['first'], (int)$good['n']);
    $badFirst  = rate((int)$bad['first'], (int)$bad['n']);
    $goodTop3  = rate((int)$good['top3'], (int)$good['n']);
    $badTop3   = rate((int)$bad['top3'], (int)$bad['n']);

    $firstGap = ($goodFirst !== null && $badFirst !== null)
        ? round($goodFirst - $badFirst, 2)
        : null;
    $top3Gap = ($goodTop3 !== null && $badTop3 !== null)
        ? round($goodTop3 - $badTop3, 2)
        : null;

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
                ? round($firstGap - (float)$overall['first_gap'], 2)
                : null,
            'top3_gap' => ($top3Gap !== null && ($overall['top3_gap'] ?? null) !== null)
                ? round($top3Gap - (float)$overall['top3_gap'], 2)
                : null,
        ];
    }

    return $out;
}

/** 6艇すべて値があるときだけレース平均を返す */
function avgOfSix(array $rows, string $key): ?float
{
    $values = [];
    foreach ($rows as $row) {
        $v = numOrNull($row[$key] ?? null);
        if ($v === null) {
            return null;
        }
        $values[] = $v;
    }
    return count($values) === 6 ? array_sum($values) / 6.0 : null;
}

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ------------------------------------------------------------------
// 場6か月展示平均を24場ぶんだけ事前取得する。
// tenji_api.php と同じく、場名に対して最初の有効値を1つ取得する。
// ------------------------------------------------------------------
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
    $v = $stmtAvg->fetchColumn();
    $num = numOrNull($v);
    if ($num !== null && $num > 0) {
        $avgByCode[$code] = $num;
    }
}

// ------------------------------------------------------------------
// メインSQL。
// 各JOIN先をLATERAL + LIMIT 1で必ず1選手1行へ固定する。
// stadium_master / exhibition_avg_6m はJOINしない。
// ------------------------------------------------------------------
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
    ORDER BY x.updated_date DESC NULLS LAST,
             x.created_date DESC NULLS LAST
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
$stmt->execute([
    ':from_date' => $from,
    ':to_date' => $to,
]);

$stats = [];
$overallRaw = [];
ensurePlace($overallRaw, 'ALL', $items);

$venueRaces = [];
$processedRaces = 0;
$skippedRaces = 0;
$skipReasons = [
    'not_6_rows' => 0,
    'invalid_lane_or_rank' => 0,
    'duplicate_lane' => 0,
];
$currentCode = null;
$raceRows = [];

$processRace = static function (array $rows) use (
    &$stats,
    &$overallRaw,
    &$venueRaces,
    &$processedRaces,
    &$skippedRaces,
    &$skipReasons,
    $items,
    $avgByCode
): void {
    if (count($rows) !== 6) {
        $skippedRaces++;
        $skipReasons['not_6_rows']++;
        return;
    }

    $raceCode = (string)$rows[0]['race_code'];
    $placeCode = substr($raceCode, 8, 3);
    if ($placeCode === '') {
        $skippedRaces++;
        $skipReasons['invalid_lane_or_rank']++;
        return;
    }

    $lanes = [];
    $ranks = [];
    foreach ($rows as $row) {
        $lane = (isset($row['lane_number']) && is_numeric($row['lane_number']))
            ? (int)$row['lane_number']
            : 0;
        $rank = rankOrNull($row['rank'] ?? null);

        if ($lane < 1 || $lane > 6 || $rank === null) {
            $skippedRaces++;
            $skipReasons['invalid_lane_or_rank']++;
            return;
        }

        $lanes[] = $lane;
        $ranks[] = $rank;
    }

    if (count(array_unique($lanes)) !== 6) {
        $skippedRaces++;
        $skipReasons['duplicate_lane']++;
        return;
    }

    ensurePlace($stats, $placeCode, $items);

    $avgLap = avgOfSix($rows, 'lap_time');
    $avgMawari = avgOfSix($rows, 'around_time');
    $avgStraight = avgOfSix($rows, 'straight_time');
    $allExhibition = avgOfSix($rows, 'exhibition_time') !== null;
    $allSt = avgOfSix($rows, 'start_timing') !== null;
    $venueAvg = $avgByCode[$placeCode] ?? null;

    $processedRaces++;
    $venueRaces[$placeCode] = ($venueRaces[$placeCode] ?? 0) + 1;

    foreach ($rows as $i => $row) {
        $rank = $ranks[$i];
        $scores = [];

        if ($allExhibition && $venueAvg !== null && $venueAvg > 0) {
            $scores['exhibition'] = calcExhibitionScore(
                (float)$row['exhibition_time'] - $venueAvg
            );
        }
        if ($allSt) {
            $scores['st'] = calcStScore((float)$row['start_timing']);
        }
        if ($avgLap !== null) {
            $scores['lap'] = calcLapScore((float)$row['lap_time'] - $avgLap);
        }
        if ($avgMawari !== null) {
            $scores['mawari'] = calcMawariScore((float)$row['around_time'] - $avgMawari);
        }
        if ($avgStraight !== null) {
            $scores['straight'] = calcStraightScore(
                (float)$row['straight_time'] - $avgStraight
            );
        }

        foreach ($scores as $item => $score) {
            addScore($stats, $placeCode, $item, $score, $rank, $items);
            addScore($overallRaw, 'ALL', $item, $score, $rank, $items);
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
    $overallItems[$key] = finalizeItem($overallRaw['ALL'][$key]);
    $overallItems[$key]['name'] = $name;
}

$stadiums = [];
foreach ($stats as $code => $rawItems) {
    $finalItems = [];
    foreach ($items as $key => $name) {
        $finalItems[$key] = finalizeItem(
            $rawItems[$key] ?? emptyItemStat(),
            $overallItems[$key]
        );
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
        'processed_races' => $processedRaces,
        'skipped_races' => $skippedRaces,
        'skip_reasons' => $skipReasons,
        'stadium_count' => count($stadiums),
        'venue_avg_count' => count($avgByCode),
        'overall_items' => $overallItems,
        'note' => '展示タイムは場6か月平均との差。周回/周り足/直線はレース6艇平均との差。STは現行ST_BAND。表示専用。',
    ],
    'stadiums' => $stadiums,
];

$outputPath = __DIR__ . '/../config/stadium_exhibition_effectiveness.local.json';
$json = json_encode(
    $output,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);
if (!is_string($json) || file_put_contents($outputPath, $json . PHP_EOL) === false) {
    throw new RuntimeException('JSON出力に失敗しました。');
}

echo str_repeat('=', 60) . PHP_EOL;
echo '場別 展示・ST効き方 JSON出力完了 V2' . PHP_EOL;
echo str_repeat('=', 60) . PHP_EOL;
echo "対象期間   : {$from} ～ {$to}" . PHP_EOL;
echo "処理レース : {$processedRaces}R" . PHP_EOL;
echo "スキップ   : {$skippedRaces}R" . PHP_EOL;
echo "  6行不足  : {$skipReasons['not_6_rows']}R" . PHP_EOL;
echo "  着順等   : {$skipReasons['invalid_lane_or_rank']}R" . PHP_EOL;
echo "  枠重複   : {$skipReasons['duplicate_lane']}R" . PHP_EOL;
echo '場数       : ' . count($stadiums) . PHP_EOL;
echo '展示平均場 : ' . count($avgByCode) . PHP_EOL;
echo '基準       : 良評価4〜5点 vs 悪評価1〜2点' . PHP_EOL;
echo 'ST基準     : 現行ST_BAND（0.00〜0.12=5点）' . PHP_EOL;
echo '順位       : 3連対率差（良 - 悪）' . PHP_EOL;
echo "出力       : {$outputPath}" . PHP_EOL;

if (count($stadiums) < 24) {
    echo '注意       : 24場未満です。スキップ理由を確認してください。' . PHP_EOL;
}
