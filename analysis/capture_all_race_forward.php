<?php

declare(strict_types=1);

/**
 * 当日の全場を巡回し、締切前に展示取得・コースサイン・本命対抗・大穴を固定保存する。
 *
 * Usage:
 *   php analysis/capture_all_race_forward.php
 *   php analysis/capture_all_race_forward.php --db-only
 *   php analysis/capture_all_race_forward.php --dry-run
 */

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/logic/OfficialDeadlineLogic.php';
require_once __DIR__ . '/../web/controllers/IndexController.php';
require_once __DIR__ . '/../web/logic/Lane1EscapeFollowerLogic.php';
require_once __DIR__ . '/../web/logic/PredictionForwardSnapshotStore.php';
require_once __DIR__ . '/../web/api/ApiClientProduction.php';
require_once __DIR__ . '/../web/logic/AiTrioRateLogic.php';
require_once __DIR__ . '/../web/logic/RecentCourseTrioRateLogic.php';
require_once __DIR__ . '/../web/logic/EightPointFormationLogic.php';
require_once __DIR__ . '/../web/logic/TrifectaProbabilityLogic.php';
require_once __DIR__ . '/../web/logic/EffectiveRaceOutcomeFilter.php';
require_once __DIR__ . '/../web/logic/CommonSecondRuntimeBridge.php';
require_once __DIR__ . '/../web/logic/AiPlaceTrifectaDisplayLogic.php';
require_once __DIR__ . '/../web/logic/AiThirdCandidatePruneLogic.php';
require_once __DIR__ . '/../web/logic/OfficialOddsLogic.php';

date_default_timezone_set('Asia/Tokyo');

// 取得元への負荷を最小限にするため、締切5～6分前の1回だけ外部展示を取得する。
const EXHIBITION_WINDOW_MINUTES = 6;
const DEADLINE_SAFETY_MINUTES = 2;
const MAX_SCRAPES_PER_RUN = 24;

$dryRun = in_array('--dry-run', $argv, true);
$dbOnly = in_array('--db-only', $argv, true);
$dateText = date('Y-m-d');
$datePrefix = date('Ymd');
$now = new DateTimeImmutable('now');

$lockPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/boatrace_all_race_forward_capture.lock';
$lock = @fopen($lockPath, 'c');
if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo '[' . date('c') . "] SKIP: 全レース前向き保存は実行中です\n";
    exit(0);
}

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$store = new PredictionForwardSnapshotStore($pdo);
if (!$store->isReady()) {
    fwrite(STDERR, "自動前向き検証テーブルが未作成です。\n");
    exit(1);
}

$raceStmt = $pdo->prepare(<<<'SQL'
SELECT DISTINCT race_code
FROM boat_race.race_entry
WHERE race_code LIKE :prefix
ORDER BY race_code
SQL);
$raceStmt->execute([':prefix' => $datePrefix . '%']);
$raceCodes = array_values(array_filter(array_map(
    static fn($v): string => strtoupper(trim((string)$v)),
    $raceStmt->fetchAll(PDO::FETCH_COLUMN) ?: []
), static fn(string $v): bool => preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $v) === 1));

if ($raceCodes === []) {
    echo '[' . date('c') . "] 対象開催なし\n";
    exit(0);
}

$places = array_values(array_unique(array_map(static fn(string $code): string => substr($code, 8, 3), $raceCodes)));
$deadlineResult = (new OfficialDeadlineLogic())->load($datePrefix, $places, false);
$deadlines = is_array($deadlineResult['deadlines'] ?? null) ? $deadlineResult['deadlines'] : [];
if ($deadlines === []) {
    fwrite(STDERR, "公式締切時刻を取得できないため、安全のため保存しません。\n");
    exit(1);
}

$readyBefore = exhibitionReadyRaceCodes($pdo, $raceCodes);
$attemptStatePath = __DIR__ . '/../var/cache/forward_capture_attempts_' . $datePrefix . '.json';
$attemptState = $dbOnly ? [] : readAttemptState($attemptStatePath);
$due = [];
if (!$dbOnly) {
    foreach ($raceCodes as $raceCode) {
        if (isset($readyBefore[$raceCode])) {
            continue;
        }
        $deadline = deadlineForRace($dateText, $deadlines[$raceCode] ?? null);
        if ($deadline === null) {
            continue;
        }
        $seconds = $deadline->getTimestamp() - $now->getTimestamp();
        if ($seconds > EXHIBITION_WINDOW_MINUTES * 60 || $seconds <= DEADLINE_SAFETY_MINUTES * 60) {
            continue;
        }
        $lastAttempt = (int)($attemptState[$raceCode] ?? 0);
        // 成否や部分公開を問わず、1レースにつき外部アクセスは1回だけ。
        // 欠測は23時のラズパイ処理で履歴用に補完し、前向き検証には混ぜない。
        if ($lastAttempt > 0) {
            continue;
        }
        $due[] = ['race_code' => $raceCode, 'deadline' => $deadline];
    }
}
usort($due, static fn(array $a, array $b): int => $a['deadline'] <=> $b['deadline']);
$due = array_slice($due, 0, MAX_SCRAPES_PER_RUN);

printf(
    "[%s] 全レース前向き保存: 開催=%dR / 展示済=%dR / 今回取得候補=%dR%s%s\n",
    date('c'),
    count($raceCodes),
    count($readyBefore),
    count($due),
    $dbOnly ? ' / 日中の外部展示取得なし' : '',
    $dryRun ? ' / DRY-RUN' : ''
);

$scrapeOk = 0;
$scrapeFailed = 0;
if (!$dryRun && $due !== []) {
    $api = new ApiClientProduction();
    foreach ($due as $candidate) {
        $raceCode = (string)$candidate['race_code'];
        $attemptState[$raceCode] = time();
        [$message] = $api->updateExhibition($raceCode);
        $ok = !str_contains((string)$message, '失敗');
        $completeNow = isset(exhibitionReadyRaceCodes($pdo, [$raceCode])[$raceCode]);
        if ($ok && !$completeNow) {
            $message .= '（主要項目未完備・前向き検証は欠測）';
        }
        printf("  展示取得 %s: %s\n", $raceCode, $message);
        $ok ? $scrapeOk++ : $scrapeFailed++;
        writeAttemptState($attemptStatePath, $attemptState);
    }
}

$readyAfter = exhibitionReadyRaceCodes($pdo, $raceCodes);
$predictionSaved = snapshotRaceCodes($pdo, $dateText, 'exhibition', 'prediction');
$signalSaved = snapshotRaceCodes($pdo, $dateText, 'exhibition', 'course_signals');
$aiModeSaved = snapshotTrifectaOddsSourceRaceCodes($pdo, $dateText, 'ai_bet_modes_v1');
$holeAlertMap = loadDailyPrimaryHoleAlerts($datePrefix);
$captureTargets = [];
$aiModeTargets = [];
$signalPlaces = [];
foreach (array_keys($readyAfter) as $raceCode) {
    $deadline = deadlineForRace($dateText, $deadlines[$raceCode] ?? null);
    if ($deadline === null || $now >= $deadline->modify('-' . DEADLINE_SAFETY_MINUTES . ' minutes')) {
        continue;
    }
    if (!isset($predictionSaved[$raceCode])) {
        $captureTargets[] = $raceCode;
    }
    if (!isset($aiModeSaved[$raceCode])) {
        $aiModeTargets[] = $raceCode;
    }
    if (!isset($signalSaved[$raceCode])) {
        $signalPlaces[substr($raceCode, 8, 3)] = true;
    }
}
printf(
    "[%s] 締切前保存候補: 本命対抗=%dR / AI買い方4方式=%dR%s\n",
    date('c'),
    count($captureTargets),
    count($aiModeTargets),
    $dryRun ? ' / DRY-RUN' : ''
);

$predictionOk = 0;
$predictionFailed = 0;
$aiModeOk = 0;
$aiModeFailed = 0;
$signalOk = 0;
if (!$dryRun) {
    $captureWorkTargets = array_values(array_unique(array_merge($captureTargets, $aiModeTargets)));
    foreach ($captureWorkTargets as $raceCode) {
        try {
            captureDisplayedPrediction(
                $raceCode,
                $dateText,
                $pdo,
                !empty($holeAlertMap[$raceCode])
            );
            if (in_array($raceCode, $captureTargets, true)) {
                $after = snapshotRaceCodes($pdo, $dateText, 'exhibition', 'prediction', [$raceCode]);
                if (isset($after[$raceCode])) {
                    $predictionOk++;
                } else {
                    $predictionFailed++;
                    fwrite(STDERR, "  本命対抗を保存できませんでした: {$raceCode}\n");
                }
            }
            if (in_array($raceCode, $aiModeTargets, true)) {
                $afterMode = snapshotTrifectaOddsSourceRaceCodes($pdo, $dateText, 'ai_bet_modes_v1', [$raceCode]);
                if (isset($afterMode[$raceCode])) {
                    $aiModeOk++;
                } else {
                    $aiModeFailed++;
                    fwrite(STDERR, "  AI買い方4方式を保存できませんでした: {$raceCode}\n");
                }
            }
        } catch (Throwable $e) {
            if (in_array($raceCode, $captureTargets, true)) $predictionFailed++;
            if (in_array($raceCode, $aiModeTargets, true)) $aiModeFailed++;
            fwrite(STDERR, "  予想保存エラー {$raceCode}: {$e->getMessage()}\n");
        }
    }

    foreach (array_keys($signalPlaces) as $place) {
        if (captureLiveCourseSignals($dateText, $place)) {
            $signalOk++;
        }
    }
}

$newReady = array_diff_key($readyAfter, $readyBefore);
$holeRan = false;
if (!$dryRun && ($newReady !== [] || $predictionOk > 0)) {
    $holeTargets = array_values(array_unique(array_merge(array_keys($newReady), $captureTargets)));
    $holeRan = runHoleForwardFreeze($dateText, $holeTargets);
}

printf(
    "[%s] 完了: 展示取得成功=%d / 失敗=%d / 展示完備=%dR / 本命対抗保存=%d / 保存失敗=%d / AI4方式保存=%d / 保存失敗=%d / サイン更新=%d場 / 大穴更新=%s\n",
    date('c'),
    $scrapeOk,
    $scrapeFailed,
    count($readyAfter),
    $predictionOk,
    $predictionFailed,
    $aiModeOk,
    $aiModeFailed,
    $signalOk,
    $holeRan ? '実行' : 'なし'
);

/** @return array<string,true> */
function exhibitionReadyRaceCodes(PDO $pdo, array $raceCodes): array
{
    if ($raceCodes === []) return [];
    $marks = implode(',', array_fill(0, count($raceCodes), '?'));
    $stmt = $pdo->prepare(<<<SQL
SELECT race_code
FROM boat_race.exhibition_live
WHERE race_code IN ({$marks})
  AND entry_course BETWEEN 1 AND 6
  AND exhibition_time IS NOT NULL
  AND start_timing IS NOT NULL
  AND lap_time IS NOT NULL
  AND around_time IS NOT NULL
  AND (SUBSTRING(race_code FROM 9 FOR 3) IN ('AMG', 'TKY', 'SME') OR straight_time IS NOT NULL)
GROUP BY race_code
HAVING COUNT(DISTINCT entry_course) = 6
SQL);
    $stmt->execute($raceCodes);
    return array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
}

/** @return array<string,true> */
function snapshotRaceCodes(PDO $pdo, string $date, string $stage, string $component, array $only = []): array
{
    $sql = <<<'SQL'
SELECT DISTINCT race_code
FROM boat_race.prediction_forward_snapshots
WHERE race_date = :race_date::date
  AND stage = :stage
  AND component = :component
SQL;
    $params = [':race_date' => $date, ':stage' => $stage, ':component' => $component];
    if ($only !== []) {
        $marks = [];
        foreach (array_values($only) as $i => $raceCode) {
            $key = ':race_' . $i;
            $marks[] = $key;
            $params[$key] = $raceCode;
        }
        $sql .= ' AND race_code IN (' . implode(',', $marks) . ')';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
}

/** @return array<string,true> */
function snapshotTrifectaOddsSourceRaceCodes(PDO $pdo, string $date, string $source, array $only = []): array
{
    $sql = <<<'SQL'
SELECT DISTINCT race_code
FROM boat_race.prediction_forward_snapshots
WHERE race_date = :race_date::date
  AND stage = 'exhibition'
  AND component = 'trifecta_odds'
  AND validation_mode = 'strict'
  AND payload->>'source' = :source
SQL;
    $params = [':race_date' => $date, ':source' => $source];
    if ($only !== []) {
        $marks = [];
        foreach (array_values($only) as $i => $raceCode) {
            $key = ':mode_race_' . $i;
            $marks[] = $key;
            $params[$key] = $raceCode;
        }
        $sql .= ' AND race_code IN (' . implode(',', $marks) . ')';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
}

function deadlineForRace(string $date, mixed $time): ?DateTimeImmutable
{
    if (!is_string($time) || preg_match('/^\d{2}:\d{2}$/', $time) !== 1) return null;
    $value = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time);
    return $value === false ? null : $value;
}

function captureDisplayedPrediction(string $raceCode, string $date, PDO $pdo, bool $holeAlert): void
{
    $_GET = [
        'date' => $date,
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

    // ページ閲覧の有無に依存せず、表示用と同じ8点型を締切前に固定する。
    $courseByBoat = is_array($view['prediction_course_by_boat'] ?? null)
        ? $view['prediction_course_by_boat']
        : [];
    $aiTrioData = (new AiTrioRateLogic())->calculate(
        $raceCode,
        is_array($view['results'] ?? null) ? $view['results'] : [],
        is_array($view['tenji_list'] ?? null) ? $view['tenji_list'] : [],
        $courseByBoat
    );
    $recentTrioData = (new RecentCourseTrioRateLogic())->calculate($raceCode, $courseByBoat);
    // PC表示と同じAI着順率v1の本命2着候補を反映してから、2着拡大型を作る。
    $trifectaData = (new TrifectaProbabilityLogic())->calculate(
        $raceCode,
        is_array($view['corrected_win_rate_data']['boats'] ?? null)
            ? $view['corrected_win_rate_data']['boats']
            : [],
        is_array($aiTrioData['boats'] ?? null) ? $aiTrioData['boats'] : [],
        $courseByBoat
    );
    $trifectaData = (new EffectiveRaceOutcomeFilter())->apply($raceCode, $trifectaData);
    $mlSecondTrifectaData = (new AiPlaceTrifectaDisplayLogic())->apply(
        $trifectaData,
        is_array($view['ai_place_rate_data'] ?? null) ? $view['ai_place_rate_data'] : []
    );
    if ((string)($mlSecondTrifectaData['probability_source'] ?? '') !== 'ai_place_v1_joint120') {
        $mlSecondTrifectaData = $trifectaData;
    }
    $bridge = (new CommonSecondRuntimeBridge())->apply(
        $view,
        is_array($view['final_predictions'] ?? null) ? $view['final_predictions'] : [],
        $mlSecondTrifectaData
    );
    $view = is_array($bridge['view_data'] ?? null) ? $bridge['view_data'] : $view;
    $view = (new AiThirdCandidatePruneLogic())->apply(
        $view,
        is_array($view['ai_place_rate_data'] ?? null) ? $view['ai_place_rate_data'] : []
    );
    $view = (new EightPointFormationLogic())->apply(
        $view,
        is_array($view['final_predictions'] ?? null) ? $view['final_predictions'] : [],
        is_array($aiTrioData['boats'] ?? null) ? $aiTrioData['boats'] : [],
        is_array($recentTrioData['boats'] ?? null) ? $recentTrioData['boats'] : []
    );
    PredictionForwardSnapshotStore::captureDisplayedPrediction($view, 'automatic_all_races', $pdo);

    // 画面と同じAI着順率v1の120通りへ差し替え、締切前オッズと4方式を固定する。
    $productionWinBoats = (
        (string)($view['ai_win_rate_data']['status'] ?? '') === 'ok'
        && count((array)($view['ai_win_rate_data']['boats'] ?? [])) === 6
    )
        ? (array)$view['ai_win_rate_data']['boats']
        : (array)($view['corrected_win_rate_data']['boats'] ?? []);
    $modeTrifectaData = (new TrifectaProbabilityLogic())->calculate(
        $raceCode,
        $productionWinBoats,
        is_array($aiTrioData['boats'] ?? null) ? $aiTrioData['boats'] : [],
        $courseByBoat
    );
    $modeTrifectaData = (new EffectiveRaceOutcomeFilter())->apply($raceCode, $modeTrifectaData);
    $modeTrifectaData = (new AiPlaceTrifectaDisplayLogic())->apply(
        $modeTrifectaData,
        is_array($view['ai_place_rate_data'] ?? null) ? $view['ai_place_rate_data'] : []
    );
    $modeRows = is_array($modeTrifectaData['rows'] ?? null) ? $modeTrifectaData['rows'] : [];
    if (
        (string)($modeTrifectaData['probability_source'] ?? '') === 'ai_place_v1_joint120'
        && count($modeRows) === 120
    ) {
        $oddsData = (new OfficialOddsLogic())->load($raceCode, false);
        PredictionForwardSnapshotStore::captureCurrentTrifectaOdds(
            $raceCode,
            $oddsData,
            $modeRows,
            (string)($view['honmei_kai'] ?? ''),
            (string)($view['taikou_kai'] ?? ''),
            'ai_bet_modes_v1',
            $pdo,
            $holeAlert
        );
    }
}

/** @return array<string,true> */
function loadDailyPrimaryHoleAlerts(string $datePrefix): array
{
    $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'boatrace_home_upset_v2_' . $datePrefix . '.json';
    if (!is_file($path)) return [];
    $data = json_decode((string)@file_get_contents($path), true);
    if (!is_array($data)) return [];
    $out = [];
    foreach ((array)($data['rows'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $code = strtoupper(trim((string)($row['race_code'] ?? '')));
        if (preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $code) !== 1) continue;
        if ((string)($row['primary'] ?? '平常') !== '平常') $out[$code] = true;
    }
    return $out;
}

function captureLiveCourseSignals(string $date, string $place): bool
{
    $endpoint = realpath(__DIR__ . '/../web/tamagawa_lane4_star_api.php');
    if ($endpoint === false) return false;
    $inline = '$_GET = ['
        . "'date' => " . var_export($date, true) . ', '
        . "'place' => " . var_export($place, true) . ', '
        . "'phase' => 'live', 'refresh' => '1']; "
        . 'require ' . var_export($endpoint, true) . ';';
    $command = escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr -r ' . escapeshellarg($inline);
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname($endpoint));
    if (!is_resource($process)) return false;
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $data = json_decode(is_string($stdout) ? $stdout : '', true);
    if ($exit !== 0 || !is_array($data) || ($data['status'] ?? '') !== 'ok') {
        fwrite(STDERR, "  コースサイン更新失敗 {$place}: " . trim((string)$stderr) . "\n");
        return false;
    }
    return true;
}

function runHoleForwardFreeze(string $date, array $raceCodes): bool
{
    $raceCodes = array_values(array_filter(array_unique(array_map(
        static fn($v): string => strtoupper(trim((string)$v)),
        $raceCodes
    )), static fn(string $v): bool => preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $v) === 1));
    if ($raceCodes === []) return false;
    $script = __DIR__ . '/freeze_payout_signal_hole_forward.php';
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
        . ' ' . escapeshellarg($date) . ' exhibition ' . escapeshellarg(implode(',', $raceCodes));
    $lines = [];
    $exit = 0;
    exec($command . ' 2>&1', $lines, $exit);
    if ($exit !== 0) {
        $text = implode("\n", $lines);
        // 穴候補なしは正常。展示取得・通常予想の保存は完了している。
        if (str_contains($text, 'イン崩壊候補はありません')) return true;
        fwrite(STDERR, "  大穴保存失敗: {$text}\n");
        return false;
    }
    return true;
}

function readAttemptState(string $path): array
{
    if (!is_file($path)) return [];
    $data = json_decode((string)@file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function writeAttemptState(string $path, array $state): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) return;
    $tmp = $path . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) !== false) {
        @rename($tmp, $path);
    }
}
