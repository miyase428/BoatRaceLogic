<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../logic/OfficialAmagasakiLateHistoryImporter.php';

const AMG01_LATE_HISTORY_INPUT = '/home/miyazaki/boatrace-amg01-fetchcheck-20261022/result.json';

$execute = false;
$input = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--execute') $execute = true;
    elseif (str_starts_with($arg, '--input=')) $input = substr($arg, strlen('--input='));
    else { fwrite(STDERR, "Usage: php analysis/import_amagasaki_late_history_json.php --input=" . AMG01_LATE_HISTORY_INPUT . " [--execute]\n"); exit(2); }
}
if ($input === null || realpath($input) !== AMG01_LATE_HISTORY_INPUT || is_link($input)) {
    fwrite(STDERR, "LATE_HISTORY_INPUT_NOT_APPROVED\n");
    exit(2);
}
try {
    $raw = file_get_contents(AMG01_LATE_HISTORY_INPUT);
    $payload = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
    if (!is_array($payload)) throw new RuntimeException('late history JSON is invalid');

    $pdo = getPDO();
    $entries = $pdo->prepare('SELECT lane_number::int AS lane_number, player_id::text AS player_id FROM boat_race.race_entry WHERE race_code = :race_code ORDER BY lane_number');
    $entries->execute([':race_code' => OfficialAmagasakiLateHistoryImporter::RACE_CODE]);
    $raceEntries = $entries->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $importer = new OfficialAmagasakiLateHistoryImporter($pdo, new OfficialAmagasakiExhibitionValidator());
    $preflight = $importer->dryRun($payload, $raceEntries);
    if (!$execute) {
        echo json_encode(['dry_run' => true, 'race_code' => OfficialAmagasakiLateHistoryImporter::RACE_CODE] + $preflight, JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit($preflight['status'] === 'validated_late_history_only' ? 0 : 1);
    }
    if ($preflight['status'] !== 'validated_late_history_only') throw new RuntimeException('late history preflight requires review');
    $result = $importer->import($payload, $raceEntries);
    echo json_encode(['dry_run' => false, 'race_code' => OfficialAmagasakiLateHistoryImporter::RACE_CODE] + $result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($result['status'] === 'saved' ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, 'LATE_HISTORY_IMPORT_FAILED:' . get_class($error) . ':' . $error->getMessage() . PHP_EOL);
    exit(1);
}
