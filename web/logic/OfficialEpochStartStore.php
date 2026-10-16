<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialEpochPolicyV1.php';
require_once __DIR__ . '/OfficialEpochIncidentPolicyV1.php';

/** Create-only global singleton store for Official Epoch start records. */
final class OfficialEpochStartStore
{
    public const SCHEMA_VERSION = 'formal-official-epoch-start/v1';

    public function __construct(private readonly string $lifecycleRoot)
    {
    }

    /** @return array<string,mixed> */
    public function existing(): array
    {
        $paths = glob(rtrim($this->lifecycleRoot, DIRECTORY_SEPARATOR) . '/official_epochs/*/start.json') ?: [];
        if (count($paths) !== 1) return [];
        try { $row = json_decode((string)file_get_contents($paths[0]), true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { return []; }
        return is_array($row) && $this->valid($row) ? $row : [];
    }

    /** @param array<string,mixed> $record @return array{accepted:bool,status:string,reason:string,record:array<string,mixed>} */
    public function create(array $record): array
    {
        if (!$this->valid($record)) return $this->blocked('OFFICIAL_EPOCH_RECORD_INVALID');
        $existing = $this->existing();
        if ($existing !== []) {
            if (($existing['epoch_key'] ?? '') === ($record['epoch_key'] ?? '') && ($existing['authorization_hash'] ?? '') === ($record['authorization_hash'] ?? '')) {
                return ['accepted' => true, 'status' => 'OFFICIAL_EPOCH_ALREADY_STARTED', 'reason' => '', 'record' => $existing];
            }
            return $this->blocked('OFFICIAL_EPOCH_SINGLETON_CONFLICT', $existing);
        }
        $path = $this->path((string)$record['epoch_id']);
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) return $this->blocked('OFFICIAL_EPOCH_DIRECTORY_UNAVAILABLE');
        $handle = @fopen($path, 'x');
        if (!is_resource($handle)) return $this->blocked('OFFICIAL_EPOCH_CREATE_CONFLICT');
        $ok = @fwrite($handle, OfficialEpochPolicyV1::canonicalJson($record) . "\n") !== false;
        @fclose($handle);
        if (!$ok) return $this->blocked('OFFICIAL_EPOCH_WRITE_FAILED');
        return ['accepted' => true, 'status' => 'OFFICIAL_EPOCH_STARTED', 'reason' => '', 'record' => $record];
    }

    /** @param array<string,mixed> $record */
    public function valid(array $record): bool
    {
        if (($record['schema_version'] ?? '') !== self::SCHEMA_VERSION
            || preg_match('/^official-epoch-v1-[a-f0-9]{16}$/', (string)($record['epoch_id'] ?? '')) !== 1
            || preg_match('/^sha256:[a-f0-9]{64}$/', (string)($record['epoch_key'] ?? '')) !== 1
            || preg_match('/^sha256:[a-f0-9]{64}$/', (string)($record['authorization_hash'] ?? '')) !== 1
            || preg_match('/^sha256:[a-f0-9]{64}$/', (string)($record['record_hash'] ?? '')) !== 1) return false;
        if (($record['incident_policy_schema'] ?? '') !== OfficialEpochIncidentPolicyV1::SCHEMA_VERSION
            || !hash_equals(OfficialEpochIncidentPolicyV1::identity(), (string)($record['incident_policy_identity'] ?? ''))) return false;
        $payload = $record; unset($payload['record_hash']);
        return hash_equals((string)$record['record_hash'], 'sha256:' . hash('sha256', OfficialEpochPolicyV1::canonicalJson($payload)));
    }

    private function path(string $epochId): string
    {
        return rtrim($this->lifecycleRoot, DIRECTORY_SEPARATOR) . '/official_epochs/' . $epochId . '/start.json';
    }

    /** @param array<string,mixed> $record @return array{accepted:bool,status:string,reason:string,record:array<string,mixed>} */
    private function blocked(string $reason, array $record = []): array
    {
        return ['accepted' => false, 'status' => 'OFFICIAL_EPOCH_START_BLOCKED', 'reason' => $reason, 'record' => $record];
    }
}
