<?php

declare(strict_types=1);

require_once __DIR__ . '/FormalMeasurementWindowContext.php';
require_once __DIR__ . '/OfficialEpochWindowBindingV1.php';
require_once __DIR__ . '/OfficialEpochIncidentLifecycleStore.php';

/**
 * Creates a complete, inert measurement Window without consulting or changing
 * the production pointer.  Selection, Epoch binding, and activation remain
 * separate lifecycle operations.
 */
final class FormalMeasurementWindowPrepareOnlyV1
{
    public const PREPARED_CONTRACT_SCHEMA = 'formal-measurement-window-prepared-contract/v1';

    /** @param list<string>|null $criticalPaths fixture-only provenance scope */
    public function __construct(
        private readonly string $repoRoot,
        private readonly ?array $criticalPaths = null,
        private readonly ?DateTimeImmutable $clock = null,
    ) {}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function plan(array $input = []): array
    {
        $mode = (string)($input['mode'] ?? 'NORMAL');
        if (!in_array($mode, ['NORMAL', 'RECOVERY'], true)) return $this->blocked('PREPARE_ONLY_MODE_INVALID');
        if ($mode === 'RECOVERY') {
            if (($input['epoch_state'] ?? '') !== 'SUSPENDED') return $this->blocked('RECOVERY_PREPARE_EPOCH_NOT_SUSPENDED');
            foreach (['incident_hash', 'suspension_hash'] as $field) {
                if (!$this->validHash((string)($input[$field] ?? ''))) return $this->blocked('RECOVERY_PREPARE_' . strtoupper($field) . '_INVALID');
            }
        } elseif (($input['epoch_state'] ?? 'ACTIVE') === 'ENDED') {
            return $this->blocked('NORMAL_PREPARE_EPOCH_ENDED');
        }
        try {
            $identity = (new MeasurementImplementationProvenance($this->repo(), sys_get_temp_dir(), $this->criticalPaths))->currentIdentityReadOnly();
        } catch (Throwable $error) {
            return $this->blocked('PREPARE_ONLY_CURRENT_IDENTITY_UNAVAILABLE:' . $error->getMessage());
        }
        return [
            'accepted' => true,
            'status' => 'PREPARE_ONLY_ELIGIBLE',
            'reason' => '',
            'mode' => $mode,
            'declared_measurement_identity_candidate' => $identity,
            'pointer_dependency' => 'NONE',
            'pointer_mutation_planned' => false,
            'normal_binding_planned' => false,
            'activation_planned' => false,
            'prospective_boundary_contract' => OfficialEpochWindowBindingV1::PROSPECTIVE_BOUNDARY_CONTRACT,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function create(array $input = []): array
    {
        $plan = $this->plan($input);
        if (empty($plan['accepted'])) return $plan;
        $windowId = (string)($input['window_id'] ?? '');
        if ($windowId === '') $windowId = 'successor-v3-prepared-' . $this->now()->format('YmdHis') . '-' . bin2hex(random_bytes(4));
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $windowId)) return $this->blocked('PREPARE_ONLY_WINDOW_ID_INVALID');
        $root = $this->windowRoot($windowId);
        if (file_exists($root)) return $this->blocked('PREPARE_ONLY_WINDOW_ALREADY_EXISTS');

        // No cleanup on a later failure: every successful write is create-only
        // evidence and the partial Window must fail loud for human review.
        $manifestPath = $root . '/validation_window_manifest.json';
        $prepared = WindowActivationHold::createPrepared($manifestPath, $windowId, $this->now());
        if (empty($prepared['accepted'])) return $this->blocked('PREPARE_ONLY_MANIFEST_CREATE_FAILED:' . (string)$prepared['reason']);
        $context = FormalMeasurementWindowContext::fromWindowRoot($this->repo(), $root, $this->criticalPaths);
        $declaration = $context->declaration()->declare();
        if (empty($declaration['accepted'])) return $this->blocked('PREPARE_ONLY_DECLARATION_CREATE_FAILED:' . (string)$declaration['reason']);
        $readback = $context->declaration()->read(true);
        if (empty($readback['accepted'])) return $this->blocked('PREPARE_ONLY_DECLARATION_READBACK_FAILED:' . (string)$readback['reason']);
        $implementation = $readback['implementation'];
        if (!hash_equals((string)$plan['declared_measurement_identity_candidate'], (string)$implementation['implementation_identity'])) {
            return $this->blocked('PREPARE_ONLY_IDENTITY_CHANGED_DURING_CREATE');
        }
        $contract = [
            'schema_version' => self::PREPARED_CONTRACT_SCHEMA,
            'window_id' => $windowId,
            'window_manifest_ref' => $this->relative($manifestPath),
            'window_manifest_hash' => $this->fileHash($manifestPath),
            'window_declaration_ref' => $this->relative($context->declaration()->declarationPath()),
            'window_declaration_hash' => $this->fileHash($context->declaration()->declarationPath()),
            'declared_measurement_identity' => (string)$implementation['implementation_identity'],
            'prospective_boundary_contract' => OfficialEpochWindowBindingV1::PROSPECTIVE_BOUNDARY_CONTRACT,
        ];
        $contract['record_hash'] = $this->hash($contract);
        $contractPath = $root . '/prepared_prospective_contract.json';
        $written = $this->createOnly($contractPath, $contract);
        if (empty($written['accepted'])) return $this->blocked('PREPARE_ONLY_PROSPECTIVE_CONTRACT_FAILED:' . (string)$written['reason']);
        return [
            'accepted' => true,
            'status' => 'PREPARE_ONLY_CREATED',
            'reason' => '',
            'window_id' => $windowId,
            'window_root' => $root,
            'manifest' => $prepared['manifest'],
            'declaration' => $readback['declaration'],
            'implementation' => $implementation,
            'prepared_contract' => $contract,
            'prepared_contract_path' => $contractPath,
            'pointer_dependency' => 'NONE',
            'pointer_reads' => 0,
            'pointer_writes' => 0,
            'pointer_cas' => 0,
            'normal_binding_creates' => 0,
            'activation_creates' => 0,
        ];
    }

    public function preparedContractPath(string $windowId): string { return $this->windowRoot($windowId) . '/prepared_prospective_contract.json'; }

    /** @return array<string,mixed> */
    public function readPreparedContract(string $windowId): array
    {
        $path = $this->preparedContractPath($windowId);
        if (!is_file($path)) return [];
        try { $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { return []; }
        if (!is_array($row) || ($row['schema_version'] ?? '') !== self::PREPARED_CONTRACT_SCHEMA) return [];
        $copy = $row; unset($copy['record_hash']);
        return $this->validHash((string)($row['record_hash'] ?? '')) && hash_equals((string)$row['record_hash'], $this->hash($copy)) ? $row : [];
    }

    private function repo(): string { return rtrim($this->repoRoot, DIRECTORY_SEPARATOR); }
    private function windowRoot(string $id): string { return $this->repo() . '/var/strict_forward_live_readiness/windows/' . $id; }
    private function now(): DateTimeImmutable { return $this->clock ?? new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')); }
    private function relative(string $path): string { $root = $this->repo(); return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path; }
    private function fileHash(string $path): string { $bytes = @file_get_contents($path); return is_string($bytes) ? 'sha256:' . hash('sha256', $bytes) : ''; }
    /** @param array<string,mixed> $row */ private function hash(array $row): string { return 'sha256:' . hash('sha256', OfficialEpochPolicyV1::canonicalJson($row)); }
    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function createOnly(string $path, array $row): array { $dir=dirname($path); if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))return $this->blocked('DIRECTORY_UNAVAILABLE'); $h=@fopen($path,'x'); if(!is_resource($h))return $this->blocked('CREATE_CONFLICT'); $ok=@fwrite($h,OfficialEpochPolicyV1::canonicalJson($row)."\n")!==false; @fclose($h); return $ok?['accepted'=>true,'record'=>$row,'reason'=>'']: $this->blocked('WRITE_FAILED'); }
    private function validHash(string $hash): bool { return preg_match('/^sha256:[a-f0-9]{64}$/', $hash) === 1; }
    /** @return array<string,mixed> */ private function blocked(string $reason): array { return ['accepted'=>false,'status'=>'PREPARE_ONLY_BLOCKED','reason'=>$reason]; }
}
