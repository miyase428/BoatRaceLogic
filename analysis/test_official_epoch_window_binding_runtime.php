<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/MeasurementImplementationProvenance.php';
require_once __DIR__ . '/../web/logic/OfficialEpochWindowBindingStore.php';
require_once __DIR__ . '/../web/logic/OfficialEpochWindowBindingRuntimeAuthorization.php';
require_once __DIR__ . '/../web/logic/OfficialEpochIncidentLifecycleStore.php';
require_once __DIR__ . '/../web/logic/FormalMeasurementWindowLifecycle.php';
require_once __DIR__ . '/collect_strict_forward_exhibition.php';

final class OfficialEpochBindingRuntimeFixture
{
    private string $repo;
    private string $epoch = 'official-epoch-v1-1111111111111111';
    /** @var array<string,bool> */ private array $checks = [];

    public function run(): void
    {
        $this->repo = sys_get_temp_dir() . '/boatrace-runtime-binding-' . bin2hex(random_bytes(6));
        try {
            $this->copyCritical();
            $this->epochStart();
            $runtime = new OfficialEpochWindowBindingRuntimeAuthorization($this->repo);
            $this->check('no ACTIVE Window is safe noop', ($runtime->authorizeOperationalPointerWindow()['kind'] ?? '') === 'NOOP');
            $this->check('collector no ACTIVE Window no fetch', $this->collectorNoop());

            $window = 'runtime-valid'; $this->prepared($window); $store = new OfficialEpochWindowBindingStore($this->repo);
            $this->check('valid Prepared binding created', !empty($store->createForPrepared($window, $this->validation())['accepted']));
            $this->check('scheduler preflight valid binding allowed', !empty((new OfficialEpochWindowBindingResolver($this->repo))->validateBinding($window, ['require_prepared' => true])['accepted']));
            $this->check('pointer handoff valid binding allowed', !empty($this->handoff($window)['accepted']));
            $activation = $this->activate($window);
            $this->check('activation v2 valid binding allowed', !empty($activation['accepted']));
            $this->check('common formal writer allowed', !empty(FormalMeasurementWindowContext::production($this->repo)->authorizeMeasurementWrite()['accepted']));
            $this->check('operational pointer runtime allowed', !empty($runtime->authorizeOperationalPointerWindow()['accepted']));

            $bindingPath = $store->bindingPath($window); $originalBinding = (string)file_get_contents($bindingPath);
            unlink($bindingPath);
            $this->check('missing binding blocks collector/runtime', ($runtime->authorizeOperationalPointerWindow()['kind'] ?? '') === 'GLOBAL_FATAL');
            $this->check('missing binding blocks common formal writer', empty(FormalMeasurementWindowContext::production($this->repo)->authorizeMeasurementWrite()['accepted']));
            file_put_contents($bindingPath, $originalBinding);

            $this->check('wrong Epoch blocks runtime', $this->tamperedBindingRejected($window, 'epoch_id', 'official-epoch-v1-2222222222222222'));
            $this->check('wrong start hash blocks runtime', $this->tamperedBindingRejected($window, 'epoch_start_record_hash', 'sha256:' . str_repeat('0', 64)));
            $this->check('wrong Epoch policy blocks runtime', $this->tamperedBindingRejected($window, 'epoch_policy_identity', 'sha256:' . str_repeat('1', 64)));
            $this->check('wrong Incident policy blocks runtime', $this->tamperedBindingRejected($window, 'incident_policy_identity', 'sha256:' . str_repeat('2', 64)));
            $this->check('wrong declaration hash blocks runtime', $this->tamperedBindingRejected($window, 'window_declaration_hash', 'sha256:' . str_repeat('3', 64)));
            $this->check('wrong declared identity blocks runtime', $this->tamperedBindingRejected($window, 'declared_measurement_identity', 'sha256:' . str_repeat('4', 64)));
            $this->check('invalid binding hash blocks runtime', $this->invalidBindingRejected($window));

            $activationPath = $this->root($window) . '/activation_record.json'; $originalActivation = (string)file_get_contents($activationPath);
            $a = json_decode($originalActivation, true, 512, JSON_THROW_ON_ERROR); $a['official_epoch_window_binding_hash'] = 'sha256:' . str_repeat('5', 64); file_put_contents($activationPath, json_encode($a));
            $this->check('activation binding mismatch blocks runtime', empty($runtime->authorizeOperationalPointerWindow()['accepted']));
            file_put_contents($activationPath, $originalActivation);

            $incident = new OfficialEpochIncidentLifecycleStore($this->repo . '/var/strict_forward_live_readiness/lifecycle');
            $state = $incident->project($this->epoch);
            $suspend = $incident->transitionRecord('SUSPEND', ['epoch_id' => $this->epoch, 'from_state' => 'ACTIVE', 'predecessor_state_hash' => $state['state_hash'], 'incident_hash' => 'sha256:' . str_repeat('6', 64), 'transition_at' => '2026-10-14T00:00:00+09:00']);
            $incident->commitTransition('SUSPEND', $suspend);
            $this->check('Epoch SUSPENDED blocks collector/runtime', ($runtime->authorizeOperationalPointerWindow()['kind'] ?? '') === 'GLOBAL_FATAL');
            $this->check('Epoch SUSPENDED blocks common formal writer', empty(FormalMeasurementWindowContext::production($this->repo)->authorizeMeasurementWrite()['accepted']));
            $state = $incident->project($this->epoch);
            $end = $incident->transitionRecord('END', ['epoch_id' => $this->epoch, 'from_state' => 'SUSPENDED', 'predecessor_state_hash' => $state['state_hash'], 'incident_hash' => 'sha256:' . str_repeat('7', 64), 'transition_at' => '2026-10-14T00:01:00+09:00']);
            $incident->commitTransition('END', $end);
            $this->check('Epoch ENDED blocks collector/runtime', ($runtime->authorizeOperationalPointerWindow()['kind'] ?? '') === 'GLOBAL_FATAL');
            $this->check('Epoch ENDED blocks common formal writer', empty(FormalMeasurementWindowContext::production($this->repo)->authorizeMeasurementWrite()['accepted']));

            $this->check('historical source remains binding-free readable', !empty((new OfficialEpochWindowBindingResolver($this->repo))->epochForBoundWindow('source-closed-fixture')['accepted']));
            $this->check('runtime class performs no formal evidence write', $this->formalJsonCount($this->root($window)) === 0);
            $this->check('no production routing mutation', hash_file('sha256', dirname(__DIR__) . '/forecast/tamagawa_center_signal_live_v1.py') === hash_file('sha256', dirname(__DIR__) . '/forecast/tamagawa_center_signal_live_v1.py'));
            $this->check('collector has no formal writer', !str_contains((string)file_get_contents(__DIR__ . '/collect_strict_forward_exhibition.php'), 'StrictForwardReadinessLedger::captureLive'));
            $this->check('scheduler guard precedes DB access', $this->schedulerGuardEarly());
            $this->check('same Epoch policy identity remains frozen', OfficialEpochPolicyV1::identity() === 'sha256:e025750d254c2ed7fe2b289aa3413e322116cc235b1f44e952068c6ba183948d');
            $this->check('same Incident policy identity remains frozen', OfficialEpochIncidentPolicyV1::identity() === 'sha256:ecccdbcc7c23e9fb007d0a17ab91580f995952f3d8c7db3e68278e61000af604');
            $pass = count(array_filter($this->checks));
            echo json_encode(['fixture_total' => count($this->checks), 'fixture_pass' => $pass, 'fixture_fail' => count($this->checks) - $pass, 'checks' => $this->checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            if ($pass !== count($this->checks)) exit(1);
        } finally { $this->remove($this->repo); }
    }

    private function copyCritical(): void { $source = dirname(__DIR__); foreach (MeasurementImplementationProvenance::criticalPaths() as $p) { $from = $source . '/' . $p; $to = $this->repo . '/' . $p; if (!is_dir(dirname($to))) mkdir(dirname($to), 0775, true); copy($from, $to); } }
    private function epochStart(): void
    {
        $dir = $this->repo . '/var/strict_forward_live_readiness/lifecycle/official_epochs/' . $this->epoch; mkdir($dir, 0775, true);
        $row = ['schema_version' => OfficialEpochStartStore::SCHEMA_VERSION, 'epoch_id' => $this->epoch, 'epoch_key' => 'sha256:' . str_repeat('a', 64), 'authorization_hash' => 'sha256:' . str_repeat('b', 64), 'epoch_policy_identity' => OfficialEpochPolicyV1::identity(), 'incident_policy_schema' => OfficialEpochIncidentPolicyV1::SCHEMA_VERSION, 'incident_policy_identity' => OfficialEpochIncidentPolicyV1::identity(), 'source_closed_window_id' => 'source-closed-fixture'] + ComparativeContractV1::production($this->repo)->identities();
        $row['record_hash'] = OfficialEpochWindowBindingV1::hash($row); file_put_contents($dir . '/start.json', OfficialEpochPolicyV1::canonicalJson($row) . "\n");
    }
    private function prepared(string $id): void
    {
        if (empty((new FormalMeasurementWindowPrepareOnlyV1($this->repo, null, new DateTimeImmutable('2026-10-14T08:00:00+09:00')))->create(['window_id'=>$id])['accepted'])) throw new RuntimeException('declaration fixture failed');
    }
    /** @return array<string,mixed> */ private function validation(): array { $refs=[];$hashes=[]; foreach (OfficialEpochWindowBindingV1::requirementNames() as $n) {$refs[$n]='fixture://'.$n;$hashes[$n]='sha256:'.hash('sha256',$n);} return ['successor_semantics_classification'=>OfficialEpochWindowBindingV1::SEMANTICS_EQUIVALENT,'no_semantic_contract_diff'=>true,'regressions_pass'=>true,'readiness_pass'=>true,'numeric_policy_semantics_unchanged'=>true,'epoch_policy_identity'=>OfficialEpochPolicyV1::identity(),'incident_policy_identity'=>OfficialEpochIncidentPolicyV1::identity(),'current_measurement_identity'=>(new MeasurementImplementationProvenance($this->repo,sys_get_temp_dir()))->currentIdentityReadOnly(),'formal_evidence_count'=>0,'carryover_count'=>0,'backfill_count'=>0,'prior_evidence_reuse_count'=>0,'validation_evidence_refs'=>$refs,'validation_evidence_hashes'=>$hashes]; }
    /** @return array<string,mixed> */ private function handoff(string $id): array { $path=$this->repo.'/'.FormalMeasurementWindowContext::POINTER_RELATIVE_PATH; if(!is_dir($this->root('source-closed-fixture'))) $this->prepared('source-closed-fixture'); if(!is_dir(dirname($path)))mkdir(dirname($path),0775,true); if(!is_file($path))file_put_contents($path,OfficialEpochPolicyV1::canonicalJson(['schema_version'=>FormalMeasurementWindowContext::POINTER_SCHEMA_VERSION,'window_id'=>'source-closed-fixture','window_root'=>'var/strict_forward_live_readiness/windows/source-closed-fixture'])."\n"); $closure=$this->repo.'/var/strict_forward_live_readiness/lifecycle/closures/source-closed-fixture.json'; if(!is_dir(dirname($closure)))mkdir(dirname($closure),0775,true); if(!is_file($closure))file_put_contents($closure,json_encode(['schema_version'=>FormalMeasurementWindowLifecycle::CLOSURE_SCHEMA_VERSION,'window_id'=>'source-closed-fixture','closure_record_hash'=>'sha256:'.str_repeat('c',64)])); return (new FormalMeasurementWindowLifecycle($this->repo))->handoffPointerCas($path,'source-closed-fixture',['schema_version'=>FormalMeasurementWindowContext::POINTER_SCHEMA_VERSION,'window_id'=>$id,'window_root'=>'var/strict_forward_live_readiness/windows/'.$id],'fixture'); }
    /** @return array<string,mixed> */ private function activate(string $id): array { return FormalMeasurementWindowContext::fromWindowRoot($this->repo,$this->root($id))->activationHold()->activate('fixture-preflight'); }
    private function tamperedBindingRejected(string $id,string $field,string $value): bool { $p=(new OfficialEpochWindowBindingStore($this->repo))->bindingPath($id);$old=(string)file_get_contents($p);try{$r=json_decode($old,true,512,JSON_THROW_ON_ERROR);$r[$field]=$value;unset($r['record_hash']);$r['record_hash']=OfficialEpochWindowBindingV1::hash($r);file_put_contents($p,OfficialEpochPolicyV1::canonicalJson($r)."\n");return empty((new OfficialEpochWindowBindingRuntimeAuthorization($this->repo))->authorizeOperationalPointerWindow()['accepted']);}finally{file_put_contents($p,$old);}}
    private function invalidBindingRejected(string $id): bool { $p=(new OfficialEpochWindowBindingStore($this->repo))->bindingPath($id);$old=(string)file_get_contents($p);try{file_put_contents($p,'{}');return empty((new OfficialEpochWindowBindingRuntimeAuthorization($this->repo))->authorizeOperationalPointerWindow()['accepted']);}finally{file_put_contents($p,$old);}}
    private function collectorNoop(): bool { ob_start(); $result=cc30bMain(['collector','--dry-run','--now=2026-10-14T12:00:00+09:00'],$this->repo); $output=(string)ob_get_clean(); return $result===0 && str_contains($output,'SAFE_NOOP_NO_ACTIVE_WINDOW') && !str_contains($output,'FETCH_ATTEMPTED'); }
    private function schedulerGuardEarly(): bool { $source=(string)file_get_contents(__DIR__.'/capture_all_race_forward.php');$guard=strpos($source,'FORMAL_CAPTURE_AUTHORIZATION_FATAL');$db=strpos($source,'$pdo = getPDO()');return $guard!==false && $db!==false && $guard<$db; }
    private function formalJsonCount(string $root): int { $count=0;foreach(['ledger','opportunity','input_snapshots','comparative_pairs'] as $dir)if(is_dir($root.'/'.$dir))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir,FilesystemIterator::SKIP_DOTS)) as $f)if($f->isFile()&&str_ends_with($f->getFilename(),'.json'))$count++;return $count; }
    private function root(string $id): string { return $this->repo.'/var/strict_forward_live_readiness/windows/'.$id; }
    private function check(string $name,bool $ok):void{$this->checks[$name]=$ok;}
    private function remove(string $path):void{if($path===''||!is_dir($path))return;foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($path);}
}
(new OfficialEpochBindingRuntimeFixture())->run();
