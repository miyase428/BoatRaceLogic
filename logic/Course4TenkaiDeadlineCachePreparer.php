<?php
declare(strict_types=1);

final class Course4TenkaiDeadlineCachePreparer
{
    /** @param callable(string):list<string> $raceCodes @param callable(string,list<string>):array<string,mixed> $deadlineLoader */
    public function __construct(private readonly Closure $raceCodes, private readonly Closure $deadlineLoader) {}

    /** @return array<string,mixed> */
    public function prepare(string $date): array
    {
        $prefix = preg_replace('/\D/', '', trim($date)) ?? '';
        if (!preg_match('/^\d{8}$/', $prefix)) return ['ok'=>false, 'status'=>'INVALID_DATE'];
        $codes = array_values(array_unique(array_filter(($this->raceCodes)($prefix), static fn($code): bool => is_string($code) && preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $code) === 1)));
        sort($codes, SORT_STRING);
        if ($codes === []) return ['ok'=>true, 'status'=>'NO_RACES', 'date'=>$prefix, 'race_count'=>0, 'venue_count'=>0];
        $venues = array_values(array_unique(array_map(static fn(string $code): string => substr($code, 8, 3), $codes)));
        sort($venues, SORT_STRING);
        try { $loaded = ($this->deadlineLoader)($prefix, $venues); }
        catch (Throwable $error) { return ['ok'=>false, 'status'=>'OFFICIAL_DEADLINE_UNAVAILABLE', 'reason'=>get_class($error), 'date'=>$prefix]; }
        $deadlines = is_array($loaded['deadlines'] ?? null) ? $loaded['deadlines'] : [];
        foreach ($codes as $code) {
            if (!is_string($deadlines[$code] ?? null) || preg_match('/^\d{2}:\d{2}$/', $deadlines[$code]) !== 1) {
                return ['ok'=>false, 'status'=>'OFFICIAL_DEADLINE_INCOMPLETE', 'date'=>$prefix, 'race_count'=>count($codes), 'venue_count'=>count($venues), 'missing_race_code'=>$code];
            }
        }
        return ['ok'=>true, 'status'=>'READY', 'date'=>$prefix, 'race_count'=>count($codes), 'venue_count'=>count($venues), 'fetched_places'=>$loaded['fetched_places'] ?? [], 'cache_places'=>$loaded['cache_places'] ?? []];
    }
}
