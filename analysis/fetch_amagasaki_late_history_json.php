<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../logic/NodeOfficialAmagasakiPayloadFetcher.php';
require_once __DIR__ . '/../logic/OfficialAmagasakiDeadlineCache.php';
require_once __DIR__ . '/../logic/OfficialAmagasakiLateHistoryImporter.php';

const LATE_HISTORY_OUTPUT_DIRECTORY = '/tmp/boatrace_amagasaki_late_history_20261022';
const LATE_HISTORY_DATE = '20261022';

$race = null;
$execute = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--race=')) $race = substr($argument, 7);
    elseif ($argument === '--execute') $execute = true;
    else { fwrite(STDERR, "Usage: php analysis/fetch_amagasaki_late_history_json.php --race=02..08 [--execute]\n"); exit(2); }
}
if (!is_string($race) || preg_match('/^(0[2-8])$/', $race) !== 1) { fwrite(STDERR, "LATE_HISTORY_RACE_NOT_APPROVED\n"); exit(2); }
$raceCode = LATE_HISTORY_DATE . 'AMG' . $race;
try {
    $cache = OfficialAmagasakiDeadlineCache::readComplete('/tmp/boatrace_official_deadlines', LATE_HISTORY_DATE);
    $deadlineText = is_array($cache) ? ($cache['deadlines'][$raceCode] ?? null) : null;
    if (!is_string($deadlineText) || preg_match('/^\d{2}:\d{2}$/', $deadlineText) !== 1) throw new RuntimeException('LATE_HISTORY_DEADLINE_CACHE_MISSING');
    $deadline = new DateTimeImmutable(LATE_HISTORY_DATE . ' ' . $deadlineText . ':00', new DateTimeZone('Asia/Tokyo'));
    $pdo = getPDO();
    $time = $pdo->prepare('SELECT clock_timestamp() > CAST(:deadline AS timestamptz)');
    $time->execute([':deadline' => $deadline->format(DateTimeInterface::ATOM)]);
    if (!in_array($time->fetchColumn(), [true, 't', '1', 1], true)) throw new RuntimeException('LATE_HISTORY_NOT_AFTER_DEADLINE');
    $rows = $pdo->prepare('SELECT COUNT(*) FROM boat_race.exhibition_live WHERE race_code = :race_code');
    $rows->execute([':race_code' => $raceCode]);
    $audit = $pdo->prepare("SELECT EXISTS (SELECT 1 FROM boat_race.exhibition_source_audit_v1 WHERE race_code = :race_code AND source = 'official_amagasaki' AND result_status = 'saved')");
    $audit->execute([':race_code' => $raceCode]);
    $existingRows = (int)$rows->fetchColumn();
    $savedAudit = in_array($audit->fetchColumn(), [true, 't', '1', 1], true);
    if (!$execute) {
        echo json_encode(['dry_run' => true, 'race_code' => $raceCode, 'deadline' => $deadline->format(DateTimeInterface::ATOM), 'existing_rows' => $existingRows, 'saved_audit' => $savedAudit, 'would_fetch' => $existingRows === 0 && !$savedAudit], JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit($existingRows === 0 && !$savedAudit ? 0 : 1);
    }
    if ($existingRows !== 0 || $savedAudit) throw new RuntimeException('LATE_HISTORY_EXISTING_STATE_PROTECTED');
    if (!is_dir(LATE_HISTORY_OUTPUT_DIRECTORY) && !mkdir(LATE_HISTORY_OUTPUT_DIRECTORY, 0700, true) && !is_dir(LATE_HISTORY_OUTPUT_DIRECTORY)) throw new RuntimeException('LATE_HISTORY_OUTPUT_DIRECTORY_UNAVAILABLE');
    @chmod(LATE_HISTORY_OUTPUT_DIRECTORY, 0700);
    $fetcher = new NodeOfficialAmagasakiPayloadFetcher('/usr/bin/node', __DIR__ . '/../playwright/official_exhibition_amagasaki.js');
    $result = $fetcher->fetch($raceCode);
    if (($result['outcome'] ?? null) !== 'published' || !is_array($result['payload'] ?? null)) throw new RuntimeException('LATE_HISTORY_FETCH_' . strtoupper((string)($result['outcome'] ?? 'failed')));
    $payload = $result['payload'];
    if (($payload['race_code'] ?? null) !== $raceCode) throw new RuntimeException('LATE_HISTORY_FETCH_RACE_IDENTITY_MISMATCH');
    $fetched = new DateTimeImmutable((string)($payload['fetched_at'] ?? ''));
    if ($fetched <= $deadline) throw new RuntimeException('LATE_HISTORY_FETCH_NOT_AFTER_DEADLINE');
    $target = LATE_HISTORY_OUTPUT_DIRECTORY . DIRECTORY_SEPARATOR . $raceCode . '.json';
    $temporary = $target . '.' . bin2hex(random_bytes(8)) . '.tmp';
    if (file_put_contents($temporary, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException('LATE_HISTORY_OUTPUT_WRITE_FAILED');
    @chmod($temporary, 0600);
    if (!rename($temporary, $target)) { @unlink($temporary); throw new RuntimeException('LATE_HISTORY_OUTPUT_RENAME_FAILED'); }
    echo json_encode(['dry_run' => false, 'race_code' => $raceCode, 'status' => 'captured', 'path' => $target, 'fetched_at' => $payload['fetched_at']], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'LATE_HISTORY_FETCH_FAILED:' . get_class($error) . ':' . $error->getMessage() . PHP_EOL);
    exit(1);
}
