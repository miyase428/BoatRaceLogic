<?php

declare(strict_types=1);

require_once __DIR__ . '/MeasurementImplementationProvenance.php';
require_once __DIR__ . '/FormalWindowProvenanceResolver.php';

/**
 * Formal Evidence Aggregate v2.
 *
 * Evidence is selected by its role in a measurement decision, never merely
 * because it happens to live below a Window directory. Provenance snapshots
 * are reachable only through immutable identity references recorded by
 * declaration, activation, Expected, or Canonical evidence.
 */
final class FormalEvidenceAggregate
{
    public const SCHEMA_VERSION = 'FORMAL_EVIDENCE_AGGREGATE_V2';

    /**
     * @return array{schema_version:string,window_id:string,capture_through_date:string,generated_for_verification_only:bool,file_count:int,entries:list<array{relative_path:string,sha256:string}>,aggregate_sha256:string,referenced_implementation_identities:list<string>,excluded_auxiliary_provenance:list<string>}
     */
    public static function calculate(string $windowRoot, string $windowId, string $captureThroughDate): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $captureThroughDate)) {
            throw new InvalidArgumentException('aggregate_capture_through_date_invalid');
        }
        $root = rtrim($windowRoot, DIRECTORY_SEPARATOR);
        $files = [];
        foreach (['validation_window_manifest.json', 'activation_record.json', 'declared_implementation_identity.json'] as $relative) {
            self::addRequiredFile($files, $root, $relative);
        }
        // Legacy windows predating persisted preflight may not have this
        // optional attestation. If present it is formal evidence; its absence
        // is handled by the relevant gate rather than an aggregate crash.
        self::addFile($files, $root, 'preflight.json');

        $directories = [
            'opportunity/candidates', 'opportunity/eligibility', 'opportunity/observations',
            'opportunity/expected_inventory', 'opportunity/operational', 'opportunity/source_failures',
            'opportunity/schedule_revisions', 'ledger/expected', 'ledger/records',
            'ledger/operational', 'ledger/failures', 'comparative_policy',
        ];
        // Preserve legacy aggregate semantics byte-for-byte. New evidence is
        // included only for Windows whose immutable declaration explicitly
        // opted into Comparative Contract v1.
        if (self::usesComparativeContractV1($root)) {
            array_push(
                $directories,
                'input_snapshots',
                'comparative/pairs',
                'comparative/captures',
                'comparative/failures',
                'comparative/conflicts',
                'comparative/evaluations',
            );
        }
        foreach ($directories as $relativeDirectory) {
            self::addEvidenceDirectory($files, $root, $relativeDirectory, $captureThroughDate);
        }

        $references = self::collectReachableImplementationReferences($root, $files);
        foreach ($references as $reference) self::verifyAndAddProvenance($files, $root, $reference);

        // Comparative v1 Pair and Snapshot records deliberately do not repeat
        // every Window-level provenance field.  Resolve those fields only via
        // the immutable target Window chain; never from current routing or
        // current configuration.
        $resolution = self::hasComparativeCaptureEvidence($files)
            ? (new FormalWindowProvenanceResolver($root, $windowId))->resolve($files)
            : [];

        $excluded = self::auxiliaryProvenance($root, $files);
        ksort($files, SORT_STRING);
        $entries = [];
        $framed = '';
        foreach ($files as $relative => $sha256) {
            $entries[] = ['relative_path' => $relative, 'sha256' => $sha256];
            $framed .= $relative . "\0" . $sha256 . "\n";
        }
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'window_id' => $windowId,
            'capture_through_date' => $captureThroughDate,
            'generated_for_verification_only' => true,
            'file_count' => count($entries),
            'entries' => $entries,
            'aggregate_sha256' => 'sha256:' . hash('sha256', $framed),
            'referenced_implementation_identities' => array_values(array_keys($references)),
            'excluded_auxiliary_provenance' => $excluded,
            'provenance_resolution' => $resolution,
        ];
    }

    private static function usesComparativeContractV1(string $root): bool
    {
        $path = $root . '/declared_implementation_identity.json';
        if (!is_file($path)) return false;
        try { $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable) { return false; }
        return is_array($row)
            && is_array($row['comparative_contract'] ?? null)
            && is_string($row['comparative_contract']['comparative_scope_identity'] ?? null)
            && is_string($row['comparative_contract']['comparative_policy_identity'] ?? null);
    }

    /** @param array<string,string> $files */
    private static function hasComparativeCaptureEvidence(array $files): bool
    {
        foreach (array_keys($files) as $relative) {
            if (str_starts_with($relative, 'input_snapshots/')
                || str_starts_with($relative, 'comparative/pairs/')
                || str_starts_with($relative, 'comparative/captures/')) return true;
        }
        return false;
    }

    /** @param array<string,string> $files */
    private static function addRequiredFile(array &$files, string $root, string $relative): void
    {
        if (!is_file($root . '/' . $relative)) throw new RuntimeException('aggregate_required_evidence_missing:' . $relative);
        self::addFile($files, $root, $relative);
    }

    /** @param array<string,string> $files */
    private static function addFile(array &$files, string $root, string $relative): void
    {
        $path = $root . '/' . $relative;
        if (!is_file($path)) return;
        $hash = hash_file('sha256', $path);
        if (!is_string($hash)) throw new RuntimeException('aggregate_hash_failed:' . $relative);
        $files[str_replace('\\', '/', $relative)] = $hash;
    }

    /** @param array<string,string> $files */
    private static function addEvidenceDirectory(array &$files, string $root, string $relativeDirectory, string $through): void
    {
        $directory = $root . '/' . $relativeDirectory;
        if (!is_dir($directory)) return;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) continue;
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($file->getPathname(), strlen($root)), DIRECTORY_SEPARATOR));
            if (self::isAfterCaptureDate($relative, $through)) continue;
            self::addFile($files, $root, $relative);
        }
    }

    private static function isAfterCaptureDate(string $relative, string $through): bool
    {
        $throughCompact = str_replace('-', '', $through);
        if (preg_match('#/(\d{8})(?:/|\.jsonl?$)#', $relative, $match) !== 1) return false;
        return strcmp($match[1], $throughCompact) > 0;
    }

    /**
     * @param array<string,string> $files
     * @return array<string,array{implementation_identity:string,manifest_schema_version:string,manifest_sha256:string,manifest_ref:string,source_freeze_sha256:string,source_freeze_ref:string}>
     */
    private static function collectReachableImplementationReferences(string $root, array $files): array
    {
        $references = [];
        foreach (array_keys($files) as $relative) {
            if (!str_ends_with($relative, '.json')) continue;
            $row = self::readJson($root . '/' . $relative, 'aggregate_json_invalid:' . $relative);
            foreach (self::referencesInRow($row) as $reference) {
                $identity = $reference['implementation_identity'];
                if (isset($references[$identity]) && $references[$identity] !== $reference) {
                    throw new RuntimeException('aggregate_implementation_reference_conflict:' . $identity);
                }
                $references[$identity] = $reference;
            }
        }
        ksort($references, SORT_STRING);
        return $references;
    }

    /** @return list<array{implementation_identity:string,manifest_schema_version:string,manifest_sha256:string,manifest_ref:string,source_freeze_sha256:string,source_freeze_ref:string}> */
    private static function referencesInRow(array $row): array
    {
        $maps = [
            ['implementation_identity' => 'implementation_identity', 'manifest_schema_version' => 'manifest_schema_version', 'manifest_sha256' => 'manifest_sha256', 'manifest_ref' => 'manifest_ref', 'source_freeze_sha256' => 'source_freeze_sha256', 'source_freeze_ref' => 'source_freeze_ref'],
            ['implementation_identity' => 'measurement_implementation_identity', 'manifest_schema_version' => 'measurement_manifest_schema_version', 'manifest_sha256' => 'measurement_manifest_sha256', 'manifest_ref' => 'measurement_manifest_ref', 'source_freeze_sha256' => 'measurement_source_freeze_sha256', 'source_freeze_ref' => 'measurement_source_freeze_ref'],
        ];
        $result = [];
        foreach ($maps as $map) {
            $identity = trim((string)($row[$map['implementation_identity']] ?? ''));
            if ($identity === '') continue;
            $reference = [
                'implementation_identity' => $identity,
                'manifest_schema_version' => trim((string)($row[$map['manifest_schema_version']] ?? '')),
                'manifest_sha256' => trim((string)($row[$map['manifest_sha256']] ?? '')),
                'manifest_ref' => trim((string)($row[$map['manifest_ref']] ?? '')),
                'source_freeze_sha256' => trim((string)($row[$map['source_freeze_sha256']] ?? '')),
                'source_freeze_ref' => trim((string)($row[$map['source_freeze_ref']] ?? '')),
            ];
            foreach ($reference as $value) {
                if ($value !== '') continue;
                // Pair v1 records bind their Measurement identity directly,
                // but intentionally dereference manifest/source-freeze via
                // Window Declaration + Activation.  Any other incomplete
                // measurement reference remains fail-closed.
                if (array_key_exists('pair_status', $row)
                    && isset($row['window_id'], $row['input_snapshot_identity'])
                    && $map['implementation_identity'] === 'measurement_implementation_identity') {
                    continue 2;
                }
                throw new RuntimeException('aggregate_implementation_reference_incomplete:' . $identity);
            }
            $result[] = $reference;
        }
        return $result;
    }

    /** @param array<string,string> $files @param array{implementation_identity:string,manifest_schema_version:string,manifest_sha256:string,manifest_ref:string,source_freeze_sha256:string,source_freeze_ref:string} $reference */
    private static function verifyAndAddProvenance(array &$files, string $root, array $reference): void
    {
        if ($reference['manifest_schema_version'] !== MeasurementImplementationProvenance::MANIFEST_SCHEMA_VERSION) {
            throw new RuntimeException('aggregate_manifest_schema_invalid:' . $reference['implementation_identity']);
        }
        $storage = new MeasurementImplementationProvenance($root, $root . '/provenance');
        $verified = $storage->verify($reference['implementation_identity'], $reference['manifest_ref'], $reference['source_freeze_ref']);
        if (!$verified['valid']
            || !hash_equals($reference['manifest_sha256'], $verified['manifest_sha256'])
            || !hash_equals($reference['source_freeze_sha256'], $verified['source_freeze_sha256'])) {
            throw new RuntimeException('aggregate_referenced_provenance_invalid:' . $reference['implementation_identity']);
        }
        self::addRequiredFile($files, $root, 'provenance/' . $reference['manifest_ref']);
        self::addRequiredFile($files, $root, 'provenance/' . $reference['source_freeze_ref']);
    }

    /** @param array<string,string> $files @return list<string> */
    private static function auxiliaryProvenance(string $root, array $files): array
    {
        $included = array_fill_keys(array_keys($files), true);
        $excluded = [];
        foreach (['provenance/implementation_manifests', 'provenance/implementation_sources'] as $directory) {
            $absolute = $root . '/' . $directory;
            if (!is_dir($absolute)) continue;
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) continue;
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', ltrim(substr($file->getPathname(), strlen($root)), DIRECTORY_SEPARATOR));
                if (!isset($included[$relative])) $excluded[] = $relative;
            }
        }
        sort($excluded, SORT_STRING);
        return $excluded;
    }

    /** @return array<string,mixed> */
    private static function readJson(string $path, string $error): array
    {
        try {
            $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException($error);
        }
        if (!is_array($row)) throw new RuntimeException($error);
        return $row;
    }
}
