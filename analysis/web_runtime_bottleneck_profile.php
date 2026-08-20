<?php
declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/logic/BaseWinRateLogic.php';
require_once __DIR__ . '/../web/logic/CorrectedWinRateLogic.php';
require_once __DIR__ . '/../web/logic/RecentCourseTrioRateLogic.php';

$raceCode = strtoupper(trim((string)($argv[1] ?? '')));
if (!preg_match('/^(\d{8})([A-Z0-9]{3})(\d{2})$/', $raceCode)) {
    fwrite(STDERR, "Usage: php analysis/web_runtime_bottleneck_profile.php YYYYMMDDXXXRR\n");
    exit(1);
}

function timed(string $label, callable $fn): mixed
{
    $t0 = hrtime(true);
    try {
        $result = $fn();
        $ms = (hrtime(true) - $t0) / 1_000_000.0;
        printf("%-28s %10.1f ms  (%6.2f sec)\n", $label, $ms, $ms / 1000.0);
        return $result;
    } catch (Throwable $e) {
        $ms = (hrtime(true) - $t0) / 1_000_000.0;
        printf("%-28s %10.1f ms  ERROR: %s\n", $label, $ms, $e->getMessage());
        return null;
    }
}

$pdo = getPDO();

$courseByBoat = timed('展示進入マップ取得', function () use ($pdo, $raceCode): array {
    $sql = <<<SQL
        SELECT
            re.lane_number,
            COALESCE(
                CASE WHEN el.entry_course::text ~ '^[1-6]$' THEN el.entry_course::int ELSE NULL END,
                re.lane_number
            ) AS course
        FROM boat_race.race_entry re
        LEFT JOIN LATERAL (
            SELECT x.entry_course
            FROM boat_race.exhibition_live x
            WHERE x.race_code = re.race_code
              AND x.player_id = re.player_id
            LIMIT 1
        ) el ON TRUE
        WHERE re.race_code = ?
        ORDER BY re.lane_number
    SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$raceCode]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $row) {
        $boat = (int)($row['lane_number'] ?? 0);
        $course = (int)($row['course'] ?? 0);
        if ($boat >= 1 && $boat <= 6 && $course >= 1 && $course <= 6) {
            $map[$boat] = $course;
        }
    }
    ksort($map);
    return $map;
});

if (!is_array($courseByBoat) || count($courseByBoat) !== 6) {
    $courseByBoat = array_combine(range(1, 6), range(1, 6));
}

$baseLogic = new BaseWinRateLogic();
$correctedLogic = new CorrectedWinRateLogic();
$recentLogic = new RecentCourseTrioRateLogic();

echo str_repeat('=', 82) . PHP_EOL;
echo "Web表示 残存ボトルネック個別計測" . PHP_EOL;
echo str_repeat('=', 82) . PHP_EOL;
echo "race_code : {$raceCode}\n";
echo "進入      : " . implode('', array_map('strval', $courseByBoat)) . "（艇番->コース）\n\n";

$base = timed('BaseWinRateLogic', fn() => $baseLogic->calculate($raceCode, $courseByBoat));
$corrected = timed('CorrectedWinRateLogic', fn() => $correctedLogic->calculate($raceCode, null));
$recent = timed('RecentCourseTrioRateLogic', fn() => $recentLogic->calculate($raceCode, $courseByBoat));

if (is_array($base)) {
    echo 'Base status              : ' . (($base['status'] ?? 'ok')) . PHP_EOL;
}
if (is_array($corrected)) {
    echo 'Corrected status         : ' . (($corrected['status'] ?? 'unknown')) . PHP_EOL;
    if (!empty($corrected['error'])) {
        echo 'Corrected error          : ' . $corrected['error'] . PHP_EOL;
    }
}
if (is_array($recent)) {
    echo 'Recent status            : ' . (($recent['status'] ?? 'unknown')) . PHP_EOL;
    if (!empty($recent['error'])) {
        echo 'Recent error             : ' . $recent['error'] . PHP_EOL;
    }
}

echo str_repeat('-', 82) . PHP_EOL;
echo "目安: 10秒超の処理がWeb 71秒の主犯候補。特にCorrectedが大きければSUM履歴再構築をキャッシュ/集約化する。\n";
echo str_repeat('=', 82) . PHP_EOL;
