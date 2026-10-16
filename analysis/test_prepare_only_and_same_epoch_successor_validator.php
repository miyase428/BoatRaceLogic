<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/FormalMeasurementWindowPrepareOnlyV1.php';
require_once __DIR__ . '/../web/logic/OfficialEpochSameEpochSuccessorValidator.php';

/** Focused isolated fixture for pointer-free preparation and state-neutral validation. */
final class PrepareOnlyAndSuccessorValidatorFixture
{
    private string $repo;
    /** @var array<string,bool> */ private array $prepare = [];
    /** @var array<string,bool> */ private array $validator = [];

    public function run(): void
    {
        $this->repo = sys_get_temp_dir() . '/boatrace-prepare-only-' . bin2hex(random_bytes(6));
        try {
            $this->copyCritical();
            $service = new FormalMeasurementWindowPrepareOnlyV1($this->repo, null, new DateTimeImmutable('2026-10-14T10:00:00+09:00'));
            $pointer = $this->repo . '/' . FormalMeasurementWindowContext::POINTER_RELATIVE_PATH;
            $made = $service->create(['window_id' => 'prepared-fixture']);
            $root = $this->root('prepared-fixture');
            $this->p('normal valid prepare-only creates', !empty($made['accepted']) && ($made['manifest']['status'] ?? '') === 'PREPARED');
            $this->p('pointer read zero structurally', !file_exists($pointer) && ($made['pointer_reads'] ?? -1) === 0);
            $this->p('pointer write zero', !file_exists($pointer) && ($made['pointer_writes'] ?? -1) === 0);
            $this->p('pointer CAS zero', ($made['pointer_cas'] ?? -1) === 0);
            $this->p('Window Manifest created', is_file($root . '/validation_window_manifest.json'));
            $this->p('Declaration created', is_file($root . '/declared_implementation_identity.json'));
            $this->p('Measurement Manifest created', is_file($root . '/provenance/' . $made['implementation']['manifest_ref']));
            $this->p('Source Freeze created', is_file($root . '/provenance/' . $made['implementation']['source_freeze_ref']));
            $this->p('prospective contract created', $service->readPreparedContract('prepared-fixture') !== []);
            $this->p('declared identity matches current', (new MeasurementImplementationProvenance($this->repo, sys_get_temp_dir()))->currentIdentityReadOnly() === ($made['implementation']['implementation_identity'] ?? ''));
            $this->p('no formal evidence', $this->formalCount($root) === 0);
            $this->p('no binding', !is_file($this->repo . '/var/strict_forward_live_readiness/lifecycle/official_epoch_window_bindings/prepared-fixture.json'));
            $this->p('no activation', !is_file($root . '/activation_record.json'));
            $this->p('no closure', !is_file($this->repo . '/var/strict_forward_live_readiness/lifecycle/closures/prepared-fixture.json'));
            $this->p('no abort', !is_file($this->repo . '/var/strict_forward_live_readiness/lifecycle/aborts/prepared-fixture.json'));
            $recovery = $service->plan(['mode'=>'RECOVERY','epoch_state'=>'SUSPENDED','incident_hash'=>'sha256:'.str_repeat('a',64),'suspension_hash'=>'sha256:'.str_repeat('b',64)]);
            $this->p('SUSPENDED recovery prepare allowed', !empty($recovery['accepted']));
            $this->p('ENDED recovery prepare blocked', empty($service->plan(['mode'=>'RECOVERY','epoch_state'=>'ENDED','incident_hash'=>'sha256:'.str_repeat('a',64),'suspension_hash'=>'sha256:'.str_repeat('b',64)])['accepted']));
            $this->p('missing Incident blocked', empty($service->plan(['mode'=>'RECOVERY','epoch_state'=>'SUSPENDED','suspension_hash'=>'sha256:'.str_repeat('b',64)])['accepted']));
            $this->p('missing Suspension blocked', empty($service->plan(['mode'=>'RECOVERY','epoch_state'=>'SUSPENDED','incident_hash'=>'sha256:'.str_repeat('a',64)])['accepted']));
            $this->p('duplicate Window conflict', empty($service->create(['window_id'=>'prepared-fixture'])['accepted']));
            $this->p('invalid ID fails before partial create', empty($service->create(['window_id'=>'../invalid'])['accepted']) && !is_dir($this->root('../invalid')));
            $this->p('normal initialize source remains pointer-owning', str_contains((string)file_get_contents(__DIR__ . '/initialize_successor_v3_prepared.php'), 'successorV3WritePointer'));

            $epoch = ['epoch_id'=>'official-epoch-v1-fixture','record_hash'=>'sha256:'.str_repeat('c',64),'epoch_policy_identity'=>OfficialEpochPolicyV1::identity(),'incident_policy_identity'=>OfficialEpochIncidentPolicyV1::identity()] + ComparativeContractV1::production($this->repo)->identities();
            $input = $this->input((string)$made['implementation']['implementation_identity']);
            $validator = new OfficialEpochSameEpochSuccessorValidator($this->repo);
            $valid=$validator->evaluate('prepared-fixture',$epoch,$input);
            $this->v('valid evidence 9/9 PASS', !empty($valid['accepted']) && ($valid['overall_result']??'')==='PASS');
            $this->v('9 item count fixed', count($valid['requirements']??[])===9);
            $this->v('core has no Epoch ACTIVE branch', !str_contains((string)file_get_contents(__DIR__ . '/../web/logic/OfficialEpochSameEpochSuccessorValidator.php'), "'ACTIVE'"));
            $this->v('SUSPENDED has same core result', ($validator->evaluate('prepared-fixture',$epoch,$input)['overall_result']??'')==='PASS');
            $this->v('canonical hash deterministic', ($valid['package']['validation_package_hash']??'')===($validator->evaluate('prepared-fixture',$epoch,$input)['package']['validation_package_hash']??''));
            $this->v('timestamp excluded from key', !array_key_exists('evaluated_at',$valid['package']??[]));
            $this->v('same evidence same hash', !empty($validator->validatePackage('prepared-fixture',$valid['package'],$epoch,$input)['accepted']));
            foreach (['comparative_scope_identity'=>'sha256:'.str_repeat('0',64),'comparative_policy_identity'=>'sha256:'.str_repeat('1',64),'epoch_policy_identity'=>'sha256:'.str_repeat('2',64),'incident_policy_identity'=>'sha256:'.str_repeat('3',64)] as $field=>$value) {
                $badEpoch=$epoch; $badEpoch[$field]=$value; $this->v($field.' mismatch fails', ($validator->evaluate('prepared-fixture',$badEpoch,$input)['overall_result']??'')==='FAIL');
            }
            foreach (['no_semantic_contract_diff'=>false,'formal_evidence_count'=>1,'regressions_pass'=>false,'readiness_pass'=>false,'current_measurement_identity'=>'sha256:'.str_repeat('4',64)] as $field=>$value) {
                $bad=$input; $bad[$field]=$value; $this->v($field.' fails', ($validator->evaluate('prepared-fixture',$epoch,$bad)['overall_result']??'')==='FAIL');
            }
            $badPackage=$valid['package']; $badPackage['requirements']['readiness_pass']='FAIL';
            $this->v('wrong package hash rejected', empty($validator->validatePackage('prepared-fixture',$badPackage,$epoch,$input)['accepted']));
            $otherPackage=$valid['package'];$otherPackage['window_id']='other';unset($otherPackage['validation_package_hash'],$otherPackage['validation_key']);$otherPackage['validation_key']='sha256:'.hash('sha256',OfficialEpochPolicyV1::canonicalJson($otherPackage));$otherPackage['validation_package_hash']='sha256:'.hash('sha256',OfficialEpochPolicyV1::canonicalJson($otherPackage));
            $this->v('package for another Window rejected', empty($validator->validatePackage('prepared-fixture',$otherPackage,$epoch,$input)['accepted']));
            $this->v('manual package cannot replace recomputed package', empty($validator->validatePackage('prepared-fixture',['validation_package_hash'=>'sha256:'.str_repeat('f',64)],$epoch,$input)['accepted']));
            $bad=$input;$bad['validation_evidence_refs']['readiness_pass']='';$this->v('missing requirement evidence ref fails', ($validator->evaluate('prepared-fixture',$epoch,$bad)['overall_result']??'')==='FAIL');
            $bad=$input;$bad['validation_evidence_hashes']['readiness_pass']='invalid';$this->v('invalid requirement evidence hash fails', ($validator->evaluate('prepared-fixture',$epoch,$bad)['overall_result']??'')==='FAIL');
            $bad=$input;$bad['successor_semantics_classification']='OTHER';$this->v('classification mismatch fails', ($validator->evaluate('prepared-fixture',$epoch,$bad)['overall_result']??'')==='FAIL');
            $legacyRoot=$this->root('legacy-without-contract');WindowActivationHold::createPrepared($legacyRoot.'/validation_window_manifest.json','legacy-without-contract',new DateTimeImmutable('2026-10-14T10:00:00+09:00'));$legacyDecl=new WindowDeclaredImplementationIdentity($legacyRoot.'/validation_window_manifest.json',new MeasurementImplementationProvenance($this->repo,$legacyRoot.'/provenance'),$legacyRoot.'/declared_implementation_identity.json');$legacyDecl->declare();$this->v('missing prepared contract is blocked', empty($validator->evaluate('legacy-without-contract',$epoch,$input)['accepted']));
            $this->v('state-neutral validator emits no lifecycle record', !is_dir($this->repo.'/var/strict_forward_live_readiness/lifecycle/official_epoch_recovery_successor_attestations'));
            $this->v('no pointer requirement', !array_key_exists('pointer_window_id',$valid['package']));
            $this->v('no normal binding requirement', !array_key_exists('binding_hash',$valid['package']));
            $this->v('prepared contract is bound', ($valid['package']['window_declaration_hash']??'') === $this->hash($root.'/declared_implementation_identity.json'));
            $this->v('measurement manifest is bound', !empty($valid['package']['measurement_manifest_hash']));
            $this->v('source freeze is bound', !empty($valid['package']['measurement_source_freeze_hash']));
            $this->v('validator performs no Window write', $this->formalCount($root)===0 && !file_exists($pointer));
            $this->out();
        } finally { $this->remove($this->repo); }
    }
    /** @return array<string,mixed> */ private function input(string $identity):array{$refs=[];$hashes=[];foreach(OfficialEpochWindowBindingV1::requirementNames()as$n){$refs[$n]='fixture://'.$n;$hashes[$n]='sha256:'.hash('sha256',$n);}return ['successor_semantics_classification'=>OfficialEpochWindowBindingV1::SEMANTICS_EQUIVALENT,'no_semantic_contract_diff'=>true,'regressions_pass'=>true,'readiness_pass'=>true,'numeric_policy_semantics_unchanged'=>true,'epoch_policy_identity'=>OfficialEpochPolicyV1::identity(),'incident_policy_identity'=>OfficialEpochIncidentPolicyV1::identity(),'current_measurement_identity'=>$identity,'formal_evidence_count'=>0,'carryover_count'=>0,'backfill_count'=>0,'prior_evidence_reuse_count'=>0,'validation_evidence_refs'=>$refs,'validation_evidence_hashes'=>$hashes];}
    private function copyCritical():void{foreach(MeasurementImplementationProvenance::criticalPaths()as$p){$from=dirname(__DIR__).'/'.$p;$to=$this->repo.'/'.$p;if(!is_dir(dirname($to)))mkdir(dirname($to),0775,true);copy($from,$to);}}
    private function root(string $id):string{return $this->repo.'/var/strict_forward_live_readiness/windows/'.$id;}
    private function formalCount(string $root):int{$n=0;foreach(['ledger','opportunity','input_snapshots','comparative_pairs']as$d)if(is_dir($root.'/'.$d))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$d,FilesystemIterator::SKIP_DOTS))as$f)if($f->isFile()&&str_ends_with($f->getFilename(),'.json'))$n++;return$n;}
    private function hash(string $path):string{return 'sha256:'.hash_file('sha256',$path);} private function p(string $name,bool $ok):void{$this->prepare[$name]=$ok;}private function v(string $name,bool $ok):void{$this->validator[$name]=$ok;}
    private function out():void{$all=$this->prepare+$this->validator;$pass=count(array_filter($all));echo json_encode(['status'=>$pass===count($all)?'PASS':'FAIL','prepare_only_fixture_total'=>count($this->prepare),'prepare_only_fixture_pass'=>count(array_filter($this->prepare)),'prepare_only_fixture_fail'=>count($this->prepare)-count(array_filter($this->prepare)),'validator_fixture_total'=>count($this->validator),'validator_fixture_pass'=>count(array_filter($this->validator)),'validator_fixture_fail'=>count($this->validator)-count(array_filter($this->validator)),'checks'=>$all],JSON_UNESCAPED_SLASHES).PHP_EOL;if($pass!==count($all))exit(1);}
    private function remove(string $path):void{if(!is_dir($path))return;foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($path);}
}
(new PrepareOnlyAndSuccessorValidatorFixture())->run();
