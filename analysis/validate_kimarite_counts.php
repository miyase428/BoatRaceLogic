<?php

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/api/ApiClientProduction.php';

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php analysis/validate_kimarite_counts.php RACE_CODE\n");
    exit(1);
}

$raceCode = strtoupper(trim($argv[1]));
if (!preg_match('/^\d{8}[A-Z]{3}\d{2}$/', $raceCode)) {
    fwrite(STDERR, "race_codeが不正です: {$raceCode}\n");
    exit(1);
}

$place = substr($raceCode, 8, 3);
$metricKeys = [
    'nige',
    'sashi',
    'makuri',
    'makurizashi',
    'nogashi',
    'sasare',
    'makurare',
    'makurarezashi',
];

/**
 * 本番APIとは別クエリで、採用済みrace_entry母集団方式を再計算する。
 */
function loadIndependentCounts(PDO $pdo, string $playerId, int $course, int $months): array
{
    if ($course < 1 || $course > 6) {
        throw new RuntimeException('courseが不正です');
    }
    if (!in_array($months, [6, 12], true)) {
        throw new RuntimeException('monthsは6または12のみです');
    }

    $sql = "
WITH base AS (
    SELECT
        re.race_code,
        COALESCE(rd.entry_course, ex.entry_course)::integer AS resolved_course,
        w.player_id::text AS winner_player_id,
        w.entry_course::integer AS winner_course,
        TRIM(COALESCE(w.technique, '')) AS winner_technique
    FROM boat_race.race_entry re
    JOIN boat_race.race_master rm
      ON rm.race_code = re.race_code

    LEFT JOIN LATERAL (
        SELECT rrd.entry_course
        FROM boat_race.race_result_detail rrd
        WHERE rrd.race_code = re.race_code
          AND rrd.player_id = re.player_id
          AND rrd.entry_course BETWEEN 1 AND 6
        LIMIT 1
    ) rd ON TRUE

    LEFT JOIN LATERAL (
        SELECT el.entry_course
        FROM boat_race.exhibition_live el
        WHERE el.race_code = re.race_code
          AND el.player_id = re.player_id
          AND el.entry_course BETWEEN 1 AND 6
        LIMIT 1
    ) ex ON TRUE

    JOIN LATERAL (
        SELECT rrd.player_id, rrd.entry_course, rrd.technique
        FROM boat_race.race_result_detail rrd
        WHERE rrd.race_code = re.race_code
          AND TRIM(rrd.rank) = '1'
        LIMIT 1
    ) w ON TRUE

    WHERE rm.race_date >= CURRENT_DATE - INTERVAL '{$months} months'
      AND re.player_id::text = :player_id
),
filtered AS (
    SELECT *
    FROM base
    WHERE resolved_course = {$course}
)
SELECT
    COUNT(*) AS sample_n,
    COUNT(*) FILTER (
        WHERE {$course} = 1
          AND winner_player_id = :player_id
    ) AS nige,
    COUNT(*) FILTER (
        WHERE {$course} <> 1
          AND winner_player_id = :player_id
          AND winner_technique = '差し'
    ) AS sashi,
    COUNT(*) FILTER (
        WHERE {$course} <> 1
          AND winner_player_id = :player_id
          AND winner_technique = 'まくり'
    ) AS makuri,
    COUNT(*) FILTER (
        WHERE {$course} <> 1
          AND winner_player_id = :player_id
          AND winner_technique = 'まくり差し'
    ) AS makurizashi,
    COUNT(*) FILTER (
        WHERE {$course} = 2
          AND winner_player_id <> :player_id
          AND winner_course = 1
    ) AS nogashi,
    COUNT(*) FILTER (
        WHERE {$course} = 1
          AND winner_player_id <> :player_id
          AND winner_technique = '差し'
    ) AS sasare,
    COUNT(*) FILTER (
        WHERE {$course} = 1
          AND winner_player_id <> :player_id
          AND winner_technique = 'まくり'
    ) AS makurare,
    COUNT(*) FILTER (
        WHERE {$course} = 1
          AND winner_player_id <> :player_id
          AND winner_technique = 'まくり差し'
    ) AS makurarezashi
FROM filtered
";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':player_id' => $playerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $n = (int)($row['sample_n'] ?? 0);
    $counts = [];
    $rates = [];

    foreach ([
        'nige', 'sashi', 'makuri', 'makurizashi',
        'nogashi', 'sasare', 'makurare', 'makurarezashi'
    ] as $key) {
        $cnt = (int)($row[$key] ?? 0);
        $counts[$key] = $cnt;
        $rates[$key] = $n > 0 ? round(100.0 * $cnt / $n, 1) : 0.0;
    }

    return [
        'n' => $n,
        'counts' => $counts,
        'rates' => $rates,
    ];
}

function periodCheck(array $apiPeriod, array $independent, array $metricKeys): array
{
    $ok = true;
    $details = [];

    $apiN = (int)($apiPeriod['_sample_n'] ?? -1);
    if ($apiN !== (int)$independent['n']) {
        $ok = false;
        $details[] = "N {$apiN}!={$independent['n']}";
    }

    foreach ($metricKeys as $key) {
        $apiCount = (int)($apiPeriod['_counts'][$key] ?? -1);
        $expectedCount = (int)($independent['counts'][$key] ?? 0);
        $apiRate = round((float)($apiPeriod[$key] ?? 0), 1);
        $expectedRate = round((float)($independent['rates'][$key] ?? 0), 1);

        if ($apiCount !== $expectedCount || abs($apiRate - $expectedRate) > 0.001) {
            $ok = false;
            $details[] = sprintf(
                '%s count %d!=%d / rate %.1f!=%.1f',
                $key,
                $apiCount,
                $expectedCount,
                $apiRate,
                $expectedRate
            );
        }
    }

    return [$ok, $details];
}

$api = new ApiClientProduction();
[$entries, $firstResults, $calcError] = $api->fetchCalcScores($raceCode);
[$tenjiList, $tenjiError] = $api->fetchTenji($raceCode, $firstResults, $place);

$nameByBoat = [];
foreach ($entries as $entry) {
    $boat = (int)($entry['lane_number'] ?? 0);
    if ($boat >= 1 && $boat <= 6) {
        $nameByBoat[$boat] = trim((string)($entry['player_name'] ?? ''));
    }
}

$courseByBoat = [];
$boatByCourse = [];
foreach ($tenjiList as $idx => $t) {
    $boat = (int)($t['teiban'] ?? ($idx + 1));
    $course = (int)($t['tenji_course'] ?? 0);
    if ($boat >= 1 && $boat <= 6 && $course >= 1 && $course <= 6) {
        $courseByBoat[$boat] = $course;
        $boatByCourse[$course] = $boat;
    }
}

if (count($courseByBoat) !== 6 || count($boatByCourse) !== 6) {
    fwrite(STDERR, "展示進入1～6が揃っていないため検証できません。\n");
    if ($calcError !== '') fwrite(STDERR, "calc: {$calcError}\n");
    if ($tenjiError !== '') fwrite(STDERR, "tenji: {$tenjiError}\n");
    exit(1);
}

ksort($courseByBoat);
ksort($boatByCourse);
$effectiveInCourse = '';
for ($boat = 1; $boat <= 6; $boat++) {
    $effectiveInCourse .= (string)$courseByBoat[$boat];
}

[$kimarite, $kimariteError] = $api->fetchKimarite($raceCode, $effectiveInCourse);

$pdo = getPDO();
$stmt = $pdo->prepare(<<<SQL
    SELECT lane_number, player_id::text
    FROM boat_race.race_entry
    WHERE race_code = :race_code
    ORDER BY lane_number
SQL);
$stmt->execute([':race_code' => $raceCode]);

$playerByBoat = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $boat = (int)($row['lane_number'] ?? 0);
    if ($boat >= 1 && $boat <= 6) {
        $playerByBoat[$boat] = trim((string)($row['player_id'] ?? ''));
    }
}

printf("%s\n", str_repeat('=', 118));
printf("決まり手 本番再構築版 率・回数・母数 検証: %s\n", $raceCode);
printf("%s\n", str_repeat('=', 118));
printf("場                  : %s\n", $place);
printf("艇→展示C            : %s\n", $effectiveInCourse);
printf("母集団               : race_entry / 完了レースのみ\n");
printf("実進入               : result_detail優先 / 欠損時exhibition_live\n");
printf("集計対象             : 全場 / 選手×展示進入コース\n");
printf("期間基準             : CURRENT_DATEから直近6ヶ月・1年\n");
if ($calcError !== '') printf("calc error          : %s\n", $calcError);
if ($tenjiError !== '') printf("tenji error         : %s\n", $tenjiError);
if ($kimariteError !== '') printf("kimarite error      : %s\n", $kimariteError);

printf("\n【API ↔ 独立SQL 一致確認】\n");
printf("%-8s %-8s %-18s %7s %7s %-8s %-8s\n", 'コース', '艇番', '選手', '1年N', '6ヶ月N', '1年', '6ヶ月');
printf("%s\n", str_repeat('-', 118));

$allOk = true;
$independentByCourse = [];

for ($course = 1; $course <= 6; $course++) {
    $boat = (int)($boatByCourse[$course] ?? 0);
    $pid = (string)($playerByBoat[$boat] ?? '');

    $oneYear = loadIndependentCounts($pdo, $pid, $course, 12);
    $sixMonth = loadIndependentCounts($pdo, $pid, $course, 6);
    $independentByCourse[$course] = ['1year' => $oneYear, '6month' => $sixMonth];

    [$ok1, $d1] = periodCheck($kimarite[$course]['1year'] ?? [], $oneYear, $metricKeys);
    [$ok6, $d6] = periodCheck($kimarite[$course]['6month'] ?? [], $sixMonth, $metricKeys);
    if (!$ok1 || !$ok6) {
        $allOk = false;
    }

    $displayName = $nameByBoat[$boat] ?? '';
    if ($displayName === '') {
        $displayName = $pid;
    }

    printf(
        "%-8s %-8s %-18s %7d %7d %-8s %-8s\n",
        $course . 'コース',
        $boat . '号艇',
        $displayName,
        $oneYear['n'],
        $sixMonth['n'],
        $ok1 ? 'OK' : 'NG',
        $ok6 ? 'OK' : 'NG'
    );

    if (!$ok1) printf("  1年詳細   : %s\n", implode(' / ', $d1));
    if (!$ok6) printf("  6ヶ月詳細 : %s\n", implode(' / ', $d6));
}

printf("\n【画面表示イメージ：直近1年】\n");
printf("コース     艇番     主な決まり手（発生回数/母数）\n");
printf("%s\n", str_repeat('-', 118));

for ($course = 1; $course <= 6; $course++) {
    $boat = (int)($boatByCourse[$course] ?? 0);
    $d = $independentByCourse[$course]['1year'];
    $n = (int)$d['n'];

    if ($course === 1) {
        $keys = ['nige' => '逃げ', 'sasare' => '差され', 'makurare' => 'まくられ', 'makurarezashi' => 'まくられ差'];
    } elseif ($course === 2) {
        $keys = ['nogashi' => '逃がし', 'sashi' => '差し', 'makuri' => 'まくり', 'makurizashi' => 'まくり差し'];
    } else {
        $keys = ['sashi' => '差し', 'makuri' => 'まくり', 'makurizashi' => 'まくり差し'];
    }

    $parts = [];
    foreach ($keys as $key => $label) {
        $parts[] = sprintf(
            '%s %.1f%%（%d/%d）',
            $label,
            (float)$d['rates'][$key],
            (int)$d['counts'][$key],
            $n
        );
    }

    printf("%-10s %-8s %s\n", $course . 'コース', $boat . '号艇', implode(' / ', $parts));
}

printf("\n【最終判定】\n");
printf("APIの率・回数・母数 : %s\n", $allOk ? 'ALL OK' : 'NGあり');
printf("表示形式             : xx.x%%（発生回数/母数）\n");
printf("%s\n", str_repeat('=', 118));

exit($allOk ? 0 : 2);
