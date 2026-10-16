<?php

declare(strict_types=1);

require_once __DIR__ . '/WindowActivationHold.php';
require_once __DIR__ . '/FormalMeasurementWindowLifecycle.php';
require_once __DIR__ . '/OfficialEpochWindowBindingRuntimeAuthorization.php';

/**
 * The sole routing authority for formal Strict Forward measurement.
 *
 * A Window owns its declaration, immutable provenance, opportunity ledger,
 * and Expected/Canonical ledger.  Production may use a Window only through
 * an explicit pointer; it never searches another Window or legacy root.
 */
final class FormalMeasurementWindowContext
{
    public const POINTER_SCHEMA_VERSION = 'formal-measurement-window-pointer/v1';
    public const POINTER_RELATIVE_PATH = 'var/strict_forward_live_readiness/formal_measurement_window_context.json';

    private function __construct(
        private readonly string $repoRoot,
        private readonly string $windowManifestPath,
        private readonly string $ledgerRoot,
        private readonly string $opportunityRoot,
        private readonly WindowDeclaredImplementationIdentity $declaration,
        private readonly WindowActivationHold $activationHold,
    ) {
    }

    /**
     * Canonical layout for every future Window.  No files are created here.
     */
    /** @param list<string>|null $criticalPaths Fixture-only override for provenance tests. */
    public static function fromWindowRoot(string $repoRoot, string $windowRoot, ?array $criticalPaths = null): self
    {
        $root = rtrim($windowRoot, DIRECTORY_SEPARATOR);
        $manifest = $root . '/validation_window_manifest.json';
        $provenance = new MeasurementImplementationProvenance(rtrim($repoRoot, DIRECTORY_SEPARATOR), $root . '/provenance', $criticalPaths);
        $declaration = new WindowDeclaredImplementationIdentity($manifest, $provenance, $root . '/declared_implementation_identity.json');
        $hold = new WindowActivationHold($manifest, $declaration, $root . '/ledger', $root . '/activation_record.json', rtrim($repoRoot, DIRECTORY_SEPARATOR));
        return new self(rtrim($repoRoot, DIRECTORY_SEPARATOR), $manifest, $root . '/ledger', $root . '/opportunity', $declaration, $hold);
    }

    /**
     * Fixture/adapter constructor.  It preserves the supplied dependency
     * graph rather than resolving any ambient production path.
     */
    public static function fromBoundComponents(
        string $repoRoot,
        string $ledgerRoot,
        string $opportunityRoot,
        WindowDeclaredImplementationIdentity $declaration,
        WindowActivationHold $activationHold,
    ): self {
        return new self(
            rtrim($repoRoot, DIRECTORY_SEPARATOR),
            $activationHold->windowManifestPath(),
            rtrim($ledgerRoot, DIRECTORY_SEPARATOR),
            rtrim($opportunityRoot, DIRECTORY_SEPARATOR),
            $declaration,
            $activationHold,
        );
    }

    /**
     * Production resolution requires an explicit first-write pointer.  The
     * absent-pointer failure is intentional: it prevents a new Window from
     * silently writing to the legacy production ledger.
     */
    public static function production(string $repoRoot): self
    {
        $repo = rtrim($repoRoot, DIRECTORY_SEPARATOR);
        $pointerPath = $repo . '/' . self::POINTER_RELATIVE_PATH;
        if (!is_file($pointerPath)) throw new RuntimeException('FORMAL_WINDOW_CONTEXT_POINTER_MISSING');
        try {
            $pointer = json_decode((string)file_get_contents($pointerPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('FORMAL_WINDOW_CONTEXT_POINTER_INVALID');
        }
        if (!is_array($pointer)
            || ($pointer['schema_version'] ?? '') !== self::POINTER_SCHEMA_VERSION
            || !is_string($pointer['window_root'] ?? null)
            || !is_string($pointer['window_id'] ?? null)
            || !self::validRelativePath($pointer['window_root'])) {
            throw new RuntimeException('FORMAL_WINDOW_CONTEXT_POINTER_INVALID');
        }
        $context = self::fromWindowRoot($repo, $repo . '/' . $pointer['window_root']);
        $resolved = $context->resolve();
        if (!$resolved['accepted'] || !hash_equals($pointer['window_id'], $resolved['window_id'])) {
            throw new RuntimeException('FORMAL_WINDOW_CONTEXT_POINTER_WINDOW_MISMATCH');
        }
        return $context;
    }

    /** @return array{accepted:bool,status:string,reason:string,window_id:string,manifest:array<string,mixed>,declaration:array<string,mixed>} */
    public function resolve(bool $historical = false): array
    {
        try {
            $manifest = json_decode((string)file_get_contents($this->windowManifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $this->blocked('WINDOW_MANIFEST_INVALID');
        }
        if (!is_array($manifest) || !is_string($manifest['validation_window_id'] ?? null) || $manifest['validation_window_id'] === '') {
            return $this->blocked('WINDOW_MANIFEST_INVALID');
        }
        $declaration = $this->declaration->read($historical);
        if (!$declaration['accepted']) {
            return $this->blocked((string)$declaration['reason'], (string)$manifest['validation_window_id'], $manifest, $declaration['declaration']);
        }
        if (!hash_equals((string)$manifest['validation_window_id'], (string)($declaration['declaration']['window_id'] ?? ''))) {
            return $this->blocked('WINDOW_DECLARATION_WINDOW_MISMATCH', (string)$manifest['validation_window_id'], $manifest, $declaration['declaration']);
        }
        return ['accepted' => true, 'status' => 'WINDOW_CONTEXT_RESOLVED', 'reason' => '', 'window_id' => (string)$manifest['validation_window_id'], 'manifest' => $manifest, 'declaration' => $declaration['declaration']];
    }

    /** @return array{accepted:bool,status:string,reason:string,window_id:string,manifest:array<string,mixed>,declaration:array<string,mixed>,activation:array<string,mixed>,implementation:array<string,string>} */
    public function authorizeFormalWrite(): array
    {
        $resolved = $this->resolve();
        if (!$resolved['accepted']) return $this->authorizationBlocked($resolved['reason'], $resolved);
        $authorization = $this->activationHold->authorizeFormalWrite();
        if (!$authorization['accepted']) {
            return $this->authorizationBlocked((string)$authorization['reason'], $resolved, $authorization['activation'] ?? [], $authorization['implementation'] ?? []);
        }
        $windowId = $resolved['window_id'];
        if (!hash_equals($windowId, (string)($authorization['manifest']['validation_window_id'] ?? ''))
            || !hash_equals($windowId, (string)($resolved['declaration']['window_id'] ?? ''))
            || !hash_equals($windowId, (string)($authorization['activation']['window_id'] ?? ''))) {
            return $this->authorizationBlocked('FORMAL_WINDOW_CONTEXT_IDENTITY_MISMATCH', $resolved, $authorization['activation'], $authorization['implementation']);
        }
        $epoch = (new OfficialEpochWindowBindingRuntimeAuthorization($this->repoRoot))
            ->authorizeWindowForFormalWrite($this, $resolved, $authorization);
        if (empty($epoch['accepted'])) {
            return $this->authorizationBlocked((string)($epoch['reason'] ?? 'OFFICIAL_EPOCH_WINDOW_BINDING_INVALID'), $resolved, $authorization['activation'], $authorization['implementation']);
        }
        return [
            'accepted' => true,
            'status' => 'FORMAL_WINDOW_CONTEXT_AUTHORIZED',
            'reason' => '',
            'window_id' => $windowId,
            'manifest' => $authorization['manifest'],
            'declaration' => $resolved['declaration'],
            'activation' => $authorization['activation'],
            'implementation' => $authorization['implementation'],
            'official_epoch_binding' => $epoch['binding'] ?? [],
            'official_epoch' => $epoch['epoch'] ?? [],
            'official_epoch_projection' => $epoch['projection'] ?? [],
        ];
    }

    /** @return array{accepted:bool,status:string,reason:string,window_id:string,manifest:array<string,mixed>,declaration:array<string,mixed>,activation:array<string,mixed>,implementation:array<string,string>} */
    public function authorizeMeasurementWrite(): array
    {
        $resolved = $this->resolve();
        if (!$resolved['accepted']) return $this->authorizationBlocked($resolved['reason'], $resolved);
        $lifecycle = $this->lifecycle()->authorizeWrite((string)$resolved['window_id'], $resolved['manifest']);
        if (!$lifecycle['accepted']) return $this->authorizationBlocked($lifecycle['reason'], $resolved);
        return $this->authorizeFormalWrite();
    }

    public function windowId(bool $historical = false): string
    {
        $resolved = $this->resolve($historical);
        return $resolved['accepted'] ? $resolved['window_id'] : '';
    }

    public function ledgerRoot(): string { return $this->ledgerRoot; }
    public function opportunityRoot(): string { return $this->opportunityRoot; }
    public function windowManifestPath(): string { return $this->windowManifestPath; }
    public function declaration(): WindowDeclaredImplementationIdentity { return $this->declaration; }
    public function activationHold(): WindowActivationHold { return $this->activationHold; }
    public function lifecycle(): FormalMeasurementWindowLifecycle { return new FormalMeasurementWindowLifecycle($this->repoRoot); }
    public function repoRoot(): string { return $this->repoRoot; }

    /** @param array<string,mixed> $pointer */
    public static function validPointerPayload(array $pointer): bool
    {
        return ($pointer['schema_version'] ?? '') === self::POINTER_SCHEMA_VERSION
            && is_string($pointer['window_root'] ?? null)
            && is_string($pointer['window_id'] ?? null)
            && self::validRelativePath((string)$pointer['window_root']);
    }

    /** @return array{accepted:bool,status:string,reason:string,window_id:string,manifest:array<string,mixed>,declaration:array<string,mixed>} */
    private function blocked(string $reason, string $windowId = '', array $manifest = [], array $declaration = []): array
    {
        return ['accepted' => false, 'status' => 'WINDOW_CONTEXT_UNRESOLVED', 'reason' => $reason, 'window_id' => $windowId, 'manifest' => $manifest, 'declaration' => $declaration];
    }

    /** @param array<string,mixed> $resolved @param array<string,mixed> $activation @param array<string,string> $implementation */
    private function authorizationBlocked(string $reason, array $resolved, array $activation = [], array $implementation = []): array
    {
        return [
            'accepted' => false,
            'status' => 'WINDOW_CONTEXT_WRITE_BLOCKED',
            'reason' => $reason,
            'window_id' => (string)($resolved['window_id'] ?? ''),
            'manifest' => $resolved['manifest'] ?? [],
            'declaration' => $resolved['declaration'] ?? [],
            'activation' => $activation,
            'implementation' => $implementation,
        ];
    }

    private static function validRelativePath(string $path): bool
    {
        return $path !== '' && !str_starts_with($path, '/') && !str_contains($path, '..') && !str_contains($path, "\0");
    }
}
