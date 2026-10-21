<?php

declare(strict_types=1);

/**
 * Read-only process identity guard for CC30C rollover operation 1.
 *
 * A process is relevant only when its NUL-separated argv contains an exact
 * natural-pipeline script token.  Text embedded in a shell -c command, audit
 * command, grep/pgrep expression, parent argv, or arbitrary process name is
 * never treated as a pipeline identity.
 */
final class Cc30cRolloverProcessGuard
{
    public const NATURAL_CAPTURE_RUNNING = 'NATURAL_CAPTURE_RUNNING';
    public const NATURAL_COLLECTOR_RUNNING = 'NATURAL_COLLECTOR_RUNNING';
    public const NO_NATURAL_PIPELINE_PROCESS = 'NO_NATURAL_PIPELINE_PROCESS';
    public const UNKNOWN_PROCESS_STATE = 'UNKNOWN_PROCESS_STATE';

    private const HOLD_PREFIX = '# MAINTENANCE_HOLD_CC30C_20261020 ';

    public function __construct(private readonly string $repoRoot, private readonly string $procRoot = '/proc')
    {
    }

    /** @return array<string,mixed> */
    public function scan(): array
    {
        $first = $this->scanOnce();
        // A single repeat absorbs a normal PID exit between directory listing
        // and cmdline read. Persistent uncertainty remains fail-closed.
        if ($first['classification'] === self::UNKNOWN_PROCESS_STATE) {
            $second = $this->scanOnce();
            if ($second['classification'] !== self::UNKNOWN_PROCESS_STATE) {
                $second['rescan_performed'] = true;
                return $second;
            }
            $second['rescan_performed'] = true;
            return $second;
        }
        $first['rescan_performed'] = false;
        return $first;
    }

    /** @return array<string,mixed> */
    private function scanOnce(): array
    {
        $paths = glob(rtrim($this->procRoot, '/') . '/[0-9]*', GLOB_ONLYDIR);
        if ($paths === false) return $this->unknown('PROC_DIRECTORY_LIST_UNAVAILABLE');
        $selfAndAncestors = $this->selfAndAncestors();
        $capture = [];
        $collector = [];
        $unknown = [];
        $ignored = [];
        foreach ($paths as $path) {
            $pidText = basename($path);
            if (!ctype_digit($pidText)) continue;
            $pid = (int)$pidText;
            $cmdlinePath = $path . '/cmdline';
            if (!is_file($cmdlinePath)) {
                // A vanished PID is a normal race; an extant non-file cmdline
                // is not safely classifiable.
                if (file_exists($path)) $unknown[] = ['pid' => $pid, 'reason' => 'PROC_CMDLINE_UNAVAILABLE'];
                continue;
            }
            $raw = @file_get_contents($cmdlinePath);
            if (!is_string($raw)) {
                if (file_exists($path)) $unknown[] = ['pid' => $pid, 'reason' => 'PROC_CMDLINE_READ_FAILED'];
                continue;
            }
            $argv = $this->argv($raw);
            if ($argv === []) continue;
            $kind = $this->naturalKind($argv);
            if ($kind === '') continue;
            if (isset($selfAndAncestors[$pid])) {
                $ignored[] = ['pid' => $pid, 'kind' => $kind, 'reason' => 'SELF_OR_ANCESTOR_EXCLUDED'];
                continue;
            }
            $row = ['pid' => $pid, 'argv' => $argv, 'identity' => $kind];
            if ($kind === 'CAPTURE') $capture[] = $row;
            else $collector[] = $row;
        }
        if ($unknown !== []) return [
            'accepted' => false, 'classification' => self::UNKNOWN_PROCESS_STATE,
            'reason' => 'PROCESS_IDENTITY_UNAVAILABLE', 'capture_processes' => $capture,
            'collector_processes' => $collector, 'unknown_processes' => $unknown,
            'self_or_ancestor_excluded' => $ignored,
        ];
        $classification = $capture !== [] ? self::NATURAL_CAPTURE_RUNNING
            : ($collector !== [] ? self::NATURAL_COLLECTOR_RUNNING : self::NO_NATURAL_PIPELINE_PROCESS);
        return [
            'accepted' => true, 'classification' => $classification, 'reason' => '',
            'capture_processes' => $capture, 'collector_processes' => $collector,
            'unknown_processes' => [], 'self_or_ancestor_excluded' => $ignored,
        ];
    }

    /** @param list<string> $argv */
    private function naturalKind(array $argv): string
    {
        $captureRunner = $this->repoRoot . '/analysis/run_all_race_forward_capture_cron.sh';
        $collectorRunner = $this->repoRoot . '/analysis/run_strict_forward_exhibition_collector.sh';
        $capturePhp = $this->repoRoot . '/analysis/capture_all_race_forward.php';
        $collectorPhp = $this->repoRoot . '/analysis/collect_strict_forward_exhibition.php';
        if (in_array($captureRunner, $argv, true)
            || (in_array($capturePhp, $argv, true) && in_array('--db-only', $argv, true))) return 'CAPTURE';
        if (in_array($collectorRunner, $argv, true) || in_array($collectorPhp, $argv, true)) return 'COLLECTOR';
        return '';
    }

    /** @return list<string> */
    private function argv(string $raw): array
    {
        $tokens = explode("\0", rtrim($raw, "\0"));
        return array_values(array_filter($tokens, static fn(string $token): bool => $token !== ''));
    }

    /** @return array<int,true> */
    private function selfAndAncestors(): array
    {
        $seen = [];
        $pid = getmypid();
        for ($i = 0; $i < 32 && $pid > 1 && !isset($seen[$pid]); $i++) {
            $seen[$pid] = true;
            $stat = @file_get_contents(rtrim($this->procRoot, '/') . '/' . $pid . '/stat');
            if (!is_string($stat) || preg_match('/^\d+\s+\(.+\)\s+\S+\s+(\d+)\s/', $stat, $match) !== 1) break;
            $pid = (int)$match[1];
        }
        return $seen;
    }

    /** @return array<string,mixed> */
    private function unknown(string $reason): array
    {
        return ['accepted' => false, 'classification' => self::UNKNOWN_PROCESS_STATE, 'reason' => $reason,
            'capture_processes' => [], 'collector_processes' => [], 'unknown_processes' => [], 'self_or_ancestor_excluded' => []];
    }

    /** @return array{accepted:bool,reason:string,crontab:string} */
    public function holdText(string $crontab): array
    {
        $capture = '*/2 8-21 * * * /usr/bin/bash ' . $this->repoRoot . '/analysis/run_all_race_forward_capture_cron.sh # BOATRACE_ALL_RACE_FORWARD_CAPTURE';
        $collector = '1-59/2 8-22 * * * /usr/bin/bash ' . $this->repoRoot . '/analysis/run_strict_forward_exhibition_collector.sh # BOATRACE_STRICT_FORWARD_EXHIBITION_COLLECTOR';
        if (substr_count($crontab, $capture) !== 1 || substr_count($crontab, $collector) !== 1
            || str_contains($crontab, self::HOLD_PREFIX)) return ['accepted' => false, 'reason' => 'HOLD_CRONTAB_CONTRACT_MISMATCH', 'crontab' => $crontab];
        $held = str_replace([$capture, $collector], [self::HOLD_PREFIX . $capture, self::HOLD_PREFIX . $collector], $crontab, $count);
        return $count === 2 ? ['accepted' => true, 'reason' => '', 'crontab' => $held]
            : ['accepted' => false, 'reason' => 'HOLD_TEXT_REWRITE_FAILED', 'crontab' => $crontab];
    }

    /** @return array{accepted:bool,reason:string,crontab:string} */
    public function restoreText(string $crontab): array
    {
        if (substr_count($crontab, self::HOLD_PREFIX) !== 2) return ['accepted' => false, 'reason' => 'RESTORE_HOLD_MARKER_MISMATCH', 'crontab' => $crontab];
        $restored = str_replace(self::HOLD_PREFIX, '', $crontab, $count);
        return $count === 2 ? ['accepted' => true, 'reason' => '', 'crontab' => $restored]
            : ['accepted' => false, 'reason' => 'RESTORE_TEXT_REWRITE_FAILED', 'crontab' => $crontab];
    }
}
