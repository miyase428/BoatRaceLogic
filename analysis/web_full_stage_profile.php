<?php
declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/api/ApiClientProduction.php';
require_once __DIR__ . '/../web/logic/BaseWinRateLogic.php';
require_once __DIR__ . '/../web/logic/CorrectedWinRateLogic.php';
require_once __DIR__ . '/../web/logic/RecentCourseTrioRateLogic.php';
require_once __DIR__ . '/../web/logic/AiTrioRateLogic.php';
require_once __DIR__ . '/../web/logic/TrifectaProbabilityLogic.php';
require_once __DIR__ . '/../web/logic/Head1SecondPlaceLogic.php';

$raceCode = strtoupper(trim((string)($argv[1] ?? '')));
if (!preg_match('/^(\d{8})([A-Z0-9]{3})(\d{2})$/', $raceCode, $m)) {
    fwrite(STDERR, "Usage: php analysis/web_full_stage_profile.php YYYYMMDDXXXRR\n");
    exit(1);
}
$placeCode = $m[2];

function timed(string $label, callable $fn, array &$times): mixed
{
    $t0 = hrtime(true);
    try {
        $value = $fn();
        $ok = true;
        $err = '';
    } catch (Throwable $e) {
        $value = null;
        $ok = false;
        $err = $e->getMessage();
    }
    $ms = (hrtime(true) - $t0) / 1_000_000.0;
    $times[] = [$label, $ms, $ok, $err];
    printf("%-32s %10.1f ms  (%6.2f sec)%s\n", $label, $ms, $ms / 1000.0, $ok ? '' : '  ERROR');
    if (!$ok && $err !== '') {
        echo "  -> {$err}\n";
    }
    return $value;
}

function statusOf(mixed $data): string
{
    if (!is_array($data)) return '-';
    if (isset($data['status'])) return (string)$data['status'];
    if (isset($data['error']) && (string)$data['error'] !== '') return 'error';
    return 'ok';
}

$times = [];
$all0 = hrtime(true);

$api = new ApiClientProduction();
$baseLogic = new BaseWinRateLogic();
$correctedLogic = new CorrectedWinRateLogic();
$recentLogic = new RecentCourseTrioRateLogic();
$aiTrioLogic = new AiTrioRateLogic();
$trifectaLogic = new TrifectaProbabilityLogic();
$head1SecondLogic = new Head1SecondPlaceLogic();

echo str_repeat('=', 88) . PHP_EOL;
echo "Web表示 全主要処理 段階計測" . PHP_EOL;
echo str_repeat('=', 88) . PHP_EOL;
echo "race_code : {$raceCode}\n\n";

$calc = timed('1 fetchCalcScores', function () use ($api, $raceCode) {
    return $api->fetchCalcScores($raceCode);
}, $times);
$entries = is_array($calc) ? ($calc[0] ?? []) : [];
$results = is_array($calc) ? ($calc[1] ?? []) : [];

$base = timed('2 BaseWinRateLogic', function () use ($baseLogic, $raceCode) {
    return $baseLogic->calculate($raceCode);
}, $times);

$tenjiRet = timed('3 fetchTenji', function () use ($api, $raceCode, $results, $placeCode) {
    return $api->fetchTenji($raceCode, is_array($results) ? $results : [], $placeCode);
}, $times);
$tenji = is_array($tenjiRet) ? ($tenjiRet[0] ?? []) : [];

$courseByBoat = [];
if (is_array($tenji) && count($tenji) === 6) {
    foreach ($tenji as $idx => $row) {
        $boat = (int)($row['teiban'] ?? ($idx + 1));
        $course = (int)($row['tenji_course'] ?? 0);
        if ($boat >= 1 && $boat <= 6 && $course >= 1 && $course <= 6) {
            $courseByBoat[$boat] = $course;
        }
    }
}
if (count($courseByBoat) !== 6) {
    $courseByBoat = array_combine(range(1, 6), range(1, 6));
}
ksort($courseByBoat);
$effectiveInCourse = implode('', array_map('strval', $courseByBoat));

echo "進入      : {$effectiveInCourse}（艇番->コース）\n\n";

$kimarite = timed('4 fetchKimarite', function () use ($api, $raceCode, $effectiveInCourse) {
    return $api->fetchKimarite($raceCode, $effectiveInCourse);
}, $times);

$tenjiTest = timed('5 fetchTenjiTest', function () use ($api, $raceCode, $tenji) {
    return $api->fetchTenjiTest($raceCode, is_array($tenji) ? $tenji : []);
}, $times);

$sam = timed('6 fetchSamMaster', function () use ($api, $placeCode) {
    return $api->fetchSamMaster($placeCode);
}, $times);

$slit = timed('7 fetchSlit', function () use ($api, $raceCode) {
    return $api->fetchSlit($raceCode);
}, $times);

$corrected = timed('8 CorrectedWinRateLogic', function () use ($correctedLogic, $raceCode) {
    return $correctedLogic->calculate($raceCode);
}, $times);

$aiTrio = timed('9 AiTrioRateLogic', function () use ($aiTrioLogic, $raceCode, $results, $tenji, $courseByBoat) {
    return $aiTrioLogic->calculate(
        $raceCode,
        is_array($results) ? $results : [],
        is_array($tenji) ? $tenji : [],
        $courseByBoat,
        false
    );
}, $times);

$recent = timed('10 RecentCourseTrioRateLogic', function () use ($recentLogic, $raceCode, $courseByBoat) {
    return $recentLogic->calculate($raceCode, $courseByBoat);
}, $times);

$trifecta = timed('11 TrifectaProbabilityLogic', function () use ($trifectaLogic, $raceCode, $corrected, $aiTrio, $courseByBoat) {
    $correctedBoats = is_array($corrected) && is_array($corrected['boats'] ?? null) ? $corrected['boats'] : [];
    $aiBoats = is_array($aiTrio) && is_array($aiTrio['boats'] ?? null) ? $aiTrio['boats'] : [];
    return $trifectaLogic->calculate($raceCode, $correctedBoats, $aiBoats, $courseByBoat);
}, $times);

$head1Second = timed('12 Head1SecondPlaceLogic', function () use ($head1SecondLogic, $raceCode, $courseByBoat) {
    return $head1SecondLogic->calculate($raceCode, $courseByBoat);
}, $times);

$totalMs = (hrtime(true) - $all0) / 1_000_000.0;
$sumMs = array_sum(array_map(static fn(array $x): float => (float)$x[1], $times));

usort($times, static fn(array $a, array $b): int => $b[1] <=> $a[1]);

echo PHP_EOL . str_repeat('-', 88) . PHP_EOL;
echo "重い順\n";
foreach ($times as [$label, $ms, $ok, $err]) {
    printf("%-32s %8.2f sec  %5.1f%%\n", $label, $ms / 1000.0, $sumMs > 0 ? ($ms / $sumMs * 100.0) : 0.0);
}

echo str_repeat('-', 88) . PHP_EOL;
printf("段階計測合計 : %.2f sec\n", $sumMs / 1000.0);
printf("実測全体     : %.2f sec\n", $totalMs / 1000.0);
echo "Base status      : " . statusOf($base) . PHP_EOL;
echo "Corrected status : " . statusOf($corrected) . PHP_EOL;
echo "AI trio status   : " . statusOf($aiTrio) . PHP_EOL;
echo "Recent status    : " . statusOf($recent) . PHP_EOL;
echo "Trifecta status  : " . statusOf($trifecta) . PHP_EOL;
echo "Head1 status     : " . statusOf($head1Second) . PHP_EOL;
if (is_array($recent) && !empty($recent['error'])) {
    echo "Recent error     : " . (string)$recent['error'] . PHP_EOL;
}
if (is_array($aiTrio) && !empty($aiTrio['error'])) {
    echo "AI trio error    : " . (string)$aiTrio['error'] . PHP_EOL;
}
if (is_array($trifecta) && !empty($trifecta['error'])) {
    echo "Trifecta error   : " . (string)$trifecta['error'] . PHP_EOL;
}
if (is_array($head1Second) && !empty($head1Second['error'])) {
    echo "Head1 error      : " . (string)$head1Second['error'] . PHP_EOL;
}
echo str_repeat('=', 88) . PHP_EOL;
