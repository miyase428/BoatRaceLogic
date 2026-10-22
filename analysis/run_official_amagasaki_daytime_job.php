<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../logic/OfficialAmagasakiDaytimeJob.php';
require_once __DIR__ . '/../logic/NodeOfficialAmagasakiPayloadFetcher.php';

final class IsolatedFixtureAmagasakiPayloadFetcher implements OfficialAmagasakiPayloadFetcher
{
    /** @param array<string,mixed> $result */
    public function __construct(private readonly array $result) {}
    public function fetch(string $raceCode): array { return $this->result; }
}

$date = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Ymd');
$dryRun = true;
$execute = false;
$fixturePath = null;
$nowOverride = null;
$captureDiagnosticHtml = false;
$diagnosticRetentionSeconds = 86400;
$deadlineCacheDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/boatrace_official_deadlines';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--date=')) $date = substr($arg, 7);
    elseif ($arg === '--dry-run') $dryRun = true;
    elseif ($arg === '--execute') { $dryRun = false; $execute = true; }
    elseif (str_starts_with($arg, '--fixture-fetch-json=')) $fixturePath = substr($arg, strlen('--fixture-fetch-json='));
    elseif (str_starts_with($arg, '--now=')) $nowOverride = substr($arg, strlen('--now='));
    elseif (str_starts_with($arg, '--deadline-cache-dir=')) $deadlineCacheDirectory = substr($arg, strlen('--deadline-cache-dir='));
    elseif ($arg === '--capture-diagnostic-html') $captureDiagnosticHtml = true;
    elseif (str_starts_with($arg, '--diagnostic-retention-seconds=')) $diagnosticRetentionSeconds = (int)substr($arg, strlen('--diagnostic-retention-seconds='));
    else { fwrite(STDERR, "Usage: php analysis/run_official_amagasaki_daytime_job.php [--date=YYYYMMDD] [--dry-run|--execute]\n"); exit(2); }
}
if (!preg_match('/^\d{8}$/', $date)) { fwrite(STDERR, "DATE_INVALID\n"); exit(2); }
if ($diagnosticRetentionSeconds < 60 || $diagnosticRetentionSeconds > 604800) { fwrite(STDERR, "DIAGNOSTIC_RETENTION_INVALID\n"); exit(2); }
if ($captureDiagnosticHtml && !$execute) { fwrite(STDERR, "DIAGNOSTIC_CAPTURE_REQUIRES_EXECUTE\n"); exit(2); }
if (($fixturePath !== null || $nowOverride !== null || $deadlineCacheDirectory !== rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/boatrace_official_deadlines') && getenv('APP_ENV') !== 'isolated_test') {
    fwrite(STDERR, "TEST_HOOK_REQUIRES_ISOLATED_TEST_ENVIRONMENT\n");
    exit(2);
}
if (getenv('APP_ENV') === 'isolated_test') {
    $deadlineCacheDirectory = realpath($deadlineCacheDirectory) ?: $deadlineCacheDirectory;
    $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!str_starts_with($deadlineCacheDirectory, $tmp)) { fwrite(STDERR, "TEST_CACHE_PATH_INVALID\n"); exit(2); }
}
if ($fixturePath !== null) {
    $fixturePath = realpath($fixturePath);
    $tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (!is_string($fixturePath) || !str_starts_with($fixturePath, $tmp)) { fwrite(STDERR, "TEST_FIXTURE_PATH_INVALID\n"); exit(2); }
    $fixture = json_decode((string)file_get_contents($fixturePath), true);
    if (!is_array($fixture) || !in_array($fixture['outcome'] ?? null, ['published', 'not_yet_published', 'http_restricted', 'server_error', 'parse_error'], true)) { fwrite(STDERR, "TEST_FIXTURE_INVALID\n"); exit(2); }
}
try {
    $now = $nowOverride === null ? new DateTimeImmutable('now', new DateTimeZone('UTC')) : new DateTimeImmutable($nowOverride);
    $now = $now->setTimezone(new DateTimeZone('UTC'));
} catch (Throwable) { fwrite(STDERR, "NOW_INVALID\n"); exit(2); }
try {
    $pdo = getPDO();
    $policy = OfficialAmagasakiOperationalPolicy::fromPdo($pdo);
    $mode = $policy->decide($date . 'AMG01', 'daytime')['mode'];
    if ($execute && $mode !== 'official') {
        fwrite(STDERR, "OFFICIAL_EXECUTION_REQUIRES_SHARED_ROUTE_AND_APPROVAL\n");
        exit(3);
    }
    $attempts = new PdoOfficialAmagasakiAttemptRepository($pdo);
    $validator = new OfficialAmagasakiExhibitionValidator();
    $flow = new OfficialAmagasakiDaytimeFlow(new OfficialExhibitionSourceRouter(true), $validator, 120, $policy->retryIntervalSeconds());
    $fetcher = $fixturePath === null
        ? new NodeOfficialAmagasakiPayloadFetcher('/usr/bin/node', __DIR__ . '/../playwright/official_exhibition_amagasaki.js', $captureDiagnosticHtml, $diagnosticRetentionSeconds)
        : new IsolatedFixtureAmagasakiPayloadFetcher($fixture);
    $integration = new OfficialAmagasakiDaytimeIntegration(
        $flow,
        $attempts,
        $fetcher,
        new PdoOfficialAmagasakiClaimedPersistence($pdo, $validator, $attempts, $policy->retryIntervalSeconds()),
        $fixturePath === null ? null : static fn() => $now->modify('+1 second'),
    );
    $job = new OfficialAmagasakiDaytimeJob($policy, new PdoOfficialAmagasakiDaytimeCandidateSource($pdo, $deadlineCacheDirectory), $integration);
    $events = $job->run($date, $now, $dryRun);
    echo json_encode(['date' => $date, 'dry_run' => $dryRun, 'events' => $events], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'OFFICIAL_AMAGASAKI_DAYTIME_JOB_FAILED:' . get_class($error) . ':' . $error->getMessage() . PHP_EOL);
    exit(1);
}
