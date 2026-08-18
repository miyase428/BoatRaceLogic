<?php

require_once __DIR__ . '/../web/api/ApiClientProduction.php';
require_once __DIR__ . '/../web/logic/PredictionLogicProduction.php';

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php analysis/debug_entry_change_web.php RACE_CODE\n");
    exit(1);
}

$raceCode = strtoupper(trim($argv[1]));
if (!preg_match('/^\d{8}[A-Z]{3}\d{2}$/', $raceCode)) {
    fwrite(STDERR, "race_codeが不正です: {$raceCode}\n");
    exit(1);
}

$place = substr($raceCode, 8, 3);
$api = new ApiClientProduction();
$logic = new PredictionLogicProduction();

[$entries, $firstResults, $calcError] = $api->fetchCalcScores($raceCode);
[$tenjiList, $tenjiError] = $api->fetchTenji($raceCode, $firstResults, $place);

$entryByBoat = [];
$nameByBoat = [];
$playerByBoat = [];
foreach ($entries as $e) {
    $boat = (int)($e['lane_number'] ?? 0);
    if ($boat < 1 || $boat > 6) {
        continue;
    }
    $nameByBoat[$boat] = (string)($e['player_name'] ?? '');
    $playerByBoat[$boat] = (string)($e['player_id'] ?? '');
}

$courseToBoat = [];
foreach ($tenjiList as $idx => $t) {
    $boat = (int)($t['teiban'] ?? ($idx + 1));
    $course = (int)($t['tenji_course'] ?? 0);
    if ($boat >= 1 && $boat <= 6 && $course >= 1 && $course <= 6) {
        $entryByBoat[$boat] = $course;
        $courseToBoat[$course] = $boat;
    }
}
ksort($entryByBoat);
ksort($courseToBoat);

$laneToCourse = '';
$courseToLane = '';
for ($i = 1; $i <= 6; $i++) {
    $laneToCourse .= (string)($entryByBoat[$i] ?? 0);
    $courseToLane .= (string)($courseToBoat[$i] ?? 0);
}

[$kimarite, $kimariteError] = $api->fetchKimarite($raceCode, $laneToCourse);
$tenjiTest = $api->fetchTenjiTest($raceCode, $tenjiList);
$final = $logic->buildFinalPredictions($tenjiList, $kimarite, $tenjiTest, $firstResults);

$testByCourse = [];
foreach ($tenjiTest as $row) {
    $course = (int)($row['wakuban'] ?? 0);
    if ($course >= 1 && $course <= 6) {
        $testByCourse[$course] = $row;
    }
}

printf("%s\n", str_repeat('=', 110));
printf("進入変更Web統合診断: %s\n", $raceCode);
printf("%s\n", str_repeat('=', 110));
printf("場                  : %s\n", $place);
printf("艇→展示C            : %s\n", $laneToCourse);
printf("展示C→艇            : %s\n", $courseToLane);
printf("進入変更             : %s\n", $laneToCourse === '123456' ? 'なし' : 'あり');
if ($calcError !== '') printf("calc error          : %s\n", $calcError);
if ($tenjiError !== '') printf("tenji error         : %s\n", $tenjiError);
if ($kimariteError !== '') printf("kimarite error      : %s\n", $kimariteError);

printf("\n【進入マップ】\n");
printf("艇  選手                 展示C\n");
printf("%s\n", str_repeat('-', 52));
for ($boat = 1; $boat <= 6; $boat++) {
    printf(
        "%d   %-20s %dC\n",
        $boat,
        $nameByBoat[$boat] ?? '-',
        $entryByBoat[$boat] ?? 0
    );
}

printf("\n【tenji_test 3連対率 mapping】\n");
printf("C   実艇  API player_id   期待player_id   判定      6m      3m\n");
printf("%s\n", str_repeat('-', 86));
$mappingOk = true;
for ($course = 1; $course <= 6; $course++) {
    $boat = $courseToBoat[$course] ?? 0;
    $row = $testByCourse[$course] ?? [];
    $actualPid = (string)($row['player_id'] ?? '');
    $expectedPid = (string)($playerByBoat[$boat] ?? '');
    $ok = $boat > 0 && $actualPid !== '' && $actualPid === $expectedPid;
    if (!$ok) $mappingOk = false;

    printf(
        "%d   %d号  %-13s %-13s %-6s %6.2f%% %6.2f%%\n",
        $course,
        $boat,
        $actualPid !== '' ? $actualPid : '-',
        $expectedPid !== '' ? $expectedPid : '-',
        $ok ? 'OK' : 'NG',
        (float)($row['three_in_rate_6m'] ?? 0) * 100,
        (float)($row['three_in_rate_3m'] ?? 0) * 100
    );
}

printf("\n【最終予想 艇→展示C対応】\n");
printf("艇  展示C   6m3連    3m3連    期待値      type        final3   flags\n");
printf("%s\n", str_repeat('-', 105));
$finalMapOk = true;
for ($boat = 1; $boat <= 6; $boat++) {
    $fp = $final[$boat] ?? [];
    $course = (int)($fp['course'] ?? 0);
    $expectedCourse = (int)($entryByBoat[$boat] ?? 0);
    if ($course !== $expectedCourse) $finalMapOk = false;

    $flags = array_filter([
        $fp['flg_sashi'] ?? '-',
        $fp['flg_makuri'] ?? '-',
        $fp['flg_makurizashi'] ?? '-',
        $fp['flg_nogashi'] ?? '-',
    ], fn($v) => $v !== '-');

    printf(
        "%d   %dC      %6.2f%%   %6.2f%%   %7.2f%%   %-10s %7.2f   %s\n",
        $boat,
        $course,
        (float)($fp['rate6_dec'] ?? 0) * 100,
        (float)($fp['rate3_dec'] ?? 0) * 100,
        (float)($fp['kitai_dec'] ?? 0) * 100,
        (string)($fp['type'] ?? '-'),
        (float)($fp['final3'] ?? 0),
        $flags ? implode(' / ', $flags) : '-'
    );
}

printf("\n【判定】\n");
printf("tenji_test player mapping : %s\n", $mappingOk ? 'OK' : 'NG');
printf("final boat/course mapping : %s\n", $finalMapOk ? 'OK' : 'NG');
printf("%s\n", str_repeat('=', 110));
