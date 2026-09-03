<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/logic/BaseWinRateLogic.php';

$raceCode = strtoupper(trim($argv[1] ?? ''));
if ($raceCode === '') {
    fwrite(STDERR, "Usage: php analysis/diagnose_base_winrate_target.php RACE_CODE\n");
    exit(1);
}

$pdo = getPDO();

function scalar(PDO $pdo, string $sql, array $params): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function rows(PDO $pdo, string $sql, array $params): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

printf("%s\n", str_repeat('=', 100));
printf("基本1着率 target 診断: %s\n", $raceCode);
printf("%s\n", str_repeat('=', 100));

$entryCount = scalar(
    $pdo,
    'SELECT COUNT(*) FROM boat_race.race_entry WHERE race_code = ?',
    [$raceCode]
);
$entryDistinctLane = scalar(
    $pdo,
    'SELECT COUNT(DISTINCT lane_number) FROM boat_race.race_entry WHERE race_code = ?',
    [$raceCode]
);
$masterCount = scalar(
    $pdo,
    'SELECT COUNT(*) FROM boat_race.race_master WHERE race_code = ?',
    [$raceCode]
);
$joinCount = scalar(
    $pdo,
    'SELECT COUNT(*) FROM boat_race.race_entry re JOIN boat_race.race_master rm ON rm.race_code = re.race_code WHERE re.race_code = ?',
    [$raceCode]
);
$resultCount = scalar(
    $pdo,
    'SELECT COUNT(*) FROM boat_race.race_result_detail WHERE race_code = ?',
    [$raceCode]
);
$exhibitionCount = scalar(
    $pdo,
    'SELECT COUNT(*) FROM boat_race.exhibition_live WHERE race_code = ?',
    [$raceCode]
);

printf("race_entry rows          : %d\n", $entryCount);
printf("race_entry distinct lane : %d\n", $entryDistinctLane);
printf("race_master rows         : %d\n", $masterCount);
printf("entry x master join rows : %d\n", $joinCount);
printf("race_result_detail rows  : %d\n", $resultCount);
printf("exhibition_live rows     : %d\n", $exhibitionCount);

printf("\n【race_entry】\n");
$entryRows = rows(
    $pdo,
    'SELECT lane_number, player_id::text AS player_id, player_name FROM boat_race.race_entry WHERE race_code = ? ORDER BY lane_number, player_id',
    [$raceCode]
);
foreach ($entryRows as $r) {
    printf(
        "lane=%s player_id=%s name=%s\n",
        (string)($r['lane_number'] ?? ''),
        (string)($r['player_id'] ?? ''),
        (string)($r['player_name'] ?? '')
    );
}

printf("\n【race_master】\n");
$masterRows = rows(
    $pdo,
    'SELECT race_code, race_date, stadium_name FROM boat_race.race_master WHERE race_code = ? ORDER BY race_date, stadium_name',
    [$raceCode]
);
foreach ($masterRows as $r) {
    printf(
        "race_code=%s race_date=%s stadium=%s\n",
        (string)($r['race_code'] ?? ''),
        (string)($r['race_date'] ?? ''),
        (string)($r['stadium_name'] ?? '')
    );
}

printf("\n【BaseWinRateLogic calculate】\n");
$logic = new BaseWinRateLogic();
$result = $logic->calculate($raceCode);
$error = trim((string)($result['error'] ?? ''));
$boatCount = count($result['boats'] ?? []);
$normalizedTotal = (float)($result['normalized_total'] ?? 0);
printf("error=%s\n", $error);
printf("boats=%d raw_total=%.6f normalized_total=%.6f\n",
    $boatCount,
    (float)($result['raw_total'] ?? 0),
    $normalizedTotal
);

$calcOk = $boatCount === 6
    && $error === ''
    && abs($normalizedTotal - 1.0) < 0.000001;

printf("\n【判定目安】\n");
if ($entryCount !== 6 || $entryDistinctLane !== 6) {
    printf("NG: race_entry 自体が6艇揃っていません。出走表保存側を確認。\n");
} elseif ($masterCount > 1) {
    printf("NG: race_master が重複しています。JOINで行数が増えるためrace_master側を確認。\n");
} elseif ($masterCount === 0) {
    if ($calcOk) {
        printf("OK: race_master は欠損していますが、race_entry + race_codeフォールバックで基本1着率を6艇分計算できています。\n");
    } else {
        printf("NG: race_master欠損フォールバックで基本1着率を計算できていません。上のerrorを確認。\n");
    }
} elseif ($joinCount !== 6) {
    printf("NG: race_master はありますが entry x master JOIN が6行ではありません。\n");
} elseif (!$calcOk) {
    printf("NG: target取得は6艇ですが、BaseWinRateLogicの後段で失敗しています。上のerrorを確認。\n");
} else {
    printf("OK: race_masterあり・6艇取得・基本1着率100%%正規化まで正常です。\n");
}
