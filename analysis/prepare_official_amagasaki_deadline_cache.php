<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../logic/OfficialAmagasakiOperationalPolicy.php';
require_once __DIR__ . '/../logic/OfficialAmagasakiDeadlineCache.php';
require_once __DIR__ . '/../web/logic/OfficialDeadlineLogic.php';

$date = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Ymd');
$execute = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--date=')) $date = substr($argument, 7);
    elseif ($argument === '--execute') $execute = true;
    elseif ($argument === '--dry-run') $execute = false;
    else { fwrite(STDERR, "Usage: php analysis/prepare_official_amagasaki_deadline_cache.php [--date=YYYYMMDD] [--dry-run|--execute]\n"); exit(2); }
}
if (preg_match('/^\d{8}$/', $date) !== 1) { fwrite(STDERR, "DATE_INVALID\n"); exit(2); }

try {
    $pdo = getPDO();
    $policy = OfficialAmagasakiOperationalPolicy::fromPdo($pdo);
    $approved = $policy->decide($date . 'AMG01', 'daytime')['mode'] === 'official';
    $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/boatrace_official_deadlines';
    $preparer = new OfficialAmagasakiDeadlineCachePreparer(
        $directory,
        static function (string $requestedDate): array { return (new OfficialDeadlineLogic())->load($requestedDate, ['AMG'], false); }
    );
    $result = $preparer->prepare($date, $execute, $approved);
    echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($result['status'] === 'blocked' ? 3 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, 'OFFICIAL_AMAGASAKI_DEADLINE_PREP_FAILED:' . $error->getMessage() . PHP_EOL);
    exit(1);
}
