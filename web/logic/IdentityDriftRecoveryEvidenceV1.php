<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialEpochPolicyV1.php';
require_once __DIR__ . '/OfficialEpochWindowBindingV1.php';

/** Create-only, hash-backed evidence for an ACTIVE same-Epoch identity-drift successor. */
final class IdentityDriftRecoveryEvidenceV1
{
    public const REASON = 'MEASUREMENT_IMPLEMENTATION_IDENTITY_DRIFT';
    public const ATTESTATION_SCHEMA = 'identity-drift-attestation/v1';
    public const EVIDENCE_SCHEMA = 'identity-drift-requirement-evidence/v1';
    public const PACKAGE_SCHEMA = 'identity-drift-recovery-package/v1';

    public function __construct(private readonly string $repoRoot) {}

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public function createAttestation(string $relative, array $row): array
    {
        $required=['predecessor_window_id','predecessor_epoch_id','predecessor_declared_identity','observed_runtime_identity','successor_canonical_identity','mismatch_validator_result','schedule_contract_ref','schedule_contract_sha256','created_at'];
        foreach($required as $f) if(trim((string)($row[$f]??''))==='') return $this->blocked('ATTESTATION_FIELD_MISSING_'.$f);
        if(($row['recovery_reason']??self::REASON)!==self::REASON) return $this->blocked('RECOVERY_REASON_INVALID');
        if(!empty($row['mismatch_detected'])!==true || ($row['mismatch_validator_result']??'')!=='DECLARED_IMPLEMENTATION_IDENTITY_MISMATCH') return $this->blocked('IDENTITY_MISMATCH_NOT_REPRODUCED');
        if(hash_equals((string)$row['predecessor_declared_identity'],(string)$row['successor_canonical_identity'])) return $this->blocked('IDENTITY_DRIFT_ABSENT');
        if(!$this->validHash((string)$row['schedule_contract_sha256']) || !$this->refHashMatches((string)$row['schedule_contract_ref'],(string)$row['schedule_contract_sha256'])) return $this->blocked('SCHEDULE_CONTRACT_INVALID');
        $record=['schema_version'=>self::ATTESTATION_SCHEMA,'recovery_reason'=>self::REASON]+$row;
        return $this->createOnly($relative,$record);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    public function createRequirement(string $relative, array $row): array
    {
        $id=(string)($row['requirement_id']??'');
        if(!in_array($id,OfficialEpochWindowBindingV1::requirementNames(),true)) return $this->blocked('REQUIREMENT_ID_INVALID');
        foreach(['predecessor_refs','predecessor_hashes','successor_refs','successor_hashes'] as $f) if(!is_array($row[$f]??null)||$row[$f]===[]) return $this->blocked('REQUIREMENT_SOURCE_MISSING_'.$f);
        if(empty($row['validated'])||($row['comparison_result']??'')!=='PASS') return $this->blocked('REQUIREMENT_NOT_VALIDATED');
        if(!$this->refsValidate($row['predecessor_refs'],$row['predecessor_hashes'])||!$this->refsValidate($row['successor_refs'],$row['successor_hashes'])) return $this->blocked('REQUIREMENT_SOURCE_HASH_INVALID');
        return $this->createOnly($relative,['schema_version'=>self::EVIDENCE_SCHEMA]+$row);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function createPackage(string $relative,array $input):array
    {
        $required=['predecessor_window_id','predecessor_epoch_id','predecessor_declared_identity','successor_window_id','successor_canonical_identity','recovery_armed_at','schedule_contract_ref','schedule_contract_sha256','identity_drift_attestation_ref','identity_drift_attestation_sha256','abort_record_ref','abort_record_sha256','created_at'];
        foreach($required as $f) if(trim((string)($input[$f]??''))==='') return $this->blocked('PACKAGE_FIELD_MISSING_'.$f);
        if(($input['recovery_reason']??self::REASON)!==self::REASON) return $this->blocked('RECOVERY_REASON_INVALID');
        if(hash_equals((string)$input['predecessor_declared_identity'],(string)$input['successor_canonical_identity'])) return $this->blocked('IDENTITY_DRIFT_ABSENT');
        try{$armed=new DateTimeImmutable((string)$input['recovery_armed_at']);}catch(Throwable){return $this->blocked('RECOVERY_BOUNDARY_INVALID');}
        if($armed->getOffset()!==32400) return $this->blocked('RECOVERY_BOUNDARY_NOT_JST');
        foreach(['schedule_contract','identity_drift_attestation','abort_record'] as $p) if(!$this->refHashMatches((string)$input[$p.'_ref'],(string)$input[$p.'_sha256'])) return $this->blocked(strtoupper($p).'_HASH_INVALID');
        $refs=$input['requirement_evidence_refs']??null;$hashes=$input['requirement_evidence_hashes']??null;
        if(!is_array($refs)||!is_array($hashes)) return $this->blocked('REQUIREMENT_EVIDENCE_MISSING');
        foreach(OfficialEpochWindowBindingV1::requirementNames() as $name) if(!$this->refHashMatches((string)($refs[$name]??''),(string)($hashes[$name]??''))) return $this->blocked('REQUIREMENT_EVIDENCE_INVALID_'.$name);
        return $this->createOnly($relative,['schema_version'=>self::PACKAGE_SCHEMA,'recovery_reason'=>self::REASON,'boundary_semantics'=>OfficialEpochWindowBindingV1::PROSPECTIVE_BOUNDARY_CONTRACT]+$input);
    }

    /** @param array<string,mixed> $context @return array<string,mixed> */
    public function validateForBinding(string $packageRef,array $context):array
    {
        $package=$this->readRecord($packageRef,self::PACKAGE_SCHEMA); if($package===[]) return $this->blocked('RECOVERY_PACKAGE_INVALID');
        foreach(['schedule_contract','identity_drift_attestation','abort_record'] as $p) if(!$this->refHashMatches((string)($package[$p.'_ref']??''),(string)($package[$p.'_sha256']??''))) return $this->blocked(strtoupper($p).'_MUTATED');
        $att=$this->readRecord((string)$package['identity_drift_attestation_ref'],self::ATTESTATION_SCHEMA);
        if($att===[]||($att['recovery_reason']??'')!==self::REASON||empty($att['mismatch_detected'])
            ||!hash_equals((string)$att['predecessor_window_id'],(string)$package['predecessor_window_id'])
            ||!hash_equals((string)$att['predecessor_epoch_id'],(string)$package['predecessor_epoch_id'])
            ||!hash_equals((string)$att['successor_canonical_identity'],(string)$package['successor_canonical_identity'])) return $this->blocked('IDENTITY_DRIFT_ATTESTATION_INVALID');
        foreach(['predecessor_window_id','successor_window_id','predecessor_epoch_id'] as $f) if(!hash_equals((string)($package[$f]??''),(string)($context[$f]??''))) return $this->blocked('PACKAGE_CONTEXT_MISMATCH_'.$f);
        if(($context['epoch_state']??'')!=='ACTIVE') return $this->blocked('EPOCH_NOT_ACTIVE');
        if(!hash_equals((string)$package['predecessor_window_id'],(string)($context['pointer_owner']??''))) return $this->blocked('POINTER_OWNER_STALE');
        if(($context['predecessor_state']??'')!=='ABORTED'||($context['abort_reason']??'')!==self::REASON) return $this->blocked('PREDECESSOR_ABORT_INVALID');
        if(empty($context['successor_prepared'])||!empty($context['historical_mutation_detected'])) return $this->blocked('SUCCESSOR_OR_HISTORY_INVALID');
        $refs=$package['requirement_evidence_refs'];$hashes=$package['requirement_evidence_hashes'];
        foreach(OfficialEpochWindowBindingV1::requirementNames() as $name){$e=$this->readRecord((string)$refs[$name],self::EVIDENCE_SCHEMA);if($e===[]||($e['requirement_id']??'')!==$name||empty($e['validated'])||!hash_equals($this->fileHash((string)$refs[$name]),(string)$hashes[$name]))return $this->blocked('REQUIREMENT_VALIDATION_FAILED_'.$name);}
        return ['accepted'=>true,'status'=>'IDENTITY_DRIFT_RECOVERY_PACKAGE_VALID','reason'=>'','binding_validation'=>['successor_semantics_classification'=>OfficialEpochWindowBindingV1::SEMANTICS_EQUIVALENT,'no_semantic_contract_diff'=>true,'numeric_policy_semantics_unchanged'=>true,'formal_evidence_count'=>0,'carryover_count'=>0,'backfill_count'=>0,'prior_evidence_reuse_count'=>0,'regressions_pass'=>true,'readiness_pass'=>true,'validation_evidence_refs'=>$refs,'validation_evidence_hashes'=>$hashes]];
    }

    /** @param array<string,string> $refs @param array<string,string> $hashes */
    private function refsValidate(array $refs,array $hashes):bool{foreach($refs as $k=>$ref)if(!$this->refHashMatches((string)$ref,(string)($hashes[$k]??'')))return false;return true;}
    private function refHashMatches(string $ref,string $hash):bool{return $ref!==''&&$this->validHash($hash)&&hash_equals($this->fileHash($ref),$hash);}
    private function fileHash(string $ref):string{$p=$this->path($ref);return is_file($p)?'sha256:'.hash_file('sha256',$p):'';}
    private function path(string $ref):string{return rtrim($this->repoRoot,DIRECTORY_SEPARATOR).'/'.ltrim($ref,'/');}
    /** @return array<string,mixed> */ private function readRecord(string $ref,string $schema):array{$p=$this->path($ref);try{$r=json_decode((string)file_get_contents($p),true,512,JSON_THROW_ON_ERROR);}catch(Throwable){return [];}if(!is_array($r)||($r['schema_version']??'')!==$schema||!$this->validHash((string)($r['record_hash']??'')))return [];$c=$r;unset($c['record_hash']);return hash_equals((string)$r['record_hash'],$this->hash($c))?$r:[];}
    /** @param array<string,mixed> $record @return array<string,mixed> */ private function createOnly(string $ref,array $record):array{$record['record_hash']=$this->hash($record);$p=$this->path($ref);$d=dirname($p);if(!is_dir($d)&&!mkdir($d,0775,true)&&!is_dir($d))return $this->blocked('DIRECTORY_UNAVAILABLE');$h=@fopen($p,'x');if(!is_resource($h))return $this->blocked('CREATE_ONLY_CONFLICT');$ok=fwrite($h,OfficialEpochPolicyV1::canonicalJson($record)."\n")!==false;fclose($h);return $ok?['accepted'=>true,'status'=>'CREATED','reason'=>'','record'=>$record,'ref'=>$ref,'sha256'=>$this->fileHash($ref)]:$this->blocked('WRITE_FAILED');}
    /** @param array<string,mixed> $r */ private function hash(array $r):string{return 'sha256:'.hash('sha256',OfficialEpochPolicyV1::canonicalJson($r));}
    private function validHash(string $h):bool{return preg_match('/^sha256:[a-f0-9]{64}$/',$h)===1;}
    /** @return array<string,mixed> */ private function blocked(string $r):array{return ['accepted'=>false,'status'=>'IDENTITY_DRIFT_RECOVERY_BLOCKED','reason'=>$r];}
}
