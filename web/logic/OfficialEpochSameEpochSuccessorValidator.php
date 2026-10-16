<?php

declare(strict_types=1);

require_once __DIR__ . '/OfficialEpochWindowBindingV1.php';
require_once __DIR__ . '/FormalMeasurementWindowContext.php';
require_once __DIR__ . '/FormalMeasurementWindowPrepareOnlyV1.php';

/** State-neutral evaluator for the nine semantic successor requirements. */
final class OfficialEpochSameEpochSuccessorValidator
{
    public const SCHEMA_VERSION = 'official-epoch-same-epoch-successor-validation/v1';

    public function __construct(private readonly string $repoRoot) {}

    /** @param array<string,mixed> $epoch @param array<string,mixed> $input @return array<string,mixed> */
    public function evaluate(string $windowId, array $epoch, array $input): array
    {
        $root = rtrim($this->repoRoot, DIRECTORY_SEPARATOR) . '/var/strict_forward_live_readiness/windows/' . $windowId;
        $context = FormalMeasurementWindowContext::fromWindowRoot($this->repoRoot, $root);
        $resolved = $context->resolve(true);
        $declaration = $context->declaration()->read(true);
        $prepared = (new FormalMeasurementWindowPrepareOnlyV1($this->repoRoot))->readPreparedContract($windowId);
        if (empty($resolved['accepted']) || empty($declaration['accepted']) || $prepared === []) return $this->blocked('SUCCESSOR_VALIDATION_WINDOW_PROVENANCE_INVALID');
        $manifest = $resolved['manifest']; $decl = $declaration['declaration']; $implementation = $declaration['implementation'];
        if (($manifest['status'] ?? '') !== 'PREPARED' || ($manifest['activation_state'] ?? '') !== 'PREPARED' || !empty($manifest['armed_at']) || $context->activationHold()->readActivation() !== []) return $this->blocked('SUCCESSOR_VALIDATION_WINDOW_NOT_PRISTINE_PREPARED');
        $manifestHash=$this->fileHash($root.'/validation_window_manifest.json'); $declarationHash=$this->fileHash($context->declaration()->declarationPath());
        if (!hash_equals((string)($prepared['window_manifest_hash'] ?? ''),$manifestHash) || !hash_equals((string)($prepared['window_declaration_hash'] ?? ''),$declarationHash) || !hash_equals((string)($prepared['declared_measurement_identity'] ?? ''),(string)$implementation['implementation_identity'])) return $this->blocked('SUCCESSOR_VALIDATION_PREPARED_CONTRACT_MISMATCH');
        $contract=is_array($decl['comparative_contract']??null)?$decl['comparative_contract']:[];
        $truth=static fn(mixed $value):bool=>$value===true||$value==='PASS';
        $requirements=[
            'measurement_semantics_unchanged'=>((string)($input['successor_semantics_classification']??'')===OfficialEpochWindowBindingV1::SEMANTICS_EQUIVALENT&&$truth($input['no_semantic_contract_diff']??null)&&$truth($input['regressions_pass']??null))?'PASS':'FAIL',
            'comparative_scope_unchanged'=>hash_equals((string)($epoch['comparative_scope_identity']??''),(string)($contract['comparative_scope_identity']??''))?'PASS':'FAIL',
            'comparative_policy_semantics_unchanged'=>hash_equals((string)($epoch['comparative_policy_identity']??''),(string)($contract['comparative_policy_identity']??''))?'PASS':'FAIL',
            'hard_gate_policy_unchanged'=>hash_equals((string)($epoch['epoch_policy_identity']??''),(string)($input['epoch_policy_identity']??''))?'PASS':'FAIL',
            'numeric_policy_semantics_unchanged'=>$truth($input['numeric_policy_semantics_unchanged']??null)?'PASS':'FAIL',
            'incident_policy_semantics_unchanged'=>hash_equals((string)($epoch['incident_policy_identity']??''),(string)($input['incident_policy_identity']??''))?'PASS':'FAIL',
            'affected_evidence_uniquely_isolated'=>((int)($input['formal_evidence_count']??-1)===0&&(int)($input['carryover_count']??-1)===0&&(int)($input['backfill_count']??-1)===0&&(int)($input['prior_evidence_reuse_count']??-1)===0)?'PASS':'FAIL',
            'regressions_pass'=>$truth($input['regressions_pass']??null)?'PASS':'FAIL',
            'readiness_pass'=>$truth($input['readiness_pass']??null)?'PASS':'FAIL',
        ];
        $refs=is_array($input['validation_evidence_refs']??null)?$input['validation_evidence_refs']:[]; $hashes=is_array($input['validation_evidence_hashes']??null)?$input['validation_evidence_hashes']:[];
        foreach(OfficialEpochWindowBindingV1::requirementNames() as $name) if(trim((string)($refs[$name]??''))===''||!OfficialEpochWindowBindingV1::validHash((string)($hashes[$name]??''))) $requirements[$name]='FAIL';
        if(!hash_equals((string)($input['current_measurement_identity']??''),(string)$implementation['implementation_identity'])) $requirements['measurement_semantics_unchanged']='FAIL';
        $payload=['schema_version'=>self::SCHEMA_VERSION,'window_id'=>$windowId,'window_manifest_hash'=>$manifestHash,'window_declaration_hash'=>$declarationHash,'declared_measurement_identity'=>(string)$implementation['implementation_identity'],'measurement_manifest_hash'=>(string)$implementation['manifest_sha256'],'measurement_source_freeze_hash'=>(string)$implementation['source_freeze_sha256'],'epoch_id'=>(string)($epoch['epoch_id']??''),'epoch_start_record_hash'=>(string)($epoch['record_hash']??''),'epoch_policy_identity'=>(string)($epoch['epoch_policy_identity']??''),'incident_policy_identity'=>(string)($epoch['incident_policy_identity']??''),'requirements'=>$requirements,'validation_evidence_refs'=>$refs,'validation_evidence_hashes'=>$hashes];
        $payload['validation_key']=$this->hash($payload); $payload['validation_package_hash']=$this->hash($payload);
        return ['accepted'=>true,'status'=>in_array('FAIL',$requirements,true)?'SAME_EPOCH_SUCCESSOR_VALIDATION_FAIL':'SAME_EPOCH_SUCCESSOR_VALIDATION_PASS','reason'=>'','requirements'=>$requirements,'overall_result'=>in_array('FAIL',$requirements,true)?'FAIL':'PASS','package'=>$payload];
    }

    /** @param array<string,mixed> $package @param array<string,mixed> $epoch @param array<string,mixed> $input @return array<string,mixed> */
    public function validatePackage(string $windowId,array $package,array $epoch,array $input): array
    {
        $hash=(string)($package['validation_package_hash']??''); $copy=$package; unset($copy['validation_package_hash']);
        if(!OfficialEpochWindowBindingV1::validHash($hash)||!hash_equals($hash,$this->hash($copy))) return $this->blocked('SUCCESSOR_VALIDATION_PACKAGE_HASH_INVALID');
        $fresh=$this->evaluate($windowId,$epoch,$input); if(empty($fresh['accepted'])) return $fresh;
        if(!hash_equals((string)$fresh['package']['validation_package_hash'],$hash)) return $this->blocked('SUCCESSOR_VALIDATION_PACKAGE_RECOMPUTE_MISMATCH');
        return $fresh;
    }

    private function fileHash(string $path):string{$b=@file_get_contents($path);return is_string($b)?'sha256:'.hash('sha256',$b):'';}
    /** @param array<string,mixed> $payload */ private function hash(array $payload):string{return 'sha256:'.hash('sha256',OfficialEpochPolicyV1::canonicalJson($payload));}
    /** @return array<string,mixed> */ private function blocked(string $reason):array{return ['accepted'=>false,'status'=>'SAME_EPOCH_SUCCESSOR_VALIDATION_BLOCKED','reason'=>$reason,'requirements'=>[],'overall_result'=>'FAIL','package'=>[]];}
}
