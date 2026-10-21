<?php

declare(strict_types=1);

/**
 * Validates and prepares the single AMG deadline cache consumed by the
 * daytime worker.  It never fetches by itself: the caller must explicitly
 * supply an approved loader.
 */
final class OfficialAmagasakiDeadlineCache
{
    public static function path(string $directory, string $date): string
    {
        if (preg_match('/^\d{8}$/', $date) !== 1) throw new InvalidArgumentException('DATE_INVALID');
        return rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'deadlines_' . $date . '.json';
    }

    /** @return array<string,mixed>|null */
    public static function readComplete(string $directory, string $date): ?array
    {
        $raw = @file_get_contents(self::path($directory, $date));
        $value = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($value) || ($value['date'] ?? null) !== $date || !is_array($value['deadlines'] ?? null)) return null;
        for ($race = 1; $race <= 12; $race++) {
            $code = $date . 'AMG' . sprintf('%02d', $race);
            if (!is_string($value['deadlines'][$code] ?? null) || preg_match('/^\d{2}:\d{2}$/', $value['deadlines'][$code]) !== 1) return null;
        }
        return $value;
    }
}

final class OfficialAmagasakiDeadlineCachePreparer
{
    /** @param callable(string):array<string,mixed> $loader */
    public function __construct(private readonly string $directory, private readonly Closure $loader)
    {
    }

    /** @return array{status:string,reason:string,path:string} */
    public function prepare(string $date, bool $execute, bool $approved): array
    {
        $path = OfficialAmagasakiDeadlineCache::path($this->directory, $date);
        if (OfficialAmagasakiDeadlineCache::readComplete($this->directory, $date) !== null) {
            return ['status' => 'ready', 'reason' => 'CACHE_COMPLETE', 'path' => $path];
        }
        if (!$execute) return ['status' => 'dry_run', 'reason' => 'CACHE_MISSING', 'path' => $path];
        if (!$approved) return ['status' => 'blocked', 'reason' => 'OFFICIAL_DEADLINE_FETCH_NOT_APPROVED', 'path' => $path];
        ($this->loader)($date);
        if (OfficialAmagasakiDeadlineCache::readComplete($this->directory, $date) === null) {
            return ['status' => 'blocked', 'reason' => 'OFFICIAL_DEADLINE_CACHE_INCOMPLETE', 'path' => $path];
        }
        return ['status' => 'prepared', 'reason' => 'CACHE_COMPLETE', 'path' => $path];
    }
}
