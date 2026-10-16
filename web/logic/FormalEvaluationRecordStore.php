<?php

declare(strict_types=1);

/**
 * Create-only store for Formal Evaluator v2 decisions.
 *
 * Records live outside a measurement Window: evaluation must never alter the
 * manifest, closure, candidate inventory, or measurement evidence it reads.
 */
final class FormalEvaluationRecordStore
{
    public const SCHEMA_VERSION = 'formal-evaluation-record/v2';

    public function __construct(private readonly string $repoRoot)
    {
    }

    public function recordPath(string $evaluationKey): string
    {
        if (!preg_match('/^sha256:[a-f0-9]{64}$/', $evaluationKey)) {
            throw new InvalidArgumentException('formal_evaluation_key_invalid');
        }
        return rtrim($this->repoRoot, DIRECTORY_SEPARATOR)
            . '/var/strict_forward_live_readiness/formal_evaluations/'
            . substr($evaluationKey, strlen('sha256:')) . '.json';
    }

    /**
     * The caller supplies an already completed, race-free evaluation.  This
     * method is intentionally the sole write path and is never used by a
     * status read or read-only historical replay.
     *
     * @param array<string,mixed> $decision
     * @return array{accepted:bool,status:string,reason:string,record:array<string,mixed>}
     */
    public function create(array $decision): array
    {
        $key = (string)($decision['evaluation_key'] ?? '');
        $hash = (string)($decision['decision_hash'] ?? '');
        if (!preg_match('/^sha256:[a-f0-9]{64}$/', $key)
            || !preg_match('/^sha256:[a-f0-9]{64}$/', $hash)
            || ($decision['official_epoch'] ?? null) !== 'NO') {
            return $this->blocked('FORMAL_EVALUATION_RECORD_INPUT_INVALID');
        }
        $hashPayload = $decision;
        unset($hashPayload['decision_hash'], $hashPayload['record_hash']);
        $calculated = 'sha256:' . hash('sha256', $this->canonicalJson($hashPayload));
        if (!hash_equals($calculated, $hash)) {
            return $this->blocked('FORMAL_EVALUATION_DECISION_HASH_INVALID');
        }

        $path = $this->recordPath($key);
        $existing = $this->readPath($path);
        if ($existing !== []) {
            if (($existing['decision_hash'] ?? '') === $hash
                && ($existing['evaluation_key'] ?? '') === $key) {
                return ['accepted' => true, 'status' => 'FORMAL_EVALUATION_ALREADY_EXISTS', 'reason' => '', 'record' => $existing];
            }
            return $this->blocked('BLOCKED_EVALUATOR_NONDETERMINISM', $existing);
        }

        $record = $decision;
        $record['schema_version'] = self::SCHEMA_VERSION;
        $record['record_hash'] = 'sha256:' . hash('sha256', $this->canonicalJson($record));
        $bytes = $this->canonicalJson($record) . "\n";
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return $this->blocked('FORMAL_EVALUATION_RECORD_DIRECTORY_UNAVAILABLE');
        }
        $handle = @fopen($path, 'x');
        if (!is_resource($handle)) {
            $existing = $this->readPath($path);
            if ($existing !== [] && ($existing['decision_hash'] ?? '') === $hash) {
                return ['accepted' => true, 'status' => 'FORMAL_EVALUATION_ALREADY_EXISTS', 'reason' => '', 'record' => $existing];
            }
            return $this->blocked('BLOCKED_EVALUATOR_NONDETERMINISM', $existing);
        }
        $ok = @fwrite($handle, $bytes) !== false;
        @fclose($handle);
        if (!$ok) return $this->blocked('FORMAL_EVALUATION_RECORD_WRITE_FAILED');
        return ['accepted' => true, 'status' => 'FORMAL_EVALUATION_RECORD_CREATED', 'reason' => '', 'record' => $record];
    }

    /** @return array<string,mixed> */
    public function read(string $evaluationKey): array
    {
        return $this->readPath($this->recordPath($evaluationKey));
    }

    /** @return array<string,mixed> */
    private function readPath(string $path): array
    {
        if (!is_file($path)) return [];
        try {
            $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }
        return is_array($row) ? $row : [];
    }

    /** @param array<string,mixed> $existing @return array{accepted:bool,status:string,reason:string,record:array<string,mixed>} */
    private function blocked(string $reason, array $existing = []): array
    {
        return ['accepted' => false, 'status' => 'FORMAL_EVALUATION_RECORD_BLOCKED', 'reason' => $reason, 'record' => $existing];
    }

    /** @param array<string,mixed> $value */
    private function canonicalJson(array $value): string
    {
        $normal = $this->sort($value);
        $json = json_encode($normal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return is_string($json) ? $json : '{}';
    }

    private function sort(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map(fn(mixed $item): mixed => $this->sort($item), $value);
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) $value[$key] = $this->sort($item);
        return $value;
    }
}
