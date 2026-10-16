<?php

declare(strict_types=1);

require_once __DIR__ . '/MeasurementImplementationProvenance.php';
require_once __DIR__ . '/ComparativeContractV1.php';
require_once __DIR__ . '/FormalMeasurementWindowLifecycle.php';

/**
 * First-write Window contract for one Strict Forward measurement identity.
 * It is separate from the immutable ARM manifest so an already armed Window
 * can gain provenance enforcement without being re-armed or rewritten.
 */
final class WindowDeclaredImplementationIdentity
{
    public const DECLARATION_SCHEMA_VERSION = 'window-declared-implementation-identity/v1';

    public function __construct(
        private readonly string $windowManifestPath,
        private readonly MeasurementImplementationProvenance $provenance,
        private readonly ?string $declarationPath = null,
    ) {
    }

    /**
     * Formal callers must now receive an explicit Window Context.  Retaining
     * no ambient production fallback prevents an arbitrary Window from being
     * verified against the old global manifest/ledger pair.
     */
    public static function production(string $strictLedgerRoot): self
    {
        throw new LogicException('FORMAL_WINDOW_CONTEXT_REQUIRED');
    }

    public function windowManifestPath(): string
    {
        return $this->windowManifestPath;
    }

    public function declarationPath(): string
    {
        return $this->resolvedDeclarationPath();
    }

    public function provenanceStorageRoot(): string
    {
        return $this->provenance->storageRoot();
    }

    public function repoRoot(): string
    {
        return $this->provenance->repoRoot();
    }

    /**
     * Initial declaration only. Existing declarations are never replaced.
     *
     * @return array{accepted:bool,status:string,reason:string,declaration:array<string,mixed>,implementation:array<string,string>}
     */
    public function declare(): array
    {
        $window = $this->windowManifest();
        if ($window === []) return $this->blocked('WINDOW_MANIFEST_INVALID');
        $state = $this->preFreezeWindowState($window);
        if ($state !== '') return $this->blocked($state);
        try {
            $implementation = $this->provenance->freeze();
            $verified = $this->provenance->verify($implementation['implementation_identity'], $implementation['manifest_ref'], $implementation['source_freeze_ref']);
        } catch (Throwable $error) {
            return $this->blocked('MEASUREMENT_IMPLEMENTATION_FREEZE_UNAVAILABLE:' . $error->getMessage());
        }
        if (!$verified['valid']) return $this->blocked('MEASUREMENT_IMPLEMENTATION_FREEZE_INVALID:' . $verified['reason']);

        $existing = $this->read();
        if (($existing['reason'] ?? '') !== 'DECLARATION_MISSING') {
            if (!$existing['accepted']) return $existing;
            if (!hash_equals((string)$existing['declaration']['implementation_identity'], $implementation['implementation_identity'])) {
                return $this->blocked('DECLARED_IMPLEMENTATION_IDENTITY_MISMATCH', $existing['declaration'], $implementation);
            }
            return $existing;
        }

        $comparative = ComparativeContractV1::production($this->repoRoot())->identities();
        $declaration = [
            'declaration_schema_version' => self::DECLARATION_SCHEMA_VERSION,
            'window_id' => (string)$window['validation_window_id'],
            'implementation_identity' => $implementation['implementation_identity'],
            'manifest_schema_version' => $implementation['manifest_schema_version'],
            'manifest_sha256' => $implementation['manifest_sha256'],
            'manifest_ref' => $implementation['manifest_ref'],
            'source_freeze_sha256' => $implementation['source_freeze_sha256'],
            'source_freeze_ref' => $implementation['source_freeze_ref'],
            // Optional for historical declarations; required for every new
            // declaration made by this v1-capable code.
            'comparative_contract' => $comparative,
            'declared_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM),
        ];
        $path = $this->resolvedDeclarationPath();
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) return $this->blocked('DECLARATION_DIRECTORY_UNAVAILABLE');
        $bytes = json_encode($declaration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $handle = @fopen($path, 'x');
        if (is_resource($handle)) {
            $ok = @fwrite($handle, $bytes) !== false;
            @fclose($handle);
            if (!$ok) {
                @unlink($path);
                return $this->blocked('DECLARATION_WRITE_FAILED');
            }
            return $this->accepted('DECLARED_CREATED', $declaration, $implementation);
        }
        // A concurrent or restarted caller must only reuse an already valid
        // declaration. It never silently refreshes it.
        return $this->read();
    }

    /**
     * @return array{accepted:bool,status:string,reason:string,declaration:array<string,mixed>,implementation:array<string,string>}
     */
    public function read(bool $historical = false): array
    {
        $window = $this->windowManifest();
        if ($window === []) return $this->blocked('WINDOW_MANIFEST_INVALID');
        $path = $this->resolvedDeclarationPath();
        if (!is_file($path)) return $this->blocked('DECLARATION_MISSING');
        try {
            $declaration = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $this->blocked('DECLARATION_INVALID_JSON');
        }
        if (!is_array($declaration)
            || ($declaration['declaration_schema_version'] ?? '') !== self::DECLARATION_SCHEMA_VERSION
            || ($declaration['window_id'] ?? '') !== (string)$window['validation_window_id']) {
            return $this->blocked('DECLARATION_WINDOW_OR_SCHEMA_MISMATCH');
        }
        // A historical evaluator may only use the contract frozen into this
        // Window.  Runtime writers retain the current-config comparison.
        if (!$historical && array_key_exists('comparative_contract', $declaration)) {
            try {
                $currentContract = ComparativeContractV1::production($this->repoRoot())->identities();
            } catch (Throwable) {
                return $this->blocked('DECLARATION_COMPARATIVE_CONTRACT_INVALID', $declaration);
            }
            if (!is_array($declaration['comparative_contract']) || $declaration['comparative_contract'] !== $currentContract) {
                return $this->blocked('DECLARATION_COMPARATIVE_CONTRACT_MISMATCH', $declaration);
            }
        }
        $identity = (string)($declaration['implementation_identity'] ?? '');
        $manifestRef = (string)($declaration['manifest_ref'] ?? '');
        $freezeRef = (string)($declaration['source_freeze_ref'] ?? '');
        $verified = $this->provenance->verify($identity, $manifestRef, $freezeRef);
        if (!$verified['valid']
            || !hash_equals((string)($declaration['manifest_sha256'] ?? ''), $verified['manifest_sha256'])
            || !hash_equals((string)($declaration['source_freeze_sha256'] ?? ''), $verified['source_freeze_sha256'])
            || (string)($declaration['manifest_schema_version'] ?? '') !== MeasurementImplementationProvenance::MANIFEST_SCHEMA_VERSION) {
            return $this->blocked('DECLARATION_PROVENANCE_INVALID', $declaration);
        }
        return $this->accepted('DECLARATION_VALID', $declaration, [
            'implementation_identity' => $identity,
            'manifest_schema_version' => (string)$declaration['manifest_schema_version'],
            'manifest_sha256' => (string)$declaration['manifest_sha256'],
            'manifest_ref' => $manifestRef,
            'source_freeze_sha256' => (string)$declaration['source_freeze_sha256'],
            'source_freeze_ref' => $freezeRef,
        ]);
    }

    /**
     * Re-freezes the current worktree, then permits formal measurement only
     * when it exactly equals the immutable Window declaration.
     *
     * @return array{accepted:bool,status:string,reason:string,declaration:array<string,mixed>,implementation:array<string,string>}
     */
    public function assertCurrent(): array
    {
        $window = $this->windowManifest();
        if ($window === []) return $this->blocked('WINDOW_MANIFEST_INVALID');
        $state = $this->preFreezeWindowState($window);
        if ($state !== '') return $this->blocked($state);
        $declared = $this->read();
        if (!$declared['accepted']) return $declared;
        try {
            $current = $this->provenance->freeze();
            $verified = $this->provenance->verify($current['implementation_identity'], $current['manifest_ref'], $current['source_freeze_ref']);
        } catch (Throwable $error) {
            return $this->blocked('CURRENT_IMPLEMENTATION_FREEZE_UNAVAILABLE:' . $error->getMessage(), $declared['declaration']);
        }
        if (!$verified['valid']) return $this->blocked('CURRENT_IMPLEMENTATION_FREEZE_INVALID:' . $verified['reason'], $declared['declaration'], $current);
        foreach (['implementation_identity', 'manifest_schema_version', 'manifest_sha256', 'manifest_ref', 'source_freeze_sha256', 'source_freeze_ref'] as $key) {
            if (!hash_equals((string)($declared['implementation'][$key] ?? ''), (string)($current[$key] ?? ''))) {
                return $this->blocked('DECLARED_IMPLEMENTATION_IDENTITY_MISMATCH', $declared['declaration'], $current);
            }
        }
        return $this->accepted('DECLARED_IMPLEMENTATION_IDENTITY_MATCH', $declared['declaration'], $current);
    }

    private function resolvedDeclarationPath(): string
    {
        return $this->declarationPath ?? dirname($this->windowManifestPath) . '/declared_implementation_identity.json';
    }

    /**
     * A declaration or current-identity assertion may create Window-local
     * provenance. Terminal Windows are historical evidence, so this guard
     * runs before every such freeze. Historical read() remains deliberately
     * available to evaluators and does not call this method.
     *
     * @param array<string,mixed> $window
     */
    private function preFreezeWindowState(array $window): string
    {
        $windowId = (string)($window['validation_window_id'] ?? '');
        if ($windowId === '') return 'WINDOW_MANIFEST_INVALID';

        // A terminal state embedded directly in a manifest is blocked even
        // if an external lifecycle record is unavailable or malformed.
        $declaredState = strtoupper(trim((string)($window['status'] ?? '')));
        if ($declaredState === 'CLOSED') return 'WINDOW_CLOSED';
        if ($declaredState === 'ABORTED') return 'WINDOW_ABORTED';

        try {
            $state = (new FormalMeasurementWindowLifecycle($this->repoRoot()))
                ->effectiveState($windowId, $window);
        } catch (Throwable) {
            return 'WINDOW_STATE_UNVERIFIED';
        }
        return match ((string)($state['effective_state'] ?? 'INVALID')) {
            'PREPARED', 'ACTIVE' => '',
            'CLOSED' => 'WINDOW_CLOSED',
            'ABORTED' => 'WINDOW_ABORTED',
            default => 'WINDOW_STATE_INVALID',
        };
    }

    /** @return array<string,mixed> */
    private function windowManifest(): array
    {
        try {
            $manifest = json_decode((string)file_get_contents($this->windowManifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }
        return is_array($manifest) && is_string($manifest['validation_window_id'] ?? null) && $manifest['validation_window_id'] !== '' ? $manifest : [];
    }

    /** @param array<string,mixed> $declaration @param array<string,string> $implementation */
    private function accepted(string $status, array $declaration, array $implementation): array
    {
        return ['accepted' => true, 'status' => $status, 'reason' => '', 'declaration' => $declaration, 'implementation' => $implementation];
    }

    /** @param array<string,mixed> $declaration @param array<string,string> $implementation */
    private function blocked(string $reason, array $declaration = [], array $implementation = []): array
    {
        return ['accepted' => false, 'status' => 'BLOCKED_IMPLEMENTATION_PROVENANCE', 'reason' => $reason, 'declaration' => $declaration, 'implementation' => $implementation];
    }
}
