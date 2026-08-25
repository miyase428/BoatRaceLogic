<?php

declare(strict_types=1);

/**
 * 場別 展示・ST効き方 JSON exporter V4
 *
 * V3では race_entry.player_id = race_result_detail.player_id の紐付けが
 * 過去データで大量に欠け、着順取得で多数スキップした。
 *
 * V4では今回必要な判定を「1着か / 3連対か」に限定し、
 * race_result_detail からレース単位で実1〜3着コースを取得する。
 * 各展示艇は exhibition_live.entry_course と照合して first/top3 を判定する。
 * これにより player_id による結果紐付けへ依存しない。
 *
 * 展示評価基準:
 * - 展示タイム: 場6か月平均との差
 * - 展示ST: 現行 tenji_api.php の ST_BAND
 * - 周回/周り足/直線: レース6艇平均との差
 * - 良評価 = 4〜5点 / 悪評価 = 1〜2点
 *
 * Usage:
 *   php analysis/export_stadium_exhibition_effectiveness_json_v4.php 2025-08-15 2026-08-14
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

function v4Num(mixed $value): ?float
{
    return ($value !== null && $value !== '' && is_numeric($value))
        ? (float)$value
        : null;
}

function v4Course(mixed $value): ?int
{
    $s = trim((string)($value ?? ''));
    return preg_match('/^[1-6]$/', $s) ? (int)$s : null;
}

function v4ExScore(float $diff): int
{
    if ($diff <= -0.10) return 5;
    if ($diff <= -0.05) return 4;
    if ($diff <=  0.05) return 3;
    if ($diff <=  0.10) return 2;
    return 1;
}

/** 現行 tenji_api.php と同じ ST_BAND */
function v4StScore(float $st): int
{
    if ($st <= 0.00) return 3;
    if ($st <= 0.12) return 5;
    if ($st <= 0.20) return 3;
    if ($st <= 0.30) return 2;
    return 1;
}

function v4LapScore(float $diff): int
{
    if ($diff <= -0.30) return 5;
    if ($diff <= -0.10) return 4;
    if ($diff <=  0.10) return 3;
    if ($diff <=  0.30) return 2;
    return 1;
}

function v4MawariScore(float $diff): int
{
    if ($diff <= -0.20) return 5;
    if ($diff <= -0.05) return 4;
    if ($diff <=  0.05) return 3;
    if ($diff <=  0.20) return 2;
    return 1;
}

function v4StraightScore(float $diff): int
{
    if ($diff <= -0.04) return 5;
    if ($diff <= -0.01) return 4;
    if ($diff <=  0.01) return 3;
    if ($diff <=  0.04) return 2;
    return 1;
}

function v4EmptyBucket(): array
{
    return ['n' => 0, 'first' => 0, 'top3' => 0];
}

function v4EmptyItem(): array
{
    return ['valid_n' => 0, 'good' => v4EmptyBucket(), 'bad' => v4EmptyBucket()];
}

function v4EnsurePlace(array &$stats, string $place, array $items): void
{
    if (isset($stats[$place])) return;
    $stats[$place] = [];
    foreach ($items as $key => $_name) {
        $stats[$place][$key] = v4EmptyItem();
    }
}

function v4AddScore(
    array &$stats,
    string $place,
    string $item,
    int $score,
    bool $isFirst,
    bool $isTop3,
    array $items
): void {
    v4EnsurePlace($stats, $place, $items);
    $stats[$place][$item]['valid_n']++;

    $bucket = $score >= 4 ? 'good' : ($score <= 2 ? 'bad' : null);
    if ($bucket === null) return;

    $stats[$place][$item][$bucket]['n']++;
    if ($isFirst) $stats[$place][$item][$bucket]['first']++;
    if ($isTop3) $stats[$place][$item][$bucket]['top3']++;
}

function v4Pct(int $n, int $d): ?float
{
    return $d > 0 ? round($n / $d * 100.0, 2) : null;
}

function v4FinalizeItem(array $raw, ?array $overall = null): array
{
    $good = $raw['good'] ?? v4EmptyBucket();
    $bad  = $raw['bad'] ?? v4EmptyBucket();

    $goodFirst = v4Pct((int)$good['first'], (int)$good['n']);
    $badFirst  = v4Pct((int)$bad['first'], (int)$bad['n']);
    $goodTop3  = v4Pct((int)$good['top3'], (int)$good['n']);
    $badTop3   = v4Pct((int)$bad['top3'], (int)$bad['n']);

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

function v4Avg6(array $rows, string $key): ?float
{
    if (count($rows) !== 6) return null;
    $values = [];
    foreach ($rows as $row) {
        $v = v4Num($row[$key] ?? null);
        if ($v === null) return null;
        $values[] = $v;
    }
    return array_sum($values) / 6.0;
}

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 場6か月展示平均は24場ぶんだけ事前取得する。
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
    $avg = v4Num($stmtAvg->fetchColumn());
    if ($avg !== null && $avg > 0) {
        $avgByCode[$code] = $avg;
    }
}

/**
 * exhibition_live は race_entry.player_id で取得できており、診断上24場をカバーしている。
 * 結果側は player_id を使わず、レース単位で1〜3着コースを集約して各行へ付与する。
 */
$sql = <<<SQL
SELECT
    re.race_code,
    re.race_date,
    re.lane_number,
    el.entry_course,
    el.exhibition_time,
    el.start_timing,
    el.lap_time,
    el.around_time,
    el.straight_time,
    rr.c1,
    rr.c2,
    rr.c3,
    rr.rank1_n,
    rr.rank2_n,
    rr.rank3_n,
    rr.top3_course_n
FROM boat_race.race_entry re
JOIN LATERAL (
    SELECT
        x.entry_course,
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
    SELECT
        MAX(CASE
            WHEN TRIM(x.rank::text) = '1'
             AND x.entry_course::text ~ '^[1-6]$'
            THEN x.entry_course::int END
        ) AS c1,
        MAX(CASE
            WHEN TRIM(x.rank::text) = '2'
             AND x.entry_course::text ~ '^[1-6]$'
            THEN x.entry_course::int END
        ) AS c2,
        MAX(CASE
            WHEN TRIM(x.rank::text) = '3'
             AND x.entry_course::text ~ '^[1-6]$'
            THEN x.entry_course::int END
        ) AS c3,
        COUNT(*) FILTER (WHERE TRIM(x.rank::text) = '1') AS rank1_n,
        COUNT(*) FILTER (WHERE TRIM(x.rank::text) = '2') AS rank2_n,
        COUNT(*) FILTER (WHERE TRIM(x.rank::text) = '3') AS rank3_n,
        COUNT(DISTINCT CASE
            WHEN TRIM(x.rank::text) IN ('1','2','3')
             AND x.entry_course::text ~ '^[1-6]$'
            THEN x.entry_course::int END
        ) AS top3_course_n
    FROM boat_race.race_result_detail x
    WHERE x.race_code = re.race_code
) rr ON TRUE
WHERE re.race_date BETWEEN :from_date AND :to_date
ORDER BY re.race_code, re.lane_number
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute([':from_date' => $from, ':to_date' => $to]);

$stats = [];
$overallRaw = [];
v4EnsurePlace($overallRaw, 'ALL', $items);
$venueRaces = [];
$processed = 0;
$skipped = 0;
$skip = [
    'not_6_rows' => 0,
    'invalid_exhibition_course' => 0,
    'duplicate_exhibition_course' => 0,
    'invalid_result_top3' => 0,
];
$currentCode = null;
$raceRows = [];

$processRace = static function (array $rows) use (
    &$stats,
    &$overallRaw,
    &$venueRaces,
    &$processed,
    &$skipped,
    &$skip,
    $items,
    $avgByCode
): void {
    if (count($rows) !== 6) {
        $skipped++;
        $skip['not_6_rows']++;
        return;
    }

    $raceCode = (string)$rows[0]['race_code'];
    $place = substr($raceCode, 8, 3);

    $courses = [];
    foreach ($rows as $row) {
        $course = v4Course($row['entry_course'] ?? null);
        if ($course === null) {
            $skipped++;
            $skip['invalid_exhibition_course']++;
            return;
        }
        $courses[] = $course;
    }

    if (count(array_unique($courses)) !== 6) {
        $skipped++;
        $skip['duplicate_exhibition_course']++;
        return;
    }

    $c1 = v4Course($rows[0]['c1'] ?? null);
    $c2 = v4Course($rows[0]['c2'] ?? null);
    $c3 = v4Course($rows[0]['c3'] ?? null);
    $rank1n = (int)($rows[0]['rank1_n'] ?? 0);
    $rank2n = (int)($rows[0]['rank2_n'] ?? 0);
    $rank3n = (int)($rows[0]['rank3_n'] ?? 0);
    $top3CourseN = (int)($rows[0]['top3_course_n'] ?? 0);

    if ($c1 === null || $c2 === null || $c3 === null
        || $rank1n !== 1 || $rank2n !== 1 || $rank3n !== 1
        || $top3CourseN !== 3
        || count(array_unique([$c1, $c2, $c3])) !== 3) {
        $skipped++;
        $skip['invalid_result_top3']++;
        return;
    }

    v4EnsurePlace($stats, $place, $items);

    $avgLap = v4Avg6($rows, 'lap_time');
    $avgMawari = v4Avg6($rows, 'around_time');
    $avgStraight = v4Avg6($rows, 'straight_time');
    $allEx = v4Avg6($rows, 'exhibition_time') !== null;
    $allSt = v4Avg6($rows, 'start_timing') !== null;
    $venueAvg = $avgByCode[$place] ?? null;

    $processed++;
    $venueRaces[$place] = ($venueRaces[$place] ?? 0) + 1;
    $top3Courses = [$c1, $c2, $c3];

    foreach ($rows as $i => $row) {
        $course = $courses[$i];
        $isFirst = ($course === $c1);
        $isTop3 = in_array($course, $top3Courses, true);
        $scores = [];

        if ($allEx && $venueAvg !== null) {
            $scores['exhibition'] = v4ExScore((float)$row['exhibition_time'] - $venueAvg);
        }
        if ($allSt) {
            $scores['st'] = v4StScore((float)$row['start_timing']);
        }
        if ($avgLap !== null) {
            $scores['lap'] = v4LapScore((float)$row['lap_time'] - $avgLap);
        }
        if ($avgMawari !== null) {
            $scores['mawari'] = v4MawariScore((float)$row['around_time'] - $avgMawari);
        }
        if ($avgStraight !== null) {
            $scores['straight'] = v4StraightScore((float)$row['straight_time'] - $avgStraight);
        }

        foreach ($scores as $item => $score) {
            v4AddScore($stats, $place, $item, $score, $isFirst, $isTop3, $items);
            v4AddScore($overallRaw, 'ALL', $item, $score, $isFirst, $isTop3, $items);
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
    $overallItems[$key] = v4FinalizeItem($overallRaw['ALL'][$key]);
    $overallItems[$key]['name'] = $name;
}

$stadiums = [];
foreach ($stats as $code => $rawItems) {
    $finalItems = [];
    foreach ($items as $key => $name) {
        $finalItems[$key] = v4FinalizeItem(
            $rawItems[$key] ?? v4EmptyItem(),
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
        'method' => '良評価(4〜5点) vs 悪評価(1〜2点)。結果判定は展示進入コースと実1〜3着コースの照合。',
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
echo '場別 展示・ST効き方 JSON出力完了 V4' . PHP_EOL;
echo str_repeat('=', 60) . PHP_EOL;
echo "対象期間   : {$from} ～ {$to}" . PHP_EOL;
echo "処理レース : {$processed}R" . PHP_EOL;
echo "スキップ   : {$skipped}R" . PHP_EOL;
echo "  6行不足        : {$skip['not_6_rows']}R" . PHP_EOL;
echo "  展示進入不正    : {$skip['invalid_exhibition_course']}R" . PHP_EOL;
echo "  展示進入重複    : {$skip['duplicate_exhibition_course']}R" . PHP_EOL;
echo "  結果TOP3不備    : {$skip['invalid_result_top3']}R" . PHP_EOL;
echo '場数       : ' . count($stadiums) . PHP_EOL;
echo '展示平均場 : ' . count($avgByCode) . PHP_EOL;
echo '結果判定   : 展示進入コース × 実1〜3着コース' . PHP_EOL;
echo '基準       : 良評価4〜5点 vs 悪評価1〜2点' . PHP_EOL;
echo 'ST基準     : 現行ST_BAND（0.00〜0.12=5点）' . PHP_EOL;
echo '順位       : 3連対率差（良 - 悪）' . PHP_EOL;
echo "出力       : {$outputPath}" . PHP_EOL;

if (count($stadiums) < 24) {
    echo '注意       : 24場未満です。スキップ理由を確認してください。' . PHP_EOL;
}
