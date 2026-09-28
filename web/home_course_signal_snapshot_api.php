<?php

declare(strict_types=1);

require_once __DIR__ . '/logic/HomeCourseSignalSnapshotStore.php';
require_once __DIR__ . '/logic/PredictionForwardSnapshotStore.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');

$date = trim((string)($_GET['date'] ?? ''));
$dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if ($dt === false || $dt->format('Y-m-d') !== $date) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'invalid date'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$snapshot = HomeCourseSignalSnapshotStore::read($date);
if ($snapshot === null) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'error' => 'snapshot not found'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$currentLogicVersion = PredictionForwardSnapshotStore::courseSignalLogicVersion();
$snapshotLogicVersion = (string)($snapshot['logic_version'] ?? '');
$validForCurrentLogic = HomeCourseSignalSnapshotStore::isValidForLogicVersion(
    $snapshot,
    $currentLogicVersion
);

echo json_encode([
    'status' => 'ok',
    'date' => $snapshot['date'],
    'generated_at' => $snapshot['generated_at'] ?? null,
    'logic_version' => $snapshotLogicVersion !== '' ? $snapshotLogicVersion : null,
    'current_logic_version' => $currentLogicVersion,
    'valid_for_current_logic' => $validForCurrentLogic,
    'places' => $snapshot['places'],
    'race_codes' => $snapshot['race_codes'] ?? [],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
