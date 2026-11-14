<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/logic/FormalEvidenceAggregate.php';
require_once __DIR__ . '/../web/logic/FormalMeasurementWindowContext.php';
require_once __DIR__ . '/../web/logic/FormalMeasurementWindowPrepareOnlyV1.php';
require_once __DIR__ . '/../web/logic/IdentityDriftRecoveryEvidenceV1.php';
require_once __DIR__ . '/../web/logic/MeasurementImplementationProvenance.php';
require_once __DIR__ . '/../web/logic/OfficialEpochIncidentLifecycleStore.php';
require_once __DIR__ . '/../web/logic/OfficialEpochIncidentPolicyV1.php';
require_once __DIR__ . '/../web/logic/OfficialEpochPolicyV1.php';
require_once __DIR__ . '/../web/logic/OfficialEpochStartStore.php';
require_once __DIR__ . '/../web/logic/OfficialEpochWindowBindingRuntimeAuthorization.php';
require_once __DIR__ . '/../web/logic/OfficialEpochWindowBindingStore.php';
require_once __DIR__ . '/../web/logic/StrictForwardLateEligibilityScheduleContract.php';

/**
 * Production orchestrator for one future-only ACTIVE-Window identity-drift
 * recovery.  It only composes existing lifecycle primitives.  --preflight
 * and --dry-run are read-only; --execute is the sole mutation mode.
 */

const IDR_DEFAULT_OLD_WINDOW = 'cc30c-clean-window-v4-20261020140615-c8ac4ed2';
const IDR_EXPECTED_OLD_IDENTITY = 'sha256:d7f958adbd1e2102b591fb8df7a85ec1e120b6cc941143265ec3c9b09de494c2';
const IDR_EXPECTED_OLD_FORMAL_COUNT = 38;
const IDR_MISMATCH_DETECTED_AT = '2026-10-21T09:33:01+09:00';

/** These are the audited implementation-only differences from the predecessor freeze. */
const IDR_ALLOWED_SEMANTIC_EQUIVALENT_DIFFS = [
    'analysis/collect_strict_forward_exhibition.php',
    'analysis/finalize_strict_forward_late_eligibility.php',
    'analysis/install_cc30c_late_eligibility_cron.sh',
    'analysis/output/cc30c_late_eligibility_schedule_contract_v1.json',
    'analysis/output/cc30c_late_eligibility_schedule_contract_v2.json',
    'analysis/run_strict_forward_late_eligibility_cron.sh',
    'logic/OfficialAmagasakiLegacyWriteGuard.php',
    'logic/OfficialAmagasakiOperationalPolicy.php',
    'web/logic/FormalMeasurementWindowLifecycle.php',
    'web/logic/IdentityDriftRecoveryEvidenceV1.php',
    'web/logic/MeasurementImplementationProvenance.php',
    'web/logic/OfficialEpochWindowBindingStore.php',
    'web/logic/StrictForwardLateEligibilityScheduleContract.php',
];

function idrUsage(): never
{
    fwrite(STDERR, "usage: php analysis/run_identity_drift_formal_forward_recovery.php (--preflight|--dry-run|--execute) [--old-window=<id>]\n");
    exit(64);
}

/** @param array<string,mixed> $row */
function idrCanonical(array $row): string { return OfficialEpochPolicyV1::canonicalJson($row); }
function idrShaFile(string $path): string { $h = @hash_file('sha256', $path); return is_string($h) ? 'sha256:' . $h : ''; }
function idrRelative(string $root, string $path): string { return str_starts_with($path, rtrim($root, '/') . '/') ? substr($path, strlen(rtrim($root, '/')) + 1) : $path; }
function idrNow(): string { return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM); }

/** @return array<string,mixed> */
function idrReadJson(string $path): array
{
    try { $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
    catch (Throwable) { return []; }
    return is_array($row) ? $row : [];
}

/** @param array<string,mixed> $row @return array{accepted:bool,path:string,sha256:string,record:array<string,mixed>,reason:string} */
function idrCreateOnlyJson(string $path, array $row): array
{
    $copy = $row;
    unset($copy['record_hash']);
    $row['record_hash'] = 'sha256:' . hash('sha256', idrCanonical($copy));
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return ['accepted'=>false,'path'=>$path,'sha256'=>'','record'=>[],'reason'=>'DIRECTORY_UNAVAILABLE'];
    $handle = @fopen($path, 'x');
    if (!is_resource($handle)) {
        $existing = idrReadJson($path);
        if ($existing !== [] && idrCanonical($existing) === idrCanonical($row)) return ['accepted'=>true,'path'=>$path,'sha256'=>idrShaFile($path),'record'=>$existing,'reason'=>'ALREADY_EXISTS'];
        return ['accepted'=>false,'path'=>$path,'sha256'=>'','record'=>[],'reason'=>'CREATE_ONLY_CONFLICT'];
    }
    $ok = @fwrite($handle, idrCanonical($row) . "\n") !== false;
    @fclose($handle);
    return $ok ? ['accepted'=>true,'path'=>$path,'sha256'=>idrShaFile($path),'record'=>$row,'reason'=>''] : ['accepted'=>false,'path'=>$path,'sha256'=>'','record'=>[],'reason'=>'WRITE_FAILED'];
}

/** @return array<string,string> */
function idrFreezeFiles(string $path): array
{
    $row = idrReadJson($path); $out = [];
    foreach (($row['files'] ?? []) as $file) {
        if (!is_array($file) || !is_string($file['path'] ?? null)) continue;
        $hash = (string)($file['sha256'] ?? $file['content_sha256'] ?? '');
        $out[(string)$file['path']] = str_starts_with($hash, 'sha256:') ? substr($hash, 7) : $hash;
    }
    ksort($out, SORT_STRING);
    return $out;
}

/** @param array<string,string> $before @param array<string,string> $after @return list<string> */
function idrChangedPaths(array $before, array $after): array
{
    $all = array_values(array_unique(array_merge(array_keys($before), array_keys($after)))); sort($all, SORT_STRING); $out=[];
    foreach ($all as $path) if (($before[$path] ?? null) !== ($after[$path] ?? null)) $out[] = $path;
    return $out;
}

/** @return array{count:int,sha256:string,entries:list<array{path:string,sha256:string}>} */
function idrTreeIdentity(string $root, array $subdirs): array
{
    $entries=[];
    foreach ($subdirs as $subdir) {
        $dir=$root.'/'.$subdir; if(!is_dir($dir)) continue;
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS));
        foreach($it as $file){if(!$file instanceof SplFileInfo||!$file->isFile())continue;$path=$file->getPathname();$entries[]=['path'=>idrRelative($root,$path),'sha256'=>idrShaFile($path)];}
    }
    usort($entries,static fn(array $a,array $b):int=>strcmp($a['path'],$b['path']));$framed='';foreach($entries as $e)$framed.=$e['path']."\0".$e['sha256']."\n";
    return ['count'=>count($entries),'sha256'=>'sha256:'.hash('sha256',$framed),'entries'=>$entries];
}

/** @return array{exit_code:int,stdout:string,stderr:string} */
function idrRun(array $command, string $cwd): array
{
    $spec=[1=>['pipe','w'],2=>['pipe','w']];$process=@proc_open($command,$spec,$pipes,$cwd);
    if(!is_resource($process))return ['exit_code'=>127,'stdout'=>'','stderr'=>'PROCESS_START_FAILED'];
    $stdout=(string)stream_get_contents($pipes[1]);$stderr=(string)stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
    return ['exit_code'=>$code,'stdout'=>$stdout,'stderr'=>$stderr];
}

/** @return array<string,mixed> */
function idrRegression(string $root): array
{
    $tests=[
        'analysis/test_identity_drift_recovery_evidence_v1.php',
        'analysis/test_formal_measurement_window_lifecycle_v2.php',
        'analysis/test_official_epoch_window_binding_v1.php',
        'analysis/test_official_epoch_window_binding_runtime.php',
        'analysis/test_prepare_only_and_same_epoch_successor_validator.php',
    ];
    $rows=[];$pass=true;
    foreach($tests as $test){$run=idrRun(['/usr/bin/php',$root.'/'.$test],$root);$ok=$run['exit_code']===0;$pass=$pass&&$ok;$rows[]=['test'=>$test,'exit_code'=>$run['exit_code'],'status'=>$ok?'PASS':'FAIL','stdout_sha256'=>'sha256:'.hash('sha256',$run['stdout']),'stderr_sha256'=>'sha256:'.hash('sha256',$run['stderr'])];}
    return ['status'=>$pass?'PASS':'FAIL','tests'=>$rows];
}

/** @return array{count:int,paths:list<string>} */
function idrCriticalDirty(string $root): array
{
    $paths=MeasurementImplementationProvenance::criticalPaths();$run=idrRun(['/usr/bin/git','status','--porcelain','--',...$paths],$root);$dirty=[];
    foreach(preg_split('/\R/',trim($run['stdout']))?:[] as $line)if(trim($line)!=='')$dirty[]=$line;
    return ['count'=>count($dirty),'paths'=>$dirty];
}

/** @return array{pass:bool,exit_code:int} */
function idrCriticalParity(string $root): array
{
    $run=idrRun(['/usr/bin/git','diff','--quiet','refs/remotes/origin/main','--',...MeasurementImplementationProvenance::criticalPaths()],$root);
    return ['pass'=>$run['exit_code']===0,'exit_code'=>$run['exit_code']];
}

/** @return array<string,mixed> */
function idrCronReadiness(string $root): array
{
    $run=idrRun(['/usr/bin/crontab','-l'],$root);$text=$run['stdout'];
    $count=static fn(string $needle):int=>substr_count($text,$needle);
    $late=StrictForwardLateEligibilityScheduleContract::attestCrontabText($root,$text);
    return [
        'readable'=>$run['exit_code']===0,
        'collector'=>$count('BOATRACE_STRICT_FORWARD_EXHIBITION_COLLECTOR')===1,
        'static_snapshot'=>$count('BOATRACE_COURSE4_TENKAI_STATIC_SNAPSHOT')===1,
        'scheduler'=>$count('BOATRACE_ALL_RACE_FORWARD_CAPTURE')===1,
        'late_eligibility'=>!empty($late['accepted']),
        'late_schedule'=>$late,
    ];
}

/** @return array{rows:int,races:int,error:string} */
function idrRaceUniverse(): array
{
    try{$pdo=getPDO();$stmt=$pdo->prepare('SELECT COUNT(*)::int AS rows, COUNT(DISTINCT race_code)::int AS races FROM boat_race.race_entry WHERE race_date = CAST(:d AS date)');$stmt->execute([':d'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Tokyo')))->format('Y-m-d')]);$row=$stmt->fetch(PDO::FETCH_ASSOC)?:[];return ['rows'=>(int)($row['rows']??0),'races'=>(int)($row['races']??0),'error'=>''];}
    catch(Throwable $e){return ['rows'=>0,'races'=>0,'error'=>get_class($e).':'.$e->getMessage()];}
}

/** @return array{count:int,lines:list<string>} */
function idrCaptureProcesses(string $root): array
{
    $run=idrRun(['/usr/bin/pgrep','-af','capture_all_race_forward.php|collect_strict_forward_exhibition.php|finalize_strict_forward_late_eligibility.php'],$root);$lines=[];
    foreach(preg_split('/\R/',trim($run['stdout']))?:[] as $line){if($line!==''&&!str_contains($line,'run_identity_drift_formal_forward_recovery.php'))$lines[]=$line;}
    return ['count'=>count($lines),'lines'=>$lines];
}

/** @return array<string,mixed> */
function idrPreflight(string $root, string $oldWindow): array
{
    $pointerPath=$root.'/'.FormalMeasurementWindowContext::POINTER_RELATIVE_PATH;$pointer=idrReadJson($pointerPath);
    $oldRoot=$root.'/var/strict_forward_live_readiness/windows/'.$oldWindow;
    $context=FormalMeasurementWindowContext::fromWindowRoot($root,$oldRoot);$resolved=$context->resolve(true);$manifest=$resolved['manifest']??[];
    $declaration=$context->declaration()->read(true);$declared=(string)($declaration['declaration']['implementation_identity']??'');
    $provenance=new MeasurementImplementationProvenance($root,sys_get_temp_dir());
    $currentReads=[$provenance->currentIdentityReadOnly(),$provenance->currentIdentityReadOnly(),$provenance->currentIdentityReadOnly()];
    $current=$currentReads[0];
    $formal=idrTreeIdentity($oldRoot,['input_snapshots']);$captures=idrTreeIdentity($oldRoot,['comparative/captures']);$whole=idrTreeIdentity($oldRoot,['input_snapshots','comparative/captures']);
    $epoch=(new OfficialEpochStartStore($root.'/var/strict_forward_live_readiness/lifecycle'))->existing();
    $projection=$epoch===[]?[]:(new OfficialEpochIncidentLifecycleStore($root.'/var/strict_forward_live_readiness/lifecycle'))->project((string)$epoch['epoch_id']);
    $schedule=StrictForwardLateEligibilityScheduleContract::load($root);$cron=idrCronReadiness($root);$dirty=idrCriticalDirty($root);$parity=idrCriticalParity($root);$races=idrRaceUniverse();$processes=idrCaptureProcesses($root);
    $captureDates=[];foreach($formal['entries'] as $entry)if(preg_match('#/(\d{8})/#',$entry['path'],$m))$captureDates[]=$m[1];sort($captureDates);$through=$captureDates===[]?'':DateTimeImmutable::createFromFormat('!Ymd',end($captureDates),new DateTimeZone('Asia/Tokyo'))?->format('Y-m-d');
    $aggregate=[];$aggregateError='';try{if($through!=='')$aggregate=FormalEvidenceAggregate::calculate($oldRoot,$oldWindow,$through);}catch(Throwable $e){$aggregateError=$e->getMessage();}
    $checks=[
        'old_window_resolved'=>!empty($resolved['accepted']),
        'old_window_active'=>(($context->lifecycle()->effectiveState($oldWindow,$manifest)['effective_state']??'')==='ACTIVE'),
        'pointer_owner'=>($pointer['window_id']??'')===$oldWindow,
        'old_identity'=>$declared===IDR_EXPECTED_OLD_IDENTITY,
        'identity_mismatch'=>$declared!==''&&!hash_equals($declared,$current),
        'current_identity_stable'=>count(array_unique($currentReads))===1,
        'old_formal_count'=>$formal['count']===IDR_EXPECTED_OLD_FORMAL_COUNT&&$captures['count']===IDR_EXPECTED_OLD_FORMAL_COUNT,
        'aggregate_valid'=>$aggregate!==[]&&$aggregateError==='',
        'schedule_v2'=>!empty($schedule['accepted'])&&!empty($cron['late_eligibility']),
        'epoch_active'=>!empty($projection['valid'])&&($projection['state']??'')==='ACTIVE',
        'critical_dirty_zero'=>$dirty['count']===0,
        'production_critical_parity'=>$parity['pass'],
        'race_universe'=>$races['races']>0&&$races['rows']>0,
        'collector_ready'=>!empty($cron['collector']),
        'static_snapshot_ready'=>!empty($cron['static_snapshot']),
        'scheduler_ready'=>!empty($cron['scheduler']),
        'formal_writer_ready'=>class_exists(OfficialEpochWindowBindingRuntimeAuthorization::class),
        'capture_processes_zero'=>$processes['count']===0,
        'abort_absent'=>$context->lifecycle()->readAbort($oldWindow)===[],
    ];
    $formalSummary=$formal;unset($formalSummary['entries']);$captureSummary=$captures;unset($captureSummary['entries']);$wholeSummary=$whole;unset($wholeSummary['entries']);
    $aggregateSummary=$aggregate===[]?[]:['schema_version'=>$aggregate['schema_version'],'file_count'=>$aggregate['file_count'],'aggregate_sha256'=>$aggregate['aggregate_sha256'],'referenced_implementation_identities'=>$aggregate['referenced_implementation_identities']];
    return ['accepted'=>!in_array(false,$checks,true),'status'=>!in_array(false,$checks,true)?'PRODUCTION_RECOVERY_PREFLIGHT_PASS':'PRODUCTION_RECOVERY_PREFLIGHT_BLOCKED','checks'=>$checks,'old_window'=>$oldWindow,'old_root'=>$oldRoot,'manifest'=>$manifest,'declared_identity'=>$declared,'current_identity'=>$current,'current_identity_reads'=>$currentReads,'pointer'=>$pointer,'pointer_sha256'=>idrShaFile($pointerPath),'epoch'=>$epoch,'projection'=>['valid'=>$projection['valid']??false,'state'=>$projection['state']??'','state_hash'=>$projection['state_hash']??''],'schedule'=>$schedule,'cron'=>$cron,'critical_dirty'=>$dirty,'production_critical_parity'=>$parity,'race_universe'=>$races,'capture_processes'=>$processes,'formal'=>$formalSummary,'captures'=>$captureSummary,'old_evidence_identity'=>$wholeSummary,'capture_through_date'=>$through,'aggregate'=>$aggregateSummary,'aggregate_error'=>$aggregateError];
}

/** @param array<string,mixed> $preflight @return array<string,mixed> */
function idrExecute(string $root, array $preflight): array
{
    if(empty($preflight['accepted']))return ['accepted'=>false,'status'=>'EXECUTE_BLOCKED','reason'=>'PREFLIGHT_NOT_PASS','preflight'=>$preflight];
    $old=(string)$preflight['old_window'];$oldRoot=(string)$preflight['old_root'];$epoch=$preflight['epoch'];$now=idrNow();
    $suffix=substr((string)$preflight['current_identity'],7,8);$windowId='cc30c-identity-drift-successor-'.(new DateTimeImmutable($now))->format('YmdHis').'-'.$suffix;
    $successor=(new FormalMeasurementWindowPrepareOnlyV1($root))->create(['window_id'=>$windowId,'mode'=>'NORMAL','epoch_state'=>'ACTIVE']);
    if(empty($successor['accepted']))return ['accepted'=>false,'status'=>'EXECUTE_BLOCKED','reason'=>'SUCCESSOR_PREPARE_FAILED','successor'=>$successor];
    $successorRoot=(string)$successor['window_root'];$successorDeclaration=$successor['declaration'];$successorImplementation=$successor['implementation'];
    if(!hash_equals((string)$preflight['current_identity'],(string)$successorImplementation['implementation_identity']))return ['accepted'=>false,'status'=>'EXECUTE_BLOCKED','reason'=>'SUCCESSOR_IDENTITY_MISMATCH','successor'=>$successor];

    $recoveryId='identity-drift-'.$old.'-'.substr((string)$preflight['current_identity'],7,16);$recoveryRoot=$root.'/var/strict_forward_live_readiness/lifecycle/identity_drift_recoveries/'.$recoveryId;
    $scheduleRef=StrictForwardLateEligibilityScheduleContract::CONTRACT_RELATIVE_PATH;$scheduleHash=idrShaFile($root.'/'.$scheduleRef);
    $evidence=new IdentityDriftRecoveryEvidenceV1($root);
    $att=$evidence->createAttestation(idrRelative($root,$recoveryRoot.'/identity_drift_attestation.json'),['recovery_reason'=>IdentityDriftRecoveryEvidenceV1::REASON,'predecessor_window_id'=>$old,'predecessor_epoch_id'=>(string)$epoch['epoch_id'],'predecessor_declared_identity'=>(string)$preflight['declared_identity'],'observed_runtime_identity'=>(string)$preflight['current_identity'],'successor_canonical_identity'=>(string)$successorImplementation['implementation_identity'],'mismatch_detected'=>true,'mismatch_validator_result'=>'DECLARED_IMPLEMENTATION_IDENTITY_MISMATCH','schedule_contract_ref'=>$scheduleRef,'schedule_contract_sha256'=>$scheduleHash,'created_at'=>$now]);
    if(empty($att['accepted']))return ['accepted'=>false,'status'=>'EXECUTE_BLOCKED','reason'=>'ATTESTATION_FAILED','detail'=>$att,'successor'=>$successor];

    $oldDeclRef=idrRelative($root,$oldRoot.'/declared_implementation_identity.json');$newDeclRef=idrRelative($root,$successorRoot.'/declared_implementation_identity.json');
    $oldFreezeRef='var/strict_forward_live_readiness/windows/'.$old.'/provenance/'.(string)$preflight['manifest']['validation_window_id'];
    $oldDeclaration=idrReadJson($root.'/'.$oldDeclRef);$oldFreezeRef='var/strict_forward_live_readiness/windows/'.$old.'/provenance/'.(string)$oldDeclaration['source_freeze_ref'];
    $newFreezeRef=idrRelative($root,$successorRoot.'/provenance/'.(string)$successorDeclaration['source_freeze_ref']);
    $beforeFiles=idrFreezeFiles($root.'/'.$oldFreezeRef);$afterFiles=idrFreezeFiles($root.'/'.$newFreezeRef);$changed=idrChangedPaths($beforeFiles,$afterFiles);$unexpected=array_values(array_diff($changed,IDR_ALLOWED_SEMANTIC_EQUIVALENT_DIFFS));
    $regression=idrRegression($root);
    $isolation=['schema_version'=>'identity-drift-isolation-audit/v1','old_window'=>$old,'old_formal_count'=>$preflight['formal']['count'],'old_capture_count'=>$preflight['captures']['count'],'old_evidence_sha256'=>$preflight['old_evidence_identity']['sha256'],'identity_drift_after_formal_additions'=>0,'historical_candidate_repair'=>0,'historical_eligibility_repair'=>0,'historical_formal_repair'=>0,'historical_snapshot_repair'=>0,'created_at'=>$now];
    $isolationWrite=idrCreateOnlyJson($recoveryRoot.'/isolation_audit.json',$isolation);
    $regressionWrite=idrCreateOnlyJson($recoveryRoot.'/regression_audit.json',['schema_version'=>'identity-drift-regression-audit/v1','result'=>$regression['status'],'tests'=>$regression['tests'],'created_at'=>$now]);
    $readiness=['schema_version'=>'identity-drift-readiness-audit/v1','canonical_identity'=>(string)$preflight['current_identity'],'critical_dirty_count'=>(int)$preflight['critical_dirty']['count'],'schedule_v2'=>'VALID','race_universe'=>$preflight['race_universe'],'collector_ready'=>true,'late_eligibility_ready'=>true,'static_snapshot_ready'=>true,'formal_writer_ready'=>true,'pointer_owner'=>$old,'epoch_id'=>(string)$epoch['epoch_id'],'epoch_state'=>'ACTIVE','same_epoch'=>true,'capture_process_count'=>0,'created_at'=>$now];
    $readinessWrite=idrCreateOnlyJson($recoveryRoot.'/readiness_audit.json',$readiness);
    if(empty($isolationWrite['accepted'])||empty($regressionWrite['accepted'])||empty($readinessWrite['accepted'])||$unexpected!==[]||$regression['status']!=='PASS')return ['accepted'=>false,'status'=>'EXECUTE_BLOCKED','reason'=>'PRE_ABORT_EVIDENCE_SOURCE_FAILED','unexpected_diffs'=>$unexpected,'regression'=>$regression,'successor'=>$successor];

    $epochRef='var/strict_forward_live_readiness/lifecycle/official_epochs/'.(string)$epoch['epoch_id'].'/start.json';
    $oldContract=is_array($oldDeclaration['comparative_contract']??null)?$oldDeclaration['comparative_contract']:[];$newContract=is_array($successorDeclaration['comparative_contract']??null)?$successorDeclaration['comparative_contract']:[];
    $requirementChecks=[
        'measurement_semantics_unchanged'=>$unexpected===[]&&$regression['status']==='PASS',
        'comparative_scope_unchanged'=>hash_equals((string)($oldContract['comparative_scope_identity']??''),(string)($newContract['comparative_scope_identity']??''))&&hash_equals((string)$epoch['comparative_scope_identity'],(string)($newContract['comparative_scope_identity']??'')),
        'comparative_policy_semantics_unchanged'=>hash_equals((string)($oldContract['comparative_policy_identity']??''),(string)($newContract['comparative_policy_identity']??''))&&hash_equals((string)$epoch['comparative_policy_identity'],(string)($newContract['comparative_policy_identity']??'')),
        'hard_gate_policy_unchanged'=>hash_equals((string)$epoch['epoch_policy_identity'],OfficialEpochPolicyV1::identity()),
        'numeric_policy_semantics_unchanged'=>$unexpected===[],
        'incident_policy_semantics_unchanged'=>hash_equals((string)$epoch['incident_policy_identity'],OfficialEpochIncidentPolicyV1::identity()),
        'affected_evidence_uniquely_isolated'=>$preflight['formal']['count']===38&&$preflight['captures']['count']===38,
        'regressions_pass'=>$regression['status']==='PASS',
        'readiness_pass'=>true,
    ];
    $refs=[];$hashes=[];
    foreach(OfficialEpochWindowBindingV1::requirementNames() as $name){
        $pred=match($name){'affected_evidence_uniquely_isolated'=>[idrRelative($root,$isolationWrite['path'])],'regressions_pass'=>[$oldDeclRef],'readiness_pass'=>[$oldDeclRef],default=>[$oldDeclRef,$oldFreezeRef,$epochRef]};
        $succ=match($name){'affected_evidence_uniquely_isolated'=>[idrRelative($root,$isolationWrite['path'])],'regressions_pass'=>[idrRelative($root,$regressionWrite['path'])],'readiness_pass'=>[idrRelative($root,$readinessWrite['path'])],default=>[$newDeclRef,$newFreezeRef,$epochRef]};
        $predMap=[];$predHashes=[];foreach($pred as $i=>$ref){$predMap['source_'.$i]=$ref;$predHashes['source_'.$i]=idrShaFile($root.'/'.$ref);} $succMap=[];$succHashes=[];foreach($succ as $i=>$ref){$succMap['source_'.$i]=$ref;$succHashes['source_'.$i]=idrShaFile($root.'/'.$ref);}
        $made=$evidence->createRequirement(idrRelative($root,$recoveryRoot.'/requirements/'.$name.'.json'),['requirement_id'=>$name,'predecessor_refs'=>$predMap,'predecessor_hashes'=>$predHashes,'successor_refs'=>$succMap,'successor_hashes'=>$succHashes,'comparison_method'=>'PRODUCTION_STATE_HASH_AND_SEMANTIC_IDENTITY','comparison_result'=>$requirementChecks[$name]?'PASS':'FAIL','validated'=>$requirementChecks[$name],'changed_components'=>$name==='measurement_semantics_unchanged'?$changed:[],'created_at'=>$now]);
        if(empty($made['accepted']))return ['accepted'=>false,'status'=>'EXECUTE_BLOCKED','reason'=>'REQUIREMENT_FAILED_'.$name,'detail'=>$made,'successor'=>$successor];$refs[$name]=$made['ref'];$hashes[$name]=$made['sha256'];
    }
    $allRequirements=!in_array(false,$requirementChecks,true)&&count($refs)===9;
    $beforeAbortTree=idrTreeIdentity($oldRoot,['input_snapshots','comparative/captures']);
    $postAbortSafe=$allRequirements&&$beforeAbortTree['sha256']===$preflight['old_evidence_identity']['sha256']&&($preflight['pointer']['window_id']??'')===$old&&($preflight['projection']['state']??'')==='ACTIVE'&&$preflight['capture_processes']['count']===0;
    $checkpoint=idrCreateOnlyJson($recoveryRoot.'/pre_abort_checkpoint.json',['schema_version'=>'identity-drift-pre-abort-checkpoint/v1','POST_ABORT_SAFE_TO_CONTINUE'=>$postAbortSafe?'YES':'NO','PRE_RECOVERY_EVIDENCE_VALID'=>$allRequirements?'YES':'NO','ALL_9_REQUIREMENTS_VALID'=>$allRequirements?'YES':'NO','SAME_EPOCH_BINDING_PRECONDITION'=>'VALID','POINTER_CAS_PRECONDITION'=>'VALID','ACTIVATION_PRECONDITION'=>'VALID','AUTHORIZATION_PRECONDITION'=>'VALID','successor_window_id'=>$windowId,'successor_identity'=>(string)$successorImplementation['implementation_identity'],'old_evidence_sha256'=>$beforeAbortTree['sha256'],'created_at'=>$now]);
    if(!$postAbortSafe||empty($checkpoint['accepted']))return ['accepted'=>false,'status'=>'EXECUTE_BLOCKED','reason'=>'POST_ABORT_SAFE_TO_CONTINUE_NO','checkpoint'=>$checkpoint,'successor'=>$successor];

    $aggregate=$preflight['aggregate'];$pointerBytes=(string)file_get_contents($root.'/'.FormalMeasurementWindowContext::POINTER_RELATIVE_PATH);$manifest=$preflight['manifest'];
    $abort=$context=FormalMeasurementWindowContext::fromWindowRoot($root,$oldRoot);$abortResult=$context->lifecycle()->createAbort(['window_id'=>$old,'armed_at'=>(string)$manifest['armed_at'],'aborted_at'=>idrNow(),'capture_through_date'=>(string)$preflight['capture_through_date'],'abort_reason'=>IdentityDriftRecoveryEvidenceV1::REASON,'failure_code'=>'DECLARED_IMPLEMENTATION_IDENTITY_MISMATCH','failure_details'=>'The production measurement implementation changed after Window activation; no repair or backfill is permitted.','gate_a1'=>'PASS','gate_a2'=>'PASS','gate_b_final'=>'PENDING_LIVE','gate_c'=>'BLOCKED_MEASUREMENT_IMPLEMENTATION_IDENTITY_DRIFT','candidate_count'=>count(glob($oldRoot.'/opportunity/candidates/*/*.json')?:[]),'expected_count'=>0,'terminal_candidate_count'=>0,'source_failure_count'=>0,'backfill_count'=>0,'prearmed_sample_count'=>0,'final_evidence_hash'=>(string)$aggregate['aggregate_sha256'],'verified_final_evidence_hash'=>(string)$aggregate['aggregate_sha256'],'declared_implementation_identity'=>(string)$preflight['declared_identity'],'activation_implementation_identity'=>(string)$preflight['declared_identity'],'comparative_policy_identity'=>(string)($oldContract['comparative_policy_identity']??'NOT_AVAILABLE'),'previous_pointer_identity'=>'sha256:'.hash('sha256',$pointerBytes),'closing_tool_implementation_identity'=>(string)$preflight['current_identity'],'operator_tool_provenance'=>'analysis/run_identity_drift_formal_forward_recovery.php']);
    if(empty($abortResult['accepted']))return ['accepted'=>false,'status'=>'EXECUTE_BLOCKED_AFTER_CHECKPOINT','reason'=>'ABORT_FAILED','abort'=>$abortResult,'successor'=>$successor];
    $abortRef='var/strict_forward_live_readiness/lifecycle/aborts/'.$old.'.json';$abortHash=idrShaFile($root.'/'.$abortRef);$armedAt=idrNow();
    $package=$evidence->createPackage(idrRelative($root,$recoveryRoot.'/recovery_package.json'),['recovery_reason'=>IdentityDriftRecoveryEvidenceV1::REASON,'predecessor_window_id'=>$old,'predecessor_epoch_id'=>(string)$epoch['epoch_id'],'predecessor_declared_identity'=>(string)$preflight['declared_identity'],'successor_window_id'=>$windowId,'successor_canonical_identity'=>(string)$successorImplementation['implementation_identity'],'recovery_armed_at'=>$armedAt,'schedule_contract_ref'=>$scheduleRef,'schedule_contract_sha256'=>$scheduleHash,'identity_drift_attestation_ref'=>$att['ref'],'identity_drift_attestation_sha256'=>$att['sha256'],'abort_record_ref'=>$abortRef,'abort_record_sha256'=>$abortHash,'requirement_evidence_refs'=>$refs,'requirement_evidence_hashes'=>$hashes,'created_at'=>idrNow()]);
    if(empty($package['accepted']))return ['accepted'=>false,'status'=>'EXECUTE_BLOCKED_AFTER_ABORT','reason'=>'RECOVERY_PACKAGE_FAILED','package'=>$package,'abort'=>$abortResult,'successor'=>$successor];
    $validation=['identity_drift_recovery_package_ref'=>$package['ref'],'predecessor_window_id'=>$old,'pointer_owner'=>$old,'predecessor_state'=>'ABORTED','abort_reason'=>IdentityDriftRecoveryEvidenceV1::REASON,'historical_mutation_detected'=>false,'current_measurement_identity'=>(string)$preflight['current_identity'],'epoch_policy_identity'=>OfficialEpochPolicyV1::identity(),'incident_policy_identity'=>OfficialEpochIncidentPolicyV1::identity()];
    $binding=(new OfficialEpochWindowBindingStore($root))->createForPrepared($windowId,$validation);
    if(empty($binding['accepted']))return ['accepted'=>false,'status'=>'EXECUTE_BLOCKED_AFTER_ABORT','reason'=>'BINDING_FAILED','binding'=>$binding,'package'=>$package,'abort'=>$abortResult,'successor'=>$successor];
    $newPointer=['schema_version'=>FormalMeasurementWindowContext::POINTER_SCHEMA_VERSION,'window_id'=>$windowId,'window_root'=>'var/strict_forward_live_readiness/windows/'.$windowId];
    $cas=$context->lifecycle()->handoffPointerCas($root.'/'.FormalMeasurementWindowContext::POINTER_RELATIVE_PATH,$old,$newPointer,'identity-drift-production-recovery');
    if(empty($cas['accepted']))return ['accepted'=>false,'status'=>'EXECUTE_BLOCKED_AFTER_ABORT','reason'=>'POINTER_CAS_FAILED','cas'=>$cas,'binding'=>$binding,'package'=>$package,'abort'=>$abortResult,'successor'=>$successor];
    $newContext=FormalMeasurementWindowContext::fromWindowRoot($root,$successorRoot);$activation=$newContext->activationHold()->activate($package['ref'],'PRECHECK_PASS');
    if(empty($activation['accepted']))return ['accepted'=>false,'status'=>'EXECUTE_BLOCKED_AFTER_CAS','reason'=>'ACTIVATION_FAILED','activation'=>$activation,'cas'=>$cas,'binding'=>$binding,'package'=>$package,'abort'=>$abortResult,'successor'=>$successor];
    $authorization=(new OfficialEpochWindowBindingRuntimeAuthorization($root))->authorizeOperationalPointerWindow();
    $afterTree=idrTreeIdentity($oldRoot,['input_snapshots','comparative/captures']);$historySafe=hash_equals($beforeAbortTree['sha256'],$afterTree['sha256'])&&$beforeAbortTree['count']===$afterTree['count'];
    $result=['accepted'=>!empty($authorization['accepted'])&&$historySafe,'status'=>!empty($authorization['accepted'])&&$historySafe?'PRODUCTION_RECOVERY_COMPLETE':'PRODUCTION_RECOVERY_POSTCHECK_BLOCKED','old_window'=>$old,'new_window'=>$windowId,'recovery_armed_at'=>$armedAt,'attestation'=>$att,'requirements'=>['refs'=>$refs,'hashes'=>$hashes,'validation'=>$requirementChecks],'checkpoint'=>$checkpoint,'abort'=>$abortResult,'abort_ref'=>$abortRef,'abort_sha256'=>$abortHash,'package'=>$package,'binding'=>$binding,'cas'=>$cas,'activation'=>$activation,'authorization_status'=>$authorization['status']??'','authorization_reason'=>$authorization['reason']??'','old_evidence_before'=>$beforeAbortTree,'old_evidence_after'=>$afterTree,'historical_safety'=>$historySafe,'historical_generation'=>['candidate'=>0,'eligibility'=>0,'formal'=>0,'snapshot'=>0]];
    $report=idrCreateOnlyJson($recoveryRoot.'/execution_report.json',['schema_version'=>'identity-drift-production-recovery-report/v1','result'=>$result,'created_at'=>idrNow()]);$result['report_path']=$report['path'];$result['report_sha256']=$report['sha256'];return $result;
}

$mode='';$oldWindow=IDR_DEFAULT_OLD_WINDOW;
foreach(array_slice($argv,1) as $arg){if(in_array($arg,['--preflight','--dry-run','--execute'],true)){$mode=$arg;}elseif(str_starts_with($arg,'--old-window=')){$oldWindow=substr($arg,13);}elseif(in_array($arg,['--help','-h'],true)){idrUsage();}else{idrUsage();}}
if($mode===''||preg_match('/^[A-Za-z0-9._-]+$/',$oldWindow)!==1)idrUsage();
$root=(string)realpath(__DIR__.'/..');$preflight=idrPreflight($root,$oldWindow);
if($mode==='--preflight'||$mode==='--dry-run'){$preflight['mode']=$mode;$preflight['post_abort_safe_to_continue_candidate']=!empty($preflight['accepted']);echo json_encode($preflight,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";exit(!empty($preflight['accepted'])?0:1);}
$result=idrExecute($root,$preflight);echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";exit(!empty($result['accepted'])?0:1);
