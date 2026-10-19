<?php

declare(strict_types=1);

require_once __DIR__ . '/../web/logic/FormalEvidenceAggregate.php';

const AGG_V2_WINDOW = 'successor-v3-prepared-20261010215741-f5098693';
const AGG_V2_SOURCE = __DIR__ . '/../var/strict_forward_live_readiness/windows/' . AGG_V2_WINDOW;

function av2Assert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException('ASSERTION_FAILED:' . $message); }
function av2Json(string $path): array { $row=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR); return is_array($row)?$row:[]; }
function av2Write(string $path, array $row): void { file_put_contents($path, json_encode($row, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n"); }
function av2Copy(string $source, string $destination): void { if(!is_dir($destination)&&!mkdir($destination,0775,true)&&!is_dir($destination))throw new RuntimeException('fixture_mkdir'); foreach(scandir($source)?:[] as $n){if($n==='.'||$n==='..')continue;$a=$source.'/'.$n;$b=$destination.'/'.$n;if(is_dir($a))av2Copy($a,$b);elseif(!copy($a,$b))throw new RuntimeException('fixture_copy');} }
function av2Remove(string $path): void { if(!file_exists($path))return;if(is_dir($path)&&!is_link($path)){foreach(scandir($path)?:[] as $n)if($n!=='.'&&$n!=='..')av2Remove($path.'/'.$n);rmdir($path);}else unlink($path); }
function av2First(string $pattern): string { $rows=glob($pattern)?:[];sort($rows,SORT_STRING);if($rows===[])throw new RuntimeException('fixture_missing:'.$pattern);return $rows[0]; }

/** @return array{base:string,window:string} */
function av2Fixture(): array
{
    $base=sys_get_temp_dir().'/boatrace_aggregate_v2_'.bin2hex(random_bytes(6));
    $window=$base.'/repo/var/strict_forward_live_readiness/windows/'.AGG_V2_WINDOW;
    av2Copy(AGG_V2_SOURCE,$window);
    return ['base'=>$base,'window'=>$window];
}

/** @param callable(array{base:string,window:string}):void|null $mutate */
function av2Run(?callable $mutate=null): array
{
    $f=av2Fixture();
    try { if($mutate!==null)$mutate($f); return [$f,FormalEvidenceAggregate::calculate($f['window'],AGG_V2_WINDOW,'2026-10-11')]; }
    catch(Throwable $e){ av2Remove($f['base']); throw $e; }
}

/** @param callable(array{base:string,window:string}):void $mutate */
function av2Fails(callable $mutate, string $prefix): void
{
    try { [$f,$result]=av2Run($mutate); av2Remove($f['base']); throw new RuntimeException('fixture_expected_failure:'.$prefix); }
    catch(RuntimeException $e){
        if(str_starts_with($e->getMessage(),'fixture_expected_failure:')) throw $e;
        av2Assert(str_starts_with($e->getMessage(),$prefix), 'expected '.$prefix.' got '.$e->getMessage());
    }
}

$fixtures=[];
try {
    [$normal,$aggregate]=av2Run();$fixtures[]=$normal;
    $resolution=$aggregate['provenance_resolution']??[];
    av2Assert(($aggregate['schema_version']??'')==='FORMAL_EVIDENCE_AGGREGATE_V2','v2 schema');
    av2Assert(($resolution['snapshot_resolution']['count']??0)===48 && ($resolution['pair_resolution']['count']??0)===504 && ($resolution['completion_resolution']['count']??0)===48,'resolved counts');
    av2Assert(($resolution['source_freeze']['frozen_source_file_count']??0)===43,'all frozen source hashes');
    av2Remove($normal['base']);array_pop($fixtures);

    av2Fails(function(array $f):void { $p=av2First($f['window'].'/input_snapshots/*/*.json');$r=av2Json($p);$r['window_id']='other-window';av2Write($p,$r); },'PROVENANCE_SNAPSHOT_WINDOW_OR_KEY_INVALID');
    av2Fails(function(array $f):void { $p=av2First($f['window'].'/comparative/pairs/*/*.json');$r=av2Json($p);$r['window_id']='other-window';av2Write($p,$r); },'PROVENANCE_PAIR_WINDOW_OR_KEY_INVALID');
    av2Fails(function(array $f):void { $p=av2First($f['window'].'/input_snapshots/*/*.json');$r=av2Json($p);$r['race_code']='20261012TDA01';av2Write($p,$r); },'PROVENANCE_SNAPSHOT_ELIGIBILITY_INVALID');
    av2Fails(function(array $f):void { $p=av2First($f['window'].'/activation_record.json');$r=av2Json($p);$r['implementation_identity']='sha256:'.str_repeat('0',64);av2Write($p,$r); },'aggregate_referenced_provenance_invalid');
    av2Fails(function(array $f):void { $p=av2First($f['window'].'/comparative/pairs/*/*.json');$r=av2Json($p);$r['comparative_scope_identity']='sha256:'.str_repeat('1',64);av2Write($p,$r); },'PROVENANCE_PAIR_IDENTITY_MISMATCH');
    av2Fails(function(array $f):void { $p=av2First($f['window'].'/comparative/pairs/*/*.json');$r=av2Json($p);$r['comparative_policy_identity']='sha256:'.str_repeat('2',64);av2Write($p,$r); },'PROVENANCE_PAIR_IDENTITY_MISMATCH');
    // The Window deliberately retains one later failed-close operational
    // source-freeze artifact.  Tamper with the declaration-bound capture
    // freeze rather than whichever filename sorts first.
    av2Fails(function(array $f):void { $d=av2Json($f['window'].'/declared_implementation_identity.json');$p=$f['window'].'/provenance/'.(string)$d['source_freeze_ref'];$r=av2Json($p);$r['files'][0]['content_base64']='WA==';av2Write($p,$r); },'aggregate_referenced_provenance_invalid');
    av2Fails(function(array $f):void { $p=$f['window'].'/declared_implementation_identity.json';$r=av2Json($p);$r['manifest_sha256']='sha256:'.str_repeat('3',64);av2Write($p,$r); },'aggregate_implementation_reference_conflict');
    av2Fails(function(array $f):void { $p=av2First($f['window'].'/comparative/captures/*/*.json');$r=av2Json($p);$r['snapshot_reference']='input_snapshots/20261011/not-the-real-file.json';av2Write($p,$r); },'PROVENANCE_COMPLETION_SNAPSHOT_OR_CONTRACT_INVALID');
    av2Fails(function(array $f):void { $p=av2First($f['window'].'/comparative/pairs/*/*.json');$r=av2Json($p);$r['snapshot_reference']='input_snapshots/20261011/not-the-real-file.json';av2Write($p,$r); },'PROVENANCE_PAIR_SNAPSHOT_REFERENCE_INVALID');

    // Neither a different current pointer nor changed latest config is below
    // the explicit Window root, so both must leave its aggregate identical.
    [$historical,$one]=av2Run();$fixtures[]=$historical;
    $pointer=$historical['base'].'/repo/var/strict_forward_live_readiness/formal_measurement_window_context.json';
    if(!is_dir(dirname($pointer)))mkdir(dirname($pointer),0775,true);
    file_put_contents($pointer,'{"window_id":"different-window"}');
    $config=$historical['base'].'/repo/config/strict_forward_comparative_scope_v1.json';
    if(!is_dir(dirname($config)))mkdir(dirname($config),0775,true);
    file_put_contents($config,'{"latest":"changed"}');
    $two=FormalEvidenceAggregate::calculate($historical['window'],AGG_V2_WINDOW,'2026-10-11');
    av2Assert(($one['aggregate_sha256']??'')===($two['aggregate_sha256']??''),'historical pointer/config independence');

    echo json_encode(['status'=>'PASS','checks'=>[
        'normal_frozen_dereference','cross_window_snapshot','cross_window_pair','different_day_race','activation_identity','scope_identity','policy_identity','source_freeze','manifest_hash','completion_snapshot','pair_snapshot','historical_pointer_and_latest_config_independence',
    ],'count'=>12],JSON_UNESCAPED_SLASHES).PHP_EOL;
} finally { foreach($fixtures as $f) av2Remove($f['base']); }
