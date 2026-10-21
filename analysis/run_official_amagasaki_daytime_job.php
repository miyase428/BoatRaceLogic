<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../logic/OfficialAmagasakiDaytimeJob.php';
require_once __DIR__ . '/../logic/NodeOfficialAmagasakiPayloadFetcher.php';

$date = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Ymd');
$dryRun = true;
$execute = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--date=')) $date = substr($arg, 7);
    elseif ($arg === '--dry-run') $dryRun = true;
    elseif ($arg === '--execute') { $dryRun = false; $execute = true; }
    else { fwrite(STDERR, "Usage: php analysis/run_official_amagasaki_daytime_job.php [--date=YYYYMMDD] [--dry-run|--execute]\n"); exit(2); }
}
if (!preg_match('/^\d{8}$/', $date)) { fwrite(STDERR, "DATE_INVALID\n"); exit(2); }
$policy = OfficialAmagasakiOperationalPolicy::fromEnvironment();
if ($execute && (!$policy->routeEnabled() || !$policy->automationApproved())) {
    fwrite(STDERR, "OFFICIAL_EXECUTION_REQUIRES_ROUTE_AND_APPROVAL\n");
    exit(3);
}
// A default dry-run is intentionally usable without DB credentials. It proves
// that deployment alone leaves the existing routes untouched and cannot start
// an official request.
if ($dryRun && $policy->decide($date . 'AMG01', 'daytime')['mode'] === 'legacy') {
    echo json_encode(['date' => $date, 'dry_run' => true, 'events' => [['action' => 'skip', 'reason' => 'OFFICIAL_ROUTE_DISABLED', 'dry_run' => true]]], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}
try {
    $pdo = getPDO();
    $attempts = new PdoOfficialAmagasakiAttemptRepository($pdo);
    $validator = new OfficialAmagasakiExhibitionValidator();
    $flow = new OfficialAmagasakiDaytimeFlow(new OfficialExhibitionSourceRouter(true), $validator);
    $integration = new OfficialAmagasakiDaytimeIntegration(
        $flow,
        $attempts,
        new NodeOfficialAmagasakiPayloadFetcher('/usr/bin/node', __DIR__ . '/../playwright/official_exhibition_amagasaki.js'),
        new PdoOfficialAmagasakiClaimedPersistence($pdo, $validator, $attempts),
    );
    $job = new OfficialAmagasakiDaytimeJob($policy, new PdoOfficialAmagasakiDaytimeCandidateSource($pdo, rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/boatrace_official_deadlines'), $integration);
    $events = $job->run($date, new DateTimeImmutable('now', new DateTimeZone('UTC')), $dryRun);
    echo json_encode(['date' => $date, 'dry_run' => $dryRun, 'events' => $events], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'OFFICIAL_AMAGASAKI_DAYTIME_JOB_FAILED:' . get_class($error) . ':' . $error->getMessage() . PHP_EOL);
    exit(1);
}
