<?php
declare(strict_types=1);
require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/logic/OfficialDeadlineLogic.php';
require_once __DIR__ . '/../logic/Course4TenkaiDeadlineCachePreparer.php';
date_default_timezone_set('Asia/Tokyo');
$date = date('Ymd');
foreach (array_slice($argv, 1) as $arg) if (str_starts_with($arg, '--date=')) $date = substr($arg, 7);
$prefix = preg_replace('/\D/', '', $date) ?? '';
if (!preg_match('/^\d{8}$/', $prefix)) { echo json_encode(['ok'=>false, 'status'=>'INVALID_DATE']), "\n"; exit(1); }
$cacheDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/boatrace_official_deadlines';
if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) { echo json_encode(['ok'=>false, 'status'=>'CACHE_DIRECTORY_UNAVAILABLE']), "\n"; exit(1); }
$lockPath = $cacheDir . '/static_snapshot_deadline_prepare_' . $prefix . '.lock';
$lock = @fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { echo json_encode(['ok'=>false, 'status'=>'PREPARATION_IN_PROGRESS']), "\n"; exit(1); }
try {
    $attemptPath = $cacheDir . '/static_snapshot_deadline_prepare_' . $prefix . '.json';
    $marker = is_file($attemptPath) ? json_decode((string)file_get_contents($attemptPath), true) : null;
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
    $decision = Course4TenkaiDeadlineCachePreparer::retryDecision(is_array($marker) ? $marker : null, $now);
    $cachePath = $cacheDir . '/deadlines_' . $prefix . '.json';
    $cached = is_file($cachePath) ? json_decode((string)file_get_contents($cachePath), true) : [];
    $pdo = getPDO(); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $preparer = new Course4TenkaiDeadlineCachePreparer(
        static function (string $ymd) use ($pdo): array {
            $stmt = $pdo->prepare('SELECT DISTINCT race_code FROM boat_race.race_entry WHERE race_code LIKE :prefix ORDER BY race_code');
            $stmt->execute([':prefix' => $ymd . '%']);
            return array_map(static fn($v): string => strtoupper(trim((string)$v)), $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        },
        static fn(string $ymd, array $venues): array => (new OfficialDeadlineLogic())->load($ymd, $venues, false),
    );
    // A complete cache needs no new official access, even after a prior temporary failure.
    $cachedResult = $preparer->prepare($prefix, is_array($cached) ? $cached : [], false);
    if (($cachedResult['status'] ?? '') === 'READY') {
        if (is_file($attemptPath)) @unlink($attemptPath);
        echo json_encode($cachedResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n"; exit(0);
    }
    if (!$decision['retry']) {
        $cachedResult['ok'] = !empty($cachedResult['ready_race_codes']);
        $cachedResult['status'] = $decision['status'];
        $cachedResult['retry_after'] = is_array($marker) ? ($marker['next_retry_at'] ?? null) : null;
        echo json_encode($cachedResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n"; exit($cachedResult['ok'] ? 0 : 1);
    }
    $result = $preparer->prepare($prefix, is_array($cached) ? $cached : []);
    $result['retry_decision'] = $decision['status'];
    if (($result['status'] ?? '') === 'READY') {
        if (is_file($attemptPath)) @unlink($attemptPath);
    } elseif (($result['status'] ?? '') !== 'NO_RACES') {
        $failureStatus = (string)($result['deadline_fetch_status'] ?? $result['status'] ?? 'UNKNOWN');
        $newMarker = Course4TenkaiDeadlineCachePreparer::failureMarker($prefix, is_array($marker) ? $marker : [], $failureStatus, $now);
        $tmp = $attemptPath . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($newMarker, JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) !== false) @rename($tmp, $attemptPath);
        $result['retry_after'] = $newMarker['next_retry_at'];
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n"; exit(!empty($result['ok']) ? 0 : 1);
} finally { flock($lock, LOCK_UN); fclose($lock); }
