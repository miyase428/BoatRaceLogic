<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialExhibitionSourceRouter.php';
require_once __DIR__ . '/OfficialAmagasakiOperationalStateRepository.php';

/**
 * Separates a future AMG cut-over from the existing policy-only source router.
 *
 * The default is deliberately legacy: merely deploying this code cannot stop
 * the current Kyotei Biyori paths or cause a request to the official site.
 * An operator must set both independent switches before an AMG race is owned
 * by the official daytime worker.
 */
final class OfficialAmagasakiOperationalPolicy
{
    public function __construct(
        private readonly bool $routeEnabled,
        private readonly bool $automationApproved,
        private readonly ?int $retryIntervalSeconds = null,
        private readonly string $controlStatus = 'ok',
        private readonly bool $nightlyBackfillEnabled = false,
    ) {
    }

    public static function fromPdo(PDO $pdo): self
    {
        $state = (new PdoOfficialAmagasakiOperationalStateRepository($pdo))->load();
        return new self($state['route_enabled'], $state['automation_approved'], $state['retry_interval_seconds'], $state['status'], $state['nightly_backfill_enabled']);
    }

    /** @return array{mode:string,reason:string,source:string,entry:string} */
    public function decide(string $raceCode, string $entry): array
    {
        if (!preg_match('/^(\d{8})([A-Z]{3})(0[1-9]|1[0-2])$/', $raceCode, $matches)) {
            throw new InvalidArgumentException('invalid race_code');
        }
        if (!in_array($entry, ['nightly', 'nightly_backfill', 'web', 'manual', 'retry', 'strict_forward', 'daytime'], true)) {
            throw new InvalidArgumentException('unknown exhibition entry');
        }
        $target = $matches[2] === 'AMG' && $matches[1] >= OfficialExhibitionSourceRouter::OFFICIAL_START_DATE;
        if (!$target) {
            return ['mode' => 'legacy', 'reason' => 'LEGACY_ROUTE', 'source' => 'kyoteibiyori', 'entry' => $entry];
        }
        // Before migration the existing path remains untouched. Once the
        // shared table exists, a missing/corrupt control row is never treated
        // as permission to write a potentially official AMG race.
        if ($this->controlStatus === 'invalid') {
            return ['mode' => 'blocked', 'reason' => 'OFFICIAL_CONTROL_STATE_INVALID', 'source' => 'official_amagasaki', 'entry' => $entry];
        }
        if (!$this->routeEnabled) {
            if ($entry === 'manual') {
                return ['mode' => 'blocked', 'reason' => 'OFFICIAL_MANUAL_ROUTE_DISABLED', 'source' => 'official_amagasaki', 'entry' => $entry];
            }
            return ['mode' => 'legacy', 'reason' => !$target ? 'LEGACY_ROUTE' : 'OFFICIAL_ROUTE_DISABLED', 'source' => 'kyoteibiyori', 'entry' => $entry];
        }
        // A browser action is an explicit, one-race request.  It deliberately
        // does not share the scheduler's automation approval flag.
        if ($entry === 'manual') {
            return ['mode' => 'manual_official', 'reason' => 'OFFICIAL_MANUAL_REQUEST', 'source' => 'official_amagasaki', 'entry' => $entry];
        }
        if ($entry === 'nightly_backfill') {
            if (!$this->nightlyBackfillEnabled) {
                return ['mode' => 'blocked', 'reason' => 'OFFICIAL_NIGHTLY_BACKFILL_NOT_ENABLED', 'source' => 'kyoteibiyori_nightly', 'entry' => $entry];
            }
            return ['mode' => 'nightly_backfill', 'reason' => 'OFFICIAL_NIGHTLY_BACKFILL_OWNER', 'source' => 'kyoteibiyori_nightly', 'entry' => $entry];
        }
        if (!$this->automationApproved) {
            return ['mode' => 'blocked', 'reason' => 'OFFICIAL_AUTOMATION_NOT_APPROVED', 'source' => 'official_amagasaki', 'entry' => $entry];
        }
        return ['mode' => 'official', 'reason' => 'OFFICIAL_AMAGASAKI_OWNER', 'source' => 'official_amagasaki', 'entry' => $entry];
    }

    public function routeEnabled(): bool { return $this->routeEnabled; }
    public function automationApproved(): bool { return $this->automationApproved; }
    public function retryIntervalSeconds(): ?int { return $this->retryIntervalSeconds; }
    public function controlStatus(): string { return $this->controlStatus; }
    public function nightlyBackfillEnabled(): bool { return $this->nightlyBackfillEnabled; }
}
