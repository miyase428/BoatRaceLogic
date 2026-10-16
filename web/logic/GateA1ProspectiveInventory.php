<?php

declare(strict_types=1);

require_once __DIR__ . '/FormalMeasurementWindowContext.php';

/**
 * Independent, window-local source inventory for Gate A1.
 *
 * Candidate rows remain an immutable scheduler observation.  This class reads
 * the official-deadline cache directly and freezes a separate first-write
 * expected inventory only after the Window has become ACTIVE.  Gate A1 can
 * therefore detect missing or unexpected Candidate observations without
 * deriving its expectation from the Candidate ledger itself.
 */
final class GateA1ProspectiveInventory
{
    public const SCHEMA_VERSION = 'gate-a1-prospective-inventory/v1';
    public const AUTHORITATIVE_SNAPSHOT_SCHEMA_VERSION = 'strict-forward-authoritative-deadline-snapshot/v2';
    public const LEGACY_AUTHORITATIVE_SNAPSHOT_SCHEMA_VERSION = 'strict-forward-authoritative-deadline-snapshot/v1';
    public const DEADLINE_SEMANTIC_IDENTITY_CONTRACT_VERSION = 'strict-forward-deadline-semantic-identity/v1';
    public const DEADLINE_ACQUISITION_IDENTITY_CONTRACT_VERSION = 'strict-forward-deadline-acquisition-identity/v1';
    public const PROSPECTIVE_FILTER_CONTRACT_VERSION = 'strict-forward-prospective-cutoff-gt-armed-at/v1';

    /** @var list<string> */
    private const STRICT_VENUES = ['AMG', 'ASY', 'BWK', 'EDG', 'HWJ', 'KRY', 'MKN', 'TMG', 'TDA', 'OMR', 'SMS', 'SME'];

    public function __construct(private readonly string $opportunityRoot)
    {
    }

    public static function isStrictVenue(string $place): bool
    {
        return in_array(strtoupper(trim($place)), self::STRICT_VENUES, true);
    }

    /** @return list<string> */
    public static function strictVenues(): array
    {
        return self::STRICT_VENUES;
    }

    /**
     * Scheduler-facing first-write hook. PREPARED is deliberately a no-op:
     * Candidate collection may occur there, but an expected source inventory
     * belongs to the prospective ACTIVE Window only.
     */
    public static function freezeFromContext(string $date, string $datePrefix): void
    {
        try {
            $context = FormalMeasurementWindowContext::production(dirname(__DIR__, 2));
            $authorization = $context->authorizeFormalWrite();
            if (empty($authorization['accepted'])) return;
            $armedAt = trim((string)($authorization['manifest']['armed_at'] ?? ''));
            if (!self::isTimestamp($armedAt)) throw new RuntimeException('gate_a1_armed_at_missing');
            (new self($context->opportunityRoot()))->freezeOfficialCache(
                $date,
                $datePrefix,
                $context->windowId(),
                $armedAt,
            );
        } catch (Throwable $error) {
            // This is an additive denominator source. A later Gate A1 check
            // fails loud if the expected inventory cannot be found or read.
            error_log('gate a1 prospective inventory freeze failed open: ' . $error->getMessage());
        }
    }

    /**
     * Turns the already-loaded OfficialDeadlineLogic result into the only
     * scheduler input permitted for an A1 bootstrap.  This deliberately does
     * not re-open the cache: a second read would reintroduce the cache refresh
     * TOCTOU that caused the empty first-write incident.
     *
     * @param array<string,mixed> $deadlineResult
     * @param list<string> $requestedPlaces
     * @return array{accepted:bool,status:string,reason:string,snapshot:array<string,mixed>}
     */
    public static function validateAuthoritativeDeadlineResult(string $date, string $datePrefix, array $deadlineResult, array $requestedPlaces): array
    {
        $empty = ['accepted' => false, 'status' => 'AUTHORITATIVE_DEADLINE_SNAPSHOT_INVALID', 'reason' => '', 'snapshot' => []];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{8}$/', $datePrefix)
            || str_replace('-', '', $date) !== $datePrefix) {
            $empty['reason'] = 'AUTHORITATIVE_DATE_INVALID';
            return $empty;
        }
        if ((string)($deadlineResult['date'] ?? '') !== $datePrefix
            || !in_array((string)($deadlineResult['status'] ?? ''), ['ok', 'partial'], true)
            || !is_array($deadlineResult['deadlines'] ?? null)
            || !is_array($deadlineResult['errors'] ?? null)
            || !self::isTimestamp(trim((string)($deadlineResult['fetched_at'] ?? '')))) {
            $empty['reason'] = 'AUTHORITATIVE_DEADLINE_RESULT_INVALID';
            return $empty;
        }
        $strictRequested = array_values(array_unique(array_filter(array_map(
            static fn(mixed $place): string => strtoupper(trim((string)$place)),
            $requestedPlaces,
        ), static fn(string $place): bool => self::isStrictVenue($place))));
        sort($strictRequested, SORT_STRING);
        $errors = $deadlineResult['errors'];
        $strictErrors = [];
        foreach ($strictRequested as $place) {
            $error = trim((string)($errors[$place] ?? ''));
            $strictErrors[$place] = $error;
            if ($error !== '') {
                $empty['reason'] = 'AUTHORITATIVE_STRICT_VENUE_ERROR';
                return $empty;
            }
        }
        $entries = [];
        $seen = [];
        foreach ($deadlineResult['deadlines'] as $raceCode => $time) {
            $raceCode = strtoupper(trim((string)$raceCode));
            if (preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $raceCode) !== 1) continue;
            $venue = substr($raceCode, 8, 3);
            if (!self::isStrictVenue($venue)) continue;
            if (!in_array($venue, $strictRequested, true) || !preg_match('/^\d{2}:\d{2}$/', (string)$time)) {
                $empty['reason'] = 'AUTHORITATIVE_DEADLINE_ENTRY_INVALID';
                return $empty;
            }
            try {
                $cutoff = (new DateTimeImmutable($date . ' ' . $time . ':00', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM);
            } catch (Throwable) {
                $empty['reason'] = 'AUTHORITATIVE_CUTOFF_INVALID';
                return $empty;
            }
            if (isset($seen[$raceCode])) {
                $empty['reason'] = 'AUTHORITATIVE_DUPLICATE_RACE_CODE';
                return $empty;
            }
            $seen[$raceCode] = true;
            $entries[] = ['race_code' => $raceCode, 'venue' => $venue, 'cutoff_timestamp' => $cutoff];
        }
        usort($entries, static fn(array $a, array $b): int => strcmp((string)$a['race_code'], (string)$b['race_code']));
        // An empty strict inventory is valid only when there was demonstrably
        // no strict venue in the authoritative request. Empty data for a
        // requested strict venue is a failure, never a zero-race conclusion.
        if ($entries === [] && $strictRequested !== []) {
            $empty['reason'] = 'AUTHORITATIVE_STRICT_INVENTORY_EMPTY';
            return $empty;
        }
        // The semantic identity is intentionally independent of one HTTP/cache
        // acquisition.  A scheduler run may legitimately refresh the same
        // official deadlines with a new fetched_at.  That provenance remains
        // visible below, but it must not invalidate an already frozen A1
        // denominator unless the official strict-scope meaning changed.
        $semanticMaterial = [
            'schema_version' => self::AUTHORITATIVE_SNAPSHOT_SCHEMA_VERSION,
            'semantic_identity_contract' => self::DEADLINE_SEMANTIC_IDENTITY_CONTRACT_VERSION,
            'target_date' => $date,
            'source' => trim((string)($deadlineResult['source'] ?? 'OfficialDeadlineLogic')),
            'source_status' => (string)$deadlineResult['status'],
            'strict_requested_venues' => $strictRequested,
            'strict_source_errors' => $strictErrors,
            'strict_entries' => $entries,
        ];
        $semanticIdentity = 'sha256:' . hash('sha256', self::canonicalJson($semanticMaterial));
        $sourceGeneratedAt = (string)$deadlineResult['fetched_at'];
        $acquisitionIdentity = 'sha256:' . hash('sha256', self::canonicalJson([
            'acquisition_identity_contract' => self::DEADLINE_ACQUISITION_IDENTITY_CONTRACT_VERSION,
            'semantic_deadline_identity' => $semanticIdentity,
            'source_generated_at' => $sourceGeneratedAt,
        ]));
        return [
            'accepted' => true,
            'status' => $entries === [] ? 'AUTHORITATIVE_VALID_EMPTY' : 'AUTHORITATIVE_VALID',
            'reason' => '',
            'snapshot' => array_merge($semanticMaterial, [
                // deadline_snapshot_identity remains as the downstream field
                // name for compatibility; from v2 onward it is semantic.
                'semantic_deadline_identity' => $semanticIdentity,
                'deadline_snapshot_identity' => $semanticIdentity,
                'source_identity' => $semanticIdentity,
                'source_generated_at' => $sourceGeneratedAt,
                'acquisition_deadline_identity' => $acquisitionIdentity,
                'valid_empty' => $entries === [],
            ]),
        ];
    }

    /**
     * Derives the only formal A1 population from one validated deadline
     * snapshot and the immutable Window activation instant.  It deliberately
     * does not accept a scheduler "now": membership is strictly cutoff >
     * armed_at, with both values parsed as offset-aware instants.
     *
     * @param array<string,mixed> $snapshot
     * @return array{accepted:bool,status:string,reason:string,snapshot:array<string,mixed>}
     */
    public static function deriveProspectiveSnapshot(array $snapshot, string $armedAt): array
    {
        $blocked = ['accepted' => false, 'status' => 'AUTHORITATIVE_PROSPECTIVE_SNAPSHOT_INVALID', 'reason' => '', 'snapshot' => []];
        if (!self::validAuthoritativeSnapshot($snapshot)) {
            $blocked['reason'] = 'AUTHORITATIVE_SNAPSHOT_INVALID';
            return $blocked;
        }
        if (!self::isOffsetAwareTimestamp($armedAt)) {
            $blocked['reason'] = 'ARMED_AT_TIMEZONE_INVALID';
            return $blocked;
        }
        try {
            $armed = new DateTimeImmutable($armedAt);
        } catch (Throwable) {
            $blocked['reason'] = 'ARMED_AT_PARSE_FAILED';
            return $blocked;
        }

        $prospective = [];
        $excluded = 0;
        foreach ($snapshot['strict_entries'] as $entry) {
            if (!is_array($entry) || !self::isOffsetAwareTimestamp((string)($entry['cutoff_timestamp'] ?? ''))) {
                $blocked['reason'] = 'CUTOFF_TIMEZONE_INVALID';
                return $blocked;
            }
            try {
                $cutoff = new DateTimeImmutable((string)$entry['cutoff_timestamp']);
            } catch (Throwable) {
                $blocked['reason'] = 'CUTOFF_PARSE_FAILED';
                return $blocked;
            }
            if ($cutoff > $armed) {
                $prospective[] = $entry;
            } else {
                $excluded++;
            }
        }
        usort($prospective, static fn(array $a, array $b): int => strcmp((string)$a['race_code'], (string)$b['race_code']));
        $keysetIdentity = self::prospectiveKeysetIdentity(
            (string)$snapshot['deadline_snapshot_identity'],
            $armedAt,
            $prospective,
        );
        return [
            'accepted' => true,
            'status' => 'AUTHORITATIVE_PROSPECTIVE_DERIVED',
            'reason' => '',
            'snapshot' => array_merge($snapshot, [
                // Downstream consumers intentionally use this filtered list;
                // the immutable deadline_snapshot_identity still binds it to
                // the complete authoritative source result.
                'strict_scope_entry_count' => count($snapshot['strict_entries']),
                'strict_entries' => $prospective,
                'prospective_filter_contract' => self::PROSPECTIVE_FILTER_CONTRACT_VERSION,
                'prospective_armed_at' => $armedAt,
                'prospective_keyset_identity' => $keysetIdentity,
                'pre_armed_excluded_count' => $excluded,
            ]),
        ];
    }

    /**
     * Stable identity for the exact derived population, not a cache byte hash.
     *
     * @param list<array<string,mixed>> $entries
     */
    public static function prospectiveKeysetIdentity(string $deadlineSnapshotIdentity, string $armedAt, array $entries): string
    {
        $keys = [];
        foreach ($entries as $entry) {
            $keys[] = [
                'race_code' => (string)($entry['race_code'] ?? ''),
                'venue' => (string)($entry['venue'] ?? ''),
                'cutoff_timestamp' => (string)($entry['cutoff_timestamp'] ?? ''),
            ];
        }
        usort($keys, static fn(array $a, array $b): int => strcmp($a['race_code'], $b['race_code']));
        return 'sha256:' . hash('sha256', self::canonicalJson([
            'prospective_filter_contract' => self::PROSPECTIVE_FILTER_CONTRACT_VERSION,
            'deadline_snapshot_identity' => $deadlineSnapshotIdentity,
            'armed_at' => $armedAt,
            'entries' => $keys,
        ]));
    }

    /**
     * Freezes an Expected inventory from a validated in-memory snapshot. The
     * caller must read it back with verifyValidatedAuthoritativeSnapshot()
     * before it is allowed to write Candidates or schedule observations.
     *
     * @param array<string,mixed> $snapshot
     * @return array{accepted:bool,status:string,reason:string,path:string,expected_count:int}
     */
    public function freezeValidatedAuthoritativeSnapshot(string $windowId, string $armedAt, array $snapshot): array
    {
        if ($windowId === '' || !$this->validProspectiveSnapshot($snapshot, $armedAt)) {
            return $this->blocked('EXPECTED_INVENTORY_AUTHORITATIVE_SNAPSHOT_INVALID');
        }
        $date = (string)$snapshot['target_date'];
        $datePrefix = str_replace('-', '', $date);
        $path = $this->inventoryPath($datePrefix);
        $entries = [];
        foreach ($snapshot['strict_entries'] as $sourceEntry) {
            if (!is_array($sourceEntry)) return $this->blocked('EXPECTED_INVENTORY_AUTHORITATIVE_ENTRY_INVALID');
            $raceCode = (string)$sourceEntry['race_code'];
            $venue = (string)$sourceEntry['venue'];
            $cutoff = (string)$sourceEntry['cutoff_timestamp'];
            $entries[] = [
                'canonical_key' => self::canonicalKey($windowId, $date, $raceCode, $venue),
                'stable_candidate_key' => 'scheduler-opportunity|' . $raceCode,
                'race_code' => $raceCode, 'venue' => $venue, 'target_date' => $date,
                'cutoff_timestamp' => $cutoff,
                'semantic_schedule_fingerprint' => self::semanticFingerprint($raceCode, $venue, $cutoff, 'ACTIVE'),
            ];
        }
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'window_id' => $windowId, 'target_date' => $date, 'armed_at' => $armedAt,
            'source' => (string)$snapshot['source'], 'source_identity' => (string)$snapshot['source_identity'],
            'source_generated_at' => (string)$snapshot['source_generated_at'],
            'semantic_deadline_identity' => (string)($snapshot['semantic_deadline_identity'] ?? $snapshot['deadline_snapshot_identity']),
            'acquisition_deadline_identity' => (string)($snapshot['acquisition_deadline_identity'] ?? ''),
            'deadline_snapshot_identity' => (string)$snapshot['deadline_snapshot_identity'],
            'authoritative_snapshot_schema_version' => self::AUTHORITATIVE_SNAPSHOT_SCHEMA_VERSION,
            'prospective_filter_contract' => (string)$snapshot['prospective_filter_contract'],
            'prospective_keyset_identity' => (string)$snapshot['prospective_keyset_identity'],
            'strict_scope_entry_count' => (int)$snapshot['strict_scope_entry_count'],
            'pre_armed_excluded_count' => (int)$snapshot['pre_armed_excluded_count'],
            'valid_empty' => !empty($snapshot['valid_empty']),
            'expected_entries' => $entries,
            'created_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM),
        ];
        if (is_file($path)) return $this->verifyValidatedAuthoritativeSnapshot($windowId, $armedAt, $snapshot);
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) return $this->blocked('EXPECTED_INVENTORY_DIRECTORY_UNAVAILABLE');
        $handle = @fopen($path, 'x');
        if (!is_resource($handle)) return $this->verifyValidatedAuthoritativeSnapshot($windowId, $armedAt, $snapshot);
        $bytes = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
        $ok = @fwrite($handle, $bytes) !== false;
        @fclose($handle);
        if (!$ok) { @unlink($path); return $this->blocked('EXPECTED_INVENTORY_WRITE_FAILED'); }
        return ['accepted' => true, 'status' => 'EXPECTED_INVENTORY_FROZEN', 'reason' => '', 'path' => $path, 'expected_count' => count($entries)];
    }

    /** @param array<string,mixed> $snapshot @return array{accepted:bool,status:string,reason:string,path:string,expected_count:int} */
    public function verifyValidatedAuthoritativeSnapshot(string $windowId, string $armedAt, array $snapshot): array
    {
        if (!$this->validProspectiveSnapshot($snapshot, $armedAt)) return $this->blocked('EXPECTED_INVENTORY_AUTHORITATIVE_SNAPSHOT_INVALID');
        $path = $this->inventoryPath(str_replace('-', '', (string)$snapshot['target_date']));
        $existing = $this->readJson($path);
        $valid = $this->validSnapshot($existing, $windowId, $armedAt);
        if (!$valid['accepted']) return $this->blocked((string)$valid['reason']);
        $existingSemantic = (string)($existing['semantic_deadline_identity'] ?? $existing['deadline_snapshot_identity'] ?? '');
        $incomingSemantic = (string)($snapshot['semantic_deadline_identity'] ?? $snapshot['deadline_snapshot_identity'] ?? '');
        if (!hash_equals($existingSemantic, $incomingSemantic)) {
            return $this->blocked('EXPECTED_INVENTORY_SEMANTIC_REVISION_CONFLICT');
        }
        $expected = array_map(static fn(array $row): string => (string)($row['race_code'] ?? '') . '|' . (string)($row['cutoff_timestamp'] ?? ''), $existing['expected_entries']);
        if (!hash_equals((string)($existing['prospective_keyset_identity'] ?? ''), (string)$snapshot['prospective_keyset_identity'])
            || !hash_equals((string)($existing['prospective_filter_contract'] ?? ''), (string)$snapshot['prospective_filter_contract'])) {
            return $this->blocked('EXPECTED_INVENTORY_PROSPECTIVE_CONTRACT_CONFLICT');
        }
        $source = array_map(static fn(array $row): string => (string)($row['race_code'] ?? '') . '|' . (string)($row['cutoff_timestamp'] ?? ''), $snapshot['strict_entries']);
        sort($expected, SORT_STRING); sort($source, SORT_STRING);
        if ($expected !== $source) return $this->blocked('EXPECTED_INVENTORY_KEYSET_CONFLICT');
        return ['accepted' => true, 'status' => 'EXPECTED_INVENTORY_READBACK_VERIFIED', 'reason' => '', 'path' => $path, 'expected_count' => count($expected)];
    }

    /**
     * Production bootstrap boundary.  It is intentionally fail-open to the
     * legacy prediction path, but never reports the formal barrier as open
     * unless Expected was created (or already existed) and read back from the
     * same authoritative snapshot.
     *
     * @param array<string,mixed> $snapshot
     * @return array{accepted:bool,formal_active:bool,status:string,reason:string,window_id:string,armed_at:string,snapshot:array<string,mixed>}
     */
    public static function bootstrapAuthoritativeFromContext(array $snapshot): array
    {
        $none = ['accepted' => false, 'formal_active' => false, 'status' => 'FORMAL_BARRIER_INACTIVE', 'reason' => '', 'window_id' => '', 'armed_at' => '', 'snapshot' => $snapshot];
        try {
            $context = FormalMeasurementWindowContext::production(dirname(__DIR__, 2));
            $authorization = $context->authorizeFormalWrite();
            if (empty($authorization['accepted'])) {
                $none['reason'] = (string)($authorization['reason'] ?? 'FORMAL_WRITE_NOT_ACTIVE');
                return $none;
            }
            $armedAt = trim((string)($authorization['manifest']['armed_at'] ?? ''));
            if (!self::isOffsetAwareTimestamp($armedAt)) { $none['reason'] = 'GATE_A1_ARMED_AT_MISSING_OR_TIMEZONE_INVALID'; return $none; }
            $derived = self::deriveProspectiveSnapshot($snapshot, $armedAt);
            if (empty($derived['accepted'])) {
                return array_merge($none, ['formal_active' => true, 'status' => (string)$derived['status'], 'reason' => (string)$derived['reason'], 'window_id' => $context->windowId(), 'armed_at' => $armedAt]);
            }
            $prospectiveSnapshot = $derived['snapshot'];
            $inventory = new self($context->opportunityRoot());
            $freeze = $inventory->freezeValidatedAuthoritativeSnapshot($context->windowId(), $armedAt, $prospectiveSnapshot);
            if (empty($freeze['accepted'])) {
                return array_merge($none, ['formal_active' => true, 'status' => (string)$freeze['status'], 'reason' => (string)$freeze['reason'], 'window_id' => $context->windowId(), 'armed_at' => $armedAt]);
            }
            $readback = $inventory->verifyValidatedAuthoritativeSnapshot($context->windowId(), $armedAt, $prospectiveSnapshot);
            return [
                'accepted' => !empty($readback['accepted']), 'formal_active' => true,
                'status' => (string)$readback['status'], 'reason' => (string)$readback['reason'],
                'window_id' => $context->windowId(), 'armed_at' => $armedAt, 'snapshot' => $prospectiveSnapshot,
            ];
        } catch (Throwable $error) {
            $none['reason'] = 'GATE_A1_BOOTSTRAP_CONTEXT_UNAVAILABLE:' . $error->getMessage();
            return $none;
        }
    }

    /**
     * @return array{accepted:bool,status:string,reason:string,path:string,expected_count:int}
     */
    public function freezeOfficialCache(string $date, string $datePrefix, string $windowId, string $armedAt, ?string $cachePath = null): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || !preg_match('/^\d{8}$/', $datePrefix)
            || str_replace('-', '', $date) !== $datePrefix
            || $windowId === '' || !self::isTimestamp($armedAt)) {
            return $this->blocked('EXPECTED_INVENTORY_ARGUMENT_INVALID');
        }
        $path = $this->inventoryPath($datePrefix);
        if (is_file($path)) return $this->validateExisting($path, $windowId, $armedAt);

        $cachePath ??= rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/boatrace_official_deadlines/deadlines_' . $datePrefix . '.json';
        $raw = @file_get_contents($cachePath);
        if (!is_string($raw)) return $this->blocked('EXPECTED_INVENTORY_SOURCE_MISSING');
        try {
            $cache = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $this->blocked('EXPECTED_INVENTORY_SOURCE_INVALID_JSON');
        }
        if (!is_array($cache) || (string)($cache['date'] ?? '') !== $datePrefix) {
            return $this->blocked('EXPECTED_INVENTORY_SOURCE_DATE_MISMATCH');
        }
        $validated = self::validateAuthoritativeDeadlineResult($date, $datePrefix, $cache, self::strictVenues());
        if (empty($validated['accepted'])) return $this->blocked('EXPECTED_INVENTORY_SOURCE_INCOMPLETE:' . (string)$validated['reason']);
        $derived = self::deriveProspectiveSnapshot($validated['snapshot'], $armedAt);
        if (empty($derived['accepted'])) return $this->blocked('EXPECTED_INVENTORY_PROSPECTIVE_DERIVATION_FAILED:' . (string)$derived['reason']);
        return $this->freezeValidatedAuthoritativeSnapshot($windowId, $armedAt, $derived['snapshot']);
    }

    /**
     * Compares independent expected schedule keys with immutable Candidate
     * keys. Revision semantics deliberately remain an A2 responsibility.
     *
     * @return array{status:string,reason:string,expected_count:int,observed_count:int,missing:list<string>,unexpected:list<string>,duplicate:list<string>,conflicting:list<string>,expected_rows:list<array<string,mixed>>,observed_rows:list<array<string,mixed>>}
     */
    public function compare(string $windowId, string $armedAt): array
    {
        $empty = ['status' => 'BLOCKED_EXPECTED_INVENTORY_INVALID', 'reason' => 'EXPECTED_INVENTORY_ARGUMENT_INVALID', 'expected_count' => 0, 'observed_count' => 0, 'missing' => [], 'unexpected' => [], 'duplicate' => [], 'conflicting' => [], 'expected_rows' => [], 'observed_rows' => []];
        if ($windowId === '' || !self::isTimestamp($armedAt)) return $empty;
        $expected = [];
        $expectedRows = [];
        $duplicate = [];
        $sourceProblems = [];
        $snapshots = glob($this->inventoryDirectory() . '/*.json') ?: [];
        foreach ($snapshots as $path) {
            $snapshot = $this->readJson($path);
            $valid = $this->validSnapshot($snapshot, $windowId, $armedAt);
            if (!$valid['accepted']) {
                $sourceProblems[] = $valid['reason'];
                continue;
            }
            foreach ($snapshot['expected_entries'] as $entry) {
                if (!self::after((string)$entry['cutoff_timestamp'], $armedAt)) continue;
                $key = (string)$entry['canonical_key'];
                if (isset($expected[$key])) {
                    $duplicate[] = $key;
                    continue;
                }
                $expected[$key] = $entry;
                $expectedRows[] = $entry;
            }
        }

        $observed = [];
        $observedRows = [];
        foreach (glob($this->opportunityRoot . '/candidates/*/*.json') ?: [] as $path) {
            $row = $this->readJson($path);
            $raceCode = strtoupper(trim((string)($row['race_code'] ?? '')));
            $venue = strtoupper(trim((string)($row['venue'] ?? '')));
            $date = trim((string)($row['target_date'] ?? ''));
            $cutoff = trim((string)($row['cutoff_timestamp'] ?? ''));
            if (!preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $raceCode) || !self::isStrictVenue($venue) || !self::isTimestamp($cutoff)) {
                $sourceProblems[] = 'OBSERVED_CANDIDATE_INVALID';
                continue;
            }
            if (!self::after($cutoff, $armedAt)) continue;
            $key = self::canonicalKey($windowId, $date, $raceCode, $venue);
            if (isset($observed[$key])) {
                $duplicate[] = $key;
                continue;
            }
            $row['canonical_key'] = $key;
            $observed[$key] = $row;
            $observedRows[] = $row;
        }
        foreach (glob($this->opportunityRoot . '/operational/*.jsonl') ?: [] as $path) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $event = json_decode($line, true);
                if (!is_array($event) || ($event['event'] ?? '') !== 'CANDIDATE_ORIGINAL_SEMANTIC_DIFFERENCE') continue;
                $raceCode = strtoupper(trim((string)($event['race_code'] ?? '')));
                foreach ($observed as $key => $row) {
                    if (($row['race_code'] ?? '') === $raceCode) $duplicate[] = 'CONFLICT:' . $key;
                }
            }
        }
        $missing = array_values(array_diff(array_keys($expected), array_keys($observed)));
        $unexpected = array_values(array_diff(array_keys($observed), array_keys($expected)));
        $duplicate = array_values(array_unique($duplicate));
        $conflicting = array_values(array_filter($duplicate, static fn(string $key): bool => str_starts_with($key, 'CONFLICT:')));
        $status = 'PASS';
        $reason = '';
        if ($sourceProblems !== []) {
            $status = 'BLOCKED_EXPECTED_INVENTORY_INVALID';
            $reason = implode(',', array_values(array_unique($sourceProblems)));
        } elseif ($expected === [] && $observed === []) {
            $status = 'PENDING_LIVE';
            $reason = 'EXPECTED_AND_OBSERVED_PROSPECTIVE_INVENTORY_NOT_YET_PRESENT';
        } elseif ($expected === []) {
            $status = 'BLOCKED_EXPECTED_INVENTORY_MISSING';
            $reason = 'OBSERVED_PROSPECTIVE_CANDIDATES_WITHOUT_INDEPENDENT_EXPECTED_INVENTORY';
        } elseif ($missing !== [] || $unexpected !== [] || $duplicate !== []) {
            $status = 'FAIL';
            $reason = 'PROSPECTIVE_CANONICAL_KEYSET_MISMATCH';
        }
        return ['status' => $status, 'reason' => $reason, 'expected_count' => count($expected), 'observed_count' => count($observed), 'missing' => $missing, 'unexpected' => $unexpected, 'duplicate' => $duplicate, 'conflicting' => $conflicting, 'expected_rows' => $expectedRows, 'observed_rows' => $observedRows];
    }

    public static function canonicalKey(string $windowId, string $date, string $raceCode, string $venue): string
    {
        return 'sha256:' . hash('sha256', json_encode([
            'window_id' => $windowId, 'target_date' => $date,
            'stable_candidate_key' => 'scheduler-opportunity|' . $raceCode,
            'race_code' => $raceCode, 'venue' => $venue,
        ], JSON_UNESCAPED_SLASHES));
    }

    private function validSnapshot(array $snapshot, string $windowId, string $armedAt): array
    {
        if (($snapshot['schema_version'] ?? '') !== self::SCHEMA_VERSION || ($snapshot['window_id'] ?? '') !== $windowId
            || !self::isOffsetAwareTimestamp((string)($snapshot['armed_at'] ?? '')) || !hash_equals((string)$snapshot['armed_at'], $armedAt)
            || !str_starts_with((string)($snapshot['source_identity'] ?? ''), 'sha256:')
            || !self::isTimestamp((string)($snapshot['source_generated_at'] ?? ''))
            || !is_array($snapshot['expected_entries'] ?? null)) {
            return ['accepted' => false, 'reason' => 'EXPECTED_INVENTORY_SNAPSHOT_INVALID'];
        }
        if (array_key_exists('semantic_deadline_identity', $snapshot)
            && !hash_equals((string)$snapshot['semantic_deadline_identity'], (string)($snapshot['deadline_snapshot_identity'] ?? ''))) {
            return ['accepted' => false, 'reason' => 'EXPECTED_INVENTORY_SEMANTIC_IDENTITY_INVALID'];
        }
        // Historical immutable Windows predate the explicit prospective-set
        // fields. They remain readable for audit; all newly frozen snapshots
        // carry the v1 prospective contract below.
        $hasProspectiveContract = array_key_exists('prospective_filter_contract', $snapshot)
            || array_key_exists('prospective_keyset_identity', $snapshot);
        if ($hasProspectiveContract
            && ((string)($snapshot['prospective_filter_contract'] ?? '') !== self::PROSPECTIVE_FILTER_CONTRACT_VERSION
                || !preg_match('/^sha256:[a-f0-9]{64}$/', (string)($snapshot['prospective_keyset_identity'] ?? '')))) {
            return ['accepted' => false, 'reason' => 'EXPECTED_INVENTORY_PROSPECTIVE_CONTRACT_INVALID'];
        }
        $identityEntries = [];
        foreach ($snapshot['expected_entries'] as $entry) {
            if (!is_array($entry) || !is_string($entry['canonical_key'] ?? null) || !is_string($entry['race_code'] ?? null)
                || !is_string($entry['venue'] ?? null) || !is_string($entry['target_date'] ?? null) || !self::isOffsetAwareTimestamp((string)($entry['cutoff_timestamp'] ?? ''))
                || ($hasProspectiveContract && !self::after((string)$entry['cutoff_timestamp'], $armedAt))
                || !hash_equals((string)$entry['canonical_key'], self::canonicalKey($windowId, (string)$entry['target_date'], (string)$entry['race_code'], (string)$entry['venue']))) {
                return ['accepted' => false, 'reason' => 'EXPECTED_INVENTORY_KEY_INVALID'];
            }
            $identityEntries[] = $entry;
        }
        if ($hasProspectiveContract && !hash_equals((string)$snapshot['prospective_keyset_identity'], self::prospectiveKeysetIdentity(
            (string)($snapshot['deadline_snapshot_identity'] ?? ''), $armedAt, $identityEntries,
        ))) return ['accepted' => false, 'reason' => 'EXPECTED_INVENTORY_PROSPECTIVE_KEYSET_IDENTITY_INVALID'];
        return ['accepted' => true, 'reason' => ''];
    }

    /** @param array<string,mixed> $snapshot */
    private function validProspectiveSnapshot(array $snapshot, string $armedAt): bool
    {
        if (!self::validAuthoritativeSnapshot($snapshot)
            || !self::isOffsetAwareTimestamp($armedAt)
            || !hash_equals((string)($snapshot['prospective_armed_at'] ?? ''), $armedAt)
            || (string)($snapshot['prospective_filter_contract'] ?? '') !== self::PROSPECTIVE_FILTER_CONTRACT_VERSION
            || !preg_match('/^sha256:[a-f0-9]{64}$/', (string)($snapshot['prospective_keyset_identity'] ?? ''))
            || !is_int($snapshot['strict_scope_entry_count'] ?? null)
            || !is_int($snapshot['pre_armed_excluded_count'] ?? null)) return false;
        foreach ($snapshot['strict_entries'] as $entry) {
            if (!is_array($entry) || !self::isOffsetAwareTimestamp((string)($entry['cutoff_timestamp'] ?? ''))
                || !self::after((string)$entry['cutoff_timestamp'], $armedAt)) return false;
        }
        if ((int)$snapshot['strict_scope_entry_count'] !== count($snapshot['strict_entries']) + (int)$snapshot['pre_armed_excluded_count']) return false;
        return hash_equals((string)$snapshot['prospective_keyset_identity'], self::prospectiveKeysetIdentity(
            (string)$snapshot['deadline_snapshot_identity'], $armedAt, $snapshot['strict_entries'],
        ));
    }

    private function validateExisting(string $path, string $windowId, string $armedAt): array
    {
        $snapshot = $this->readJson($path);
        $valid = $this->validSnapshot($snapshot, $windowId, $armedAt);
        if (!$valid['accepted']) return $this->blocked($valid['reason']);
        return ['accepted' => true, 'status' => 'EXPECTED_INVENTORY_ALREADY_FROZEN', 'reason' => '', 'path' => $path, 'expected_count' => count($snapshot['expected_entries'])];
    }

    private function inventoryDirectory(): string
    {
        return rtrim($this->opportunityRoot, DIRECTORY_SEPARATOR) . '/expected_inventory';
    }

    private function inventoryPath(string $datePrefix): string
    {
        return $this->inventoryDirectory() . '/' . $datePrefix . '.json';
    }

    private static function semanticFingerprint(string $raceCode, string $venue, string $cutoff, string $status): string
    {
        return 'sha256:' . hash('sha256', json_encode([
            'race_code' => $raceCode, 'venue' => $venue,
            'effective_cutoff_timestamp' => $cutoff, 'official_status' => $status,
        ], JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string,mixed> $snapshot */
    private static function validAuthoritativeSnapshot(array $snapshot): bool
    {
        $schema = (string)($snapshot['schema_version'] ?? '');
        if (!in_array($schema, [self::AUTHORITATIVE_SNAPSHOT_SCHEMA_VERSION, self::LEGACY_AUTHORITATIVE_SNAPSHOT_SCHEMA_VERSION], true)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($snapshot['target_date'] ?? ''))
            || !self::isTimestamp((string)($snapshot['source_generated_at'] ?? ''))
            || !preg_match('/^sha256:[a-f0-9]{64}$/', (string)($snapshot['deadline_snapshot_identity'] ?? ''))
            || !is_array($snapshot['strict_requested_venues'] ?? null)
            || !is_array($snapshot['strict_entries'] ?? null)) return false;
        if ($schema === self::AUTHORITATIVE_SNAPSHOT_SCHEMA_VERSION
            && ((string)($snapshot['semantic_identity_contract'] ?? '') !== self::DEADLINE_SEMANTIC_IDENTITY_CONTRACT_VERSION
                || !hash_equals((string)($snapshot['semantic_deadline_identity'] ?? ''), (string)($snapshot['deadline_snapshot_identity'] ?? ''))
                || !preg_match('/^sha256:[a-f0-9]{64}$/', (string)($snapshot['acquisition_deadline_identity'] ?? '')))) return false;
        $requested = array_values(array_unique(array_map(static fn(mixed $v): string => strtoupper(trim((string)$v)), $snapshot['strict_requested_venues'])));
        foreach ($requested as $venue) if (!self::isStrictVenue($venue)) return false;
        foreach ($snapshot['strict_entries'] as $entry) {
            if (!is_array($entry) || !preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', (string)($entry['race_code'] ?? ''))
                || !self::isStrictVenue((string)($entry['venue'] ?? ''))
                || !in_array((string)$entry['venue'], $requested, true)
                || !self::isOffsetAwareTimestamp((string)($entry['cutoff_timestamp'] ?? ''))) return false;
        }
        // A raw source may not claim an empty requested strict universe.  A
        // derived prospective snapshot may legitimately be empty when every
        // official cutoff was at or before armed_at.
        return $snapshot['strict_entries'] !== [] || $requested === []
            || (($snapshot['prospective_filter_contract'] ?? '') === self::PROSPECTIVE_FILTER_CONTRACT_VERSION
                && is_int($snapshot['strict_scope_entry_count'] ?? null));
    }

    /** @param array<string,mixed> $value */
    private static function canonicalJson(array $value): string
    {
        $sort = static function (mixed $item) use (&$sort): mixed {
            if (!is_array($item)) return $item;
            if (array_is_list($item)) return array_map($sort, $item);
            ksort($item, SORT_STRING);
            foreach ($item as $key => $child) $item[$key] = $sort($child);
            return $item;
        };
        return (string)json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function isTimestamp(string $value): bool
    {
        try { new DateTimeImmutable($value); return $value !== ''; }
        catch (Throwable) { return false; }
    }

    private static function isOffsetAwareTimestamp(string $value): bool
    {
        if ($value === '' || preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) return false;
        try { new DateTimeImmutable($value); return true; }
        catch (Throwable) { return false; }
    }

    private static function after(string $left, string $right): bool
    {
        try { return new DateTimeImmutable($left) > new DateTimeImmutable($right); }
        catch (Throwable) { return false; }
    }

    private function readJson(string $path): array
    {
        try {
            $value = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            return is_array($value) ? $value : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function blocked(string $reason): array
    {
        return ['accepted' => false, 'status' => 'EXPECTED_INVENTORY_BLOCKED', 'reason' => $reason, 'path' => '', 'expected_count' => 0];
    }
}
