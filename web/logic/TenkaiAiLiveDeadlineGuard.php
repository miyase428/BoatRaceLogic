<?php

declare(strict_types=1);

final class TenkaiAiLiveDeadlineGuard
{
    public function __construct(private readonly ?string $deadlineRoot = null, private readonly ?Closure $clock = null)
    {
    }

    public function reason(string $raceCode): ?string
    {
        $root = $this->deadlineRoot ?? (rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/boatrace_official_deadlines');
        $path = $root . '/deadlines_' . substr($raceCode, 0, 8) . '.json';
        if (!is_file($path)) return 'OFFICIAL_DEADLINE_UNAVAILABLE';
        try { $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable) { return 'OFFICIAL_DEADLINE_UNAVAILABLE'; }
        $time = $data['deadlines'][$raceCode] ?? null;
        if (!is_string($time) || preg_match('/^\d{2}:\d{2}$/', $time) !== 1) return 'OFFICIAL_DEADLINE_UNAVAILABLE';
        $deadline = DateTimeImmutable::createFromFormat('!Ymd H:i', substr($raceCode, 0, 8) . ' ' . $time, new DateTimeZone('Asia/Tokyo'));
        if ($deadline === false) return 'OFFICIAL_DEADLINE_UNAVAILABLE';
        $now = $this->clock === null ? new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')) : ($this->clock)();
        if (!$now instanceof DateTimeImmutable) return 'OFFICIAL_DEADLINE_UNAVAILABLE';
        return $now->setTimezone(new DateTimeZone('Asia/Tokyo')) >= $deadline ? 'POSTDEADLINE_REFERENCE_REQUIRED' : null;
    }
}
