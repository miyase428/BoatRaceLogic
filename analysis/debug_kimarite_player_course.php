<?php

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/api/ApiClientProduction.php';

if ($argc < 2 || $argc > 3) {
    fwrite(STDERR, "Usage: php analysis/debug_kimarite_player_course.php RACE_CODE [COMPARE_COURSE]\n");
    exit(1);
}

$raceCode = strtoupper(trim($argv[1]));
if (!preg_match('/^\d{8}[A-Z]{3}\d{2}$/', $raceCode)) {
    fwrite(STDERR, "race_codeが不正です: {$raceCode}\n");
    exit(1);
}

$compareCourse = isset($argv[2]) ? (int)$argv[2] : 0;
if ($compareCourse !== 0 && ($compareCourse < 1 || $compareCourse > 6)) {
    fwrite(STDERR, "COMPARE_COURSEは1～6で指定してください。\n");
    exit(1);
}

$place = substr($raceCode, 8, 3);
$metrics = [
    'nige' => '逃げ',
    'sashi' => '差し',
    'makuri' => 'まくり',
    'makurizashi' => 'まくり差し',
    'nogashi' => '逃がし',
    'sasare' => '差され',
    'makurare' => 'まくられ',
    'makurarezashi' => 'まくられ差',
];

function loadKimariteRates(PDO $pdo, ?string $playerId, int $course, int $months, ?string $place): array
{
    if (!in_array($months, [6, 12], true)) {
        throw new RuntimeException('monthsは6または12のみ対応です');
    }

    $where = [
        "rm.race_date >= CURRENT_DATE - INTERVAL '{$months} months'",
        'rrd.entry_course = :course',
    ];
    $params = [':course' => $course];

    if ($playerId !== null) {
        $where[] = 'rrd.player_id::text = :player_id';
        $params[':player_id'] = $playerId;
    }

    if ($place !== null) {
        $where[] = 'SUBSTRING(rrd.race_code FROM 9 FOR 3) = :place';
        $params[':place'] = $place;
    }

    $sql = "
WITH past AS (
    SELECT
        TRIM(rrd.rank) AS rank,
        rrd.technique,
        (
            SELECT r1.entry_course
            FROM boat_race.race_result_detail r1
            WHERE r1.race_code = rrd.race_code
              AND TRIM(r1.rank) = '1'
            LIMIT 1
        ) AS winner_course
    FROM boat_race.race_result_detail rrd
    JOIN boat_race.race_master rm
      ON rm.race_code = rrd.race_code
    WHERE " . implode("\n      AND ", $where) . "
),
typed AS (
    SELECT
        CASE
            WHEN :course_case = 1 AND rank = '1' THEN 'nige'
            WHEN :course_case = 1 AND rank != '1' AND technique = '差し' THEN 'sasare'
            WHEN :course_case = 1 AND rank != '1' AND technique = 'まくり' THEN 'makurare'
            WHEN :course_case = 1 AND rank != '1' AND technique = 'まくり差し' THEN 'makurarezashi'
            WHEN :course_case = 2 AND rank != '1' AND winner_course = 1 THEN 'nogashi'
            WHEN rank = '1' AND technique = '差し' THEN 'sashi'
            WHEN rank = '1' AND technique = 'まくり' THEN 'makuri'
            WHEN rank = '1' AND technique = 'まくり差し' THEN 'makurizashi'
            ELSE NULL
        END AS technique_type
    FROM past
)
SELECT
    COUNT(*) AS total_n,
    COUNT(*) FILTER (WHERE technique_type = 'nige') AS nige,
    COUNT(*) FILTER (WHERE technique_type = 'sashi') AS sashi,
    COUNT(*) FILTER (WHERE technique_type = 'makuri') AS makuri,
    COUNT(*) FILTER (WHERE technique_type = 'makurizashi') AS makurizashi,
    COUNT(*) FILTER (WHERE technique_type = 'nogashi') AS nogashi,
    COUNT(*) FILTER (WHERE technique_type = 'sasare') AS sasare,
    COUNT(*) FILTER (WHERE technique_type = 'makurare') AS makurare,
    COUNT(*) FILTER (WHERE technique_type = 'makurarezashi') AS makurarezashi
FROM typed
";

    $params[':course_case'] = $course;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $n = (int)($row['total_n'] ?? 0);
    $result = ['n' => $n];
    foreach (['nige', 'sashi', 'makuri', 'makurizashi', 'nogashi', 'sasare', 'makurare', 'makurarezashi'] as $key) {
        $cnt = (int)($row[$key] ?? 0);
        $result[$key] = $n > 0 ? round(100.0 * $cnt / $n, 1) : 0.0;
    }

    return $result;
}

function maxMetricDiff(array $a, array $b): float
{
    $max = 0.0;
    foreach (['nige', 'sashi', 'makuri', 'makurizashi', 'nogashi', 'sasare', 'makurare', 'makurarezashi'] as $key) {
        $max = max($max, abs((float)($a[$key] ?? 0) - (float)($b[$key] ?? 0)));
    }
    return $max;
}

function compactRates(array $r, int $course): string
{
    if ($course === 1) {
        return sprintf(
            '逃げ %.1f / 差され %.1f / まくられ %.1f / まくられ差 %.1f',
            $r['nige'], $r['sasare'], $r['makurare'], $r['makurarezashi']
        );
    }

    if ($course === 2) {
        return sprintf(
            '差し %.1f / まくり %.1f / まくり差し %.1f / 逃がし %.1f',
            $r['sashi'], $r['makuri'], $r['makurizashi'], $r['nogashi']
        );
    }

    return sprintf(
        '差し %.1f / まくり %.1f / まくり差し %.1f',
        $r['sashi'], $r['makuri'], $r['makurizashi']
    );
}

$api = new ApiClientProduction();
[$entries, $firstResults, $calcError] = $api->fetchCalcScores($raceCode);
[$tenjiList, $tenjiError] = $api->fetchTenji($raceCode, $firstResults, $place);

$nameByBoat = [];
foreach ($entries as $e) {
    $boat = (int)($e['lane_number'] ?? 0);
    if ($boat >= 1 && $boat <= 6) {
        $nameByBoat[$boat] = (string)($e['player_name'] ?? '-');
    }
}

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
    fwrite(STDERR, "展示進入1～6が揃っていないため診断できません。\n");
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

[$apiKimarite, $kimariteError] = $api->fetchKimarite($raceCode, $effectiveInCourse);

if ($compareCourse === 0) {
    for ($course = 1; $course <= 6; $course++) {
        if (($boatByCourse[$course] ?? $course) !== $course) {
            $compareCourse = $course;
            break;
        }
    }
    if ($compareCourse === 0) {
        $compareCourse = 4;
    }
}

printf("%s\n", str_repeat('=', 122));
printf("決まり手：選手×展示進入コース診断  %s\n", $raceCode);
printf("%s\n", str_repeat('=', 122));
printf("場                  : %s\n", $place);
printf("艇→展示C            : %s\n", $effectiveInCourse);
printf("比較コース           : %dコース\n", $compareCourse);
if ($calcError !== '') printf("calc error          : %s\n", $calcError);
if ($tenjiError !== '') printf("tenji error         : %s\n", $tenjiError);
if ($kimariteError !== '') printf("kimarite error      : %s\n", $kimariteError);

printf("\n【現行APIの意味確認】\n");
printf("現行SQL : 今回のplayer_id × 今回の展示進入コースで過去走を抽出\n");
printf("期間基準: CURRENT_DATE基準（過去レース画面でも対象レース日基準ではない）\n");
printf("%-4s %-8s %-18s %-7s %-7s %-9s %-10s\n", '艇', '展示C', '選手', '6m N', '1y N', 'API一致6m', 'API一致1y');
printf("%s\n", str_repeat('-', 122));

$allMatch = true;
for ($course = 1; $course <= 6; $course++) {
    $boat = $boatByCourse[$course];
    $pid = $playerByBoat[$boat] ?? '';
    $r6 = loadKimariteRates($pdo, $pid, $course, 6, null);
    $r12 = loadKimariteRates($pdo, $pid, $course, 12, null);
    $api6 = $apiKimarite[$course]['6month'] ?? [];
    $api12 = $apiKimarite[$course]['1year'] ?? [];
    $ok6 = maxMetricDiff($r6, $api6) < 0.051;
    $ok12 = maxMetricDiff($r12, $api12) < 0.051;
    if (!$ok6 || !$ok12) $allMatch = false;

    printf(
        "%d号   %dコース   %-18s %7d %7d %-9s %-10s\n",
        $boat,
        $course,
        $nameByBoat[$boat] ?? '-',
        $r6['n'],
        $r12['n'],
        $ok6 ? 'OK' : 'NG',
        $ok12 ? 'OK' : 'NG'
    );
}

printf("\n現行API = 選手×展示進入コース : %s\n", $allMatch ? '確認OK' : '要確認');

printf("\n【今回の各コース：現行 vs 場要素】\n");
printf("同じ選手・同じコースでも、場まで限定すると母数がどれだけ減るかを見る。\n");
printf("%-7s %-8s %-18s %-9s %-9s %-9s\n", 'コース', '艇', '選手', '選手×C N', '選手×場×C', '場×C N');
printf("%s\n", str_repeat('-', 122));
for ($course = 1; $course <= 6; $course++) {
    $boat = $boatByCourse[$course];
    $pid = $playerByBoat[$boat] ?? '';
    $pc = loadKimariteRates($pdo, $pid, $course, 12, null);
    $pvc = loadKimariteRates($pdo, $pid, $course, 12, $place);
    $vc = loadKimariteRates($pdo, null, $course, 12, $place);

    printf(
        "%dコース   %d号     %-18s %9d %9d %9d\n",
        $course,
        $boat,
        $nameByBoat[$boat] ?? '-',
        $pc['n'],
        $pvc['n'],
        $vc['n']
    );
}

printf("\n【同じ%dコースに入る選手が変わると決まり手率は変わるか】\n", $compareCourse);
printf("今回出走6選手を全員、仮に%dコースへ置いた場合の『選手×コース』実績。\n", $compareCourse);
printf("%-5s %-18s %-7s %-70s\n", '艇', '選手', '1y N', '1年 決まり手率(%%)');
printf("%s\n", str_repeat('-', 122));
for ($boat = 1; $boat <= 6; $boat++) {
    $pid = $playerByBoat[$boat] ?? '';
    $r = loadKimariteRates($pdo, $pid, $compareCourse, 12, null);
    $mark = (($boatByCourse[$compareCourse] ?? 0) === $boat) ? ' ←今回' : '';
    printf(
        "%d号   %-18s %7d %-70s%s\n",
        $boat,
        $nameByBoat[$boat] ?? '-',
        $r['n'],
        compactRates($r, $compareCourse),
        $mark
    );
}

$assignedBoat = $boatByCourse[$compareCourse] ?? 0;
if ($assignedBoat > 0) {
    $pid = $playerByBoat[$assignedBoat] ?? '';
    $pc6 = loadKimariteRates($pdo, $pid, $compareCourse, 6, null);
    $pvc6 = loadKimariteRates($pdo, $pid, $compareCourse, 6, $place);
    $vc6 = loadKimariteRates($pdo, null, $compareCourse, 6, $place);

    printf("\n【%dコース・今回%d号艇の6ヶ月比較】\n", $compareCourse, $assignedBoat);
    printf("選手×コース      N=%-5d %s\n", $pc6['n'], compactRates($pc6, $compareCourse));
    printf("選手×場×コース  N=%-5d %s\n", $pvc6['n'], compactRates($pvc6, $compareCourse));
    printf("場×コース        N=%-5d %s\n", $vc6['n'], compactRates($vc6, $compareCourse));
}

printf("\n【次の判断ポイント】\n");
printf("1. 現行API一致が全艇OKなら、『選手×展示進入コース』への対応自体は既にできている。\n");
printf("2. 選手×場×コースは母数Nを見て、そのまま使わず平滑化が必要か判断する。\n");
printf("3. 改修する場合は、場×コースを母集団 → 選手×コース → 選手×場×コースの順で補正候補を検証する。\n");
printf("4. パラメータはこの単発結果で決めず、固定2期間バックテストで選ぶ。\n");
printf("%s\n", str_repeat('=', 122));
