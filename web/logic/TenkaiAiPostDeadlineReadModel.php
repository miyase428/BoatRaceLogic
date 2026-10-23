<?php

declare(strict_types=1);

/** Read-only display projection for formal replay and post-race reference. */
final class TenkaiAiPostDeadlineReadModel
{
    public const API_VERSION = 'tenkai-ai-postdeadline-display/v1';

    public function __construct(
        private readonly string $repoRoot,
        private readonly ?Closure $clock = null,
        private readonly ?Closure $referenceRunner = null,
        private readonly ?string $deadlineRoot = null,
        private readonly ?string $formalRoot = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function display(string $raceCode): array
    {
        $raceCode = strtoupper(trim($raceCode));
        if (preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $raceCode) !== 1) {
            throw new InvalidArgumentException('INVALID_RACE_CODE');
        }
        $deadline = $this->deadline($raceCode);
        if ($deadline === null) return $this->unavailable($raceCode, 'OFFICIAL_DEADLINE_UNAVAILABLE');
        $now = $this->now();
        if ($now <= $deadline) {
            return ['api_version' => self::API_VERSION, 'race_code' => $raceCode, 'phase' => 'PRE_DEADLINE',
                'official_deadline' => $deadline->format(DATE_ATOM), 'display_mode' => 'LIVE_PREDEADLINE'];
        }

        $formalAttack = $this->formalAttack($raceCode, $deadline);
        $reference = $this->reference($raceCode, $deadline);
        $wall = (array)($reference['components']['wall'] ?? ['prediction_available' => false, 'reason_code' => 'REFERENCE_UNAVAILABLE']);
        $referenceAttack = (array)($reference['components']['attack'] ?? ['prediction_available' => false, 'reason_code' => 'REFERENCE_UNAVAILABLE']);
        $attack = $formalAttack ?? $referenceAttack;
        if ($formalAttack !== null) $attack['kind'] = 'FORMAL_PREDICTION';
        elseif (!empty($attack['prediction_available'])) $attack['kind'] = 'POST_RACE_REFERENCE';
        if (!empty($wall['prediction_available'])) $wall['kind'] = 'POST_RACE_REFERENCE';

        $formal = $formalAttack !== null;
        $referenceAvailable = !empty($wall['prediction_available']) || (!$formal && !empty($attack['prediction_available']));
        $mode = $formal && $referenceAvailable ? 'MIXED_FORMAL_REFERENCE'
            : ($formal ? 'FORMAL_REDISPLAY' : ($referenceAvailable ? 'POST_RACE_REFERENCE' : 'DATA_INSUFFICIENT'));
        return [
            'api_version' => self::API_VERSION, 'race_code' => $raceCode, 'phase' => 'POST_DEADLINE',
            'official_deadline' => $deadline->format(DATE_ATOM), 'display_mode' => $mode,
            'strict_forward_eligible' => false, 'persisted' => false,
            'components' => ['wall' => $wall, 'attack' => $attack],
            'reference' => [
                'calculated_at' => $reference['calculated_at'] ?? null,
                'input_provenance' => $reference['input_provenance'] ?? null,
                'model_context' => $reference['model_context'] ?? null,
                'notice' => '締切後に取得した展示情報などを現在のモデルで計算した参考値です。当時の正式予測や前方検証の実績には含まれません。',
            ],
        ];
    }

    private function now(): DateTimeImmutable
    {
        $value = $this->clock === null ? new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')) : ($this->clock)();
        if (!$value instanceof DateTimeImmutable) throw new RuntimeException('INVALID_CLOCK');
        return $value->setTimezone(new DateTimeZone('Asia/Tokyo'));
    }

    private function deadline(string $raceCode): ?DateTimeImmutable
    {
        $root = $this->deadlineRoot ?? (rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/boatrace_official_deadlines');
        $path = $root . '/deadlines_' . substr($raceCode, 0, 8) . '.json';
        if (!is_file($path)) return $this->deadlineFromFormalSnapshot($raceCode);
        try { $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable) { return $this->deadlineFromFormalSnapshot($raceCode); }
        $time = $data['deadlines'][$raceCode] ?? null;
        if (!is_string($time) || preg_match('/^\d{2}:\d{2}$/', $time) !== 1) return $this->deadlineFromFormalSnapshot($raceCode);
        $parsed = DateTimeImmutable::createFromFormat('!Ymd H:i', substr($raceCode, 0, 8) . ' ' . $time, new DateTimeZone('Asia/Tokyo'));
        return $parsed === false ? $this->deadlineFromFormalSnapshot($raceCode) : $parsed;
    }

    private function deadlineFromFormalSnapshot(string $raceCode): ?DateTimeImmutable
    {
        $root = $this->formalRoot ?? ($this->repoRoot . '/var/course4_attack_type_forward_snapshots/strict_forward');
        $deadlines = [];
        foreach (glob($root . '/' . $raceCode . '__POST_EXHIBITION__*.json') ?: [] as $path) {
            try { $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
            catch (Throwable) { continue; }
            if (!is_array($row) || ($row['race_code'] ?? '') !== $raceCode || !$this->formalHashValid($row)) continue;
            try { $deadline = new DateTimeImmutable((string)($row['official_deadline'] ?? '')); }
            catch (Throwable) { continue; }
            if ($deadline->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Ymd') !== substr($raceCode, 0, 8)) continue;
            $deadlines[$deadline->format(DATE_ATOM)] = $deadline;
        }
        return count($deadlines) === 1 ? array_values($deadlines)[0] : null;
    }

    /** @return array<string,mixed>|null */
    private function formalAttack(string $raceCode, DateTimeImmutable $deadline): ?array
    {
        $root = $this->formalRoot ?? ($this->repoRoot . '/var/course4_attack_type_forward_snapshots/strict_forward');
        $valid = [];
        foreach (glob($root . '/' . $raceCode . '__POST_EXHIBITION__*.json') ?: [] as $path) {
            try { $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
            catch (Throwable) { continue; }
            if (!is_array($row) || ($row['race_code'] ?? '') !== $raceCode || ($row['sample_kind'] ?? '') !== 'STRICT_FORWARD'
                || empty($row['prediction_available'])) continue;
            if (!$this->formalHashValid($row)) continue;
            try {
                $cutoff = new DateTimeImmutable((string)($row['prediction_cutoff'] ?? ''));
                $generated = new DateTimeImmutable((string)($row['prediction_generated_at'] ?? ''));
                $savedDeadline = new DateTimeImmutable((string)($row['official_deadline'] ?? ''));
            } catch (Throwable) { continue; }
            if ($cutoff >= $deadline || $generated >= $deadline || $savedDeadline != $deadline) continue;
            $probabilities = [(float)($row['p_no_attack_success'] ?? NAN), (float)($row['p_makuri_success'] ?? NAN), (float)($row['p_makurizashi_success'] ?? NAN)];
            if (array_filter($probabilities, static fn(float $value): bool => !is_finite($value) || $value < 0 || $value > 1) !== []
                || abs(array_sum($probabilities) - 1.0) > 1e-8) continue;
            $valid[] = [
                'prediction_available' => true,
                'probabilities' => ['no_attack_success' => $probabilities[0], 'makuri_success' => $probabilities[1], 'makurizashi_success' => $probabilities[2]],
                'prediction_generated_at' => $generated->format(DATE_ATOM), 'prediction_cutoff' => $cutoff->format(DATE_ATOM),
                'logic_version' => (string)($row['logic_version'] ?? ''), 'artifact_sha256' => (string)($row['artifact_sha256'] ?? ''),
                'snapshot_hash' => (string)($row['snapshot_hash'] ?? ''),
            ];
        }
        if ($valid === []) return null;
        usort($valid, static fn(array $left, array $right): int => strcmp($left['prediction_generated_at'], $right['prediction_generated_at']));
        return $valid[0];
    }

    /** @return array<string,mixed> */
    private function reference(string $raceCode, DateTimeImmutable $deadline): array
    {
        $variant = $this->modelVariant(substr($raceCode, 8, 3));
        if ($variant === null) return ['components' => ['wall' => ['prediction_available' => false, 'reason_code' => 'VENUE_NOT_SUPPORTED'], 'attack' => ['prediction_available' => false, 'reason_code' => 'VENUE_NOT_SUPPORTED']]];
        if ($this->referenceRunner !== null) return (array)($this->referenceRunner)($raceCode, $deadline->format(DATE_ATOM), $variant);
        $cmd = ['/usr/bin/python3', $this->repoRoot . '/forecast/tenkai_ai_postrace_reference.py', '--race-code', $raceCode,
            '--official-deadline', $deadline->format(DATE_ATOM), '--model-variant', $variant];
        $pipes = []; $process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->repoRoot);
        if (!is_resource($process)) return [];
        fclose($pipes[0]); stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $out = ''; $started = microtime(true); $timedOut = false;
        while (($state = proc_get_status($process))['running']) {
            $out .= stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
            if (microtime(true) - $started > 15.0) { proc_terminate($process); $timedOut = true; break; }
            usleep(10000);
        }
        $out .= stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
        if ($timedOut || $exit !== 0) return [];
        try { $value = json_decode($out, true, 512, JSON_THROW_ON_ERROR); return is_array($value) ? $value : []; }
        catch (Throwable) { return []; }
    }

    private function modelVariant(string $venue): ?string
    {
        try { $data = json_decode((string)file_get_contents($this->repoRoot . '/config/course4_tenkai_venue_capabilities_v1.json'), true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable) { return null; }
        $capability = $data['venues'][$venue] ?? $data['default'] ?? null;
        if (!is_array($capability) || ($capability['supported'] ?? true) === false) return null;
        $variant = $capability['model_variant'] ?? null;
        return in_array($variant, ['FULL', 'NO_STRAIGHT'], true) ? $variant : null;
    }

    private function canonicalJson(mixed $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) return $item;
            if (array_is_list($item)) return array_map($normalize, $item);
            ksort($item, SORT_STRING);
            foreach ($item as $key => $child) $item[$key] = $normalize($child);
            return $item;
        };
        $encoded = json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (!is_string($encoded)) throw new RuntimeException('CANONICAL_JSON_FAILED');
        return $encoded;
    }

    /** @param array<string,mixed> $row */
    private function formalHashValid(array $row): bool
    {
        $hashBody = $row;
        unset($hashBody['prediction_generated_at'], $hashBody['capture_generated_at'], $hashBody['snapshot_hash']);
        return is_string($row['snapshot_hash'] ?? null)
            && hash_equals((string)$row['snapshot_hash'], hash('sha256', $this->canonicalJson($hashBody)));
    }

    /** @return array<string,mixed> */
    private function unavailable(string $raceCode, string $reason): array
    {
        return ['api_version' => self::API_VERSION, 'race_code' => $raceCode, 'phase' => 'UNKNOWN', 'display_mode' => 'DATA_INSUFFICIENT',
            'strict_forward_eligible' => false, 'persisted' => false,
            'components' => ['wall' => ['prediction_available' => false, 'reason_code' => $reason], 'attack' => ['prediction_available' => false, 'reason_code' => $reason]]];
    }
}
