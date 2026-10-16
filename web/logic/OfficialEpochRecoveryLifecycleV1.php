<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialEpochPolicyV1.php';
require_once __DIR__ . '/OfficialEpochIncidentPolicyV1.php';
require_once __DIR__ . '/OfficialEpochIncidentLifecycleStore.php';
require_once __DIR__ . '/OfficialEpochWindowBindingV1.php';
require_once __DIR__ . '/OfficialEpochSameEpochSuccessorValidator.php';
require_once __DIR__ . '/FormalMeasurementWindowContext.php';

/**
 * External-only recovery records for a suspended Official Epoch.
 *
 * These records never make a Window writable.  In particular, a recovery
 * successor attestation is deliberately not a normal Epoch Window binding:
 * the latter is only valid while the Epoch is ACTIVE and is created later.
 */
final class OfficialEpochRecoveryLifecycleV1
{
    public const SUPERSESSION_SCHEMA = 'formal-measurement-window-prepared-supersession/v1';
    public const ATTESTATION_SCHEMA = 'official-epoch-recovery-successor-attestation/v1';
    public const CG_APPROVAL_SCHEMA = 'official-epoch-cg-recovery-approval/v1';
    public const RESOLUTION_SCHEMA = 'official-epoch-incident-resolution/v2';

    public function __construct(private readonly string $repoRoot, private readonly string $lifecycleRoot) {}

    public function supersessionPath(string $windowId): string { return $this->lifecycleRoot . '/prepared_supersessions/' . rawurlencode($windowId) . '.json'; }
    public function attestationPath(string $windowId): string { return $this->lifecycleRoot . '/official_epoch_recovery_successor_attestations/' . rawurlencode($windowId) . '.json'; }
    public function approvalPath(string $key): string { return $this->lifecycleRoot . '/official_epoch_recovery_approvals/' . preg_replace('/^sha256:/', '', $key) . '.json'; }

    /** @return array<string,mixed> */
    public function supersessionForWindow(string $windowId): array { return $this->readValid($this->supersessionPath($windowId), self::SUPERSESSION_SCHEMA); }
    /** @return array<string,mixed> */
    public function attestationForWindow(string $windowId): array { return $this->readValid($this->attestationPath($windowId), self::ATTESTATION_SCHEMA); }
    /** @return array<string,mixed> */
    public function approvalForKey(string $key): array { return $this->readValid($this->approvalPath($key), self::CG_APPROVAL_SCHEMA); }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function preflightSupersession(string $windowId, array $input): array
    {
        $base = $this->preparedContext($windowId, $input);
        if (empty($base['accepted'])) return $base;
        if (($base['declared_identity'] ?? '') === ($input['current_measurement_identity'] ?? '')) {
            return $this->blocked('PREPARED_SUPERSESSION_IDENTITY_NOT_STALE');
        }
        if (($input['epoch_state'] ?? '') !== 'SUSPENDED') return $this->blocked('PREPARED_SUPERSESSION_EPOCH_NOT_SUSPENDED');
        if (!$this->validHash((string)($input['incident_hash'] ?? '')) || !$this->validHash((string)($input['suspension_hash'] ?? ''))) return $this->blocked('PREPARED_SUPERSESSION_INCIDENT_OR_SUSPENSION_INVALID');
        $stable = ['schema_version' => self::SUPERSESSION_SCHEMA, 'window_id' => $windowId, 'reason' => (string)($input['reason'] ?? ''), 'declared_measurement_identity' => (string)$base['declared_identity'], 'recovery_measurement_identity' => (string)($input['current_measurement_identity'] ?? ''), 'incident_hash' => (string)$input['incident_hash'], 'suspension_hash' => (string)$input['suspension_hash']];
        $record = $stable + [
            'supersession_key' => $this->hash($stable), 'window_manifest_ref' => (string)$base['manifest_ref'], 'window_manifest_hash' => (string)$base['manifest_hash'],
            'window_declaration_ref' => (string)$base['declaration_ref'], 'window_declaration_hash' => (string)$base['declaration_hash'],
            'formal_evidence_count' => 0, 'activation_present' => false, 'epoch_id' => (string)($input['epoch_id'] ?? ''),
            'incident_ref' => (string)($input['incident_ref'] ?? ''), 'incident_hash' => (string)$input['incident_hash'],
            'suspension_ref' => (string)($input['suspension_ref'] ?? ''), 'suspension_hash' => (string)$input['suspension_hash'],
            'reason' => (string)($input['reason'] ?? 'MEASUREMENT_IMPLEMENTATION_IDENTITY_SUPERSEDED_DURING_INCIDENT_RECOVERY'),
            'created_at' => (string)($input['created_at'] ?? ''),
        ];
        $record['record_hash'] = $this->hash($record);
        return ['accepted' => true, 'status' => 'PREPARED_SUPERSESSION_ELIGIBLE', 'reason' => '', 'record' => $record];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createSupersession(string $windowId, array $input): array
    {
        return $this->withLock(function () use ($windowId, $input): array {
            $pre = $this->preflightSupersession($windowId, $input);
            if (empty($pre['accepted'])) return $pre;
            return $this->createOnly($this->supersessionPath($windowId), $pre['record'], 'PREPARED_SUPERSESSION');
        });
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function preflightAttestation(string $windowId, array $input): array
    {
        $base = $this->preparedContext($windowId, $input);
        if (empty($base['accepted'])) return $base;
        foreach (['epoch_id','epoch_start_record_hash','incident_hash','suspension_hash','current_measurement_identity','epoch_policy_identity','incident_policy_identity'] as $field) if (trim((string)($input[$field] ?? '')) === '') return $this->blocked('RECOVERY_ATTESTATION_FIELD_MISSING_' . strtoupper($field));
        if (($input['epoch_state'] ?? '') !== 'SUSPENDED') return $this->blocked('RECOVERY_ATTESTATION_EPOCH_NOT_SUSPENDED');
        if (!hash_equals((string)$base['declared_identity'], (string)$input['current_measurement_identity'])) return $this->blocked('RECOVERY_ATTESTATION_DECLARED_IDENTITY_MISMATCH');
        if (!hash_equals(OfficialEpochPolicyV1::identity(), (string)$input['epoch_policy_identity']) || !hash_equals(OfficialEpochIncidentPolicyV1::identity(), (string)$input['incident_policy_identity'])) return $this->blocked('RECOVERY_ATTESTATION_POLICY_IDENTITY_MISMATCH');
        $epoch = (new OfficialEpochStartStore($this->lifecycleRoot))->existing();
        if ($epoch === [] || !hash_equals((string)($epoch['epoch_id'] ?? ''), (string)$input['epoch_id']) || !hash_equals((string)($epoch['record_hash'] ?? ''), (string)$input['epoch_start_record_hash'])) return $this->blocked('RECOVERY_ATTESTATION_EPOCH_PROVENANCE_INVALID');
        $package = is_array($input['validation_package'] ?? null) ? $input['validation_package'] : [];
        $validationInput = is_array($input['validation_input'] ?? null) ? $input['validation_input'] : [];
        $evaluation = (new OfficialEpochSameEpochSuccessorValidator($this->repoRoot))->validatePackage($windowId, $package, $epoch, $validationInput);
        if (empty($evaluation['accepted'])) return $this->blocked('RECOVERY_ATTESTATION_VALIDATION_PACKAGE_INVALID:' . (string)$evaluation['reason']);
        $requirements = $evaluation['requirements'];
        foreach (OfficialEpochWindowBindingV1::requirementNames() as $name) if (($requirements[$name] ?? '') !== 'PASS') return $this->blocked('RECOVERY_ATTESTATION_REQUIREMENT_NOT_PASS:' . $name);
        $refs = is_array($evaluation['package']['validation_evidence_refs'] ?? null) ? $evaluation['package']['validation_evidence_refs'] : [];
        $hashes = is_array($evaluation['package']['validation_evidence_hashes'] ?? null) ? $evaluation['package']['validation_evidence_hashes'] : [];
        $stable = ['schema_version' => self::ATTESTATION_SCHEMA, 'window_id' => $windowId, 'declaration_hash' => (string)$base['declaration_hash'], 'declared_identity' => (string)$base['declared_identity'], 'epoch_id' => (string)$input['epoch_id'], 'epoch_start_record_hash' => (string)$input['epoch_start_record_hash'], 'incident_hash' => (string)$input['incident_hash'], 'suspension_hash' => (string)$input['suspension_hash'], 'requirements' => $requirements, 'evidence_hashes' => $hashes];
        $record = $stable + [
            'attestation_key' => $this->hash($stable), 'window_manifest_ref' => (string)$base['manifest_ref'], 'window_manifest_hash' => (string)$base['manifest_hash'],
            'window_declaration_ref' => (string)$base['declaration_ref'], 'window_declaration_hash' => (string)$base['declaration_hash'],
            'declared_measurement_identity' => (string)$base['declared_identity'], 'measurement_manifest_ref' => (string)$base['measurement_manifest_ref'], 'measurement_manifest_hash' => (string)$base['measurement_manifest_hash'],
            'measurement_source_freeze_ref' => (string)$base['measurement_source_freeze_ref'], 'measurement_source_freeze_hash' => (string)$base['measurement_source_freeze_hash'],
            'prospective_boundary_contract' => OfficialEpochWindowBindingV1::PROSPECTIVE_BOUNDARY_CONTRACT, 'epoch_start_record_ref' => (string)($input['epoch_start_record_ref'] ?? ''),
            'incident_ref' => (string)($input['incident_ref'] ?? ''), 'incident_hash' => (string)$input['incident_hash'], 'suspension_ref' => (string)($input['suspension_ref'] ?? ''), 'suspension_hash' => (string)$input['suspension_hash'],
            'epoch_policy_identity' => (string)$input['epoch_policy_identity'], 'incident_policy_identity' => (string)$input['incident_policy_identity'],
            'successor_semantics_classification' => OfficialEpochWindowBindingV1::SEMANTICS_EQUIVALENT, 'same_epoch_successor_requirements' => OfficialEpochWindowBindingV1::requirementNames(), 'same_epoch_successor_validation_result' => $requirements,
            'validation_evidence_refs' => $refs, 'validation_evidence_hashes' => $hashes, 'root_cause_fix_evidence_refs' => $input['root_cause_fix_evidence_refs'] ?? [], 'regression_evidence_refs' => $input['regression_evidence_refs'] ?? [], 'canonical_source_window' => $input['canonical_source_window'] ?? [], 'created_at' => (string)($input['created_at'] ?? ''),
            'same_epoch_successor_validation_package_hash' => (string)($evaluation['package']['validation_package_hash'] ?? ''),
        ];
        $record['record_hash'] = $this->hash($record);
        return ['accepted' => true, 'status' => 'RECOVERY_SUCCESSOR_ATTESTATION_ELIGIBLE', 'reason' => '', 'record' => $record];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createAttestation(string $windowId, array $input): array { return $this->withLock(function () use ($windowId, $input): array { $pre=$this->preflightAttestation($windowId,$input); return empty($pre['accepted']) ? $pre : $this->createOnly($this->attestationPath($windowId), $pre['record'], 'RECOVERY_SUCCESSOR_ATTESTATION'); }); }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function resolutionRecord(array $input): array
    {
        $key=['schema_version'=>self::RESOLUTION_SCHEMA,'epoch_id'=>(string)($input['epoch_id']??''),'incident_hash'=>(string)($input['incident_hash']??''),'fix_implementation_identity'=>(string)($input['fix_implementation_identity']??'')];
        $record = $key + [
            'resolution_id'=>'official-epoch-resolution-v2-'.substr(hash('sha256',OfficialEpochPolicyV1::canonicalJson($key)),0,16), 'resolution_key'=>$this->hash($key), 'incident_id'=>(string)($input['incident_id']??''), 'resolved_at'=>(string)($input['resolved_at']??''),
            'root_cause'=>(string)($input['root_cause']??''), 'root_cause_fixed'=>!empty($input['root_cause_fixed']), 'affected_scope_final'=>is_array($input['affected_scope_final']??null)?$input['affected_scope_final']:[],
            'artifact_disposition'=>(string)($input['artifact_disposition']??''), 'canonical_capture_evidence_status'=>(string)($input['canonical_capture_evidence_status']??''), 'closure_status'=>(string)($input['closure_status']??''), 'epoch_source_status'=>(string)($input['epoch_source_status']??''),
            'regression_result'=>(string)($input['regression_result']??''), 'scheduler_held'=>!empty($input['scheduler_held']), 'capture_process_count'=>(int)($input['capture_process_count']??-1),
            'requires_new_window'=>!empty($input['requires_new_window']), 'requires_new_epoch'=>!empty($input['requires_new_epoch']), 'requires_cg_reapproval'=>!empty($input['requires_cg_reapproval']),
            'resolution_evidence_ref'=>(string)($input['resolution_evidence_ref']??''), 'resolution_evidence_hash'=>(string)($input['resolution_evidence_hash']??''), 'recovery_successor_attestation_ref'=>(string)($input['recovery_successor_attestation_ref']??''), 'recovery_successor_attestation_hash'=>(string)($input['recovery_successor_attestation_hash']??''),
            'epoch_policy_identity'=>OfficialEpochPolicyV1::identity(), 'incident_policy_identity'=>OfficialEpochIncidentPolicyV1::identity(),
        ];
        $record['record_hash']=$this->hash($record); return $record;
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    public function validateResolution(array $record): array
    {
        if (($record['schema_version']??'')!==self::RESOLUTION_SCHEMA || !$this->validRecord($record)) return $this->blocked('INCIDENT_RESOLUTION_RECORD_INVALID');
        foreach (['incident_id','incident_hash','root_cause','fix_implementation_identity','resolution_evidence_ref','resolution_evidence_hash'] as $f) if (trim((string)($record[$f]??''))==='') return $this->blocked('INCIDENT_RESOLUTION_FIELD_MISSING_'.strtoupper($f));
        if (empty($record['root_cause_fixed']) || !is_array($record['affected_scope_final']??null) || $record['affected_scope_final']===[] || ($record['artifact_disposition']??'')!=='QUARANTINED_RETAINED_NONCANONICAL_NOT_INVALIDATED' || ($record['canonical_capture_evidence_status']??'')!=='VALID' || ($record['closure_status']??'')!=='VALID' || ($record['epoch_source_status']??'')!=='VALID' || ($record['regression_result']??'')!=='PASS' || empty($record['scheduler_held']) || (int)($record['capture_process_count']??-1)!==0 || !empty($record['requires_new_epoch']) || empty($record['requires_cg_reapproval'])) return $this->blocked('INCIDENT_RESOLUTION_RECOVERY_REQUIREMENTS_NOT_MET');
        return ['accepted'=>true,'status'=>'INCIDENT_RESOLUTION_VALID','reason'=>'','record'=>$record];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function recoveryApprovalRecord(array $input): array
    {
        $key=['schema_version'=>self::CG_APPROVAL_SCHEMA,'epoch_id'=>(string)($input['epoch_id']??''),'incident_hash'=>(string)($input['incident_hash']??''),'resolution_hash'=>(string)($input['resolution_hash']??''),'attestation_hash'=>(string)($input['attestation_hash']??''),'replacement_window_id'=>(string)($input['replacement_window_id']??'')];
        $record=$key+['approval_key'=>$this->hash($key),'epoch_start_record_hash'=>(string)($input['epoch_start_record_hash']??''),'incident_ref'=>(string)($input['incident_ref']??''),'suspension_ref'=>(string)($input['suspension_ref']??''),'suspension_hash'=>(string)($input['suspension_hash']??''),'resolution_ref'=>(string)($input['resolution_ref']??''),'attestation_ref'=>(string)($input['attestation_ref']??''),'replacement_declaration_hash'=>(string)($input['replacement_declaration_hash']??''),'replacement_declared_measurement_identity'=>(string)($input['replacement_declared_measurement_identity']??''),'epoch_policy_identity'=>OfficialEpochPolicyV1::identity(),'incident_policy_identity'=>OfficialEpochIncidentPolicyV1::identity(),'same_epoch_successor_result'=>$input['same_epoch_successor_result']??[],'resume_authorized'=>!empty($input['resume_authorized']),'production_routing_change_authorized'=>false,'approver_authority'=>(string)($input['approver_authority']??''),'approver_stable_identifier'=>(string)($input['approver_stable_identifier']??''),'approval_statement'=>(string)($input['approval_statement']??''),'approval_statement_hash'=>(string)($input['approval_statement_hash']??''),'created_at'=>(string)($input['created_at']??'')];
        $record['record_hash']=$this->hash($record); return $record;
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    public function validateRecoveryApproval(array $record): array
    {
        if (($record['schema_version']??'')!==self::CG_APPROVAL_SCHEMA || !$this->validRecord($record)) return $this->blocked('CG_RECOVERY_APPROVAL_RECORD_INVALID');
        foreach (['epoch_id','incident_hash','resolution_hash','attestation_hash','replacement_window_id','replacement_declaration_hash','replacement_declared_measurement_identity','approval_statement','approval_statement_hash','approver_authority','approver_stable_identifier'] as $f) if (trim((string)($record[$f]??''))==='') return $this->blocked('CG_RECOVERY_APPROVAL_FIELD_MISSING_'.strtoupper($f));
        if (empty($record['resume_authorized']) || !empty($record['production_routing_change_authorized']) || !hash_equals('sha256:'.hash('sha256',(string)$record['approval_statement']), (string)$record['approval_statement_hash'])) return $this->blocked('CG_RECOVERY_APPROVAL_CONTENT_INVALID');
        foreach (OfficialEpochWindowBindingV1::requirementNames() as $name) if (($record['same_epoch_successor_result'][$name]??'')!=='PASS') return $this->blocked('CG_RECOVERY_APPROVAL_REQUIREMENT_NOT_PASS');
        return ['accepted'=>true,'status'=>'CG_RECOVERY_APPROVAL_VALID','reason'=>'','record'=>$record];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function preflightRecoveryApproval(array $input): array
    {
        if (($input['epoch_state'] ?? '') !== 'SUSPENDED' || empty($input['scheduler_held']) || (int)($input['capture_process_count'] ?? -1) !== 0) return $this->blocked('CG_RECOVERY_APPROVAL_RUNTIME_OR_EPOCH_INVALID');
        $resolution = $this->readValid((string)($input['resolution_path'] ?? ''), self::RESOLUTION_SCHEMA);
        if (empty($this->validateResolution($resolution)['accepted'])) return $this->blocked('CG_RECOVERY_APPROVAL_RESOLUTION_INVALID');
        $attestation = $this->readValid((string)($input['attestation_path'] ?? ''), self::ATTESTATION_SCHEMA);
        if ($attestation === []) return $this->blocked('CG_RECOVERY_APPROVAL_ATTESTATION_INVALID');
        $replacement = (string)($input['replacement_window_id'] ?? '');
        if ($replacement === '' || !hash_equals($replacement, (string)($attestation['window_id'] ?? '')) || !hash_equals((string)($input['resolution_hash'] ?? ''), (string)($resolution['record_hash'] ?? '')) || !hash_equals((string)($input['attestation_hash'] ?? ''), (string)($attestation['record_hash'] ?? ''))) return $this->blocked('CG_RECOVERY_APPROVAL_CROSS_BINDING_MISMATCH');
        $base = $this->preparedContext($replacement, $input);
        if (empty($base['accepted']) || !hash_equals((string)$base['declaration_hash'], (string)($input['replacement_declaration_hash'] ?? '')) || !hash_equals((string)$base['declared_identity'], (string)($input['replacement_declared_measurement_identity'] ?? ''))) return $this->blocked('CG_RECOVERY_APPROVAL_REPLACEMENT_INVALID');
        if (!hash_equals((string)$base['declared_identity'], (string)($input['current_measurement_identity'] ?? ''))) return $this->blocked('CG_RECOVERY_APPROVAL_CURRENT_IDENTITY_MISMATCH');
        foreach (OfficialEpochWindowBindingV1::requirementNames() as $name) if (($attestation['same_epoch_successor_validation_result'][$name] ?? '') !== 'PASS') return $this->blocked('CG_RECOVERY_APPROVAL_REQUIREMENT_NOT_PASS');
        return ['accepted'=>true,'status'=>'CG_RECOVERY_APPROVAL_ELIGIBLE','reason'=>'','resolution'=>$resolution,'attestation'=>$attestation];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createRecoveryApproval(array $input): array { return $this->withLock(function () use ($input): array { $pre=$this->preflightRecoveryApproval($input); if(empty($pre['accepted'])) return $pre; $record=$this->recoveryApprovalRecord($input); $check=$this->validateRecoveryApproval($record); return empty($check['accepted'])?$check:$this->createOnly($this->approvalPath((string)$record['approval_key']),$record,'CG_RECOVERY_APPROVAL'); }); }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function preflightResume(array $input): array
    {
        if (($input['epoch_state']??'')!=='SUSPENDED') return $this->blocked('RECOVERY_RESUME_EPOCH_NOT_SUSPENDED');
        if (empty($input['scheduler_held']) || (int)($input['capture_process_count']??-1)!==0) return $this->blocked('RECOVERY_RESUME_RUNTIME_NOT_HELD');
        if (!empty($input['end_record_exists'])) return $this->blocked('RECOVERY_RESUME_END_RECORD_EXISTS');
        $resolution=$this->readValid((string)($input['resolution_path']??''),self::RESOLUTION_SCHEMA); $rv=$this->validateResolution($resolution); if(empty($rv['accepted'])) return $this->blocked('RECOVERY_RESUME_RESOLUTION_INVALID:'.(string)$rv['reason']);
        $attestation=$this->readValid((string)($input['attestation_path']??''),self::ATTESTATION_SCHEMA); if($attestation===[]) return $this->blocked('RECOVERY_RESUME_ATTESTATION_MISSING');
        $approval=$this->readValid((string)($input['approval_path']??''),self::CG_APPROVAL_SCHEMA); $av=$this->validateRecoveryApproval($approval); if(empty($av['accepted'])) return $this->blocked('RECOVERY_RESUME_CG_APPROVAL_INVALID:'.(string)$av['reason']);
        $window=(string)($attestation['window_id']??''); if($window==='' || !hash_equals((string)($approval['replacement_window_id']??''),$window) || !hash_equals((string)($approval['attestation_hash']??''),(string)($attestation['record_hash']??'')) || !hash_equals((string)($approval['resolution_hash']??''),(string)($resolution['record_hash']??''))) return $this->blocked('RECOVERY_RESUME_CROSS_BINDING_MISMATCH');
        if (!hash_equals((string)($attestation['declared_measurement_identity']??''),(string)($input['current_measurement_identity']??'')) || !hash_equals((string)($approval['replacement_declared_measurement_identity']??''),(string)($input['current_measurement_identity']??''))) return $this->blocked('RECOVERY_RESUME_CURRENT_IDENTITY_MISMATCH');
        return ['accepted'=>true,'status'=>'RECOVERY_RESUME_ELIGIBLE','reason'=>'','replacement_window_id'=>$window,'resolution'=>$resolution,'attestation'=>$attestation,'approval'=>$approval];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function preparedContext(string $windowId, array $input): array
    {
        if (!preg_match('/^[A-Za-z0-9._-]+$/',$windowId)) return $this->blocked('RECOVERY_WINDOW_ID_INVALID');
        $root=rtrim($this->repoRoot,'/').'/var/strict_forward_live_readiness/windows/'.$windowId; if(!is_dir($root)) return $this->blocked('RECOVERY_WINDOW_ROOT_MISSING');
        $context=FormalMeasurementWindowContext::fromWindowRoot($this->repoRoot,$root); $resolved=$context->resolve(true); $declaration=$context->declaration()->read(true);
        if(empty($resolved['accepted'])||empty($declaration['accepted'])) return $this->blocked('RECOVERY_WINDOW_CONTEXT_INVALID');
        $m=$resolved['manifest']; if(($m['status']??'')!=='PREPARED'||($m['activation_state']??'')!=='PREPARED'||!empty($m['armed_at'])||$context->activationHold()->readActivation()!==[]) return $this->blocked('RECOVERY_WINDOW_NOT_PRISTINE_PREPARED');
        if($this->formalEvidenceCount($root)!==0) return $this->blocked('RECOVERY_WINDOW_FORMAL_EVIDENCE_PRESENT');
        $implementation=$declaration['implementation']; $manifest=$root.'/validation_window_manifest.json'; $declarationPath=$context->declaration()->declarationPath();
        return ['accepted'=>true,'manifest_ref'=>$this->relative($manifest),'manifest_hash'=>$this->fileHash($manifest),'declaration_ref'=>$this->relative($declarationPath),'declaration_hash'=>$this->fileHash($declarationPath),'declared_identity'=>(string)($implementation['implementation_identity']??''),'measurement_manifest_ref'=>(string)($implementation['manifest_ref']??''),'measurement_manifest_hash'=>(string)($implementation['manifest_sha256']??''),'measurement_source_freeze_ref'=>(string)($implementation['source_freeze_ref']??''),'measurement_source_freeze_hash'=>(string)($implementation['source_freeze_sha256']??'')];
    }
    private function formalEvidenceCount(string $root): int { $n=0; foreach(['ledger','opportunity','input_snapshots','comparative_pairs'] as $dir) if(is_dir($root.'/'.$dir)) foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir,FilesystemIterator::SKIP_DOTS)) as $f) if($f->isFile()&&str_ends_with($f->getFilename(),'.json')) $n++; return $n; }
    /** @param array<string,mixed> $record */ private function validRecord(array $record): bool { $p=$record; unset($p['record_hash']); return $this->validHash((string)($record['record_hash']??''))&&hash_equals($this->hash($p),(string)$record['record_hash']); }
    /** @return array<string,mixed> */ private function readValid(string $path,string $schema): array { if($path===''||!is_file($path))return[];try{$r=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);}catch(Throwable){return [];}return is_array($r)&&($r['schema_version']??'')===$schema&&$this->validRecord($r)?$r:[]; }
    /** @param array<string,mixed> $record @return array<string,mixed> */ private function createOnly(string $path,array $record,string $kind):array{if(is_file($path)){ $e=$this->readValid($path,(string)$record['schema_version']); return $e!==[]&&hash_equals((string)$e['record_hash'],(string)$record['record_hash'])?['accepted'=>true,'status'=>$kind.'_ALREADY_EXISTS','reason'=>'','record'=>$e]:$this->blocked($kind.'_CONFLICT');}$dir=dirname($path);if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))return $this->blocked($kind.'_DIRECTORY_UNAVAILABLE');$h=@fopen($path,'x');if(!is_resource($h))return $this->blocked($kind.'_CREATE_CONFLICT');$ok=@fwrite($h,OfficialEpochPolicyV1::canonicalJson($record)."\n")!==false;@fclose($h);return $ok?['accepted'=>true,'status'=>$kind.'_CREATED','reason'=>'','record'=>$record]:$this->blocked($kind.'_WRITE_FAILED');}
    /** @param callable():array<string,mixed> $operation @return array<string,mixed> */ private function withLock(callable $operation):array { return (new OfficialEpochIncidentLifecycleStore($this->lifecycleRoot))->withGlobalEpochLock($operation); }
    private function relative(string $path):string{$root=rtrim($this->repoRoot,'/');return str_starts_with($path,$root.'/')?substr($path,strlen($root)+1):$path;} private function fileHash(string $path):string{$b=@file_get_contents($path);return is_string($b)?'sha256:'.hash('sha256',$b):'';} private function hash(array $p):string{return 'sha256:'.hash('sha256',OfficialEpochPolicyV1::canonicalJson($p));} private function validHash(string $v):bool{return preg_match('/^sha256:[a-f0-9]{64}$/',$v)===1;} /** @return array<string,mixed> */ private function blocked(string $reason):array{return ['accepted'=>false,'status'=>'OFFICIAL_EPOCH_RECOVERY_BLOCKED','reason'=>$reason,'record'=>[]];}
}
