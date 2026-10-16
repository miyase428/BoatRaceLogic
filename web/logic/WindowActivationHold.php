<?php

declare(strict_types=1);

require_once __DIR__ . '/WindowDeclaredImplementationIdentity.php';
require_once __DIR__ . '/OfficialEpochWindowBindingResolver.php';
require_once __DIR__ . '/OfficialEpochStartStore.php';
require_once __DIR__ . '/OfficialEpochIncidentLifecycleStore.php';
require_once __DIR__ . '/OfficialEpochRecoveryLifecycleV1.php';

/**
 * Separates a persisted Window from permission to write formal evidence.
 *
 * A PREPARED Window is deliberately inert.  Only a first-write activation
 * record plus an ACTIVE manifest can authorize Strict Forward persistence.
 */
final class WindowActivationHold
{
    public const MANIFEST_SCHEMA_VERSION = 'operational-window/v2';
    public const ACTIVATION_SCHEMA_VERSION = 'window-activation-record/v2';
    public const ACTIVATION_SCHEMA_VERSION_V1 = 'window-activation-record/v1';

    public function __construct(
        private readonly string $windowManifestPath,
        private readonly WindowDeclaredImplementationIdentity $declaration,
        private readonly string $strictLedgerRoot,
        private readonly ?string $activationPath = null,
        private readonly ?string $repoRoot = null,
    ) {
    }

    /** @deprecated Formal callers must use FormalMeasurementWindowContext. */
    public static function production(string $strictLedgerRoot): self
    {
        throw new LogicException('FORMAL_WINDOW_CONTEXT_REQUIRED');
    }

    public function windowManifestPath(): string
    {
        return $this->windowManifestPath;
    }

    public function strictLedgerRoot(): string
    {
        return $this->strictLedgerRoot;
    }

    public function activationRecordPath(): string
    {
        return $this->resolvedActivationPath();
    }

    /**
     * Creates only a fixture/future PREPARED manifest.  It never activates
     * formal capture and deliberately leaves armed_at unset.
     *
     * @return array{accepted:bool,status:string,reason:string,manifest:array<string,mixed>}
     */
    public static function createPrepared(string $manifestPath, string $windowId, ?DateTimeImmutable $preparedAt = null): array
    {
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $windowId)) {
            return self::blocked('INVALID_WINDOW_ID');
        }
        $preparedAt ??= new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
        $manifest = [
            'window_schema_version' => self::MANIFEST_SCHEMA_VERSION,
            'validation_window_id' => $windowId,
            'prepared_at' => $preparedAt->format(DATE_ATOM),
            'activation_state' => 'PREPARED',
            'status' => 'PREPARED',
            'armed_at' => null,
            'official_epoch_started' => 'NO',
        ];
        $directory = dirname($manifestPath);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return self::blocked('PREPARED_MANIFEST_DIRECTORY_UNAVAILABLE');
        }
        $handle = @fopen($manifestPath, 'x');
        if (!is_resource($handle)) return self::blocked('PREPARED_WINDOW_ALREADY_EXISTS');
        $ok = @fwrite($handle, self::json($manifest) . "\n") !== false;
        @fclose($handle);
        if (!$ok) {
            @unlink($manifestPath);
            return self::blocked('PREPARED_MANIFEST_WRITE_FAILED');
        }
        return ['accepted' => true, 'status' => 'PREPARED_CREATED', 'reason' => '', 'manifest' => $manifest];
    }

    /** @return array{accepted:bool,status:string,reason:string,manifest:array<string,mixed>,activation:array<string,mixed>,implementation:array<string,string>} */
    public function authorizeFormalWrite(): array
    {
        $manifest = $this->readManifest();
        if (!$this->isActive($manifest)) return $this->blocked('WINDOW_NOT_ACTIVE', $manifest);
        $activation = $this->readActivation();
        if ($activation === []) return $this->blocked('ACTIVATION_RECORD_MISSING', $manifest);
        $declaration = $this->declaration->assertCurrent();
        if (!$declaration['accepted']) {
            return $this->blocked((string)$declaration['reason'], $manifest, $activation, $declaration['implementation']);
        }
        $implementation = $declaration['implementation'];
        if (!$this->activationMatches($activation, $manifest, $implementation)) {
            return $this->blocked('ACTIVATION_RECORD_PROVENANCE_MISMATCH', $manifest, $activation, $implementation);
        }
        return [
            'accepted' => true,
            'status' => 'FORMAL_WRITE_ALLOWED',
            'reason' => '',
            'manifest' => $manifest,
            'activation' => $activation,
            'implementation' => $implementation,
        ];
    }

    /**
     * The caller must have completed preflight externally.  This method
     * requires the immutable preflight reference and independently rechecks
     * identity plus the absence of formal rows before activation.
     *
     * @return array{accepted:bool,status:string,reason:string,manifest:array<string,mixed>,activation:array<string,mixed>,implementation:array<string,string>}
     */
    public function activate(string $preflightReference, string $preflightStatus = 'PRECHECK_PASS'): array
    {
        if ($this->officialEpochStart() !== []) {
            $lifecycleRoot = rtrim((string)$this->repoRoot, DIRECTORY_SEPARATOR) . '/var/strict_forward_live_readiness/lifecycle';
            return (new OfficialEpochIncidentLifecycleStore($lifecycleRoot))->withGlobalEpochLock(
                fn(): array => $this->activateUnlocked($preflightReference, $preflightStatus, true)
            );
        }
        return $this->activateUnlocked($preflightReference, $preflightStatus, false);
    }

    /** @return array{accepted:bool,status:string,reason:string,manifest:array<string,mixed>,activation:array<string,mixed>,implementation:array<string,string>} */
    private function activateUnlocked(string $preflightReference, string $preflightStatus, bool $officialEpochActive): array
    {
        $manifest = $this->readManifest();
        $windowId = (string)($manifest['validation_window_id'] ?? '');
        if ($windowId !== '' && $this->isSuperseded($windowId)) return $this->blocked('WINDOW_PREPARED_SUPERSEDED', $manifest);
        if (!$this->isPrepared($manifest)) return $this->blocked('WINDOW_NOT_PREPARED', $manifest);
        if (trim($preflightReference) === '' || $preflightStatus !== 'PRECHECK_PASS') {
            return $this->blocked('PREFLIGHT_NOT_PASS', $manifest);
        }
        if ($this->formalRowCount('expected') !== 0 || $this->formalRowCount('records') !== 0) {
            return $this->blocked('PREPARED_WINDOW_FORMAL_ROWS_PRESENT', $manifest);
        }
        $binding = [];
        $epoch = [];
        $projection = [];
        if ($officialEpochActive) {
            $bindingValidation = (new OfficialEpochWindowBindingResolver((string)$this->repoRoot))->validateBinding(
                (string)$manifest['validation_window_id'],
                ['require_prepared' => true, 'require_pointer' => true]
            );
            if (empty($bindingValidation['accepted'])) return $this->blocked((string)$bindingValidation['reason'], $manifest);
            $binding = $bindingValidation['binding'];
            $epoch = $bindingValidation['epoch'];
            $projection = $bindingValidation['projection'];
        }
        $declaration = $this->declaration->assertCurrent();
        if (!$declaration['accepted']) return $this->blocked((string)$declaration['reason'], $manifest, [], $declaration['implementation']);
        $implementation = $declaration['implementation'];
        $activatedAt = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM);
        $activation = [
            'activation_schema_version' => $officialEpochActive ? self::ACTIVATION_SCHEMA_VERSION : self::ACTIVATION_SCHEMA_VERSION_V1,
            'window_id' => (string)$manifest['validation_window_id'],
            'preflight_status' => $preflightStatus,
            'preflight_reference' => $preflightReference,
            'implementation_identity' => $implementation['implementation_identity'],
            'manifest_schema_version' => $implementation['manifest_schema_version'],
            'manifest_sha256' => $implementation['manifest_sha256'],
            'manifest_ref' => $implementation['manifest_ref'],
            'source_freeze_sha256' => $implementation['source_freeze_sha256'],
            'source_freeze_ref' => $implementation['source_freeze_ref'],
            'comparative_contract' => $declaration['declaration']['comparative_contract'] ?? null,
            'activated_at' => $activatedAt,
        ];
        if ($officialEpochActive) {
            $activation['official_epoch_window_binding_ref'] = 'lifecycle/official_epoch_window_bindings/' . (string)$manifest['validation_window_id'] . '.json';
            $activation['official_epoch_window_binding_hash'] = (string)$binding['record_hash'];
            $activation['epoch_id'] = (string)$epoch['epoch_id'];
            $activation['epoch_projection_hash_at_activation'] = (string)$projection['state_hash'];
        }
        $existing = $this->readActivation();
        if ($existing !== []) {
            return $this->blocked('ACTIVATION_RECORD_ALREADY_EXISTS', $manifest, $existing, $implementation);
        }
        $activationPath = $this->resolvedActivationPath();
        $directory = dirname($activationPath);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) return $this->blocked('ACTIVATION_DIRECTORY_UNAVAILABLE', $manifest);
        $handle = @fopen($activationPath, 'x');
        if (!is_resource($handle)) return $this->blocked('ACTIVATION_RECORD_RACE_OR_EXISTS', $manifest);
        $ok = @fwrite($handle, self::json($activation) . "\n") !== false;
        @fclose($handle);
        if (!$ok) {
            @unlink($activationPath);
            return $this->blocked('ACTIVATION_RECORD_WRITE_FAILED', $manifest);
        }
        $active = $manifest;
        $active['activation_state'] = 'ACTIVE';
        $active['status'] = 'ACTIVE';
        $active['armed_at'] = $activatedAt;
        $active['activated_at'] = $activatedAt;
        if (!$this->replaceManifest($active)) {
            return $this->blocked('ACTIVATION_MANIFEST_TRANSITION_FAILED', $manifest, $activation, $implementation);
        }
        return [
            'accepted' => true,
            'status' => 'WINDOW_ACTIVATED',
            'reason' => '',
            'manifest' => $active,
            'activation' => $activation,
            'implementation' => $implementation,
        ];
    }

    /** @return array<string,mixed> */
    public function readActivation(): array
    {
        $path = $this->resolvedActivationPath();
        if (!is_file($path)) return [];
        try {
            $activation = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }
        return is_array($activation) ? $activation : [];
    }

    /** @return array<string,mixed> */
    public function readManifest(): array
    {
        try {
            $manifest = json_decode((string)file_get_contents($this->windowManifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }
        return is_array($manifest) ? $manifest : [];
    }

    private function resolvedActivationPath(): string
    {
        return $this->activationPath ?? dirname($this->windowManifestPath) . '/activation_record.json';
    }

    private function isPrepared(array $manifest): bool
    {
        return ($manifest['activation_state'] ?? '') === 'PREPARED'
            && ($manifest['status'] ?? '') === 'PREPARED'
            && empty($manifest['armed_at'])
            && is_string($manifest['validation_window_id'] ?? null)
            && $manifest['validation_window_id'] !== '';
    }

    private function isActive(array $manifest): bool
    {
        return ($manifest['activation_state'] ?? '') === 'ACTIVE'
            && ($manifest['status'] ?? '') === 'ACTIVE'
            && is_string($manifest['armed_at'] ?? null)
            && $manifest['armed_at'] !== ''
            && is_string($manifest['validation_window_id'] ?? null)
            && $manifest['validation_window_id'] !== '';
    }

    private function isSuperseded(string $windowId): bool
    {
        if ($this->repoRoot === null || $this->repoRoot === '') return false;
        $root = rtrim($this->repoRoot, DIRECTORY_SEPARATOR) . '/var/strict_forward_live_readiness/lifecycle';
        return (new OfficialEpochRecoveryLifecycleV1((string)$this->repoRoot, $root))->supersessionForWindow($windowId) !== [];
    }

    /** @param array<string,mixed> $activation @param array<string,mixed> $manifest @param array<string,string> $implementation */
    private function activationMatches(array $activation, array $manifest, array $implementation): bool
    {
        if (!in_array((string)($activation['activation_schema_version'] ?? ''), [self::ACTIVATION_SCHEMA_VERSION_V1, self::ACTIVATION_SCHEMA_VERSION], true)
            || ($activation['window_id'] ?? '') !== ($manifest['validation_window_id'] ?? '')
            || ($activation['preflight_status'] ?? '') !== 'PRECHECK_PASS'
            || !is_string($activation['preflight_reference'] ?? null)
            || $activation['preflight_reference'] === ''
            || ($activation['activated_at'] ?? '') !== ($manifest['armed_at'] ?? '')) return false;
        foreach (['implementation_identity', 'manifest_schema_version', 'manifest_sha256', 'manifest_ref', 'source_freeze_sha256', 'source_freeze_ref'] as $field) {
            if (!hash_equals((string)($implementation[$field] ?? ''), (string)($activation[$field] ?? ''))) return false;
        }
        if (array_key_exists('comparative_contract', $activation)
            && ($activation['comparative_contract'] ?? null) !== ($this->declaration->read()['declaration']['comparative_contract'] ?? null)) return false;
        if (($activation['activation_schema_version'] ?? '') === self::ACTIVATION_SCHEMA_VERSION) {
            foreach (['official_epoch_window_binding_ref', 'official_epoch_window_binding_hash', 'epoch_id', 'epoch_projection_hash_at_activation'] as $field) {
                if (!is_string($activation[$field] ?? null) || $activation[$field] === '') return false;
            }
        }
        return true;
    }

    /** @return array<string,mixed> */
    private function officialEpochStart(): array
    {
        if ($this->repoRoot === null || $this->repoRoot === '') return [];
        // Fixture and explicit historical contexts may be rooted outside the
        // formal Window lifecycle directory. They are not future operational
        // successors and must retain their isolated/pre-Epoch semantics.
        $officialWindows = rtrim($this->repoRoot, DIRECTORY_SEPARATOR) . '/var/strict_forward_live_readiness/windows/';
        if (!str_starts_with($this->windowManifestPath, $officialWindows)) return [];
        return (new OfficialEpochStartStore(rtrim($this->repoRoot, DIRECTORY_SEPARATOR) . '/var/strict_forward_live_readiness/lifecycle'))->existing();
    }

    private function formalRowCount(string $directory): int
    {
        return count(glob(rtrim($this->strictLedgerRoot, DIRECTORY_SEPARATOR) . '/' . $directory . '/*/*.json') ?: []);
    }

    /** @param array<string,mixed> $manifest */
    private function replaceManifest(array $manifest): bool
    {
        $path = $this->windowManifestPath;
        $tmp = $path . '.activate.' . bin2hex(random_bytes(8));
        if (@file_put_contents($tmp, self::json($manifest) . "\n") === false) return false;
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /** @return array{accepted:bool,status:string,reason:string,manifest:array<string,mixed>,activation:array<string,mixed>,implementation:array<string,string>} */
    private static function blocked(string $reason, array $manifest = [], array $activation = [], array $implementation = []): array
    {
        return ['accepted' => false, 'status' => 'WINDOW_ACTIVATION_HOLD', 'reason' => $reason, 'manifest' => $manifest, 'activation' => $activation, 'implementation' => $implementation];
    }

    private static function json(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
