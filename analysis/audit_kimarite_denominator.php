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

function currentResultDetailN(PDO $pdo, string $playerId, int $course, int $months): int
{
    $sql = "
        SELECT COUNT(*)
        FROM boat_race.race_result_detail rrd
        JOIN boat_race.race_master rm
          ON rm.race_code = rrd.race_code
        WHERE rm.race_date >= CURRENT_DATE - INTERVAL '{$months} months'
          AND rrd.player_id::text = :player_id
          AND rrd.entry_course = {$course}
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':player_id' => $playerId]);
    return (int)$stmt->fetchColumn();
}

function resolvedCompletedStartN(PDO $pdo, string $playerId, int $course, int $months): int
{
    // race_entryを母集団にして、実進入はresult_detailを優先し、欠けていればexhibition_liveで補う。
    // どちらにも実進入がないレースは推測せず対象外にする。
    $sql = "
        SELECT COUNT(*)
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
          AND COALESCE(rd.entry_course, ex.entry_course) = {$course}
          AND EXISTS (
              SELECT 1
              FROM boat_race.race_result_detail winner
              WHERE winner.race_code = re.race_code
                AND TRIM(winner.rank) = '1'
          )
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':player_id' => $playerId]);
    return (int)$stmt->fetchColumn();
}

printf("%s\n", str_repeat('=', 112));
printf("決まり手 母数完全性監査: %s\n", $raceCode);
printf("%s\n", str_repeat('=', 112));
printf("現行N        : race_result_detailに存在する 選手×実コース 行数\n");
printf("実進入確認N  : race_entry母集団＋result_detail/exhibition_liveで実進入を確認できた完了R数\n");
printf("※実進入を確認できないレースは推測せず除外。\n\n");
printf("%-8s %-8s %-18s %8s %8s %8s %8s %8s %8s\n",
    'コース', '艇番', '選手', '1年現行', '1年確認', '差', '6m現行', '6m確認', '差');
printf("%s\n", str_repeat('-', 112));

$hasGap = false;
for ($course = 1; $course <= 6; $course++) {
    $boat = (int)($boatByCourse[$course] ?? 0);
    $pid = (string)($playerByBoat[$boat] ?? '');
    $name = $nameByBoat[$boat] ?? '';
    if ($name === '') $name = $pid;

    $cur12 = currentResultDetailN($pdo, $pid, $course, 12);
    $res12 = resolvedCompletedStartN($pdo, $pid, $course, 12);
    $cur6 = currentResultDetailN($pdo, $pid, $course, 6);
    $res6 = resolvedCompletedStartN($pdo, $pid, $course, 6);
    $diff12 = $res12 - $cur12;
    $diff6 = $res6 - $cur6;

    if ($diff12 !== 0 || $diff6 !== 0) {
        $hasGap = true;
    }

    printf(
        "%-8s %-8s %-18s %8d %8d %+8d %8d %8d %+8d\n",
        $course . 'コース',
        $boat . '号艇',
        $name,
        $cur12,
        $res12,
        $diff12,
        $cur6,
        $res6,
        $diff6
    );
}

printf("\n【判定】\n");
if ($hasGap) {
    printf("母数差あり。現行決まり手率は分母を見直す候補です。\n");
    printf("次は率そのものを変更する前に、race_entry母集団方式で過去検証します。\n");
} else {
    printf("今回6選手では母数差なし。現行Nと実進入確認Nは一致しています。\n");
}
printf("%s\n", str_repeat('=', 112));

exit(0);
