<?php

declare(strict_types=1);

// Parse before loading any Window/provenance code: help must be pure.
if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo "Usage: php analysis/prepare_official_epoch_recovery_successor.php --dry-run|--create\n";
    exit(0);
}
if (count($argv) !== 2 || !in_array($argv[1], ['--dry-run', '--create'], true)) { fwrite(STDERR, "expected --dry-run or --create\n"); exit(2); }
$commit = $argv[1] === '--create';

require_once __DIR__ . '/../web/logic/FormalMeasurementWindowPrepareOnlyV1.php';
require_once __DIR__ . '/../web/logic/OfficialEpochIncidentLifecycleStore.php';
require_once __DIR__ . '/../web/logic/OfficialEpochStartStore.php';

$repo = dirname(__DIR__);
$lifecycle = $repo . '/var/strict_forward_live_readiness/lifecycle';
$read = static function (string $path): array { try { $value=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR); return is_array($value)?$value:[]; } catch (Throwable) { return []; } };
$epoch=(new OfficialEpochStartStore($lifecycle))->existing();
$epochId=(string)($epoch['epoch_id']??'');
$projection=$epochId===''?['valid'=>false]:(new OfficialEpochIncidentLifecycleStore($lifecycle))->project($epochId);
$incident=$read($lifecycle.'/official_epoch_incidents/official-epoch-incident-v1-3cdb5f9fda3a2607.json');
$suspension=$read($lifecycle.'/official_epoch_suspensions/official-epoch-suspend-v1-1319e5f3f992f712.json');
$incidentValid = preg_match('/^sha256:[a-f0-9]{64}$/', (string)($incident['record_hash'] ?? '')) === 1;
$suspensionValid = preg_match('/^sha256:[a-f0-9]{64}$/', (string)($suspension['record_hash'] ?? '')) === 1;
$plan=(new FormalMeasurementWindowPrepareOnlyV1($repo))->plan([
    'mode'=>'RECOVERY','epoch_state'=>(string)($projection['state']??''),
    'incident_hash'=>(string)($incident['record_hash']??''),'suspension_hash'=>(string)($suspension['record_hash']??''),
]);
$result = [
    'schema_version'=>'official-epoch-recovery-prepare-only-preflight/v1','read_only'=>true,
    'epoch_id'=>$epochId,'epoch_state'=>(string)($projection['state']??'INVALID'),'epoch_projection_valid'=>!empty($projection['valid']),
    'recovery_prepare_only_eligible'=>!empty($plan['accepted']) && $incidentValid && $suspensionValid,'reason'=>(string)($plan['reason']??''),
    'declared_measurement_identity_candidate'=>(string)($plan['declared_measurement_identity_candidate']??''),
    'pointer_mutation_planned'=>false,'normal_binding_planned'=>false,'activation_planned'=>false,
    'window_root_created'=>false,'formal_evidence_created'=>false,
];
if ($commit) {
    if (empty($result['recovery_prepare_only_eligible'])) { $result['status']='RECOVERY_PREPARE_ONLY_BLOCKED'; echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL; exit(1); }
    $created=(new FormalMeasurementWindowPrepareOnlyV1($repo))->create(['mode'=>'RECOVERY','epoch_state'=>(string)($projection['state']??''),'incident_hash'=>(string)$incident['record_hash'],'suspension_hash'=>(string)$suspension['record_hash']]);
    $result['status']=(string)($created['status']??'RECOVERY_PREPARE_ONLY_BLOCKED');$result['reason']=(string)($created['reason']??'');$result['window_root_created']=!empty($created['accepted']);$result['window_id']=$created['window_id']??'';$result['declared_measurement_identity']=$created['implementation']['implementation_identity']??'';
} else $result['status']='RECOVERY_PREPARE_ONLY_DRY_RUN_PASS';
echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
