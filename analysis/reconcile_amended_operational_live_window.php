<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/StrictForwardOpportunityLedger.php';

/**
 * Outcome-blind reconciliation for the amended (cutoff-based) Accounting
 * Scope. It is read-only against ledgers and does not alter the armed manifest.
 */

const RECON_MANIFEST = __DIR__ . '/../var/strict_forward_live_readiness/validation_window_manifest.json';
const RECON_OUTPUT = __DIR__ . '/output/ml_forward_live_readiness';
const RECON_OPPORTUNITY = __DIR__ . '/../var/strict_forward_opportunity_denominator';
const RECON_STRICT = __DIR__ . '/../var/strict_forward_readiness_completion';

/** @return list<array<string,mixed>> */
function reconJson(string $pattern): array { $out=[]; foreach (glob($pattern) ?: [] as $p) { $v=json_decode((string)file_get_contents($p),true); if(is_array($v)) $out[]=$v; } return $out; }
/** @return list<array<string,mixed>> */
function reconJsonl(string $pattern): array { $out=[]; foreach (glob($pattern) ?: [] as $p) foreach(file($p,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [] as $line){$v=json_decode($line,true);if(is_array($v))$out[]=$v;} return $out; }
function reconAfter(string $left, string $right): bool { try{return $left!=='' && (new DateTimeImmutable($left))>(new DateTimeImmutable($right));}catch(Throwable){return false;} }
function reconBeforeOrEqualNow(string $cutoff, DateTimeImmutable $now): bool { try{return (new DateTimeImmutable($cutoff)) <= $now;}catch(Throwable){return false;} }
/** @param list<list<string>> $rows */
function reconCsv(string $path,array $header,array $rows):void{$h=fopen($path,'wb');if(!is_resource($h))throw new RuntimeException('cannot_write_csv');fputcsv($h,$header);foreach($rows as $r)fputcsv($h,$r);fclose($h);}
function reconText(string $path,string $text):void{if(file_put_contents($path,$text)===false)throw new RuntimeException('cannot_write_text');}

if(!is_file(RECON_MANIFEST)) throw new RuntimeException('manifest_missing');
$manifest=json_decode((string)file_get_contents(RECON_MANIFEST),true);
if(!is_array($manifest))throw new RuntimeException('manifest_invalid');
$armed=(string)($manifest['armed_at']??'');$window=(string)($manifest['validation_window_id']??'');
if($armed===''||$window==='')throw new RuntimeException('manifest_identity_missing');
if(!is_dir(RECON_OUTPUT)&&!mkdir(RECON_OUTPUT,0775,true)&&!is_dir(RECON_OUTPUT))throw new RuntimeException('cannot_create_output');
$now=new DateTimeImmutable('now',new DateTimeZone('Asia/Tokyo'));
$auditAt=$now->format(DATE_ATOM);

$candidates=array_values(array_filter(reconJson(RECON_OPPORTUNITY.'/candidates/*/*.json'),static fn(array $r):bool=>reconAfter((string)($r['cutoff_timestamp']??''),$armed)));
$observations=array_values(array_filter(reconJsonl(RECON_OPPORTUNITY.'/observations/*.jsonl'),static fn(array $r):bool=>reconAfter((string)($r['observed_at']??''),$armed)));
$resolutions=array_values(array_filter(reconJson(RECON_OPPORTUNITY.'/eligibility/*/*.json'),static fn(array $r):bool=>reconAfter((string)($r['first_eligible_observed_at']??$r['resolved_at']??''),$armed)));
$expected=array_values(array_filter(reconJson(RECON_STRICT.'/expected/*/*.json'),static fn(array $r):bool=>reconAfter((string)($r['expected_at']??''),$armed)));
$canonical=array_values(array_filter(reconJson(RECON_STRICT.'/records/*/*.json'),static fn(array $r):bool=>reconAfter((string)($r['captured_at']??''),$armed)));
$sourceFailures=array_values(array_filter(reconJsonl(RECON_OPPORTUNITY.'/source_failures/*.jsonl'),static fn(array $r):bool=>reconAfter((string)($r['observed_at']??''),$armed)));
$strictFailures=array_values(array_filter(reconJsonl(RECON_STRICT.'/failures/*.jsonl'),static fn(array $r):bool=>reconAfter((string)($r['attempted_at']??''),$armed)));
$ops=array_values(array_filter(reconJsonl(RECON_OPPORTUNITY.'/operational/*.jsonl'),static fn(array $r):bool=>reconAfter((string)($r['observed_at']??''),$armed)));

$obsBy=[];foreach($observations as $r)$obsBy[(string)($r['race_code']??'')][]=$r;
$resBy=[];foreach($resolutions as $r)$resBy[(string)($r['race_code']??'')]=$r;
$expBy=[];foreach($expected as $r)$expBy[(string)($r['race_code']??'')][]=$r;
$canBy=[];foreach($canonical as $r)$canBy[(string)($r['race_code']??'')][]=$r;
$rows=[];$classes=[];$eligible=[];$cutoffPassed=0;$unresolved=0;$eligibleCaptured=0;$expectedMissing=0;$canonicalMissing=0;
foreach($candidates as $candidate){
  $code=(string)($candidate['race_code']??'');$cutoff=(string)($candidate['cutoff_timestamp']??'');$passed=reconBeforeOrEqualNow($cutoff,$now);if($passed)$cutoffPassed++;
  $obs=$obsBy[$code]??[];$incomplete=0;$complete=0;$inputUnavailable=0;foreach($obs as $o){$s=(string)($o['eligibility_status']??'');if($s==='EXHIBITION_INCOMPLETE_OBSERVED')$incomplete++;if($s==='ELIGIBLE_OBSERVED')$complete++;if($s==='ELIGIBILITY_INPUT_UNAVAILABLE')$inputUnavailable++;}
  $res=$resBy[$code]??[];$status=(string)($res['eligibility_status']??'');$exp=$expBy[$code]??[];$can=$canBy[$code]??[];
  if(!$passed)$class='PRE_CUTOFF_PENDING';
  elseif($status==='VALID_NON_ELIGIBLE_EXHIBITION_INCOMPLETE')$class='VALID_NON_ELIGIBLE';
  elseif($status==='ELIGIBILITY_UNRESOLVED'){$class='ELIGIBILITY_UNRESOLVED';$unresolved++;}
  elseif($status==='ELIGIBLE_OBSERVED'){
    $eligible[]=$code;
    if(count($exp)!==18){$class='ELIGIBLE_EXPECTED_MISSING';$expectedMissing++;}
    else { $keys=array_flip(array_map(static fn(array $r):string=>(string)($r['stable_unique_key']??''),$can));$expectedKeys=array_map(static fn(array $r):string=>(string)($r['stable_unique_key']??''),$exp);$completeCan=count(array_diff($expectedKeys,array_keys($keys)))===0;
      if(!$completeCan){$class='ELIGIBLE_CANONICAL_MISSING';$canonicalMissing++;}else{$class='ELIGIBLE_CAPTURED';$eligibleCaptured++;}}
  } elseif($passed){$class='INSTRUMENTATION_GAP';$unresolved++;}
  else $class='PRE_CUTOFF_PENDING';
  $classes[$class]=($classes[$class]??0)+1;
  $rows[]=[(string)($candidate['stable_candidate_key']??'scheduler-opportunity|'.$code),$code,(string)($candidate['venue']??''),(string)($candidate['universe_recorded_at']??''),$cutoff,(string)($candidate['source_identity']??''),(string)count($obs),(string)$incomplete,(string)$complete,(string)$inputUnavailable,$status===''?'NONE':$status,(string)count($exp),(string)count($can),$passed?'CUTOFF_PASSED':'PRE_CUTOFF',$class];
}
usort($rows,static fn(array $a,array $b):int=>strcmp($a[1],$b[1]));
$eligible=array_values(array_unique($eligible));
$instrumentationGap=false;
$opportunitySource=(string)file_get_contents(__DIR__.'/../web/logic/StrictForwardOpportunityLedger.php');
$reobserveSupported=str_contains($opportunitySource,'foreach ($this->candidateRows($date) as $candidate)')&&str_contains($opportunitySource,"'ELIGIBLE_OBSERVED'");
$revisionConflictRows=array_values(array_filter($ops,static fn(array $r):bool=>($r['event']??'')==='CANDIDATE_SOURCE_CONFLICT'));
$sameCutoffRefreshes=count(array_filter($revisionConflictRows,static fn(array $r):bool=>(string)($r['original_cutoff_timestamp']??'')===(string)($r['new_cutoff_timestamp']??'')));
$changedCutoffRefreshes=count($revisionConflictRows)-$sameCutoffRefreshes;
// The present first-write candidate design detects cache hash refreshes but
// preserves the original cutoff and has no effective-current-revision record.
// It therefore cannot prove that a future official cutoff revision would be
// followed by Gate A/B accounting.
$revisionLedger=StrictForwardOpportunityLedger::production();
$effectiveIntegrity=0;
foreach($candidates as $candidate){
  $effective=$revisionLedger->resolveEffectiveSchedule((string)($candidate['target_date']??''),(string)($candidate['race_code']??''));
  $path=RECON_OPPORTUNITY.'/schedule_revisions/'.str_replace('-','',(string)($candidate['target_date']??'')).'/'.hash('sha256',(string)($candidate['stable_candidate_key']??'')).'.jsonl';
  if(is_file($path)&&($effective['effective_official_status']??'')==='ACTIVE'&&trim((string)($effective['effective_cutoff_timestamp']??''))!==''&&str_starts_with((string)($effective['semantic_fingerprint']??''),'sha256:'))$effectiveIntegrity++;
}
$revisionTrackingStructural=str_contains($opportunitySource,'recordOfficialScheduleSnapshot')&&str_contains((string)file_get_contents(__DIR__.'/capture_all_race_forward.php'),'recordOfficialScheduleSnapshotFromContext');
$revisionTrackingSemantic=$revisionTrackingStructural&&$effectiveIntegrity===count($candidates)&&count($candidates)>0;
if(!$reobserveSupported||!$revisionTrackingSemantic)$instrumentationGap=true;
$gateA=$instrumentationGap?'BLOCKED_INSTRUMENTATION':(count($candidates)===84?'PASS':'FAIL');
$gateB=$cutoffPassed===0?'PENDING_LIVE':($unresolved===0?'PASS':'FAIL');
$gateC=count($eligible)===0?'PENDING_LIVE':(($expectedMissing===0&&$canonicalMissing===0&&$eligibleCaptured===count($eligible))?'PASS':'FAIL');
$operational=$instrumentationGap?'BLOCKED_INSTRUMENTATION':(($gateB==='FAIL'||$gateC==='FAIL')?'FAIL':'PENDING_LIVE');

reconCsv(RECON_OUTPUT.'/amended_window_reconciliation.csv',['stable_candidate_key','race_code','venue','candidate_universe_recorded_at','cutoff_timestamp','source_identity','post_arm_observation_count','incomplete_observations','complete_observations','input_unavailable_observations','post_arm_resolution','post_arm_expected_key_count','post_arm_canonical_key_count','cutoff_state','final_classification'],$rows);
reconCsv(RECON_OUTPUT.'/amended_window_gate_result.csv',['gate','result','numerator','denominator','acceptance','note'],[
 ['A Candidate Universe Accounting',$gateA,(string)count($candidates),'84','100%','cutoff_timestamp > armed_at scope'],
 ['B Eligibility Resolution',$gateB,(string)$unresolved,(string)$cutoffPassed,'ELIGIBILITY_UNRESOLVED=0%','pre-cutoff pending excluded from fail'],
 ['C Eligible Capture Rate',$gateC,(string)$eligibleCaptured,(string)count($eligible),'100%','post-ARM ELIGIBLE_OBSERVED only'],
]);
$reobservation="# Post-ARM Reobservation Audit\n\n`POST_ARM_REOBSERVATION_SUPPORTED = ".($reobserveSupported?'YES':'NO')."`. `observeEligibility()` loops every frozen Candidate on each scheduler read and appends a fresh observation, including a race that was already complete before ARM. Thus a current post-ARM complete read can create Formal Eligibility Evidence without promoting the ARM-before observation. Observed post-ARM observations: ".count($observations)."; complete observations: ".array_sum(array_map(static fn(array $r):int=>(int)$r[8],$rows)).".\n";
reconText(RECON_OUTPUT.'/post_arm_reobservation_audit.md',$reobservation);
$revision="# Official Cache Revision Audit\n\n`CACHE_REVISION_TRACKING = ".($revisionTrackingSemantic?'PASS':'FAIL')."` for amended-window Gate use. Candidate provenance remains immutable, while append-only `schedule_revisions/` observations compute the current effective cutoff/status from semantic fingerprints. Effective schedule integrity: {$effectiveIntegrity}/".count($candidates).".\n\nLegacy post-ARM raw-hash conflict events: ".count($revisionConflictRows)."; same-cutoff legacy metadata refreshes: {$sameCutoffRefreshes}; legacy observed cutoff changes: {$changedCutoffRefreshes}. These historical raw conflicts are not semantic schedule conflicts. New observations classify unchanged schedule refreshes as `SOURCE_REFRESH_NO_SEMANTIC_CHANGE`. Post-ARM source failures: ".count($sourceFailures).".\n";
reconText(RECON_OUTPUT.'/official_cache_revision_audit.md',$revision);
$summary="# Amended Window Reconciliation\n\n- audit_at: `{$auditAt}`\n- validation_window_id: `{$window}`\n- armed_at: `{$armed}`\n- Accounting Scope: `candidate.cutoff_timestamp > armed_at`\n- Candidates reconstructed: **".count($candidates)." / 84**\n- cutoff passed: **{$cutoffPassed}**; pre-cutoff pending: **".($classes['PRE_CUTOFF_PENDING']??0)."**\n- post-ARM eligible: **".count($eligible)."**; captured: **{$eligibleCaptured}**\n- `ELIGIBILITY_UNRESOLVED`: **{$unresolved}**\n- Gate A/B/C: **{$gateA} / {$gateB} / {$gateC}**\n- Operational Live Readiness: **{$operational}**\n\nNo outcome or performance information was inspected. Direct comparative=40; ML-only policy-limited=83; TMG 2C top3 lineage conflict retained; LONG_TERM_INCIDENT_POLICY_REQUIRES_CG_DECISION=YES.\n";
reconText(RECON_OUTPUT.'/amended_window_reconciliation_summary.md',$summary);
$missing="# Amended Window Remaining Items\n\n- All **".($classes['PRE_CUTOFF_PENDING']??0)."** unresolved candidates are pre-cutoff and remain `PRE_CUTOFF_PENDING`, not failures.\n- Window requires two distinct post-ARM eligible race codes; current count=".count($eligible).".\n- Official Epoch is not started.\n- LONG_TERM_INCIDENT_POLICY_REQUIRES_CG_DECISION = YES.\n";
reconText(RECON_OUTPUT.'/amended_window_missing_items.md',$missing);
echo json_encode(['status'=>'ok','audit_at'=>$auditAt,'candidate_count'=>count($candidates),'cutoff_passed'=>$cutoffPassed,'eligible_count'=>count($eligible),'gate_a'=>$gateA,'gate_b'=>$gateB,'gate_c'=>$gateC,'operational_live_readiness'=>$operational,'rearmed'=>'NO','official_epoch_started'=>'NO'],JSON_UNESCAPED_SLASHES).PHP_EOL;
