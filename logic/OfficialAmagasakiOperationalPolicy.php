<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialExhibitionSourceRouter.php';

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
    public const ROUTE_ENABLED_ENV = 'OFFICIAL_AMAGASAKI_ROUTE_ENABLED';
    public const AUTOMATION_APPROVED_ENV = 'OFFICIAL_AMAGASAKI_AUTOMATION_APPROVED';

    public function __construct(
        private readonly bool $routeEnabled,
        private readonly bool $automationApproved,
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(self::environmentFlag(self::ROUTE_ENABLED_ENV), self::environmentFlag(self::AUTOMATION_APPROVED_ENV));
    }

    /** @return array{mode:string,reason:string,source:string,entry:string} */
    public function decide(string $raceCode, string $entry): array
    {
        if (!preg_match('/^(\d{8})([A-Z]{3})(0[1-9]|1[0-2])$/', $raceCode, $matches)) {
            throw new InvalidArgumentException('invalid race_code');
        }
        if (!in_array($entry, ['nightly', 'web', 'retry', 'strict_forward', 'daytime'], true)) {
            throw new InvalidArgumentException('unknown exhibition entry');
        }
        $target = $matches[2] === 'AMG' && $matches[1] >= OfficialExhibitionSourceRouter::OFFICIAL_START_DATE;
        if (!$target || !$this->routeEnabled) {
            return ['mode' => 'legacy', 'reason' => !$target ? 'LEGACY_ROUTE' : 'OFFICIAL_ROUTE_DISABLED', 'source' => 'kyoteibiyori', 'entry' => $entry];
        }
        if (!$this->automationApproved) {
            return ['mode' => 'blocked', 'reason' => 'OFFICIAL_AUTOMATION_NOT_APPROVED', 'source' => 'official_amagasaki', 'entry' => $entry];
        }
        return ['mode' => 'official', 'reason' => 'OFFICIAL_AMAGASAKI_OWNER', 'source' => 'official_amagasaki', 'entry' => $entry];
    }

    public function routeEnabled(): bool { return $this->routeEnabled; }
    public function automationApproved(): bool { return $this->automationApproved; }

    private static function environmentFlag(string $name): bool
    {
        return filter_var((string)getenv($name), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
    }
}
