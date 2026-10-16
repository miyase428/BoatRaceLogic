<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/OfficialEpochIncidentLifecycleStore.php';

$total = 0; $failures = [];
$assert = static function (bool $ok, string $name) use (&$total, &$failures): void { $total++; if (!$ok) $failures[] = $name; };
$remove = static function (string $path) use (&$remove): void { if (!file_exists($path)) return; if (is_dir($path)) { foreach (scandir($path) ?: [] as $x) if ($x !== '.' && $x !== '..') $remove($path . '/' . $x); rmdir($path); } else unlink($path); };
$tmp = sys_get_temp_dir() . '/official_epoch_incident_v1_' . bin2hex(random_bytes(8)); mkdir($tmp, 0775, true);

try {
    $assert(OfficialEpochIncidentPolicyV1::identity() === OfficialEpochIncidentPolicyV1::identity(), 'incident_policy_identity_deterministic');
    $assert(count(OfficialEpochIncidentPolicyV1::incidentTypes()) === 19, 'incident_type_count_19');
    foreach (array_keys(OfficialEpochIncidentPolicyV1::incidentTypes()) as $type) $assert((OfficialEpochIncidentPolicyV1::classify($type)['classification'] ?? '') === 'INCIDENT', 'known_incident_type_' . $type);
    $assert((OfficialEpochIncidentPolicyV1::classify('NON_MEASUREMENT_CHANGE')['severity'] ?? '') === 'INFO', 'nonmeasurement_information_only');
    $assert((OfficialEpochIncidentPolicyV1::classify('CAPTURE_SOURCE_FAILURE', ['affected_scope_kind' => 'RACE_LOCAL', 'classified' => true])['severity'] ?? '') === 'WARNING', 'race_local_source_warning');
    $assert((OfficialEpochIncidentPolicyV1::classify('CAPTURE_SOURCE_FAILURE', ['affected_scope_kind' => 'WINDOW'])['severity'] ?? '') === 'WARNING', 'window_source_default_warning');
    $assert((OfficialEpochIncidentPolicyV1::classify('CAPTURE_SOURCE_FAILURE', ['systemic' => true])['severity'] ?? '') === 'CRITICAL', 'systemic_source_critical');
    $assert((OfficialEpochIncidentPolicyV1::classify('SEMANTIC_REVISION')['requires_new_epoch'] ?? false) === true, 'semantic_revision_new_epoch');
    $assert((OfficialEpochIncidentPolicyV1::classify('MEASUREMENT_IMPLEMENTATION_IDENTITY_DRIFT', ['change_class' => 'MEASUREMENT_SEMANTICS_CHANGE'])['requires_new_epoch'] ?? false) === true, 'semantic_identity_drift_new_epoch');
    $assert((OfficialEpochIncidentPolicyV1::definition()['closed_window_mutation_forbidden'] ?? false) === true, 'closed_window_mutation_forbidden');
    $assert((OfficialEpochIncidentPolicyV1::definition()['physical_evidence_mutation_forbidden'] ?? false) === true, 'physical_evidence_mutation_forbidden');
    $assert(in_array('SUSPENDED->ENDED', OfficialEpochIncidentPolicyV1::definition()['allowed_transitions'], true), 'suspended_to_ended_allowed');

    $epochKey = 'sha256:' . str_repeat('1', 64); $epochId = 'official-epoch-v1-' . substr($epochKey, 7, 16);
    $start = ['schema_version' => OfficialEpochStartStore::SCHEMA_VERSION, 'epoch_id' => $epochId, 'epoch_key' => $epochKey, 'authorization_hash' => 'sha256:' . str_repeat('2', 64), 'incident_policy_schema' => OfficialEpochIncidentPolicyV1::SCHEMA_VERSION, 'incident_policy_identity' => OfficialEpochIncidentPolicyV1::identity()];
    $start['record_hash'] = 'sha256:' . hash('sha256', OfficialEpochPolicyV1::canonicalJson($start));
    $startResult = (new OfficialEpochStartStore($tmp))->create($start);
    $assert(($startResult['accepted'] ?? false) === true, 'synthetic_epoch_start_created');
    $store = new OfficialEpochIncidentLifecycleStore($tmp);
    $initial = $store->project($epochId); $assert(($initial['valid'] ?? false) === true && ($initial['state'] ?? '') === 'ACTIVE', 'initial_epoch_active');

    $incident = $store->incidentRecord(['epoch_id' => $epochId, 'detected_at' => '2026-10-12T16:00:00+09:00', 'incident_type' => 'SEMANTIC_REVISION', 'source_evidence_ref' => 'audit/semantic.json', 'source_evidence_hash' => 'sha256:' . str_repeat('3', 64), 'affected_scope' => ['window_id' => 'fixture'], 'evidence_status_projection' => 'QUARANTINED']);
    $assert(($incident['severity'] ?? '') === 'CRITICAL', 'semantic_incident_critical');
    $created = $store->createIncident($incident); $assert(($created['accepted'] ?? false) === true, 'incident_create_only');
    $assert((($store->evidenceDesignations($epochId)[0]['evidence_status'] ?? '') === 'QUARANTINED'), 'external_evidence_quarantine_projection');
    $assert(($store->createIncident($incident)['status'] ?? '') === 'OFFICIAL_EPOCH_INCIDENT_ALREADY_EXISTS', 'incident_idempotent');
    $conflict = $incident; $conflict['evidence_status_projection'] = 'INVALIDATED'; unset($conflict['record_hash']); $conflict['record_hash'] = 'sha256:' . hash('sha256', OfficialEpochPolicyV1::canonicalJson($conflict)); $assert(($store->createIncident($conflict)['reason'] ?? '') === 'OFFICIAL_EPOCH_INCIDENT_CONFLICT', 'incident_conflict_guard');

    $suspend = $store->transitionRecord('SUSPEND', ['epoch_id' => $epochId, 'from_state' => $initial['state'], 'predecessor_state_hash' => $initial['state_hash'], 'transition_at' => '2026-10-12T16:01:00+09:00', 'incident_ref' => 'official_epoch_incidents/' . $incident['incident_id'] . '.json', 'incident_hash' => $incident['record_hash']]);
    $assert(($store->commitTransition('SUSPEND', $suspend)['accepted'] ?? false) === true, 'active_to_suspended');
    $suspended = $store->project($epochId); $assert(($suspended['state'] ?? '') === 'SUSPENDED', 'suspended_projection');
    $badResume = $store->transitionRecord('RESUME', ['epoch_id' => $epochId, 'from_state' => 'SUSPENDED', 'predecessor_state_hash' => $suspended['state_hash'], 'transition_at' => '2026-10-12T16:02:00+09:00', 'incident_hash' => $incident['record_hash']]);
    $assert(($store->commitTransition('RESUME', $badResume)['accepted'] ?? true) === false, 'resume_without_resolution_and_cg_blocked');
    $resolution = $store->resolutionRecord(['epoch_id' => $epochId, 'incident_id' => $incident['incident_id'], 'incident_hash' => $incident['record_hash'], 'resolved_at' => '2026-10-12T16:03:00+09:00', 'resolution_evidence_ref' => 'audit/resolution.json', 'resolution_evidence_hash' => 'sha256:' . str_repeat('5', 64)]);
    $assert(($store->createResolution($resolution)['accepted'] ?? true) === false, 'underspecified_legacy_resolution_is_blocked');
    $resume = $store->transitionRecord('RESUME', ['epoch_id' => $epochId, 'from_state' => 'SUSPENDED', 'predecessor_state_hash' => $suspended['state_hash'], 'transition_at' => '2026-10-12T16:04:00+09:00', 'incident_ref' => 'official_epoch_incidents/' . $incident['incident_id'] . '.json', 'incident_hash' => $incident['record_hash'], 'resolution_ref' => 'official_epoch_incident_resolutions/' . $resolution['resolution_id'] . '.json', 'resolution_hash' => $resolution['record_hash'], 'cg_authorization_ref' => 'approval/fixture', 'cg_authorization_hash' => 'sha256:' . str_repeat('6', 64)]);
    $assert(($store->commitTransition('RESUME', $resume)['accepted'] ?? true) === false, 'resume_requires_verified_recovery_package');
    $end = $store->transitionRecord('END', ['epoch_id' => $epochId, 'from_state' => 'SUSPENDED', 'predecessor_state_hash' => $suspended['state_hash'], 'transition_at' => '2026-10-12T16:05:00+09:00', 'incident_hash' => $incident['record_hash'], 'cg_authorization_ref' => 'approval/fixture', 'cg_authorization_hash' => 'sha256:' . str_repeat('6', 64)]);
    $assert(($store->commitTransition('END', $end)['accepted'] ?? false) === true, 'suspended_to_ended');
    $ended = $store->project($epochId); $assert(($ended['state'] ?? '') === 'ENDED', 'ended_terminal_projection');
    $afterEnd = $store->transitionRecord('RESUME', ['epoch_id' => $epochId, 'from_state' => 'ENDED', 'predecessor_state_hash' => $ended['state_hash'], 'transition_at' => '2026-10-12T16:06:00+09:00', 'incident_hash' => $incident['record_hash'], 'resolution_hash' => $resolution['record_hash'], 'cg_authorization_hash' => 'sha256:' . str_repeat('6', 64)]);
    $assert(($store->commitTransition('RESUME', $afterEnd)['accepted'] ?? true) === false, 'ended_epoch_cannot_resume');
    $assert(($store->commitTransition('END', $end)['status'] ?? '') === 'OFFICIAL_EPOCH_END_ALREADY_EXISTS', 'transition_idempotent');
    $stale = $store->transitionRecord('SUSPEND', ['epoch_id' => $epochId, 'from_state' => 'ACTIVE', 'predecessor_state_hash' => 'sha256:' . str_repeat('0', 64), 'transition_at' => '2026-10-12T16:07:00+09:00', 'incident_hash' => $incident['record_hash']]);
    $assert(($store->commitTransition('SUSPEND', $stale)['reason'] ?? '') === 'OFFICIAL_EPOCH_STATE_CAS_CONFLICT', 'stale_state_cas_blocks');
    $assert((OfficialEpochPolicyV1::definition()['incident_policy_identity'] ?? '') === OfficialEpochIncidentPolicyV1::identity(), 'epoch_policy_binds_incident_policy');
    $assert(OfficialEpochPolicyV1::definition()['production_prediction_routing_changes'] === false, 'no_production_routing_change');
} finally { $remove($tmp); }

echo json_encode(['status' => $failures === [] ? 'PASS' : 'FAIL', 'fixture_total' => $total, 'fixture_pass' => $total - count($failures), 'fixture_fail' => count($failures), 'failures' => $failures, 'real_epoch_records_created' => false], JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
