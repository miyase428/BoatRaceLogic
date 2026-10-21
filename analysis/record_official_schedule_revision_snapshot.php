<?php

declare(strict_types=1);

/**
 * Records one outcome-blind semantic schedule observation from an already
 * present official-deadline cache. It never fetches the official site, opens
 * the database, invokes prediction/capture, or changes an armed window.
 */

require_once __DIR__ . '/../web/logic/StrictForwardOpportunityLedger.php';

$date = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
$datePrefix = str_replace('-', '', $date);
$cachePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/boatrace_official_deadlines/deadlines_' . $datePrefix . '.json';
if (!is_file($cachePath)) throw new RuntimeException('official_deadline_cache_missing');
$payload = json_decode((string)file_get_contents($cachePath), true);
if (!is_array($payload) || (string)($payload['date'] ?? '') !== $datePrefix) throw new RuntimeException('official_deadline_cache_invalid_or_wrong_date');
$sourceGeneratedAt = trim((string)($payload['fetched_at'] ?? ''));
$deadlines = is_array($payload['deadlines'] ?? null) ? $payload['deadlines'] : [];
$mlVenues = array_fill_keys(['AMG', 'ASY', 'BWK', 'EDG', 'HWJ', 'KRY', 'MKN', 'TMG', 'TDA', 'OMR', 'SMS', 'SME'], true);
$schedules = [];
foreach ($deadlines as $raceCode => $time) {
    $raceCode = strtoupper(trim((string)$raceCode));
    if (!isset($mlVenues[substr($raceCode, 8, 3)]) || !preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $raceCode)) continue;
    if (!preg_match('/^\d{2}:\d{2}$/', (string)$time)) continue;
    $cutoff = new DateTimeImmutable($date . ' ' . $time, new DateTimeZone('Asia/Tokyo'));
    $schedules[] = ['race_code' => $raceCode, 'cutoff_timestamp' => $cutoff->format(DATE_ATOM), 'official_status' => 'ACTIVE'];
}
if ($sourceGeneratedAt === '' || $schedules === []) throw new RuntimeException('official_deadline_cache_no_usable_semantic_schedule');
StrictForwardOpportunityLedger::recordOfficialScheduleSnapshotFromContext([
    'date' => $date,
    'raw_source_identity' => 'sha256:' . hash_file('sha256', $cachePath),
    'source_generated_at' => $sourceGeneratedAt,
    'schedules' => $schedules,
]);
echo json_encode(['status' => 'ok', 'date' => $date, 'source_generated_at' => $sourceGeneratedAt, 'raw_source_identity' => 'sha256:' . hash_file('sha256', $cachePath), 'schedule_count' => count($schedules), 'external_fetch' => 'NO', 'db_access' => 'NO'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
