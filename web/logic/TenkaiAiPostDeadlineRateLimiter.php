<?php

declare(strict_types=1);

final class TenkaiAiPostDeadlineLease
{
    /** @param list<resource> $handles */
    public function __construct(private array $handles)
    {
    }

    public function release(): void
    {
        foreach (array_reverse($this->handles) as $handle) {
            if (is_resource($handle)) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
        $this->handles = [];
    }

    public function __destruct()
    {
        $this->release();
    }
}

/** Cross-worker limiter for the read-only post-deadline inference process. */
final class TenkaiAiPostDeadlineRateLimiter
{
    public const GLOBAL_CONCURRENCY = 2;
    public const CLIENT_INTERVAL_SECONDS = 10.0;
    public const RACE_INTERVAL_SECONDS = 30.0;

    public function __construct(
        private readonly ?string $root = null,
        private readonly ?Closure $clock = null,
        private readonly int $globalConcurrency = self::GLOBAL_CONCURRENCY,
        private readonly float $clientIntervalSeconds = self::CLIENT_INTERVAL_SECONDS,
        private readonly float $raceIntervalSeconds = self::RACE_INTERVAL_SECONDS,
    ) {
    }

    /** @return array{ok:bool,lease?:TenkaiAiPostDeadlineLease,error_code?:string,retry_after?:int} */
    public function acquire(string $raceCode, string $remoteAddress): array
    {
        if ($this->globalConcurrency < 1 || $this->clientIntervalSeconds < 0 || $this->raceIntervalSeconds < 0
            || preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $raceCode) !== 1
            || filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
            return ['ok' => false, 'error_code' => 'REFERENCE_RATE_LIMIT_UNAVAILABLE', 'retry_after' => 0];
        }
        try {
            $root = $this->controlRoot();
            $global = $this->acquireGlobal($root);
            if ($global === null) return ['ok' => false, 'error_code' => 'REFERENCE_GLOBAL_BUSY', 'retry_after' => 2];
            $race = $this->openLock($root . '/race-' . hash('sha256', $raceCode) . '.lock');
            if (!flock($race, LOCK_EX | LOCK_NB)) {
                fclose($race); flock($global, LOCK_UN); fclose($global);
                return ['ok' => false, 'error_code' => 'REFERENCE_RACE_BUSY', 'retry_after' => 2];
            }
            $now = $this->now();
            $raceStatePath = $root . '/race-' . hash('sha256', $raceCode) . '.json';
            $raceLast = $this->readLastStartedAt($raceStatePath);
            $raceRemaining = $raceLast === null ? 0.0 : $this->raceIntervalSeconds - ($now - $raceLast);
            if ($raceRemaining > 0.0) {
                flock($race, LOCK_UN); fclose($race);
                flock($global, LOCK_UN); fclose($global);
                return ['ok' => false, 'error_code' => 'REFERENCE_RACE_RATE_LIMITED', 'retry_after' => max(1, (int)ceil($raceRemaining))];
            }
            $clientKey = hash('sha256', $remoteAddress);
            $clientLock = $this->openLock($root . '/client-' . $clientKey . '.lock');
            if (!flock($clientLock, LOCK_EX)) throw new RuntimeException('CLIENT_LOCK_FAILED');
            $statePath = $root . '/client-' . $clientKey . '.json';
            $last = $this->readLastStartedAt($statePath);
            $remaining = $last === null ? 0.0 : $this->clientIntervalSeconds - ($now - $last);
            if ($remaining > 0.0) {
                flock($clientLock, LOCK_UN); fclose($clientLock);
                flock($race, LOCK_UN); fclose($race);
                flock($global, LOCK_UN); fclose($global);
                return ['ok' => false, 'error_code' => 'REFERENCE_CLIENT_RATE_LIMITED', 'retry_after' => max(1, (int)ceil($remaining))];
            }
            $this->writeStartedAt($statePath, $now);
            $this->writeStartedAt($raceStatePath, $now);
            flock($clientLock, LOCK_UN); fclose($clientLock);
            return ['ok' => true, 'lease' => new TenkaiAiPostDeadlineLease([$global, $race])];
        } catch (Throwable) {
            if (isset($clientLock) && is_resource($clientLock)) { @flock($clientLock, LOCK_UN); @fclose($clientLock); }
            if (isset($race) && is_resource($race)) { @flock($race, LOCK_UN); @fclose($race); }
            if (isset($global) && is_resource($global)) { @flock($global, LOCK_UN); @fclose($global); }
            return ['ok' => false, 'error_code' => 'REFERENCE_RATE_LIMIT_UNAVAILABLE', 'retry_after' => 0];
        }
    }

    private function controlRoot(): string
    {
        $uid = function_exists('posix_geteuid') ? (int)posix_geteuid() : (int)getmyuid();
        $root = $this->root ?? (rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/boatrace-tenkai-postdeadline-' . $uid);
        if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) throw new RuntimeException('CONTROL_DIR_CREATE_FAILED');
        clearstatcache(true, $root);
        if (is_link($root) || !is_dir($root) || ((fileperms($root) & 0777) !== 0700)) throw new RuntimeException('CONTROL_DIR_UNSAFE');
        if (function_exists('posix_geteuid') && fileowner($root) !== posix_geteuid()) throw new RuntimeException('CONTROL_DIR_OWNER_MISMATCH');
        return $root;
    }

    /** @return resource|null */
    private function acquireGlobal(string $root)
    {
        for ($slot = 0; $slot < $this->globalConcurrency; $slot++) {
            $handle = $this->openLock($root . '/global-' . $slot . '.lock');
            if (flock($handle, LOCK_EX | LOCK_NB)) return $handle;
            fclose($handle);
        }
        return null;
    }

    /** @return resource */
    private function openLock(string $path)
    {
        $handle = fopen($path, 'c+');
        if ($handle === false || !chmod($path, 0600)) throw new RuntimeException('LOCK_OPEN_FAILED');
        return $handle;
    }

    private function readLastStartedAt(string $path): ?float
    {
        if (!is_file($path)) return null;
        try { $value = json_decode((string)file_get_contents($path), true, 8, JSON_THROW_ON_ERROR); }
        catch (Throwable) { throw new RuntimeException('CLIENT_STATE_INVALID'); }
        $last = $value['last_started_at'] ?? null;
        if (!is_numeric($last) || !is_finite((float)$last) || (float)$last < 0) throw new RuntimeException('CLIENT_STATE_INVALID');
        return (float)$last;
    }

    private function writeStartedAt(string $path, float $now): void
    {
        $encoded = json_encode(['last_started_at' => $now], JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $encoded, LOCK_EX) === false || !chmod($path, 0600)) throw new RuntimeException('STATE_WRITE_FAILED');
    }

    private function now(): float
    {
        $value = $this->clock === null ? microtime(true) : ($this->clock)();
        if (!is_numeric($value) || !is_finite((float)$value) || (float)$value < 0) throw new RuntimeException('CLOCK_INVALID');
        return (float)$value;
    }
}
