<?php

declare(strict_types=1);

/**
 * TOP画面用の展示前コースサインを、開催場ごとに先行生成して保存する。
 *
 * Usage:
 *   php analysis/prewarm_home_course_signals.php [YYYY-MM-DD]
 *
 * 実行ごとに当日の出走表がある場だけを対象にする。個別の集計は子PHPで既存API
 * と同じロジックを呼ぶため、TOPアクセス時と結果がずれない。
 */

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/logic/HomeCourseSignalSnapshotStore.php';
require_once __DIR__ . '/../web/logic/PredictionForwardSnapshotStore.php';

date_default_timezone_set('Asia/Tokyo');

function failHomeCourseSignalPrewarm(string $message, int $status = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($status);
}

function validHomeCourseSignalDate(string $date): bool
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $dt !== false && $dt->format('Y-m-d') === $date;
}

/** @return array<string,mixed>|null */
function buildBaseCourseSignal(string $date, string $place): ?array
{
    $endpoint = realpath(__DIR__ . '/../web/tamagawa_lane4_star_api.php');
    if ($endpoint === false) {
        return null;
    }

    $inline = '$_GET = ['
        . "'date' => " . var_export($date, true) . ', '
        . "'place' => " . var_export($place, true) . ', '
        . "'phase' => 'base']; "
        . 'require ' . var_export($endpoint, true) . ';';
    $command = escapeshellarg(PHP_BINARY)
        . ' -d display_errors=stderr -r ' . escapeshellarg($inline);

    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname($endpoint));
    if (!is_resource($process)) {
        return null;
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $data = json_decode(is_string($stdout) ? $stdout : '', true);
    if ($exitCode !== 0 || !is_array($data) || ($data['status'] ?? '') !== 'ok') {
        $message = trim(is_string($stderr) ? $stderr : '');
        fwrite(STDERR, sprintf("[%s] %s: %s\n", $place, $date, $message !== '' ? $message : '生成失敗'));
        return null;
    }

    return $data;
}

$date = trim((string)($argv[1] ?? date('Y-m-d')));
if (!validHomeCourseSignalDate($date)) {
    failHomeCourseSignalPrewarm('Usage: php analysis/prewarm_home_course_signals.php [YYYY-MM-DD]', 2);
}

$lockPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/boatrace_home_course_signals_prewarm.lock';
$lock = @fopen($lockPath, 'c');
if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, '[' . date('c') . "] SKIP: TOPコースサインの先行生成は実行中です\n");
    exit(0);
}

$validPlaces = array_fill_keys([
    'KRY', 'TDA', 'EDG', 'HWJ', 'TMG', 'HMN', 'GMG', 'TKN', 'TSU', 'MKN', 'BWK', 'SME',
    'AMG', 'NRT', 'MRG', 'KJM', 'MYJ', 'TKY', 'SMS', 'WKM', 'ASY', 'FKO', 'KRT', 'OMR',
], true);

try {
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        "SELECT race_code\n"
        . "FROM boat_race.race_entry\n"
        . "WHERE race_code LIKE :prefix\n"
        . "ORDER BY race_code"
    );
    $stmt->execute([':prefix' => str_replace('-', '', $date) . '%']);

    $raceCodesByPlace = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $raceCode) {
        $raceCode = strtoupper(trim((string)$raceCode));
        if (!preg_match('/^\d{8}([A-Z0-9]{3})(0[1-9]|1[0-2])$/', $raceCode, $m)) {
            continue;
        }
        $place = $m[1];
        if (isset($validPlaces[$place])) {
            $raceCodesByPlace[$place][] = $raceCode;
        }
    }
} catch (Throwable $e) {
    failHomeCourseSignalPrewarm('開催場の取得に失敗: ' . $e->getMessage());
}

if ($raceCodesByPlace === []) {
    fwrite(STDOUT, '[' . date('c') . "] TOPコースサイン: 対象開催なし\n");
    exit(0);
}

$existing = HomeCourseSignalSnapshotStore::read($date);
$places = is_array($existing['places'] ?? null) ? $existing['places'] : [];
$storedRaceCodes = is_array($existing['race_codes'] ?? null) ? $existing['race_codes'] : [];
$generated = [];

foreach ($raceCodesByPlace as $place => $raceCodes) {
    $data = buildBaseCourseSignal($date, $place);
    if ($data === null || ($data['signal_phase'] ?? '') !== 'base') {
        continue;
    }

    // APIの5分キャッシュが使われた場合でも、8:35時点の全レースを確実に前向き保存する。
    $data['_snapshot_race_codes'] = $raceCodes;
    PredictionForwardSnapshotStore::captureDisplayedCourseSignals($data);
    unset($data['_snapshot_race_codes'], $data['_snapshot_ready_race_codes']);

    $places[$place] = $data;
    $storedRaceCodes[$place] = array_values(array_unique($raceCodes));
    $generated[] = $place;
}

if ($generated === []) {
    failHomeCourseSignalPrewarm('TOPコースサインを1場も生成できませんでした');
}

if (!HomeCourseSignalSnapshotStore::write($date, $places, $storedRaceCodes)) {
    failHomeCourseSignalPrewarm('TOPコースサインの保存に失敗しました');
}

fwrite(
    STDOUT,
    sprintf("[%s] TOPコースサイン保存: %s (%s)\n", date('c'), $date, implode(',', $generated))
);
