<?php

declare(strict_types=1);

/**
 * 23時に履歴補完された展示データから、昼間の厳密保存がないレースを再現する。
 *
 * 結果を採点へ渡すのは予想保存がすべて終わった後だけ。予想計算中は
 * BOATRACE_LATE_REPLAY=1 により対象レース以降を遮断した経路を使用する。
 *
 * Usage:
 *   php analysis/replay_missing_predictions.php YYYY-MM-DD
 *   php analysis/replay_missing_predictions.php YYYY-MM-DD --dry-run
 */

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/controllers/IndexController.php';
require_once __DIR__ . '/../web/logic/Lane1EscapeFollowerLogic.php';
require_once __DIR__ . '/../web/logic/PredictionForwardSnapshotStore.php';

date_default_timezone_set('Asia/Tokyo');

$defaultDate = (int)date('G') < 6 ? date('Y-m-d', strtotime('yesterday')) : date('Y-m-d');
$dateText = trim((string)($argv[1] ?? $defaultDate));
$dryRun = in_array('--dry-run', $argv, true);
$date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateText);
if ($date === false || $date->format('Y-m-d') !== $dateText) {
    fwrite(STDERR, "Usage: php analysis/replay_missing_predictions.php YYYY-MM-DD [--dry-run]\n");
    exit(2);
}

$lockPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/boatrace_late_replay_' . $date->format('Ymd') . '.lock';
$lock = @fopen($lockPath, 'c');
if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo '[' . date('c') . "] SKIP: 夜間再現は実行中です\n";
    exit(0);
}

putenv('BOATRACE_LATE_REPLAY=1');

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$store = new PredictionForwardSnapshotStore($pdo);
if (!$store->isReady()) {
    fwrite(STDERR, "自動前向き検証テーブルが未作成です。\n");
    exit(1);
}

$raceCodes = readyReplayRaceCodes($pdo, $date->format('Ymd'));
$saved = savedReplayModes($pdo, $dateText);
$predictionTargets = array_values(array_filter(
    $raceCodes,
    static fn(string $code): bool => !isset($saved['prediction']['strict'][$code])
        && !isset($saved['prediction']['late_replay'][$code])
));

printf(
    "[%s] 夜間再現開始: 展示完備=%dR / 本命対抗対象=%dR%s\n",
    date('c'),
    count($raceCodes),
    count($predictionTargets),
    $dryRun ? ' / DRY-RUN' : ''
);

if ($dryRun) {
    foreach ($predictionTargets as $raceCode) {
        echo "  対象 {$raceCode}\n";
    }
    exit(0);
}

$predictionOk = 0;
$predictionFailed = 0;
foreach ($predictionTargets as $index => $raceCode) {
    try {
        $_GET = [
            'date' => $dateText,
            'place' => substr($raceCode, 8, 3),
            'race' => (string)(int)substr($raceCode, 11, 2),
        ];
        $_POST = [];

        $view = (new IndexController())->handle();
        $view = (new Lane1EscapeFollowerLogic())->apply(
            $view,
            (array)($view['final_predictions'] ?? []),
            (string)($view['place_names'][$view['selected_place'] ?? ''] ?? ''),
            (array)($view['entry_course_by_boat'] ?? []),
            !empty($view['entry_map_ready']) && empty($view['simulation_active'])
        );
        PredictionForwardSnapshotStore::captureLateReplayPrediction($view, $pdo);

        if (hasReplaySnapshot($pdo, $raceCode, 'prediction')) {
            $predictionOk++;
        } else {
            $predictionFailed++;
            fwrite(STDERR, "  本命対抗を再現できませんでした: {$raceCode}\n");
        }
    } catch (Throwable $e) {
        $predictionFailed++;
        fwrite(STDERR, "  本命対抗エラー {$raceCode}: {$e->getMessage()}\n");
    }
    printf("  [%d/%d] %s\n", $index + 1, count($predictionTargets), $raceCode);
}

$signalPlaces = [];
foreach ($raceCodes as $raceCode) {
    if (!isset($saved['course_signals']['strict'][$raceCode])
        && !isset($saved['course_signals']['late_replay'][$raceCode])) {
        $signalPlaces[substr($raceCode, 8, 3)] = true;
    }
}
$signalOk = 0;
foreach (array_keys($signalPlaces) as $place) {
    if (captureReplayCourseSignals($dateText, $place)) {
        $signalOk++;
    }
}

$holeTargets = array_values(array_filter(
    $raceCodes,
    static fn(string $code): bool => !isset($saved['hole_prediction']['strict'][$code])
        && !isset($saved['hole_prediction']['late_replay'][$code])
));
$holeOk = runReplayHolePredictions($dateText, $holeTargets);

// 予想経路を閉じてから初めて実結果を読む採点処理へ進む。
putenv('BOATRACE_LATE_REPLAY');
$gradeOk = runReplayGrader($dateText);

printf(
    "[%s] 夜間再現完了: 本命対抗=%d / 失敗=%d / サイン=%d場 / 大穴=%s / 採点=%s\n",
    date('c'),
    $predictionOk,
    $predictionFailed,
    $signalOk,
    $holeOk ? '完了' : '要確認',
    $gradeOk ? '完了' : '要確認'
);

/** @return list<string> */
function readyReplayRaceCodes(PDO $pdo, string $ymd): array
{
    $stmt = $pdo->prepare(<<<'SQL'
SELECT el.race_code
FROM boat_race.exhibition_live el
JOIN boat_race.race_entry re
  ON re.race_code = el.race_code
 AND re.player_id = el.player_id
WHERE el.race_code LIKE :prefix
  AND el.entry_course BETWEEN 1 AND 6
  AND el.exhibition_time IS NOT NULL
  AND el.start_timing IS NOT NULL
  AND el.lap_time IS NOT NULL
  AND el.around_time IS NOT NULL
  AND (SUBSTRING(el.race_code FROM 9 FOR 3) IN ('AMG', 'TKY', 'SME') OR el.straight_time IS NOT NULL)
GROUP BY el.race_code
HAVING COUNT(DISTINCT el.entry_course) = 6
   AND COUNT(DISTINCT re.lane_number) = 6
ORDER BY el.race_code
SQL);
    $stmt->execute([':prefix' => $ymd . '%']);
    return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
}

/** @return array<string,array<string,array<string,true>>> */
function savedReplayModes(PDO $pdo, string $date): array
{
    $stmt = $pdo->prepare(<<<'SQL'
SELECT component, validation_mode, race_code
FROM boat_race.prediction_forward_snapshots
WHERE race_date = :race_date::date
  AND stage = 'exhibition'
GROUP BY component, validation_mode, race_code
SQL);
    $stmt->execute([':race_date' => $date]);
    $saved = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $saved[(string)$row['component']][(string)$row['validation_mode']][(string)$row['race_code']] = true;
    }
    return $saved;
}

function hasReplaySnapshot(PDO $pdo, string $raceCode, string $component): bool
{
    $stmt = $pdo->prepare(<<<'SQL'
SELECT EXISTS (
    SELECT 1
    FROM boat_race.prediction_forward_snapshots
    WHERE race_code = :race_code
      AND stage = 'exhibition'
      AND component = :component
      AND validation_mode = 'late_replay'
)
SQL);
    $stmt->execute([':race_code' => $raceCode, ':component' => $component]);
    return (bool)$stmt->fetchColumn();
}

function captureReplayCourseSignals(string $date, string $place): bool
{
    $endpoint = realpath(__DIR__ . '/../web/tamagawa_lane4_star_api.php');
    if ($endpoint === false) {
        return false;
    }
    $inline = 'define("BOATRACE_LATE_REPLAY_CAPTURE", true); '
        . '$_GET = ['
        . "'date' => " . var_export($date, true) . ', '
        . "'place' => " . var_export($place, true) . ', '
        . "'phase' => 'live', 'refresh' => '1']; "
        . 'require ' . var_export($endpoint, true) . ';';
    $command = escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -r ' . escapeshellarg($inline);
    exec($command . ' 2>&1', $lines, $exit);
    if ($exit !== 0) {
        fwrite(STDERR, "  コースサイン再現失敗 {$place}: " . implode("\n", $lines) . "\n");
        return false;
    }
    return true;
}

function runReplayHolePredictions(string $date, array $raceCodes): bool
{
    if ($raceCodes === []) {
        return true;
    }
    $script = __DIR__ . '/freeze_payout_signal_hole_forward.php';
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
        . ' ' . escapeshellarg($date) . ' late_replay ' . escapeshellarg(implode(',', $raceCodes));
    exec($command . ' 2>&1', $lines, $exit);
    if ($exit !== 0) {
        $text = implode("\n", $lines);
        if (str_contains($text, '候補はありません')) {
            return true;
        }
        fwrite(STDERR, "  大穴再現失敗: {$text}\n");
        return false;
    }
    return true;
}

function runReplayGrader(string $date): bool
{
    $script = __DIR__ . '/grade_prediction_forward_snapshots.php';
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
        . ' --through ' . escapeshellarg($date);
    exec($command . ' 2>&1', $lines, $exit);
    if ($exit !== 0) {
        fwrite(STDERR, "  自動採点失敗: " . implode("\n", $lines) . "\n");
        return false;
    }
    foreach ($lines as $line) {
        echo '  ' . $line . "\n";
    }
    return true;
}
