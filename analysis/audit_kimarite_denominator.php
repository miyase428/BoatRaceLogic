<?php

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/api/ApiClientProduction.php';

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php analysis/audit_kimarite_denominator.php RACE_CODE\n");
    exit(1);
}

$raceCode = strtoupper(trim($argv[1]));
if (!preg_match('/^\d{8}[A-Z]{3}\d{2}$/', $raceCode)) {
    fwrite(STDERR, "race_codeが不正です: {$raceCode}\n");
    exit(1);
}

$place = substr($raceCode, 8, 3);
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

$boatByCourse = [];
$courseByBoat = [];
foreach ($tenjiList as $idx => $t) {
    $boat = (int)($t['teiban'] ?? ($idx + 1));
    $course = (int)($t['tenji_course'] ?? 0);
    if ($boat >= 1 && $boat <= 6 && $course >= 1 && $course <= 6) {
        $courseByBoat[$boat] = $course;
        $boatByCourse[$course] = $boat;
    }
}

if (count($courseByBoat) !== 6 || count($boatByCourse) !== 6) {
    fwrite(STDERR, "展示進入1～6が揃っていないため監査できません。\n");
    if ($calcError !== '') fwrite(STDERR, "calc: {$calcError}\n");
    if ($tenjiError !== '') fwrite(STDERR, "tenji: {$tenjiError}\n");
    exit(1);
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

function denominatorBreakdown(PDO $pdo, string $playerId, int $course, int $months): array
{
    if ($course < 1 || $course > 6) {
        throw new RuntimeException('courseが不正です');
    }
    if (!in_array($months, [6, 12], true)) {
        throw new RuntimeException('monthsは6または12のみです');
    }

    // race_entryを1レース1選手の母集団にする。
    // result_detailの本人行があればその実進入を優先し、欠けた場合のみexhibition_liveで補う。
    // winner行の有無で完了/未完了も分離する。
    $sql = "
WITH base AS (
    SELECT
        re.race_code,
        rd.entry_course AS rd_course,
        ex.entry_course AS ex_course,
        EXISTS (
            SELECT 1
            FROM boat_race.race_result_detail w
            WHERE w.race_code = re.race_code
              AND TRIM(w.rank) = '1'
        ) AS completed
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
    WHERE rm.race_date >= CURRENT_DATE - INTERVAL '{$months} months'
      AND re.player_id::text = :player_id
)
SELECT
    COUNT(*) FILTER (WHERE rd_course = {$course}) AS current_all,
    COUNT(*) FILTER (WHERE completed AND rd_course = {$course}) AS current_completed,
    COUNT(*) FILTER (WHERE NOT completed AND rd_course = {$course}) AS current_incomplete,
    COUNT(*) FILTER (
        WHERE completed
          AND rd_course IS NULL
          AND ex_course = {$course}
    ) AS fallback_missing_result,
    COUNT(*) FILTER (
        WHERE completed
          AND COALESCE(rd_course, ex_course) = {$course}
    ) AS resolved_completed,
    COUNT(*) FILTER (
        WHERE completed
          AND rd_course IS NOT NULL
          AND ex_course IS NOT NULL
          AND rd_course <> ex_course
          AND (rd_course = {$course} OR ex_course = {$course})
    ) AS course_conflict,
    COUNT(*) FILTER (
        WHERE completed
          AND rd_course IS NULL
          AND ex_course IS NULL
    ) AS completed_course_unknown
FROM base
";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':player_id' => $playerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'current_all' => (int)($row['current_all'] ?? 0),
        'current_completed' => (int)($row['current_completed'] ?? 0),
        'current_incomplete' => (int)($row['current_incomplete'] ?? 0),
        'fallback_missing_result' => (int)($row['fallback_missing_result'] ?? 0),
        'resolved_completed' => (int)($row['resolved_completed'] ?? 0),
        'course_conflict' => (int)($row['course_conflict'] ?? 0),
        'completed_course_unknown' => (int)($row['completed_course_unknown'] ?? 0),
    ];
}

printf("%s\n", str_repeat('=', 126));
printf("決まり手 母数完全性監査: %s\n", $raceCode);
printf("%s\n", str_repeat('=', 126));
printf("現行N       : result_detail本人行の選手×実コース件数（未完了も含み得る）\n");
printf("完了RD      : 完了レースかつresult_detail本人行あり\n");
printf("欠損補完    : 完了レースで本人result_detail行なし、exhibition_liveで実進入確認\n");
printf("確認N       : 完了RD + 欠損補完（result_detail優先、欠損時のみ展示で補完）\n");
printf("未完了混入  : 現行Nのうちwinner行がないレース\n");
printf("進入競合    : result_detailとexhibition_liveの実進入が食い違うレース\n\n");

$hasGap = false;
$hasConflict = false;
$hasUnknown = false;

foreach ([12 => '1年', 6 => '6ヶ月'] as $months => $label) {
    printf("【%s】\n", $label);
    printf(
        "%-8s %-8s %-18s %7s %7s %8s %7s %8s %8s %8s %8s\n",
        'コース', '艇番', '選手', '現行N', '完了RD', '欠損補完', '確認N', '差', '未完了', '進入競合', '進入不明'
    );
    printf("%s\n", str_repeat('-', 126));

    for ($course = 1; $course <= 6; $course++) {
        $boat = (int)($boatByCourse[$course] ?? 0);
        $pid = (string)($playerByBoat[$boat] ?? '');
        $name = $nameByBoat[$boat] ?? '';
        if ($name === '') $name = $pid;

        $d = denominatorBreakdown($pdo, $pid, $course, $months);
        $diff = $d['resolved_completed'] - $d['current_all'];

        if ($diff !== 0) $hasGap = true;
        if ($d['course_conflict'] > 0) $hasConflict = true;
        if ($d['completed_course_unknown'] > 0) $hasUnknown = true;

        printf(
            "%-8s %-8s %-18s %7d %7d %8d %7d %+8d %8d %8d %8d\n",
            $course . 'コース',
            $boat . '号艇',
            $name,
            $d['current_all'],
            $d['current_completed'],
            $d['fallback_missing_result'],
            $d['resolved_completed'],
            $diff,
            $d['current_incomplete'],
            $d['course_conflict'],
            $d['completed_course_unknown']
        );
    }
    printf("\n");
}

printf("【判定】\n");
if ($hasGap) {
    printf("母数差あり。『欠損補完』と『未完了混入』を分離して原因を確認してください。\n");
} else {
    printf("今回6選手では現行Nと完了レース基準の確認Nが一致しています。\n");
}
if ($hasConflict) {
    printf("注意: result_detailとexhibition_liveで進入競合があります。競合レースの個別確認が必要です。\n");
}
if ($hasUnknown) {
    printf("注意: 完了レースでも実進入を確認できないケースがあります。推測せず除外する方針が安全です。\n");
}
printf("次段階: 原因確定後、race_entry母集団＋winner情報で決まり手率を再構築し、現行率と固定期間比較します。\n");
printf("%s\n", str_repeat('=', 126));

exit(0);
