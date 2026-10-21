<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/FormalEvaluatorV2.php';

const EVAL_V2_WINDOW = 'successor-v3-prepared-20261007071142-f2fe811e';
const EVAL_V2_SOURCE = __DIR__ . '/../var/strict_forward_live_readiness/windows/' . EVAL_V2_WINDOW;

function ev2Assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException('ASSERTION_FAILED: ' . $message); }
function ev2Json(string $path): array { $row = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); return is_array($row) ? $row : []; }
function ev2WriteJson(string $path, array $row): void { file_put_contents($path, json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"); }
function ev2CopyTree(string $source, string $destination): void { if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) throw new RuntimeException('fixture_mkdir'); foreach (scandir($source) ?: [] as $name) { if ($name === '.' || $name === '..') continue; $from=$source.'/'.$name; $to=$destination.'/'.$name; if (is_dir($from)) ev2CopyTree($from,$to); elseif (!copy($from,$to)) throw new RuntimeException('fixture_copy'); } }
function ev2Remove(string $path): void { if (!file_exists($path)) return; if (is_dir($path) && !is_link($path)) { foreach (scandir($path) ?: [] as $name) if ($name!=='.'&&$name!=='..') ev2Remove($path.'/'.$name); rmdir($path); } else unlink($path); }
function ev2TreeHash(string $path): string { $rows=[]; foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS)) as $file) if($file->isFile()) { $rel=substr($file->getPathname(),strlen($path)+1); $rows[]=$rel."\0".hash_file('sha256',$file->getPathname()); } sort($rows,SORT_STRING); return 'sha256:'.hash('sha256',implode("\n",$rows)); }
function ev2First(string $pattern): string { $all=glob($pattern) ?: []; if ($all===[]) throw new RuntimeException('fixture_file_missing:'.$pattern); sort($all,SORT_STRING); return $all[0]; }
function ev2Sort(mixed $value): mixed { if(!is_array($value)) return $value; if(array_is_list($value)) return array_map(fn(mixed $v):mixed=>ev2Sort($v),$value); ksort($value,SORT_STRING); foreach($value as $key=>$item)$value[$key]=ev2Sort($item); return $value; }
function ev2DecisionHash(array $decision): string { unset($decision['decision_hash'],$decision['record_hash']); return 'sha256:'.hash('sha256',json_encode(ev2Sort($decision),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); }

/** @return array{root:string,repo:string,window:string,context:FormalMeasurementWindowContext} */
function ev2Fixture(): array
{
    $root = sys_get_temp_dir() . '/boatrace_formal_evaluator_v2_' . bin2hex(random_bytes(6));
    $repo = $root . '/repo';
    $window = $repo . '/var/strict_forward_live_readiness/windows/' . EVAL_V2_WINDOW;
    ev2CopyTree(EVAL_V2_SOURCE, $window);
    $sourceRoot = dirname(__DIR__);
    foreach (['web/logic/FormalEvaluatorV2.php', 'web/logic/FormalEvaluationRecordStore.php', 'analysis/evaluate_formal_measurement_v2.php'] as $relative) {
        $target = $repo . '/' . $relative;
        if (!is_dir(dirname($target))) mkdir(dirname($target), 0775, true);
        copy($sourceRoot . '/' . $relative, $target);
    }
    $lifecycle = $repo . '/var/strict_forward_live_readiness/lifecycle/closures';
    if (!is_dir($lifecycle)) mkdir($lifecycle, 0775, true);
    copy($sourceRoot . '/var/strict_forward_live_readiness/lifecycle/closures/' . EVAL_V2_WINDOW . '.json', $lifecycle . '/' . EVAL_V2_WINDOW . '.json');
    $pointer = $repo . '/var/strict_forward_live_readiness/formal_measurement_window_context.json';
    if (!is_dir(dirname($pointer))) mkdir(dirname($pointer), 0775, true);
    copy($sourceRoot . '/var/strict_forward_live_readiness/formal_measurement_window_context.json', $pointer);
    return ['root'=>$root, 'repo'=>$repo, 'window'=>$window, 'context'=>FormalMeasurementWindowContext::fromWindowRoot($repo,$window)];
}

/** @param callable(array{root:string,repo:string,window:string,context:FormalMeasurementWindowContext}):void|null $mutate */
function ev2Run(?callable $mutate = null, string $asOf = '2026-10-08T13:10:00+09:00', ?callable $race = null): array
{
    $fixture=ev2Fixture();
    try { if($mutate!==null)$mutate($fixture); $before=ev2TreeHash($fixture['window']); $result=(new FormalEvaluatorV2($fixture['repo'],$race))->evaluate($fixture['context'],$asOf); $after=ev2TreeHash($fixture['window']); return [$fixture,$result,$before,$after]; }
    catch(Throwable $error){ ev2Remove($fixture['root']); throw $error; }
}

$case = 'all';
foreach ($argv as $argument) if (str_starts_with($argument, '--case=')) $case = substr($argument, 7);
$run = static fn(string $name): bool => $case === 'all' || $case === $name;
$results=[]; $fixtures=[];
try {
    if ($run('normal')) {
        [$base,$good,$before,$after]=ev2Run(); $fixtures[]=$base;
        ev2Assert($good['ok'] && $good['decision']['gate_a1']['status']==='PASS' && $good['decision']['gate_a2']['status']==='PASS', 'A1/A2 normal PASS');
        ev2Assert($good['decision']['gate_b_now']['status']==='PASS' && $good['decision']['gate_b_final']['status']==='PASS', 'B normal PASS');
        ev2Assert($good['decision']['gate_c']['status']==='PASS' && $good['decision']['window_completion_status']==='PASS' && $before===$after, 'C/window pure normal PASS');
        $one=(new FormalEvaluatorV2($base['repo']))->persist($good); $two=(new FormalEvaluatorV2($base['repo']))->persist($good);
        ev2Assert($one['accepted'] && $two['status']==='FORMAL_EVALUATION_ALREADY_EXISTS','record idempotent');
        $different=$good; $different['decision']['gate_c']['reason']='FIXTURE_DIFFERENT'; $different['decision']['decision_hash']=ev2DecisionHash($different['decision']); $conflict=(new FormalEvaluatorV2($base['repo']))->persist($different);
        ev2Assert(!$conflict['accepted'] && $conflict['reason']==='BLOCKED_EVALUATOR_NONDETERMINISM','record conflict');
        $again=(new FormalEvaluatorV2($base['repo']))->evaluate($base['context'],'2026-10-08T13:10:00+09:00');
        ev2Assert($again['decision']['decision_hash']===$good['decision']['decision_hash'] && !isset($good['decision']['evaluated_at']),'decision hash excludes wall clock'); $results[]='normal_immutability_record_idempotency';
    }
    if ($run('a1')) {
        [$missing,$r]=ev2Run(function(array $f):void { unlink(ev2First($f['window'].'/opportunity/candidates/20261007/*.json')); }); $fixtures[]=$missing; ev2Assert($r['decision']['gate_a1']['status']==='FAIL','A1 missing');
        [$excess,$r]=ev2Run(function(array $f):void { $p=ev2First($f['window'].'/opportunity/candidates/20261007/*.json'); $x=ev2Json($p); $x['target_date']='2026-10-08'; ev2WriteJson($f['window'].'/opportunity/candidates/20261007/excess.json',$x); }); $fixtures[]=$excess; ev2Assert($r['decision']['gate_a1']['status']==='FAIL','A1 excess');
        [$cutoff,$r]=ev2Run(function(array $f):void { $p=ev2First($f['window'].'/opportunity/candidates/20261007/*.json'); $x=ev2Json($p); $x['cutoff_timestamp']='2026-10-07T23:59:00+09:00'; ev2WriteJson($p,$x); }); $fixtures[]=$cutoff; ev2Assert($r['decision']['gate_a1']['status']==='FAIL','A1 cutoff'); $results[]='a1_missing_excess_cutoff';
    }
    if ($run('a2')) {
        [$revision,$r]=ev2Run(function(array $f):void { $p=$f['window'].'/opportunity/schedule_revisions/20261007/fixture.jsonl'; file_put_contents($p,json_encode(['revision_type'=>'SEMANTIC_REVISION_UNVERIFIED','observed_at'=>'2026-10-07T09:00:00+09:00'])."\n"); }); $fixtures[]=$revision; ev2Assert($r['decision']['gate_a2']['status']==='FAIL','A2 revision');
        [$failure,$r]=ev2Run(function(array $f):void { file_put_contents($f['window'].'/opportunity/source_failures/20261007.jsonl',json_encode(['observed_at'=>'2026-10-07T09:00:00+09:00','event'=>'FIXTURE'])."\n",FILE_APPEND); }); $fixtures[]=$failure; ev2Assert($r['decision']['gate_a2']['status']==='FAIL','A2 source failure');
        [$unverified,$r]=ev2Run(function(array $f):void { $p=ev2First($f['window'].'/opportunity/candidates/20261007/*.json'); $x=ev2Json($p); unset($x['source_identity']); ev2WriteJson($p,$x); }); $fixtures[]=$unverified; ev2Assert($r['decision']['gate_a2']['status']==='UNVERIFIED_SOURCE_ORDER','causal unverified');
        [$reversed,$r]=ev2Run(function(array $f):void { $p=ev2First($f['window'].'/opportunity/candidates/20261007/*.json'); $x=ev2Json($p); $x['source_generated_at']='2026-10-07T09:00:00+09:00'; $x['universe_recorded_at']='2026-10-07T08:00:00+09:00'; ev2WriteJson($p,$x); }); $fixtures[]=$reversed; ev2Assert($r['decision']['gate_a2']['status']==='BLOCKED_SOURCE_ORDER','causal reversed'); $results[]='a2_revision_failure_causal_bundle';
    }
    if ($run('b')) {
        [$future,$r]=ev2Run(null,'2026-10-07T12:00:00+09:00'); $fixtures[]=$future; ev2Assert($r['decision']['gate_b_now']['status']==='PASS' && $r['decision']['gate_b_final']['status']==='PENDING_LIVE' && $r['decision']['operational_gate_c_status']==='PASS' && $r['decision']['window_completion_status']==='PENDING','B/C separation');
        [$unresolved,$r]=ev2Run(function(array $f):void { $p=ev2First($f['window'].'/opportunity/eligibility/20261007/*.json'); $x=ev2Json($p); $x['eligibility_status']='ELIGIBILITY_UNRESOLVED'; ev2WriteJson($p,$x); }); $fixtures[]=$unresolved; ev2Assert($r['decision']['gate_b_now']['status']==='FAIL','B unresolved');
        [$artifact,$r]=ev2Run(function(array $f):void { $p=ev2First($f['window'].'/ledger/records/20261007/*.json'); $x=ev2Json($p); $x['capture_status']='ARTIFACT_ERROR'; ev2WriteJson($p,$x); }); $fixtures[]=$artifact; ev2Assert($r['decision']['gate_c']['status']==='FAIL','artifact rejected'); $results[]='b_now_final_gate_c_artifact';
    }
    if ($run('c')) {
        $provenanceBlocked=false; try { ev2Run(function(array $f):void { $p=ev2First($f['window'].'/ledger/records/20261007/*.json'); $x=ev2Json($p); $x['measurement_implementation_identity']='sha256:'.str_repeat('0',64); ev2WriteJson($p,$x); }); } catch (RuntimeException $error) { $provenanceBlocked=str_starts_with($error->getMessage(),'aggregate_referenced_provenance_invalid:'); } ev2Assert($provenanceBlocked,'provenance aggregate fail-closed');
        [$nondeploy,$r]=ev2Run(function(array $f):void { $p=ev2First($f['window'].'/ledger/records/20261007/*.json'); $x=ev2Json($p); $x['capture_status']='ML_COURSE_NOT_DEPLOYED'; $x['eligible']=true; $x['formal_candidate']=true; ev2WriteJson($p,$x); }); $fixtures[]=$nondeploy; ev2Assert($r['decision']['gate_c']['status']==='FAIL','nondeploy cannot be accepted');
        [$one,$r]=ev2Run(function(array $f):void { foreach(glob($f['window'].'/opportunity/eligibility/20261007/*.json')?:[] as $p) { $x=ev2Json($p); if(($x['race_code']??'')!=='20261007TMG01') { $x['eligibility_status']='VALID_NON_ELIGIBLE_EXHIBITION_INCOMPLETE'; ev2WriteJson($p,$x); } } }); $fixtures[]=$one; ev2Assert($r['decision']['gate_c']['status']==='PENDING_LIVE','one distinct eligible pending'); $results[]='gate_c_provenance_nondeploy_and_distinct_minimum';
    }
    if ($run('race')) {
        $raceFixture=ev2Fixture(); $fixtures[]=$raceFixture;
        $r=(new FormalEvaluatorV2($raceFixture['repo'],function() use ($raceFixture):void { $p=ev2First($raceFixture['window'].'/opportunity/candidates/20261007/*.json'); $x=ev2Json($p); $x['fixture_snapshot_race']=true; ev2WriteJson($p,$x); }))->evaluate($raceFixture['context'],'2026-10-08T13:10:00+09:00');
        ev2Assert(!$r['ok'] && $r['status']==='EVIDENCE_SNAPSHOT_RACE','snapshot race'); $results[]='evidence_snapshot_race';
    }
    if ($results===[]) throw new RuntimeException('unknown_fixture_case');
    echo json_encode(['status'=>'PASS','case'=>$case,'checks'=>$results,'count'=>count($results)],JSON_UNESCAPED_SLASHES).PHP_EOL;
} finally { foreach($fixtures as $fixture) ev2Remove($fixture['root']); }
