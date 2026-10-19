<?php

declare(strict_types=1);

// Parse-only boundary: help/version/invalid input must never load a Window.
if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo "Usage: php analysis/preflight_critical_incident_recovery.php [--help|--version]\n";
    exit(0);
}
if (in_array('--version', $argv, true)) { echo "critical-incident-recovery-preflight/v1\n"; exit(0); }
if (count($argv) !== 1) { fwrite(STDERR, "invalid arguments\n"); exit(2); }

require_once __DIR__ . '/../web/logic/MeasurementImplementationProvenance.php';
require_once __DIR__ . '/../web/logic/OfficialEpochRecoveryLifecycleV1.php';

$repo = dirname(__DIR__);
$lifecycle = $repo . '/var/strict_forward_live_readiness/lifecycle';
$epochStore = new OfficialEpochIncidentLifecycleStore($lifecycle);
$start = (new OfficialEpochStartStore($lifecycle))->existing();
$epochId = (string)($start['epoch_id'] ?? '');
$projection = $epochId === '' ? ['valid' => false] : $epochStore->project($epochId);
$incidentPath = $lifecycle . '/official_epoch_incidents/official-epoch-incident-v1-3cdb5f9fda3a2607.json';
$suspensionPath = $lifecycle . '/official_epoch_suspensions/official-epoch-suspend-v1-1319e5f3f992f712.json';
$read = static function (string $path): array { try { $v=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR); return is_array($v)?$v:[]; } catch (Throwable) { return []; } };
$incident=$read($incidentPath); $suspension=$read($suspensionPath);
$crontab = (string)shell_exec('/usr/bin/crontab -l 2>/dev/null');
$main = array_values(array_filter(preg_split('/\R/', $crontab) ?: [], static fn(string $line): bool => preg_match('/#\s*BOATRACE_ALL_RACE_FORWARD_CAPTURE\s*$/', $line) === 1));
$enabled = array_filter($main, static fn(string $line): bool => preg_match('/^\s*#/', $line) !== 1);
$held = count($main) === 1 && count($enabled) === 0 && preg_match('/^\s*#\s*MAINTENANCE_HOLD/', $main[0]) === 1;
$processes = (string)shell_exec("ps -eo pid=,comm=,args= | awk '$0 ~ /run_all_race_forward_capture_cron\\.sh|capture_all_race_forward\\.php/ && $0 !~ /awk/ {print}'");
$processCount = count(array_filter(preg_split('/\R/', trim($processes)) ?: [], static fn(string $line): bool => trim($line) !== ''));
$current = (new MeasurementImplementationProvenance($repo, sys_get_temp_dir()))->currentIdentityReadOnly();
$declarationCli = (string)file_get_contents($repo . '/analysis/declare_window_implementation_identity.php');
$declarationLogic = (string)file_get_contents($repo . '/web/logic/WindowDeclaredImplementationIdentity.php');
$rootCauseFixed = str_contains($declarationCli, "if (\$arguments['mode'] === 'HELP')")
    && str_contains($declarationCli, "require_once __DIR__ . '/../web/logic/WindowDeclaredImplementationIdentity.php'")
    && strpos($declarationCli, "if (\$arguments['mode'] === 'HELP')") < strpos($declarationCli, "require_once __DIR__ . '/../web/logic/WindowDeclaredImplementationIdentity.php'")
    && str_contains($declarationLogic, "if (\$declaredState === 'CLOSED') return 'WINDOW_CLOSED'")
    && str_contains($declarationLogic, "if (\$declaredState === 'ABORTED') return 'WINDOW_ABORTED'");
$recovery = new OfficialEpochRecoveryLifecycleV1($repo, $lifecycle);
$stale = 'successor-v3-prepared-20261013140942-5ed840d9';
$sup = $recovery->preflightSupersession($stale, [
    'epoch_id'=>$epochId,'epoch_state'=>(string)($projection['state']??''),'incident_ref'=>'official_epoch_incidents/'.basename($incidentPath),'incident_hash'=>(string)($incident['record_hash']??''),'suspension_ref'=>'official_epoch_suspensions/'.basename($suspensionPath),'suspension_hash'=>(string)($suspension['record_hash']??''),'current_measurement_identity'=>$current,'reason'=>'MEASUREMENT_IMPLEMENTATION_IDENTITY_SUPERSEDED_DURING_INCIDENT_RECOVERY',
]);
$resolutionEligible = !empty($projection['valid']) && ($projection['state']??'') === 'SUSPENDED' && $rootCauseFixed && $held && $processCount === 0 && ($incident['requires_new_epoch']??true) === false && ($incident['requires_cg_reapproval']??false) === true;
$attestation = $recovery->attestationForWindow('replacement-not-created');
$out = [
    'schema_version'=>'critical-incident-recovery-preflight/v1','read_only'=>true,'current_epoch_state'=>(string)($projection['state']??'INVALID'),'epoch_projection_valid'=>!empty($projection['valid']),
    'current_measurement_identity'=>$current,'root_cause_fixed'=>$rootCauseFixed,'scheduler_held'=>$held,'capture_process_count'=>$processCount,
    'stale_prepared_supersession_eligible'=>!empty($sup['accepted']),'stale_prepared_supersession_reason'=>(string)($sup['reason']??''),
    'incident_resolution_eligibility'=>$resolutionEligible ? 'ELIGIBLE' : 'BLOCKED',
    'cg_recovery_approval_eligibility'=>'NO','resume_eligibility'=>'NO',
    'resume_blockers'=>array_values(array_filter(['RESOLUTION_MISSING','RECOVERY_SUCCESSOR_ATTESTATION_MISSING','CG_RECOVERY_APPROVAL_MISSING', $attestation===[] ? '' : ''])),
    'mutations'=>['supersession_created'=>false,'replacement_window_created'=>false,'attestation_created'=>false,'resolution_created'=>false,'cg_recovery_approval_created'=>false,'resume_created'=>false],
];
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
