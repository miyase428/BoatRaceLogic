<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../logic/OfficialAmagasakiLateHistoryImporter.php';
require_once __DIR__ . '/../logic/OfficialAmagasakiDeadlineCache.php';

const LATE_HISTORY_INPUT_DIRECTORY = '/tmp/boatrace_amagasaki_late_history_20261022';
const LATE_HISTORY_DATE = '20261022';

$execute = false;
$input = null;
$race = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--execute') $execute = true;
    elseif (str_starts_with($arg, '--input=')) $input = substr($arg, strlen('--input='));
    elseif (str_starts_with($arg, '--race=')) $race = substr($arg, strlen('--race='));
    else { fwrite(STDERR, "Usage: php analysis/import_amagasaki_late_history_json.php --race=02..08 --input=" . LATE_HISTORY_INPUT_DIRECTORY . "/20261022AMGNN.json [--execute]\n"); exit(2); }
}
if (!is_string($race) || preg_match('/^(0[2-8])$/', $race) !== 1) {
    fwrite(STDERR, "LATE_HISTORY_RACE_NOT_APPROVED\n");
    exit(2);
}
$raceCode = LATE_HISTORY_DATE . 'AMG' . $race;
$expectedInput = LATE_HISTORY_INPUT_DIRECTORY . DIRECTORY_SEPARATOR . $raceCode . '.json';
if ($input === null || realpath($input) !== $expectedInput || is_link($input)) {
    fwrite(STDERR, "LATE_HISTORY_INPUT_NOT_APPROVED\n");
    exit(2);
}
try {
    $cache = OfficialAmagasakiDeadlineCache::readComplete('/tmp/boatrace_official_deadlines', LATE_HISTORY_DATE);
    $deadlineJst = is_array($cache) ? ($cache['deadlines'][$raceCode] ?? null) : null;
    if (!is_string($deadlineJst) || preg_match('/^\d{2}:\d{2}$/', $deadlineJst) !== 1) throw new RuntimeException('LATE_HISTORY_DEADLINE_CACHE_MISSING');
    $deadline = new DateTimeImmutable(LATE_HISTORY_DATE . ' ' . $deadlineJst . ':00', new DateTimeZone('Asia/Tokyo'));
    $raw = file_get_contents($expectedInput);
    $payload = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
    if (!is_array($payload)) throw new RuntimeException('late history JSON is invalid');

    $pdo = getPDO();
    $entries = $pdo->prepare('SELECT lane_number::int AS lane_number, player_id::text AS player_id FROM boat_race.race_entry WHERE race_code = :race_code ORDER BY lane_number');
    $entries->execute([':race_code' => $raceCode]);
    $raceEntries = $entries->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $importer = new OfficialAmagasakiLateHistoryImporter($pdo, new OfficialAmagasakiExhibitionValidator(), $raceCode, $deadline);
    $preflight = $importer->dryRun($payload, $raceEntries);
    if (!$execute) {
        echo json_encode(['dry_run' => true, 'race_code' => $raceCode, 'deadline' => $deadline->format(DateTimeInterface::ATOM)] + $preflight, JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit($preflight['status'] === 'validated_late_history_only' ? 0 : 1);
    }
    if ($preflight['status'] !== 'validated_late_history_only') throw new RuntimeException('late history preflight requires review');
    $result = $importer->import($payload, $raceEntries);
    echo json_encode(['dry_run' => false, 'race_code' => $raceCode, 'deadline' => $deadline->format(DateTimeInterface::ATOM)] + $result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($result['status'] === 'saved' ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, 'LATE_HISTORY_IMPORT_FAILED:' . get_class($error) . ':' . $error->getMessage() . PHP_EOL);
    exit(1);
}
