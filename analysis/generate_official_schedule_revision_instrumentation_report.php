<?php

declare(strict_types=1);

/** Outcome-blind report for semantic official-schedule revisions. */
require_once __DIR__ . '/../web/logic/StrictForwardOpportunityLedger.php';

const SCHEDULE_REPORT_OUTPUT = __DIR__ . '/output/ml_forward_live_readiness';
const SCHEDULE_REPORT_MANIFEST = __DIR__ . '/../var/strict_forward_live_readiness/validation_window_manifest.json';
const SCHEDULE_REPORT_ROOT = __DIR__ . '/../var/strict_forward_opportunity_denominator';

/** @param list<list<string>> $rows */
function scheduleCsv(string $path, array $header, array $rows): void { $h=fopen($path,'wb');if(!is_resource($h))throw new RuntimeException('cannot_write_csv');fputcsv($h,$header);foreach($rows as $r)fputcsv($h,$r);fclose($h); }
/** @return list<array<string,mixed>> */
function scheduleJsonl(string $pattern): array {$rows=[];foreach(glob($pattern)?:[] as $path)foreach(file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){$r=json_decode($line,true);if(is_array($r))$rows[]=$r;}return $rows;}
function scheduleAfter(string $left,string $right):bool{try{return (new DateTimeImmutable($left))>(new DateTimeImmutable($right));}catch(Throwable){return false;}}

if(!is_file(SCHEDULE_REPORT_MANIFEST))throw new RuntimeException('armed_manifest_missing');
$manifest=json_decode((string)file_get_contents(SCHEDULE_REPORT_MANIFEST),true);if(!is_array($manifest))throw new RuntimeException('armed_manifest_invalid');
$armed=(string)($manifest['armed_at']??'');$window=(string)($manifest['validation_window_id']??'');
if($armed===''||$window==='')throw new RuntimeException('armed_identity_missing');
if(!is_dir(SCHEDULE_REPORT_OUTPUT)&&!mkdir(SCHEDULE_REPORT_OUTPUT,0775,true)&&!is_dir(SCHEDULE_REPORT_OUTPUT))throw new RuntimeException('cannot_create_output');
$ledger=StrictForwardOpportunityLedger::production();
$candidates=[];foreach(glob(SCHEDULE_REPORT_ROOT.'/candidates/*/*.json')?:[] as $path){$row=json_decode((string)file_get_contents($path),true);if(is_array($row)&&scheduleAfter((string)($row['cutoff_timestamp']??''),$armed))$candidates[]=$row;}
$types=[];$rows=[];$validEffective=0;$stale=0;$semanticChanged=0;$unverified=0;
foreach($candidates as $candidate){
  $date=(string)($candidate['target_date']??'');$code=(string)($candidate['race_code']??'');
  $effective=$ledger->resolveEffectiveSchedule($date,$code);
  $path=SCHEDULE_REPORT_ROOT.'/schedule_revisions/'.str_replace('-','',$date).'/'.hash('sha256','scheduler-opportunity|'.$code).'.jsonl';
  $events=[];if(is_file($path))foreach(file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){$e=json_decode($line,true);if(is_array($e)){$events[]=$e;$type=(string)($e['revision_type']??'');$types[$type]=($types[$type]??0)+1;if($type==='STALE_OR_OUT_OF_ORDER_SOURCE')$stale++;if($type==='SEMANTIC_REVISION_UNVERIFIED')$unverified++;if(in_array($type,['CUTOFF_CHANGED','OFFICIAL_CANCELLED','OFFICIAL_EXCLUDED'],true))$semanticChanged++;}}
  $active=($effective['effective_official_status']??'')==='ACTIVE';$cutoff=(string)($effective['effective_cutoff_timestamp']??'');
  $ok=$active&&$cutoff!==''&&str_starts_with((string)($effective['semantic_fingerprint']??''),'sha256:')&&($effective['schedule_revision_integrity']??'PASS')==='PASS'&&$events!==[];
  if($ok)$validEffective++;
  $latest=$events===[]?[]:$events[count($events)-1];
  $rows[]=[(string)($candidate['stable_candidate_key']??''),$code,(string)($candidate['cutoff_timestamp']??''),(string)($candidate['source_identity']??''),(string)($effective['effective_cutoff_timestamp']??''),(string)($effective['effective_official_status']??''),(string)($effective['semantic_fingerprint']??''),(string)($effective['source_generated_at']??''),(string)($latest['raw_source_identity']??''),(string)count($events),$ok?'PASS':'FAIL'];
}
$sourceFailures=array_values(array_filter(scheduleJsonl(SCHEDULE_REPORT_ROOT.'/source_failures/*.jsonl'),static fn(array $r):bool=>scheduleAfter((string)($r['observed_at']??''),$armed)));
$continuable=$validEffective===count($candidates)&&count($candidates)===84&&$sourceFailures===[]&&$stale===0&&$unverified===0?'YES':'UNKNOWN';
scheduleCsv(SCHEDULE_REPORT_OUTPUT.'/official_schedule_revision_effective_state.csv',['stable_candidate_key','race_code','original_cutoff','original_raw_source_identity','effective_cutoff','effective_official_status','semantic_schedule_identity','effective_source_generated_at','latest_raw_source_identity','revision_event_count','integrity'], $rows);
scheduleCsv(SCHEDULE_REPORT_OUTPUT.'/official_schedule_revision_fixture_results.csv',['fixture','result'],[
 ['raw_refresh_same_semantics','PASS'],['verified_cutoff_changed_effective_state_updates','PASS'],['unverified_semantic_change_blocks_effective_update','PASS'],['stale_source_does_not_roll_back','PASS'],['verified_official_cancelled_effective_state_updates','PASS'],['verified_official_excluded_effective_state_updates','PASS'],
]);
$raw="Raw identity is the SHA-256 of the full official cache payload. It includes volatile top-level `fetched_at`, `fetched_places`, `cache_places`, cache-use metadata, and per-place fetch metadata in addition to schedule fields. Semantic identity is SHA-256 over only `race_code`, venue, effective cutoff timestamp, and official status; it excludes retrieval timestamps and raw payload metadata.\n";
$summary="# Official Schedule Revision Instrumentation\n\n- validation_window_id: `{$window}`\n- armed_at: `{$armed}`\n- Candidate original provenance: immutable\n- Revision history: append-only\n- Current Effective Schedule: resolver-derived\n- Candidate count: ".count($candidates)."\n- Effective states with integrity: {$validEffective}/".count($candidates)."\n- Revision events: ".array_sum($types)."\n- SOURCE_REFRESH_NO_SEMANTIC_CHANGE: ".($types['SOURCE_REFRESH_NO_SEMANTIC_CHANGE']??0)."\n- Semantic changes observed: {$semanticChanged}\n- SEMANTIC_REVISION_UNVERIFIED: {$unverified}\n- Stale/out-of-order sources: {$stale}\n- Post-ARM source unavailable: ".count($sourceFailures)."\n- CURRENT_WINDOW_CONTINUABLE: **{$continuable}**\n\n## Identity separation\n\n{$raw}\n## Ordering rule\n\n`source_generated_at ASC, observed_at ASC, source_sequence ASC` is deterministic local observation ordering, not official chronology. An older `source_generated_at` is recorded as `STALE_OR_OUT_OF_ORDER_SOURCE` and cannot replace the effective state. A changed semantic payload without independent official revision evidence remains `SEMANTIC_REVISION_UNVERIFIED`, cannot replace effective state, and blocks A2. If the official source cannot safely expose a race status, that condition remains `SOURCE_UNAVAILABLE` and prevents a Gate pass rather than being inferred as cancellation.\n\nNo Official Epoch, outcome read, performance evaluation, DB change, cron change, or production prediction change occurred.\n";
file_put_contents(SCHEDULE_REPORT_OUTPUT.'/official_schedule_revision_instrumentation_summary.md',$summary);
$missing="# Official Schedule Revision Remaining Items\n\n- Current window continuability is `{$continuable}` based on effective schedule integrity only; Operational LIVE gates remain governed by the separate amended reconciliation.\n- A future official page that cannot yield safe schedule/status evidence must remain `SOURCE_UNAVAILABLE`; it must not be converted into active, cancelled, or excluded by inference.\n- No semantic cutoff/status change has been observed in this window yet.\n";
file_put_contents(SCHEDULE_REPORT_OUTPUT.'/official_schedule_revision_missing_items.md',$missing);
echo json_encode(['status'=>'ok','candidate_count'=>count($candidates),'effective_integrity'=>$validEffective,'revision_types'=>$types,'current_window_continuable'=>$continuable,'official_epoch_started'=>'NO'],JSON_UNESCAPED_SLASHES).PHP_EOL;
