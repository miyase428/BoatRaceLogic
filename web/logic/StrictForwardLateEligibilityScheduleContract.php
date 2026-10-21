<?php

declare(strict_types=1);

/**
 * Immutable schedule contract for the late DB-only eligibility observer.
 *
 * The class deliberately never reads or edits the installed crontab.  Its
 * attestation input is supplied by a caller, making live inspection
 * read-only and fixtures deterministic.
 */
final class StrictForwardLateEligibilityScheduleContract
{
    public const CONTRACT_VERSION = 'cc30c-late-eligibility-schedule-contract/v1';
    public const CONTRACT_RELATIVE_PATH = 'analysis/output/cc30c_late_eligibility_schedule_contract_v1.json';
    public const MARKER = 'BOATRACE_STRICT_FORWARD_LATE_ELIGIBILITY';

    /** @return array{accepted:bool,status:string,reason:string,contract:array<string,mixed>,contract_sha256:string} */
    public static function load(string $repoRoot): array
    {
        $path = rtrim($repoRoot, DIRECTORY_SEPARATOR) . '/' . self::CONTRACT_RELATIVE_PATH;
        $empty = ['accepted' => false, 'status' => 'LATE_SCHEDULE_CONTRACT_BLOCKED', 'reason' => '', 'contract' => [], 'contract_sha256' => ''];
        $bytes = @file_get_contents($path);
        if (!is_string($bytes)) { $empty['reason'] = 'SCHEDULE_CONTRACT_MISSING'; return $empty; }
        try { $contract = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable) { $empty['reason'] = 'SCHEDULE_CONTRACT_INVALID_JSON'; return $empty; }
        if (!is_array($contract) || !self::valid($contract, $repoRoot)) { $empty['reason'] = 'SCHEDULE_CONTRACT_INVALID'; return $empty; }
        return ['accepted' => true, 'status' => 'LATE_SCHEDULE_CONTRACT_VALID', 'reason' => '', 'contract' => $contract, 'contract_sha256' => 'sha256:' . hash('sha256', $bytes)];
    }

    /**
     * Checks a supplied crontab snapshot. No system command is issued here.
     * @return array{accepted:bool,status:string,reason:string,contract_sha256:string,matching_line:string}
     */
    public static function attestCrontabText(string $repoRoot, string $crontabText): array
    {
        $loaded = self::load($repoRoot);
        $empty = ['accepted' => false, 'status' => 'LATE_SCHEDULE_ATTESTATION_BLOCKED', 'reason' => '', 'contract_sha256' => (string)$loaded['contract_sha256'], 'matching_line' => ''];
        if (!$loaded['accepted']) { $empty['reason'] = (string)$loaded['reason']; return $empty; }
        $contract = $loaded['contract'];
        $runner = rtrim($repoRoot, DIRECTORY_SEPARATOR) . '/' . (string)$contract['runner']['repo_relative_path'];
        $expected = (string)$contract['cron_expression'] . ' ' . (string)$contract['runner']['command_prefix'] . ' ' . $runner . ' # ' . (string)$contract['runner']['marker'];
        $matches = [];
        foreach (preg_split('/\R/', $crontabText) ?: [] as $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) continue;
            if (str_contains($line, self::MARKER)) $matches[] = trim($line);
        }
        if (count($matches) !== 1) { $empty['reason'] = count($matches) === 0 ? 'SCHEDULE_MARKER_MISSING' : 'SCHEDULE_MARKER_DUPLICATE'; return $empty; }
        if (!hash_equals($expected, $matches[0])) { $empty['reason'] = 'SCHEDULE_CONTRACT_MISMATCH'; $empty['matching_line'] = $matches[0]; return $empty; }
        return ['accepted' => true, 'status' => 'LATE_SCHEDULE_ATTESTATION_PASS', 'reason' => '', 'contract_sha256' => (string)$loaded['contract_sha256'], 'matching_line' => $matches[0]];
    }

    /** @param array<string,mixed> $contract */
    private static function valid(array $contract, string $repoRoot): bool
    {
        if (($contract['contract_version'] ?? '') !== self::CONTRACT_VERSION
            || ($contract['timezone'] ?? '') !== 'Asia/Tokyo'
            || ($contract['active_time_start'] ?? '') !== '22:00'
            || ($contract['active_time_end'] ?? '') !== '23:59'
            || ($contract['cadence_minutes'] ?? null) !== 2
            || ($contract['cron_expression'] ?? '') !== '*/2 22-23 * * *'
            || ($contract['mode'] ?? '') !== 'DB_ONLY'
            || !empty($contract['external_fetch']) || !empty($contract['candidate_discovery'])
            || !empty($contract['collector_trigger']) || !empty($contract['historical_repair'])
            || !is_array($contract['runner'] ?? null)) return false;
        $runner = $contract['runner'];
        if (($runner['repo_relative_path'] ?? '') !== 'analysis/run_strict_forward_late_eligibility_cron.sh'
            || ($runner['command_prefix'] ?? '') !== 'TZ=Asia/Tokyo /usr/bin/bash'
            || ($runner['marker'] ?? '') !== self::MARKER
            || preg_match('/^[a-f0-9]{64}$/', (string)($runner['sha256'] ?? '')) !== 1) return false;
        $path = rtrim($repoRoot, DIRECTORY_SEPARATOR) . '/' . (string)$runner['repo_relative_path'];
        $actual = @hash_file('sha256', $path);
        return is_string($actual) && hash_equals((string)$runner['sha256'], $actual);
    }
}
