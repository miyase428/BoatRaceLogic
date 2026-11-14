<?php

declare(strict_types=1);

$repo=(string)realpath(__DIR__.'/..');
$script=$repo.'/analysis/run_identity_drift_formal_forward_recovery.php';
if(!is_file($script))throw new RuntimeException('ENTRYPOINT_MISSING');
$before=[];
foreach([
    'var/strict_forward_live_readiness/formal_measurement_window_context.json',
    'var/strict_forward_live_readiness/windows/cc30c-clean-window-v4-20261020140615-c8ac4ed2/validation_window_manifest.json',
    'var/strict_forward_live_readiness/windows/cc30c-clean-window-v4-20261020140615-c8ac4ed2/declared_implementation_identity.json',
    'var/strict_forward_live_readiness/windows/cc30c-clean-window-v4-20261020140615-c8ac4ed2/activation_record.json',
] as $relative)$before[$relative]=hash_file('sha256',$repo.'/'.$relative);
$cmd='/usr/bin/php '.escapeshellarg($script).' --preflight';
$lines=[];$code=0;exec($cmd.' 2>&1',$lines,$code);$decoded=json_decode(implode("\n",$lines),true);
if(!is_array($decoded)||!isset($decoded['checks'])||!array_key_exists('capture_processes_zero',$decoded['checks']))throw new RuntimeException('PREFLIGHT_JSON_INVALID');
foreach($before as $relative=>$hash)if(!hash_equals((string)$hash,(string)hash_file('sha256',$repo.'/'.$relative)))throw new RuntimeException('PREFLIGHT_MUTATED_PRODUCTION_STATE:'.$relative);
$source=(string)file_get_contents($script);
foreach(['--preflight','--dry-run','--execute','POST_ABORT_SAFE_TO_CONTINUE','createAttestation','createRequirement','createPackage','handoffPointerCas','authorizeOperationalPointerWindow'] as $needle)if(!str_contains($source,$needle))throw new RuntimeException('ENTRYPOINT_CONTRACT_MISSING:'.$needle);
echo json_encode(['status'=>'PASS','preflight_exit_code'=>$code,'preflight_status'=>$decoded['status']??'','read_only_state_hashes'=>$before,'entrypoint_contract'=>'PASS'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
