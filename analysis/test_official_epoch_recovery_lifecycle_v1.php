<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/MeasurementImplementationProvenance.php';
require_once __DIR__ . '/../web/logic/OfficialEpochRecoveryLifecycleV1.php';
require_once __DIR__ . '/../web/logic/OfficialEpochStartStore.php';
require_once __DIR__ . '/../web/logic/OfficialEpochWindowBindingStore.php';
require_once __DIR__ . '/../web/logic/OfficialEpochRecoveryPointerHandoffV1.php';

/** Isolated coverage of the suspended-Epoch recovery sequence. */
final class OfficialEpochRecoveryLifecycleFixture
{
    private string $repo;
    private string $epoch = 'official-epoch-v1-4444444444444444';
    /** @var array<string,bool> */ private array $checks = [];

    public function run(): void
    {
        $this->repo = sys_get_temp_dir() . '/boatrace-recovery-' . bin2hex(random_bytes(6));
        try {
            $this->copyCritical(); $this->startEpoch();
            $life = $this->repo . '/var/strict_forward_live_readiness/lifecycle';
            $incidentStore = new OfficialEpochIncidentLifecycleStore($life);
            $this->prepared('stale');
            file_put_contents($this->repo . '/web/logic/OfficialEpochRecoveryLifecycleV1.php', "\n// fixture identity revision\n", FILE_APPEND);
            $current = (new MeasurementImplementationProvenance($this->repo, $this->repo . '/tmp'))->currentIdentityReadOnly();
            $this->prepared('replacement');
            $incident = $incidentStore->incidentRecord(['epoch_id'=>$this->epoch,'incident_type'=>'UNKNOWN_UNCLASSIFIED_FAILURE','detected_at'=>'2026-10-14T09:00:00+09:00','source_evidence_ref'=>'fixture://source','source_evidence_hash'=>'sha256:'.str_repeat('1',64),'affected_scope'=>['window'=>'fixture'],'evidence_status_projection'=>'QUARANTINED']);
            $this->check('critical incident create', !empty($incidentStore->createIncident($incident)['accepted']));
            $active=$incidentStore->project($this->epoch);
            $suspend=$incidentStore->transitionRecord('SUSPEND',['epoch_id'=>$this->epoch,'from_state'=>'ACTIVE','predecessor_state_hash'=>$active['state_hash'],'incident_ref'=>'official_epoch_incidents/'.$incident['incident_id'].'.json','incident_hash'=>$incident['record_hash'],'transition_at'=>'2026-10-14T09:01:00+09:00']);
            $this->check('epoch suspended', !empty($incidentStore->commitTransition('SUSPEND',$suspend)['accepted']));
            $suspended=$incidentStore->project($this->epoch);
            $recovery=new OfficialEpochRecoveryLifecycleV1($this->repo,$life);
            $supInput=['epoch_id'=>$this->epoch,'epoch_state'=>'SUSPENDED','incident_ref'=>'official_epoch_incidents/'.$incident['incident_id'].'.json','incident_hash'=>$incident['record_hash'],'suspension_ref'=>'official_epoch_suspensions/'.$suspend['transition_id'].'.json','suspension_hash'=>$suspend['record_hash'],'current_measurement_identity'=>$current,'reason'=>'MEASUREMENT_IMPLEMENTATION_IDENTITY_SUPERSEDED_DURING_INCIDENT_RECOVERY','created_at'=>'2026-10-14T09:02:00+09:00'];
            $this->check('stale PREPARED identity mismatch eligible', !empty($recovery->preflightSupersession('stale',$supInput)['accepted']));
            $this->check('same identity stale supersession blocked', empty($recovery->preflightSupersession('stale',array_replace($supInput,['current_measurement_identity'=>$this->declared('stale')]))['accepted']));
            $sup=$recovery->createSupersession('stale',$supInput);
            $this->check('supersession create only', !empty($sup['accepted']));
            $this->check('supersession idempotent', str_contains((string)($recovery->createSupersession('stale',$supInput)['status']??''),'ALREADY_EXISTS'));
            $this->check('supersession conflict guarded', empty($recovery->createSupersession('stale',array_replace($supInput,['reason'=>'OTHER']))['accepted']));
            $this->check('supersession blocks activation', (new WindowActivationHold($this->root('stale').'/validation_window_manifest.json',new WindowDeclaredImplementationIdentity($this->root('stale').'/validation_window_manifest.json',new MeasurementImplementationProvenance($this->repo,$this->root('stale').'/provenance'),$this->root('stale').'/declared_implementation_identity.json'),$this->root('stale').'/ledger',null,$this->repo))->activate('fixture')['reason'] === 'WINDOW_PREPARED_SUPERSEDED');
            $this->check('supersession leaves Window root unchanged', $this->formalCount($this->root('stale'))===0);

            $validation=$this->validation($current);
            $validator=new OfficialEpochSameEpochSuccessorValidator($this->repo);
            $evaluation=$validator->evaluate('replacement',$this->epochRecord(),$validation);
            $requirements=$evaluation['requirements'];
            $attInput=['epoch_id'=>$this->epoch,'epoch_state'=>'SUSPENDED','epoch_start_record_ref'=>'official_epochs/'.$this->epoch.'/start.json','epoch_start_record_hash'=>$this->startHash(),'incident_ref'=>'official_epoch_incidents/'.$incident['incident_id'].'.json','incident_hash'=>$incident['record_hash'],'suspension_ref'=>'official_epoch_suspensions/'.$suspend['transition_id'].'.json','suspension_hash'=>$suspend['record_hash'],'current_measurement_identity'=>$current,'epoch_policy_identity'=>OfficialEpochPolicyV1::identity(),'incident_policy_identity'=>OfficialEpochIncidentPolicyV1::identity(),'validation_input'=>$validation,'validation_package'=>$evaluation['package'],'root_cause_fix_evidence_refs'=>['fixture://fix'=>'sha256:'.str_repeat('2',64)],'regression_evidence_refs'=>['fixture://regression'=>'sha256:'.str_repeat('3',64)],'canonical_source_window'=>['status'=>'VALID'],'created_at'=>'2026-10-14T09:03:00+09:00'];
            $this->check('SUSPENDED replacement + 9/9 attestation eligible', !empty($recovery->preflightAttestation('replacement',$attInput)['accepted']));
            $badValidation=array_replace($validation,['readiness_pass'=>false]);$bad=$validator->evaluate('replacement',$this->epochRecord(),$badValidation);
            $this->check('attestation requires validator 9/9', empty($recovery->preflightAttestation('replacement',array_replace($attInput,['validation_input'=>$badValidation,'validation_package'=>$bad['package']]))['accepted']));
            $this->check('manual 9/9 without package rejected', empty($recovery->preflightAttestation('replacement',array_replace($attInput,['validation_package'=>[]]))['accepted']));
            $att=$recovery->createAttestation('replacement',$attInput);
            $this->check('attestation create only', !empty($att['accepted']));
            $this->check('attestation needs no pointer or normal binding', !empty($recovery->attestationForWindow('replacement')));
            $this->check('attestation grants no runtime activation', empty((new WindowActivationHold($this->root('replacement').'/validation_window_manifest.json',new WindowDeclaredImplementationIdentity($this->root('replacement').'/validation_window_manifest.json',new MeasurementImplementationProvenance($this->repo,$this->root('replacement').'/provenance'),$this->root('replacement').'/declared_implementation_identity.json'),$this->root('replacement').'/ledger',null,$this->repo))->activate('fixture')['accepted']));

            $resolution=$recovery->resolutionRecord(['epoch_id'=>$this->epoch,'incident_id'=>$incident['incident_id'],'incident_hash'=>$incident['record_hash'],'resolved_at'=>'2026-10-14T09:04:00+09:00','root_cause'=>'pre-freeze mutation path','root_cause_fixed'=>true,'affected_scope_final'=>['closed_window_auxiliary_provenance_only'=>true],'artifact_disposition'=>'QUARANTINED_RETAINED_NONCANONICAL_NOT_INVALIDATED','canonical_capture_evidence_status'=>'VALID','closure_status'=>'VALID','epoch_source_status'=>'VALID','fix_implementation_identity'=>$current,'regression_result'=>'PASS','scheduler_held'=>true,'capture_process_count'=>0,'requires_new_window'=>true,'requires_new_epoch'=>false,'requires_cg_reapproval'=>true,'resolution_evidence_ref'=>'fixture://resolution','resolution_evidence_hash'=>'sha256:'.str_repeat('4',64)]);
            $this->check('resolution hard preflight valid', !empty($recovery->validateResolution($resolution)['accepted']));
            $this->check('resolution create only', !empty($incidentStore->createResolution($resolution)['accepted']));
            $this->check('resolution preserves suspended state', ($incidentStore->project($this->epoch)['state']??'')==='SUSPENDED');
            $this->check('invalid resolution blocked', empty($recovery->validateResolution(array_replace($resolution,['root_cause_fixed'=>false]))['accepted']));
            $statement='CG authorizes only this recovery package';
            $approvalInput=['epoch_id'=>$this->epoch,'epoch_state'=>'SUSPENDED','epoch_start_record_hash'=>$this->startHash(),'incident_ref'=>'official_epoch_incidents/'.$incident['incident_id'].'.json','incident_hash'=>$incident['record_hash'],'suspension_ref'=>'official_epoch_suspensions/'.$suspend['transition_id'].'.json','suspension_hash'=>$suspend['record_hash'],'resolution_ref'=>'official_epoch_incident_resolutions/'.$resolution['resolution_id'].'.json','resolution_path'=>$life.'/official_epoch_incident_resolutions/'.$resolution['resolution_id'].'.json','resolution_hash'=>$resolution['record_hash'],'attestation_ref'=>'official_epoch_recovery_successor_attestations/replacement.json','attestation_path'=>$recovery->attestationPath('replacement'),'attestation_hash'=>$att['record']['record_hash'],'replacement_window_id'=>'replacement','replacement_declaration_hash'=>$this->declarationHash('replacement'),'replacement_declared_measurement_identity'=>$current,'current_measurement_identity'=>$current,'scheduler_held'=>true,'capture_process_count'=>0,'same_epoch_successor_result'=>$requirements,'resume_authorized'=>true,'approver_authority'=>'CG','approver_stable_identifier'=>'fixture-cg','approval_statement'=>$statement,'approval_statement_hash'=>'sha256:'.hash('sha256',$statement),'created_at'=>'2026-10-14T09:05:00+09:00'];
            $approval=$recovery->recoveryApprovalRecord($approvalInput);
            $this->check('CG recovery approval valid', !empty($recovery->validateRecoveryApproval($approval)['accepted']));
            $this->check('CG recovery approval create only', !empty($recovery->createRecoveryApproval($approvalInput)['accepted']));
            $approvalPath=$recovery->approvalPath((string)$approval['approval_key']);
            $resumeInput=['epoch_state'=>'SUSPENDED','scheduler_held'=>true,'capture_process_count'=>0,'end_record_exists'=>false,'resolution_path'=>$life.'/official_epoch_incident_resolutions/'.$resolution['resolution_id'].'.json','attestation_path'=>$recovery->attestationPath('replacement'),'approval_path'=>$approvalPath,'current_measurement_identity'=>$current];
            $this->check('resume preflight valid without normal binding', !empty($recovery->preflightResume($resumeInput)['accepted']));
            $this->check('resume blocks fake resolution path', empty($recovery->preflightResume(array_replace($resumeInput,['resolution_path'=>$life.'/missing.json']))['accepted']));
            $resume=$incidentStore->transitionRecord('RESUME',['epoch_id'=>$this->epoch,'from_state'=>'SUSPENDED','predecessor_state_hash'=>$suspended['state_hash'],'transition_at'=>'2026-10-14T09:06:00+09:00','incident_ref'=>'official_epoch_incidents/'.$incident['incident_id'].'.json','incident_hash'=>$incident['record_hash'],'resolution_ref'=>'official_epoch_incident_resolutions/'.$resolution['resolution_id'].'.json','resolution_hash'=>$resolution['record_hash'],'cg_authorization_ref'=>'official_epoch_recovery_approvals/'.substr($approval['approval_key'],7).'.json','cg_authorization_hash'=>$approval['record_hash'],'recovery_successor_attestation_ref'=>'official_epoch_recovery_successor_attestations/replacement.json','recovery_successor_attestation_hash'=>$att['record']['record_hash'],'recovery_replacement_window_id'=>'replacement','recovery_current_measurement_identity'=>$current,'scheduler_held'=>true,'capture_process_count'=>0]);
            $this->check('resume creates only state transition', !empty($incidentStore->commitTransition('RESUME',$resume)['accepted']));
            $this->check('ACTIVE/PREPARED intermediate is safe', ($incidentStore->project($this->epoch)['state']??'')==='ACTIVE' && is_file($this->root('replacement').'/validation_window_manifest.json') && $this->formalCount($this->root('replacement'))===0);

            // A fresh normal binding is deliberately required for the target;
            // the historical recovery attestation never grants pointer or
            // runtime authority on its own.
            $binding=(new OfficialEpochWindowBindingStore($this->repo,$life))->createForPrepared('replacement',$validation);
            $this->check('fresh normal binding creates after resume', !empty($binding['accepted']));
            $pointerPath=$this->repo.'/'.FormalMeasurementWindowContext::POINTER_RELATIVE_PATH;
            if(!is_dir(dirname($pointerPath)))mkdir(dirname($pointerPath),0775,true);
            $oldPointer=['schema_version'=>FormalMeasurementWindowContext::POINTER_SCHEMA_VERSION,'window_id'=>'stale','window_root'=>'var/strict_forward_live_readiness/windows/stale'];
            $targetPointer=['schema_version'=>FormalMeasurementWindowContext::POINTER_SCHEMA_VERSION,'window_id'=>'replacement','window_root'=>'var/strict_forward_live_readiness/windows/replacement'];
            file_put_contents($pointerPath,OfficialEpochPolicyV1::canonicalJson($oldPointer)."\n");
            $normal=(new FormalMeasurementWindowLifecycle($this->repo))->handoffPointerCas($pointerPath,'stale',$targetPointer,'fixture');
            $this->check('normal CAS keeps superseded source blocked', empty($normal['accepted']) && ($normal['reason']??'')==='OLD_WINDOW_PREPARED_SUPERSEDED');
            $handoff=new OfficialEpochRecoveryPointerHandoffV1($this->repo,$life);
            $handoffInput=['source_window_id'=>'stale','target_window_id'=>'replacement','scheduler_held'=>true,'capture_process_count'=>0,'production_routing_unchanged'=>true,'current_measurement_identity'=>$current,'created_at'=>'2026-10-14T09:07:00+09:00','applied_at'=>'2026-10-14T09:08:00+09:00'];
            $first=$handoff->preflight($handoffInput);
            $second=$handoff->preflight($handoffInput);
            $this->check('recovery handoff accepts only complete recovery chain', !empty($first['accepted']));
            $this->check('handoff authorization key deterministic', ($first['intent']['handoff_key']??'')===($second['intent']['handoff_key']??''));
            $this->check('target stale identity blocks', empty($handoff->preflight(array_replace($handoffInput,['current_measurement_identity'=>'sha256:'.str_repeat('f',64)]))['accepted']));
            $this->check('scheduler hold is mandatory', empty($handoff->preflight(array_replace($handoffInput,['scheduler_held'=>false]))['accepted']));
            $this->check('capture process zero is mandatory', empty($handoff->preflight(array_replace($handoffInput,['capture_process_count'=>1]))['accepted']));
            $executed=$handoff->execute($handoffInput);
            $this->check('authorized intent then CAS then applied completion', !empty($executed['accepted']) && ($executed['status']??'')==='RECOVERY_HANDOFF_APPLIED');
            $this->check('handoff is pointer-only and remains PREPARED', (json_decode((string)file_get_contents($pointerPath),true)['window_id']??'')==='replacement' && $this->formalCount($this->root('replacement'))===0 && !is_file($this->root('replacement').'/activation_record.json'));
            $this->check('same payload replay idempotent', !empty($handoff->execute($handoffInput)['accepted']));
            $this->check('applied completion binds intent', ($handoff->readApplied((string)$first['intent']['handoff_key'])['intent_hash']??'')===($first['intent']['record_hash']??''));
            $this->check('policy identities unchanged', OfficialEpochPolicyV1::identity()==='sha256:e025750d254c2ed7fe2b289aa3413e322116cc235b1f44e952068c6ba183948d' && OfficialEpochIncidentPolicyV1::identity()==='sha256:ecccdbcc7c23e9fb007d0a17ab91580f995952f3d8c7db3e68278e61000af604');
            $pass=count(array_filter($this->checks)); echo json_encode(['status'=>$pass===count($this->checks)?'PASS':'FAIL','fixture_total'=>count($this->checks),'fixture_pass'=>$pass,'fixture_fail'=>count($this->checks)-$pass,'checks'=>$this->checks],JSON_UNESCAPED_SLASHES).PHP_EOL; if($pass!==count($this->checks)) exit(1);
        } finally { $this->remove($this->repo); }
    }
    private function copyCritical():void{$s=dirname(__DIR__);foreach(MeasurementImplementationProvenance::criticalPaths() as $p){$f=$s.'/'.$p;$t=$this->repo.'/'.$p;if(!is_dir(dirname($t)))mkdir(dirname($t),0775,true);copy($f,$t);}}
    private function startEpoch():void{$d=$this->repo.'/var/strict_forward_live_readiness/lifecycle/official_epochs/'.$this->epoch;mkdir($d,0775,true);$r=['schema_version'=>OfficialEpochStartStore::SCHEMA_VERSION,'epoch_id'=>$this->epoch,'epoch_key'=>'sha256:'.str_repeat('a',64),'authorization_hash'=>'sha256:'.str_repeat('b',64),'epoch_policy_identity'=>OfficialEpochPolicyV1::identity(),'incident_policy_schema'=>OfficialEpochIncidentPolicyV1::SCHEMA_VERSION,'incident_policy_identity'=>OfficialEpochIncidentPolicyV1::identity(),'source_closed_window_id'=>'source']+ComparativeContractV1::production($this->repo)->identities();$r['record_hash']='sha256:'.hash('sha256',OfficialEpochPolicyV1::canonicalJson($r));file_put_contents($d.'/start.json',OfficialEpochPolicyV1::canonicalJson($r)."\n");}
    private function prepared(string $id):void{if(empty((new FormalMeasurementWindowPrepareOnlyV1($this->repo,null,new DateTimeImmutable('2026-10-14T08:00:00+09:00')))->create(['window_id'=>$id])['accepted']))throw new RuntimeException('fixture declaration failed');}
    /** @return array<string,mixed> */ private function validation(string $current):array{$refs=[];$hashes=[];foreach(OfficialEpochWindowBindingV1::requirementNames() as $n){$refs[$n]='fixture://'.$n;$hashes[$n]='sha256:'.hash('sha256',$n);}return ['successor_semantics_classification'=>OfficialEpochWindowBindingV1::SEMANTICS_EQUIVALENT,'no_semantic_contract_diff'=>true,'regressions_pass'=>true,'readiness_pass'=>true,'numeric_policy_semantics_unchanged'=>true,'epoch_policy_identity'=>OfficialEpochPolicyV1::identity(),'incident_policy_identity'=>OfficialEpochIncidentPolicyV1::identity(),'formal_evidence_count'=>0,'carryover_count'=>0,'backfill_count'=>0,'prior_evidence_reuse_count'=>0,'current_measurement_identity'=>$current,'validation_evidence_refs'=>$refs,'validation_evidence_hashes'=>$hashes];}
    /** @return array<string,mixed> */ private function epochRecord():array{return json_decode((string)file_get_contents($this->repo.'/var/strict_forward_live_readiness/lifecycle/official_epochs/'.$this->epoch.'/start.json'),true,512,JSON_THROW_ON_ERROR);}
    private function root(string $id):string{return $this->repo.'/var/strict_forward_live_readiness/windows/'.$id;} private function declared(string $id):string{$r=json_decode((string)file_get_contents($this->root($id).'/declared_implementation_identity.json'),true);return(string)($r['implementation_identity']??'');} private function declarationHash(string $id):string{return 'sha256:'.hash_file('sha256',$this->root($id).'/declared_implementation_identity.json');} private function startHash():string{$r=json_decode((string)file_get_contents($this->repo.'/var/strict_forward_live_readiness/lifecycle/official_epochs/'.$this->epoch.'/start.json'),true);return(string)$r['record_hash'];} private function formalCount(string $r):int{$n=0;foreach(['ledger','opportunity','input_snapshots','comparative_pairs']as$d)if(is_dir($r.'/'.$d))foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r.'/'.$d,FilesystemIterator::SKIP_DOTS))as$f)if($f->isFile()&&str_ends_with($f->getFilename(),'.json'))$n++;return$n;} private function check(string $n,bool $ok):void{$this->checks[$n]=$ok;}private function remove(string $p):void{if(!is_dir($p))return;foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($p,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST)as$f)$f->isDir()?rmdir($f->getPathname()):unlink($f->getPathname());rmdir($p);}
}
(new OfficialEpochRecoveryLifecycleFixture())->run();
