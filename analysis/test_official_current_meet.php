<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/OfficialCurrentMeetLogic.php';

$raceCode = strtoupper(trim((string)($argv[1] ?? '')));
if ($raceCode === '') {
    fwrite(STDERR, "Usage: php analysis/test_official_current_meet.php RACE_CODE\n");
    exit(2);
}

$force = in_array('--force', $argv, true);
$data = (new OfficialCurrentMeetLogic())->load($raceCode, $force);

echo str_repeat('=', 110) . PHP_EOL;
echo "BOAT RACE公式 今節成績 抽出テスト" . PHP_EOL;
echo str_repeat('=', 110) . PHP_EOL;
echo 'race_code : ' . ($data['race_code'] ?? $raceCode) . PHP_EOL;
echo 'status    : ' . ($data['status'] ?? 'error') . PHP_EOL;
echo 'source    : ' . ($data['source'] ?? '-') . PHP_EOL;
echo 'cache     : ' . (!empty($data['cache']['used']) ? 'used' : 'fresh') . PHP_EOL;
if (!empty($data['error'])) {
    echo 'error     : ' . $data['error'] . PHP_EOL;
}
echo PHP_EOL;

$boats = is_array($data['boats'] ?? null) ? $data['boats'] : [];
foreach ($boats as $boat => $row) {
    $avgSt = is_numeric($row['average_st'] ?? null)
        ? number_format((float)$row['average_st'], 3)
        : '-';
    $courses = implode('-', array_map(static fn($v): string => $v === null ? '-' : (string)$v, $row['course_history'] ?? []));
    $sts = implode(' ', array_map('strval', $row['st_history'] ?? []));
    $finishes = implode('-', array_map('strval', $row['finish_history'] ?? []));

    echo sprintf(
        "%d号艇 player=%s N=%d avgST=%s\n  進入: %s\n  ST  : %s\n  着順: %s\n",
        (int)$boat,
        (string)($row['player_id'] ?? '-'),
        (int)($row['run_count'] ?? 0),
        $avgSt,
        $courses !== '' ? $courses : '-',
        $sts !== '' ? $sts : '-',
        $finishes !== '' ? $finishes : '-'
    );
}
