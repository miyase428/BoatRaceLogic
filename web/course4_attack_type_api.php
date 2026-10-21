<?php
declare(strict_types=1);
/** Read-only adapter for the frozen 4C Attack Type Python core. */
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Tokyo');
function apiOut(int $status, array $body): never { http_response_code($status); echo json_encode($body, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
$race = strtoupper(trim((string)($_GET['race_code'] ?? '')));
if (preg_match('/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/', $race) !== 1) apiOut(400,['success'=>false,'error_code'=>'INVALID_RACE_CODE','error_message'=>'race_code is invalid']);
$cutoff=(new DateTimeImmutable('now',new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM);
$root=dirname(__DIR__); $python='/usr/bin/python3';
$capabilityPath=$root.'/config/course4_tenkai_venue_capabilities_v1.json';
try{$capabilities=json_decode((string)file_get_contents($capabilityPath),true,512,JSON_THROW_ON_ERROR);}catch(Throwable){apiOut(500,['success'=>false,'error_code'=>'VENUE_CAPABILITY_UNAVAILABLE','error_message'=>'venue capability unavailable']);}
$venueCode=substr($race,8,3); $venueCapability=$capabilities['venues'][$venueCode]??$capabilities['default']??null;
if(!is_array($venueCapability)||!isset($venueCapability['model_variant']))apiOut(500,['success'=>false,'error_code'=>'VENUE_CAPABILITY_UNAVAILABLE','error_message'=>'venue capability unavailable']);
if(($venueCapability['supported']??true)===false||$venueCapability['model_variant']==='UNSUPPORTED')apiOut(200,['success'=>true,'prediction_available'=>false,'race_code'=>$race,'stage'=>'POST_EXHIBITION','prediction_cutoff'=>$cutoff,'reason_code'=>'VENUE_NOT_SUPPORTED','reason_message'=>'VENUE_NOT_SUPPORTED','generated_at'=>null]);
$script=$root.'/forecast/course4_attack_type_live.py';
if($venueCapability['model_variant']==='NO_STRAIGHT')$script=$root.'/forecast/course4_attack_type_no_straight_live.py';
elseif($venueCapability['model_variant']!=='FULL')apiOut(500,['success'=>false,'error_code'=>'VENUE_CAPABILITY_UNAVAILABLE','error_message'=>'venue capability unavailable']);
// CLI-only fixture seam; HTTP requests can never select an executable.
if (PHP_SAPI === 'cli' && is_string(getenv('COURSE4_API_TEST_PYTHON')) && getenv('COURSE4_API_TEST_PYTHON') !== '') $python=getenv('COURSE4_API_TEST_PYTHON');
$cmd=[$python,$script,'--race-code',$race,'--prediction-cutoff',$cutoff,'--json'];
$pipes=[];$proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
if (!is_resource($proc)) apiOut(500,['success'=>false,'error_code'=>'INFERENCE_START_FAILED','error_message'=>'inference service unavailable']);
fclose($pipes[0]); stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false); $start=microtime(true); $out='';$err='';$timed=false;
while (($s=proc_get_status($proc))['running']) { $out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]); if(microtime(true)-$start>15){proc_terminate($proc);$timed=true;break;} usleep(10000); }
$out.=stream_get_contents($pipes[1]);$err.=stream_get_contents($pipes[2]); fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);
error_log(sprintf('course4_attack_type_api race=%s available=%s exit=%s duration=%.3f',$race,'unknown',(string)$exit,microtime(true)-$start));
if($timed) apiOut(504,['success'=>false,'error_code'=>'INFERENCE_TIMEOUT','error_message'=>'inference timed out']);
if($exit!==0) apiOut(502,['success'=>false,'error_code'=>'INFERENCE_PROCESS_FAILED','error_message'=>'inference service failed']);
try{$data=json_decode($out,true,512,JSON_THROW_ON_ERROR);}catch(Throwable){apiOut(502,['success'=>false,'error_code'=>'INFERENCE_MALFORMED_RESPONSE','error_message'=>'inference service returned invalid data']);}
if(!is_array($data)||!array_key_exists('prediction_available',$data))apiOut(502,['success'=>false,'error_code'=>'INFERENCE_MALFORMED_RESPONSE','error_message'=>'inference service returned invalid data']);
if(!$data['prediction_available']) apiOut(200,['success'=>true,'prediction_available'=>false,'race_code'=>$race,'stage'=>$data['stage']??'POST_EXHIBITION','prediction_cutoff'=>$data['prediction_cutoff']??$cutoff,'reason_code'=>($data['missing_reason'][0]??'INFERENCE_UNAVAILABLE'),'reason_message'=>($data['missing_reason'][0]??'INFERENCE_UNAVAILABLE'),'generated_at'=>$data['generated_at']??null]);
apiOut(200,['success'=>true,'prediction_available'=>true,'race_code'=>$race,'stage'=>$data['stage'],'prediction_cutoff'=>$data['prediction_cutoff'],'probabilities'=>['no_attack_success'=>$data['p_no_attack_success'],'makuri_success'=>$data['p_makuri_success'],'makurizashi_success'=>$data['p_makurizashi_success']],'identity'=>['logic_version'=>$data['logic_version'],'artifact_sha256'=>$data['artifact_sha256'],'spec_sha256'=>$data['spec_sha256'],'input_contract_version'=>$data['input_contract_version'],'ledger_sha256'=>$data['ledger_sha256'],'input_sha256'=>$data['input_sha256']],'generated_at'=>$data['generated_at']]);
