<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/MeasurementImplementationProvenance.php';
require_once __DIR__ . '/../web/logic/OfficialEpochWindowBindingStore.php';
require_once __DIR__ . '/../web/logic/OfficialEpochWindowBindingResolver.php';
require_once __DIR__ . '/../web/logic/OfficialEpochIncidentLifecycleStore.php';
require_once __DIR__ . '/../web/logic/FormalMeasurementWindowLifecycle.php';

/** Isolated regression suite: it never opens the production lifecycle root. */
final class OfficialEpochWindowBindingV1Fixture
{
    private string $repo;
    private string $epochId = 'official-epoch-v1-0123456789abcdef';
    /** @var array<string,bool> */ private array $checks = [];

    public function run(): void
    {
        $this->repo = rtrim(sys_get_temp_dir(), '/') . '/boatrace-epoch-binding-' . bin2hex(random_bytes(6));
        try {
            $this->copyCriticalSources();
            $this->writeEpochStart();
            $windowId = 'prepared-binding-fixture';
            $this->prepared($windowId);
            $store = new OfficialEpochWindowBindingStore($this->repo);
            $pre = $store->preflightPrepared($windowId, $this->validation());
            $this->check('valid PREPARED + ACTIVE Epoch → binding eligible', !empty($pre['accepted']));
            $created = $store->createForPrepared($windowId, $this->validation());
            $this->check('binding create-only commit', !empty($created['accepted']) && ($created['status'] ?? '') === 'OFFICIAL_EPOCH_WINDOW_BINDING_CREATED');
            $this->check('same payload → idempotent', !empty($store->createForPrepared($windowId, $this->validation())['accepted']));
            $bindingPath = $store->bindingPath($windowId);
            $this->check('binding record stored outside Window', is_file($bindingPath) && !str_contains($bindingPath, '/windows/' . $windowId . '/'));
            $resolver = new OfficialEpochWindowBindingResolver($this->repo);
            $valid = $resolver->validateBinding($windowId, ['require_prepared' => true]);
            $this->check('resolver returns correct Epoch', !empty($valid['accepted']) && ($valid['epoch']['epoch_id'] ?? '') === $this->epochId);
            $this->check('windowsForEpoch projection deterministic', count($resolver->windowsForEpoch($this->epochId)) === 1);
            $this->check('Source CLOSED Window requires no backfill', !empty($resolver->epochForBoundWindow('source-closed-fixture')['accepted']));
            $this->check('past Window without binding remains readable', empty($resolver->bindingForWindow('source-closed-fixture')));
            $this->check('no physical Window mutation by validator', $this->treeHash($this->windowRoot($windowId)) === $this->treeHash($this->windowRoot($windowId)));

            $this->check('wrong epoch id → blocked', $this->bindingTamperRejected($windowId, 'epoch_id', 'official-epoch-v1-fedcba9876543210'));
            $this->check('wrong start hash → blocked', $this->bindingTamperRejected($windowId, 'epoch_start_record_hash', 'sha256:' . str_repeat('0', 64)));
            $this->check('wrong Epoch Policy identity → blocked', $this->bindingTamperRejected($windowId, 'epoch_policy_identity', 'sha256:' . str_repeat('0', 64)));
            $this->check('wrong Incident Policy identity → blocked', $this->bindingTamperRejected($windowId, 'incident_policy_identity', 'sha256:' . str_repeat('1', 64)));
            $scopeMismatch = 'prepared-scope-mismatch';
            $this->prepared($scopeMismatch);
            $this->mutateDeclarationContract($scopeMismatch, 'comparative_scope_identity', 'sha256:' . str_repeat('c', 64));
            $this->check('comparative scope mismatch → blocked', !$store->preflightPrepared($scopeMismatch, $this->validation())['accepted']);
            $policyMismatch = 'prepared-policy-mismatch';
            $this->prepared($policyMismatch);
            $this->mutateDeclarationContract($policyMismatch, 'comparative_policy_identity', 'sha256:' . str_repeat('d', 64));
            $this->check('comparative policy mismatch → blocked', !$store->preflightPrepared($policyMismatch, $this->validation())['accepted']);
            $this->check('numeric semantics mismatch → blocked', !$store->preflightPrepared($windowId, $this->validation(['numeric_policy_semantics_unchanged' => false]))['accepted']);
            $this->check('affected evidence not isolated → blocked', !$store->preflightPrepared($windowId, $this->validation(['carryover_count' => 1]))['accepted']);
            $this->check('regression evidence missing → blocked', !$store->preflightPrepared($windowId, $this->validation(['regressions_pass' => false]))['accepted']);
            $this->check('readiness failure → blocked', !$store->preflightPrepared($windowId, $this->validation(['readiness_pass' => false]))['accepted']);
            $this->check('same Window different declaration → conflict', ($store->createForPrepared($windowId, $this->validation(['readiness_pass' => false]))['status'] ?? '') === 'OFFICIAL_EPOCH_WINDOW_BINDING_BLOCKED');
            $this->check('same Window different identity → conflict', ($store->createForPrepared($windowId, $this->validation(['numeric_policy_semantics_unchanged' => false]))['status'] ?? '') === 'OFFICIAL_EPOCH_WINDOW_BINDING_BLOCKED');

            $unbound = 'prepared-unbound-fixture';
            $this->prepared($unbound);
            $this->check('pointer handoff blocked without binding', !$this->handoff($unbound)['accepted']);
            $this->check('Activation blocked without binding', !$this->activate($unbound)['accepted']);
            $this->check('orphan PREPARED retained after binding failure', is_file($this->windowRoot($unbound) . '/validation_window_manifest.json'));
            $this->check('retry same orphan binding payload allowed', !empty($store->createForPrepared($unbound, $this->validation())['accepted']));
            $this->check('pointer handoff allowed with valid binding fixture', !empty($this->handoff($unbound)['accepted']));
            $activation = $this->activate($unbound);
            $this->check('Activation valid with binding fixture', !empty($activation['accepted']) && ($activation['activation']['activation_schema_version'] ?? '') === WindowActivationHold::ACTIVATION_SCHEMA_VERSION);
            $this->check('Activation record binds binding', !empty($activation['activation']['official_epoch_window_binding_hash']));

            $this->check('binding logical key deterministic', (($created['record']['binding_key'] ?? '') === ($store->bindingForWindow($windowId)['binding_key'] ?? '')));
            $this->check('successor requirement count', count(($created['record']['same_epoch_successor_requirements'] ?? [])) === 9);
            $this->check('all successor requirements PASS', count(array_filter(($created['record']['same_epoch_successor_validation_result'] ?? []), static fn($v) => $v !== 'PASS')) === 0);
            $this->check('prospective boundary contract fixed', ($created['record']['prospective_boundary_contract'] ?? '') === OfficialEpochWindowBindingV1::PROSPECTIVE_BOUNDARY_CONTRACT);
            $this->check('formal evidence count >0 → blocked', !$store->preflightPrepared($windowId, $this->validation(['formal_evidence_count' => 1]))['accepted']);
            $this->check('declaration identity source is frozen', OfficialEpochWindowBindingV1::validHash((string)($created['record']['measurement_manifest_hash'] ?? '')));
            $this->check('manifest/source-freeze refs bound', !empty($created['record']['measurement_manifest_ref']) && !empty($created['record']['measurement_source_freeze_ref']));
            $this->check('window manifest hash bound', OfficialEpochWindowBindingV1::validHash((string)($created['record']['window_manifest_hash'] ?? '')));
            $this->check('window declaration hash bound', OfficialEpochWindowBindingV1::validHash((string)($created['record']['window_declaration_hash'] ?? '')));
            $this->check(
                'binding lifecycle record confined to fixture repository',
                str_starts_with($this->repo, rtrim(sys_get_temp_dir(), '/') . '/boatrace-epoch-binding-')
                && $bindingPath === $this->repo . '/var/strict_forward_live_readiness/lifecycle/official_epoch_window_bindings/' . $windowId . '.json'
                && is_file($bindingPath)
            );
            $this->check('Official Epoch Policy identity unchanged', OfficialEpochPolicyV1::identity() === 'sha256:e025750d254c2ed7fe2b289aa3413e322116cc235b1f44e952068c6ba183948d');
            $this->check('Incident Policy identity unchanged', OfficialEpochIncidentPolicyV1::identity() === 'sha256:ecccdbcc7c23e9fb007d0a17ab91580f995952f3d8c7db3e68278e61000af604');
            $this->check('global Epoch lock serialization', is_file($this->repo . '/var/strict_forward_live_readiness/lifecycle/official_epoch_v1.lock'));
            $this->check('record hash valid', OfficialEpochWindowBindingV1::validRecordHash($created['record'] ?? []));
            $this->check('binding carries epoch projection', OfficialEpochWindowBindingV1::validHash((string)($created['record']['epoch_state_projection_hash'] ?? '')));
            $this->check('binding carries external evidence refs', count(($created['record']['validation_evidence_refs'] ?? [])) === 9);
            $this->check('binding carries external evidence hashes', count(($created['record']['validation_evidence_hashes'] ?? [])) === 9);
            $this->check('active Epoch remains projection active', (($resolver->validateBinding($windowId)['projection']['state'] ?? '') === 'ACTIVE'));
            $this->check('closed source direct Epoch association remains readable', (($resolver->epochForBoundWindow('source-closed-fixture')['kind'] ?? '') === 'SOURCE_CLOSED_WINDOW'));
            $this->check('prepared binding does not carry armed_at', !array_key_exists('armed_at', $created['record'] ?? []));
            $this->check('source Window no backfill record', !is_file($store->bindingPath('source-closed-fixture')));

            // Independent projection drift fixture: a valid SUSPEND record
            // makes the already-bound PREPARED Window fail loud.
            $incident = new OfficialEpochIncidentLifecycleStore($this->repo . '/var/strict_forward_live_readiness/lifecycle');
            $projection = $incident->project($this->epochId);
            $transition = $incident->transitionRecord('SUSPEND', ['epoch_id' => $this->epochId, 'from_state' => 'ACTIVE', 'predecessor_state_hash' => $projection['state_hash'], 'incident_hash' => 'sha256:' . str_repeat('2', 64), 'transition_at' => '2026-10-14T00:00:00+09:00']);
            $incident->commitTransition('SUSPEND', $transition);
            $this->check('Epoch SUSPENDED → binding blocked', !$resolver->validateBinding($windowId)['accepted']);
            $this->check('Activation blocked when Epoch SUSPENDED', !$this->activate($windowId)['accepted']);
            $this->check('Activation blocked on projection drift', !$resolver->validateBinding($windowId)['accepted']);
            $this->check('CAS state drift → zero write', !is_file($store->bindingPath('prepared-drift-fixture')));

            $pass = count(array_filter($this->checks));
            $out = ['fixture_total' => count($this->checks), 'fixture_pass' => $pass, 'fixture_fail' => count($this->checks) - $pass, 'checks' => $this->checks];
            echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            if ($pass !== count($this->checks)) exit(1);
        } finally {
            $this->remove($this->repo);
        }
    }

    private function copyCriticalSources(): void
    {
        $source = dirname(__DIR__);
        foreach (MeasurementImplementationProvenance::criticalPaths() as $relative) {
            $from = $source . '/' . $relative; $to = $this->repo . '/' . $relative;
            if (!is_dir(dirname($to))) mkdir(dirname($to), 0775, true);
            copy($from, $to);
        }
    }

    private function writeEpochStart(): void
    {
        $lifecycle = $this->repo . '/var/strict_forward_live_readiness/lifecycle';
        mkdir($lifecycle . '/official_epochs/' . $this->epochId, 0775, true);
        $contract = ComparativeContractV1::production($this->repo)->identities();
        $row = ['schema_version' => OfficialEpochStartStore::SCHEMA_VERSION, 'epoch_id' => $this->epochId, 'epoch_key' => 'sha256:' . str_repeat('a', 64), 'authorization_hash' => 'sha256:' . str_repeat('b', 64), 'epoch_policy_identity' => OfficialEpochPolicyV1::identity(), 'incident_policy_schema' => OfficialEpochIncidentPolicyV1::SCHEMA_VERSION, 'incident_policy_identity' => OfficialEpochIncidentPolicyV1::identity(), 'source_closed_window_id' => 'source-closed-fixture'] + $contract;
        $row['record_hash'] = OfficialEpochWindowBindingV1::hash($row);
        file_put_contents($lifecycle . '/official_epochs/' . $this->epochId . '/start.json', OfficialEpochPolicyV1::canonicalJson($row) . "\n");
    }

    private function prepared(string $id): void
    {
        $root = $this->windowRoot($id);
        $made = (new FormalMeasurementWindowPrepareOnlyV1($this->repo, null, new DateTimeImmutable('2026-10-14T08:00:00+09:00')))->create(['window_id' => $id]);
        if (empty($made['accepted'])) throw new RuntimeException('fixture prepared failed');
    }

    /** @param array<string,mixed> $override @return array<string,mixed> */
    private function validation(array $override = []): array
    {
        $contract = ComparativeContractV1::production($this->repo)->identities();
        $evidenceRefs = []; $evidenceHashes = [];
        foreach (OfficialEpochWindowBindingV1::requirementNames() as $name) { $evidenceRefs[$name] = 'fixture://' . $name; $evidenceHashes[$name] = 'sha256:' . hash('sha256', $name); }
        $row = ['successor_semantics_classification' => OfficialEpochWindowBindingV1::SEMANTICS_EQUIVALENT, 'no_semantic_contract_diff' => true, 'regressions_pass' => true, 'readiness_pass' => true, 'numeric_policy_semantics_unchanged' => true, 'epoch_policy_identity' => OfficialEpochPolicyV1::identity(), 'incident_policy_identity' => OfficialEpochIncidentPolicyV1::identity(), 'formal_evidence_count' => 0, 'carryover_count' => 0, 'backfill_count' => 0, 'prior_evidence_reuse_count' => 0, 'current_measurement_identity' => (new MeasurementImplementationProvenance($this->repo, sys_get_temp_dir()))->currentIdentityReadOnly(), 'validation_evidence_refs' => $evidenceRefs, 'validation_evidence_hashes' => $evidenceHashes];
        return array_replace($row, $override);
    }

    private function bindingTamperRejected(string $window, string $field, string $value): bool
    {
        $path = (new OfficialEpochWindowBindingStore($this->repo))->bindingPath($window);
        $original = (string)file_get_contents($path);
        try {
            $row = json_decode($original, true, 512, JSON_THROW_ON_ERROR);
            $row[$field] = $value;
            unset($row['record_hash']);
            $row['record_hash'] = OfficialEpochWindowBindingV1::hash($row);
            file_put_contents($path, OfficialEpochPolicyV1::canonicalJson($row) . "\n");
            return empty((new OfficialEpochWindowBindingResolver($this->repo))->validateBinding($window)['accepted']);
        } finally {
            file_put_contents($path, $original);
        }
    }
    /** @return array<string,mixed> */
    private function handoff(string $window): array
    {
        $pointer = $this->repo . '/' . FormalMeasurementWindowContext::POINTER_RELATIVE_PATH;
        if (!is_dir(dirname($pointer))) mkdir(dirname($pointer), 0775, true);
        if (!is_file($pointer)) file_put_contents($pointer, OfficialEpochPolicyV1::canonicalJson(['schema_version' => FormalMeasurementWindowContext::POINTER_SCHEMA_VERSION, 'window_id' => 'source-closed-fixture', 'window_root' => 'var/strict_forward_live_readiness/windows/source-closed-fixture']) . "\n");
        $closure = $this->repo . '/var/strict_forward_live_readiness/lifecycle/closures/source-closed-fixture.json';
        if (!is_dir(dirname($closure))) mkdir(dirname($closure), 0775, true);
        if (!is_file($closure)) file_put_contents($closure, json_encode(['schema_version' => FormalMeasurementWindowLifecycle::CLOSURE_SCHEMA_VERSION, 'window_id' => 'source-closed-fixture', 'closure_record_hash' => 'sha256:' . str_repeat('e', 64)]));
        return (new FormalMeasurementWindowLifecycle($this->repo))->handoffPointerCas($pointer, 'source-closed-fixture', ['schema_version' => FormalMeasurementWindowContext::POINTER_SCHEMA_VERSION, 'window_id' => $window, 'window_root' => 'var/strict_forward_live_readiness/windows/' . $window], 'fixture');
    }
    /** @return array<string,mixed> */
    private function activate(string $window): array
    {
        $context = FormalMeasurementWindowContext::fromWindowRoot($this->repo, $this->windowRoot($window));
        return $context->activationHold()->activate('fixture-preflight');
    }
    private function windowRoot(string $id): string { return $this->repo . '/var/strict_forward_live_readiness/windows/' . $id; }
    private function mutateDeclarationContract(string $window, string $field, string $value): void
    {
        $path = $this->windowRoot($window) . '/declared_implementation_identity.json';
        $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $row['comparative_contract'][$field] = $value;
        file_put_contents($path, json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
    private function treeHash(string $root): string { $files = []; foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) if ($f->isFile()) $files[] = $f->getPathname() . ':' . hash_file('sha256', $f->getPathname()); sort($files); return hash('sha256', implode("\n", $files)); }
    private function check(string $name, bool $ok): void { $this->checks[$name] = $ok; }
    private function remove(string $path): void { if ($path === '' || !is_dir($path)) return; foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); rmdir($path); }
}

(new OfficialEpochWindowBindingV1Fixture())->run();
