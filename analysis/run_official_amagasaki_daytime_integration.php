<?php

declare(strict_types=1);

require_once __DIR__ . '/../logic/OfficialAmagasakiDaytimeIntegration.php';

final class DryRunAttemptRepository implements OfficialAmagasakiAttemptRepository
{
    public function load(string $raceCode): ?array { throw new RuntimeException('dry-run must not read attempt persistence'); }
    public function claim(string $raceCode, int $expectedVersion, array $state): bool { throw new RuntimeException('dry-run must not claim'); }
    public function complete(array $before, string $claimToken, array $after): bool { throw new RuntimeException('dry-run must not complete'); }
}
final class ForbiddenDaytimeFetcher implements OfficialAmagasakiPayloadFetcher
{
    public function fetch(string $raceCode): array { throw new RuntimeException('official fetch is disabled'); }
}
final class ForbiddenDaytimePersistence implements OfficialAmagasakiClaimedPersistence
{
    public function persistAndComplete(array $payload, string $raceCode, array $raceEntries, array $claimedState, string $claimToken, DateTimeImmutable $finishedAt): array { throw new RuntimeException('database persistence is disabled'); }
}

$planPath = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--plan-json=')) $planPath = substr($argument, strlen('--plan-json='));
    elseif ($argument !== '--dry-run') throw new InvalidArgumentException('only --dry-run --plan-json=FILE is available until production approval');
}
if (!in_array('--dry-run', $argv, true) || $planPath === null || !is_file($planPath)) {
    fwrite(STDERR, "Usage: php analysis/run_official_amagasaki_daytime_integration.php --dry-run --plan-json=FILE\n");
    exit(2);
}
$candidates = json_decode((string)file_get_contents($planPath), true);
if (!is_array($candidates)) {
    fwrite(STDERR, "PLAN_JSON_INVALID\n");
    exit(2);
}
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$flow = new OfficialAmagasakiDaytimeFlow(new OfficialExhibitionSourceRouter(false), new OfficialAmagasakiExhibitionValidator());
$runner = new OfficialAmagasakiDaytimeIntegration($flow, new DryRunAttemptRepository(), new ForbiddenDaytimeFetcher(), new ForbiddenDaytimePersistence());
echo json_encode($runner->run($candidates, $now, true), JSON_UNESCAPED_SLASHES) . PHP_EOL;
