<?php
declare(strict_types=1);

final class Course4TenkaiDeadlineCachePreparer
{
    /** @param callable(string):list<string> $raceCodes @param callable(string,list<string>):array<string,mixed> $deadlineLoader */
    public function __construct(private readonly Closure $raceCodes, private readonly Closure $deadlineLoader) {}

    /**
     * Validates only races declared in race_entry.  A venue is safe for snapshots
     * only when its declared twelve races have official HH:MM cutoffs.
     *
     * @param array<string,mixed> $cached
     * @return array<string,mixed>
     */
    public function prepare(string $date, array $cached = [], bool $loadMissing = true): array
    {
        $prefix = preg_replace('/\D/', '', trim($date)) ?? '';
        if (!preg_match('/^\d{8}$/', $prefix)) return ['ok'=>false, 'status'=>'INVALID_DATE'];
        $codes = array_values(array_unique(array_filter(($this->raceCodes)($prefix), static fn($code): bool => is_string($code) && preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $code) === 1)));
        sort($codes, SORT_STRING);
        if ($codes === []) return ['ok'=>true, 'status'=>'NO_RACES', 'date'=>$prefix, 'race_count'=>0, 'venue_count'=>0, 'ready_race_codes'=>[]];

        $venueCodes = [];
        foreach ($codes as $code) $venueCodes[substr($code, 8, 3)][] = $code;
        ksort($venueCodes, SORT_STRING);
        foreach ($venueCodes as $venue => $venueRaceCodes) {
            $expected = array_map(static fn(int $n): string => $prefix . $venue . sprintf('%02d', $n), range(1, 12));
            sort($venueRaceCodes, SORT_STRING);
            if ($venueRaceCodes !== $expected) {
                return ['ok'=>false, 'status'=>'RACE_ENTRY_INCOMPLETE', 'date'=>$prefix, 'venue'=>$venue, 'declared_race_codes'=>$venueRaceCodes, 'expected_race_codes'=>$expected];
            }
        }

        $deadlines = $this->deadlineMap($cached, $prefix);
        $missingByVenue = $this->missingByVenue($venueCodes, $deadlines);
        $requestedVenues = array_keys($missingByVenue);
        $loaded = ['deadlines'=>[], 'fetched_places'=>[], 'cache_places'=>[]];
        if ($requestedVenues !== [] && $loadMissing) {
            try { $loaded = ($this->deadlineLoader)($prefix, $requestedVenues); }
            catch (Throwable $error) {
                $result = $this->result($prefix, $venueCodes, $deadlines, $requestedVenues, [], [], null, get_class($error));
                $result['deadline_fetch_status'] = 'OFFICIAL_DEADLINE_UNAVAILABLE';
                return $result;
            }
            if (!is_array($loaded)) {
                $result = $this->result($prefix, $venueCodes, $deadlines, $requestedVenues, [], [], null, 'INVALID_LOADER_RESULT');
                $result['deadline_fetch_status'] = 'OFFICIAL_DEADLINE_UNAVAILABLE';
                return $result;
            }
            $deadlines += $this->deadlineMap($loaded, $prefix);
        }
        return $this->result(
            $prefix,
            $venueCodes,
            $deadlines,
            $requestedVenues,
            array_values(array_filter($loaded['fetched_places'] ?? [], 'is_string')),
            array_values(array_filter($loaded['cache_places'] ?? [], 'is_string')),
            null,
            null,
        );
    }

    /** @param array<string,mixed> $payload @return array<string,string> */
    private function deadlineMap(array $payload, string $prefix): array
    {
        $out = [];
        foreach (($payload['deadlines'] ?? []) as $code => $time) {
            $code = strtoupper(trim((string)$code));
            if (str_starts_with($code, $prefix) && preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $code) === 1 && is_string($time) && preg_match('/^\d{2}:\d{2}$/', $time) === 1) $out[$code] = $time;
        }
        return $out;
    }

    /** @param array<string,list<string>> $venueCodes @param array<string,string> $deadlines @return array<string,list<string>> */
    private function missingByVenue(array $venueCodes, array $deadlines): array
    {
        $out = [];
        foreach ($venueCodes as $venue => $codes) {
            $missing = array_values(array_filter($codes, static fn(string $code): bool => !isset($deadlines[$code])));
            if ($missing !== []) $out[$venue] = $missing;
        }
        return $out;
    }

    /** @param array<string,list<string>> $venueCodes @param array<string,string> $deadlines @param list<string> $requested @param list<string> $fetched @param list<string> $cached @return array<string,mixed> */
    private function result(string $prefix, array $venueCodes, array $deadlines, array $requested, array $fetched, array $cached, ?string $forcedStatus, ?string $reason): array
    {
        $missing = $this->missingByVenue($venueCodes, $deadlines);
        $ready = [];
        foreach ($venueCodes as $venue => $codes) if (!isset($missing[$venue])) array_push($ready, ...$codes);
        sort($ready, SORT_STRING);
        $status = $forcedStatus ?? ($missing === [] ? 'READY' : ($ready === [] ? 'OFFICIAL_DEADLINE_INCOMPLETE' : 'PARTIAL_READY'));
        return [
            'ok'=>$status === 'READY' || $status === 'PARTIAL_READY', 'status'=>$status, 'date'=>$prefix,
            'race_count'=>array_sum(array_map('count', $venueCodes)), 'venue_count'=>count($venueCodes),
            'ready_race_codes'=>$ready, 'missing_race_codes'=>array_values(array_merge(...array_values($missing ?: [[]]))),
            'requested_venues'=>$requested, 'fetched_places'=>array_values(array_unique($fetched)), 'cache_places'=>array_values(array_unique($cached)),
            'reason'=>$reason,
        ];
    }

    /** @param array<string,mixed>|null $marker @return array{retry:bool,status:string} */
    public static function retryDecision(?array $marker, DateTimeImmutable $now, int $intervalSeconds = 2700, int $maxAttempts = 8): array
    {
        if (!is_array($marker)) return ['retry'=>true, 'status'=>'RETRY_ALLOWED'];
        if ((int)($marker['attempt_count'] ?? 0) >= $maxAttempts) return ['retry'=>false, 'status'=>'RETRY_LIMIT_REACHED'];
        $next = strtotime((string)($marker['next_retry_at'] ?? ''));
        if ($next !== false && $now->getTimestamp() < $next) return ['retry'=>false, 'status'=>'RETRY_WAIT'];
        return ['retry'=>true, 'status'=>'RETRY_ALLOWED'];
    }

    /** @return array<string,mixed> */
    public static function failureMarker(string $date, array $prior, string $status, DateTimeImmutable $now, int $intervalSeconds = 2700): array
    {
        return ['date'=>$date, 'attempt_count'=>max(0, (int)($prior['attempt_count'] ?? 0)) + 1, 'last_attempt_at'=>$now->format(DATE_ATOM), 'next_retry_at'=>$now->modify('+' . $intervalSeconds . ' seconds')->format(DATE_ATOM), 'last_status'=>$status];
    }
}
