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
$attemptPath = $cacheDir . '/static_snapshot_deadline_prepare_' . $prefix . '.json';
if (is_file($attemptPath)) {
    $prior = json_decode((string)file_get_contents($attemptPath), true);
    if (is_array($prior)) { echo json_encode(['ok'=>false, 'status'=>'PREPARATION_ALREADY_ATTEMPTED', 'prior_status'=>$prior['status'] ?? 'UNKNOWN'], JSON_UNESCAPED_UNICODE), "\n"; exit(1); }
}
$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$preparer = new Course4TenkaiDeadlineCachePreparer(
    static function (string $prefix) use ($pdo): array {
        $stmt = $pdo->prepare('SELECT DISTINCT race_code FROM boat_race.race_entry WHERE race_code LIKE :prefix ORDER BY race_code');
        $stmt->execute([':prefix' => $prefix . '%']);
        return array_map(static fn($v): string => strtoupper(trim((string)$v)), $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    },
    static fn(string $prefix, array $venues): array => (new OfficialDeadlineLogic())->load($prefix, $venues, false),
);
$result = $preparer->prepare($prefix);
if (($result['status'] ?? '') !== 'NO_RACES' && !is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
$marker = json_encode(['date'=>$prefix, 'attempted_at'=>date(DATE_ATOM), 'status'=>$result['status'] ?? 'UNKNOWN'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (($result['status'] ?? '') !== 'NO_RACES' && is_string($marker)) { $tmp = $attemptPath . '.' . getmypid() . '.tmp'; if (@file_put_contents($tmp, $marker . "\n", LOCK_EX) !== false) @rename($tmp, $attemptPath); }
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
exit(!empty($result['ok']) ? 0 : 1);
