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
printf("error=%s\n", (string)($result['error'] ?? ''));
printf("boats=%d raw_total=%.6f normalized_total=%.6f\n",
    count($result['boats'] ?? []),
    (float)($result['raw_total'] ?? 0),
    (float)($result['normalized_total'] ?? 0)
);

printf("\n【判定目安】\n");
if ($entryCount !== 6 || $entryDistinctLane !== 6) {
    printf("NG: race_entry 自体が6艇揃っていません。出走表保存側を確認。\n");
} elseif ($masterCount !== 1) {
    printf("NG: race_master が1行ではありません。JOINで6行を超える可能性があります。\n");
} elseif ($joinCount !== 6) {
    printf("NG: BaseWinRateLogic のJOIN結果が6行ではありません。\n");
} else {
    printf("OK: target取得条件は6艇です。次はBaseWinRateLogic内の別条件を確認。\n");
}
