<?php

declare(strict_types=1);

/**
 * Policy only: no network, database, or application entry point is invoked
 * from this class.  Production wiring remains deliberately disabled until the
 * operator has confirmed the official site's automated-access conditions.
 */
final class OfficialExhibitionSourceRouter
{
    public const OFFICIAL_START_DATE = '20261021';

    public function __construct(private readonly bool $officialAutomationApproved = false)
    {
    }

    /** @return array{entry:string,source:string,action:string,reason:string,fallback:?string,guard:string} */
    public function route(string $raceCode, string $entry): array
    {
        if (!in_array($entry, ['nightly', 'web', 'retry', 'strict_forward'], true)) {
            throw new InvalidArgumentException('unknown exhibition entry: ' . $entry);
        }
        if (!preg_match('/^(\d{8})([A-Z]{3})(0[1-9]|1[0-2])$/', $raceCode, $matches)) {
            throw new InvalidArgumentException('invalid race_code');
        }

        $isOfficialTarget = $matches[2] === 'AMG' && $matches[1] >= self::OFFICIAL_START_DATE;
        if (!$isOfficialTarget) {
            return [
                'entry' => $entry,
                'source' => 'kyoteibiyori',
                'action' => 'legacy_fetch',
                'reason' => 'LEGACY_ROUTE',
                'fallback' => null,
                'guard' => 'legacy_kyoteibiyori',
            ];
        }

        if (!$this->officialAutomationApproved) {
            return [
                'entry' => $entry,
                'source' => 'official_amagasaki',
                'action' => 'blocked',
                'reason' => 'OFFICIAL_AUTOMATION_NOT_APPROVED',
                'fallback' => null,
                'guard' => 'official_amagasaki',
            ];
        }

        return [
            'entry' => $entry,
            'source' => 'official_amagasaki',
            'action' => 'official_fetch',
            'reason' => 'OFFICIAL_REQUIRED',
            'fallback' => null,
            'guard' => 'official_amagasaki',
        ];
    }

    /**
     * Existing error_urls.txt entries are Kyoteibiyori URLs.  They remain
     * legacy retry records and are never reinterpreted as official requests.
     */
    public function legacyRetryUrlAllowed(string $url): bool
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $path = (string)parse_url($url, PHP_URL_PATH);
        return $host === 'kyoteibiyori.com' && $path === '/race_shusso.php';
    }
}

/** A separate, fail-closed state holder for the official source. */
final class OfficialAmagasakiFailureGuard
{
    public function __construct(
        private readonly string $stateFile,
        private readonly int $failureLimit = 2,
        private readonly int $cooldownSeconds = 1800,
    )
    {
    }

    /** @return array{allowed:bool,reason:string} */
    public function canAttempt(int $now): array
    {
        $handle = @fopen($this->stateFile, 'c+');
        if ($handle === false || !flock($handle, LOCK_SH)) {
            if (is_resource($handle)) fclose($handle);
            return ['allowed' => false, 'reason' => 'OFFICIAL_GUARD_UNAVAILABLE'];
        }
        try {
            rewind($handle);
            $raw = (string)stream_get_contents($handle);
            if (trim($raw) === '') $state = ['blocked_until' => 0];
            else $state = json_decode($raw, true);
            if (!is_array($state)) return ['allowed' => false, 'reason' => 'OFFICIAL_GUARD_CORRUPT'];
            if ((int)($state['blocked_until'] ?? 0) > $now) return ['allowed' => false, 'reason' => 'OFFICIAL_SOURCE_COOLDOWN'];
            return ['allowed' => true, 'reason' => ''];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @return array{recorded:bool,blocked_until:int,reason:string} */
    public function recordFailure(int $now): array
    {
        $handle = @fopen($this->stateFile, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) fclose($handle);
            return ['recorded' => false, 'blocked_until' => 0, 'reason' => 'OFFICIAL_GUARD_UNAVAILABLE'];
        }
        try {
            rewind($handle);
            $raw = (string)stream_get_contents($handle);
            $state = trim($raw) === '' ? [] : json_decode($raw, true);
            if (!is_array($state)) return ['recorded' => false, 'blocked_until' => 0, 'reason' => 'OFFICIAL_GUARD_CORRUPT'];
            $previousBlock = (int)($state['blocked_until'] ?? 0);
            $count = ($previousBlock > 0 && $previousBlock <= $now) ? 0 : (int)($state['failure_count'] ?? 0);
            $count++;
            $blockedUntil = $count >= $this->failureLimit ? $now + $this->cooldownSeconds : 0;
            $encoded = json_encode(['failure_count' => $count, 'last_failure_at' => $now, 'blocked_until' => $blockedUntil], JSON_THROW_ON_ERROR);
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, $encoded);
            fflush($handle);
            return ['recorded' => true, 'blocked_until' => $blockedUntil, 'reason' => ''];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function recordSuccess(int $now): bool
    {
        $handle = @fopen($this->stateFile, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) fclose($handle);
            return false;
        }
        try {
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode(['failure_count' => 0, 'last_success_at' => $now, 'blocked_until' => 0], JSON_THROW_ON_ERROR));
            fflush($handle);
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
