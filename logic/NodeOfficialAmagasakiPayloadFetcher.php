<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialAmagasakiDaytimeIntegration.php';

/**
 * Explicit process adapter for the standalone official parser.  The daytime
 * integration calls it only after an approved router produced an atomic claim.
 * It has no import-time side effects and is never constructed by dry-run.
 */
final class NodeOfficialAmagasakiPayloadFetcher implements OfficialAmagasakiPayloadFetcher
{
    public const DIAGNOSTIC_HTML_DIRECTORY = '/var/lib/boatrace/official-exhibition-diagnostics';

    public function __construct(
        private readonly string $nodeBinary,
        private readonly string $adapterPath,
        private readonly bool $captureDiagnosticHtml = false,
        private readonly int $diagnosticRetentionSeconds = 86400,
    )
    {
    }

    public function fetch(string $raceCode): array
    {
        if (!preg_match('/^(\d{8})AMG(0[1-9]|1[0-2])$/', $raceCode, $matches)) {
            return ['outcome' => 'parse_error', 'error_reason' => 'RACE_CODE_INVALID'];
        }
        if (!is_file($this->adapterPath)) return ['outcome' => 'parse_error', 'error_reason' => 'OFFICIAL_ADAPTER_UNAVAILABLE'];
        $command = [$this->nodeBinary, $this->adapterPath, '--date', $matches[1], '--race', (string)(int)$matches[2]];
        if ($this->captureDiagnosticHtml) {
            $command[] = '--diagnostic-html-dir';
            $command[] = self::DIAGNOSTIC_HTML_DIRECTORY;
            $command[] = '--diagnostic-retention-seconds';
            $command[] = (string)$this->diagnosticRetentionSeconds;
        }
        $pipes = [];
        $process = @proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) return ['outcome' => 'server_error', 'error_reason' => 'OFFICIAL_ADAPTER_START_FAILED'];
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) return ['outcome' => $this->failureOutcome((string)$stderr), 'error_reason' => $this->safeReason((string)$stderr)];
        $payload = json_decode((string)$stdout, true);
        if (!is_array($payload)) return ['outcome' => 'parse_error', 'error_reason' => 'OFFICIAL_ADAPTER_JSON_INVALID'];
        if (($payload['status'] ?? null) === 'not_yet_published') return ['outcome' => 'not_yet_published'];
        if (($payload['status'] ?? null) !== 'published') return ['outcome' => 'parse_error', 'error_reason' => 'OFFICIAL_ADAPTER_STATUS_INVALID'];
        return ['outcome' => 'published', 'payload' => $payload];
    }

    private function failureOutcome(string $stderr): string
    {
        if (str_contains($stderr, 'HTTP_RESTRICTED') || str_contains($stderr, 'CAPTCHA') || str_contains($stderr, 'AUTH')) return 'http_restricted';
        if (str_contains($stderr, 'HTTP_ERROR') || str_contains($stderr, 'timed out') || str_contains($stderr, 'ECONN')) return 'server_error';
        return 'parse_error';
    }

    private function safeReason(string $stderr): string
    {
        $first = trim((string)strtok($stderr, "\n"));
        return $first === '' ? 'OFFICIAL_ADAPTER_FAILED' : substr($first, 0, 160);
    }
}
