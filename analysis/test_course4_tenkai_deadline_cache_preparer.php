<?php
declare(strict_types=1);
require_once __DIR__ . '/../logic/Course4TenkaiDeadlineCachePreparer.php';
$checks = 0;
function check(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException($name); $checks++; }
$codes = array_merge(array_map(static fn(int $n): string => '20990101AMG' . sprintf('%02d', $n), range(1, 12)), array_map(static fn(int $n): string => '20990101TMG' . sprintf('%02d', $n), range(1, 12)));
$all = [];
foreach ($codes as $code) $all[$code] = '10:00';
$calls = [];
$ready = new Course4TenkaiDeadlineCachePreparer(static fn(string $_): array => $GLOBALS['codes'], static function (string $_, array $venues) use (&$calls, $all): array { $calls[] = $venues; $out=[]; foreach ($all as $code=>$time) if (in_array(substr($code, 8, 3), $venues, true)) $out[$code]=$time; return ['deadlines'=>$out,'fetched_places'=>$venues,'cache_places'=>[]]; });
$r = $ready->prepare('2099-01-01', ['deadlines'=>$all]); check($r['ok'] && $r['status']==='READY' && $calls===[], 'complete_cache_no_http');
$amg = array_filter($all, static fn($_, string $code): bool => str_contains($code, 'AMG'), ARRAY_FILTER_USE_BOTH);
$r = $ready->prepare('20990101', ['deadlines'=>$amg]); check($r['ok'] && $r['status']==='READY' && $calls === [['TMG']], 'only_missing_venue_fetched');
$calls=[]; $r = $ready->prepare('20990101', ['deadlines'=>$amg], false); check($r['status']==='PARTIAL_READY' && count($r['ready_race_codes'])===12 && $r['missing_race_codes']===array_values(array_filter($codes, static fn(string $c):bool=>str_contains($c,'TMG'))) && $calls===[], 'partial_cache_isolated_without_http');
$throws = new Course4TenkaiDeadlineCachePreparer(static fn(string $_): array => $GLOBALS['codes'], static function (): array { throw new RuntimeException('fixture'); });
$r = $throws->prepare('20990101', ['deadlines'=>$amg]); check($r['status']==='PARTIAL_READY' && $r['deadline_fetch_status']==='OFFICIAL_DEADLINE_UNAVAILABLE' && count($r['ready_race_codes'])===12, 'temporary_failure_keeps_complete_venues');
$none = new Course4TenkaiDeadlineCachePreparer(static fn(string $_): array => [], static function (): array { throw new RuntimeException('must_not_load'); });
$r = $none->prepare('20990101'); check($r['ok'] && $r['status']==='NO_RACES', 'no_races_no_http');
$incomplete = new Course4TenkaiDeadlineCachePreparer(static fn(string $_): array => ['20990101AMG01'], static function (): array { throw new RuntimeException('must_not_load'); });
$r = $incomplete->prepare('20990101'); check(!$r['ok'] && $r['status']==='RACE_ENTRY_INCOMPLETE', 'race_entry_requires_twelve');
$r = $ready->prepare('bad'); check(!$r['ok'] && $r['status']==='INVALID_DATE', 'invalid_date');
$now = new DateTimeImmutable('2099-01-01T10:00:00+09:00');
$marker = Course4TenkaiDeadlineCachePreparer::failureMarker('20990101', [], 'OFFICIAL_DEADLINE_UNAVAILABLE', $now, 2700);
check(Course4TenkaiDeadlineCachePreparer::retryDecision($marker, $now->modify('+15 minutes'))['status']==='RETRY_WAIT', 'retry_interval_blocks_http');
check(Course4TenkaiDeadlineCachePreparer::retryDecision($marker, $now->modify('+46 minutes'))['retry'], 'retry_allowed_after_interval');
$limit=['attempt_count'=>8]; check(Course4TenkaiDeadlineCachePreparer::retryDecision($limit, $now)['status']==='RETRY_LIMIT_REACHED', 'daily_limit');
$lockPath = tempnam(sys_get_temp_dir(), 'deadline-lock-'); $one=fopen($lockPath, 'c'); $two=fopen($lockPath, 'c'); flock($one, LOCK_EX | LOCK_NB); check(!flock($two, LOCK_EX | LOCK_NB), 'concurrent_preparation_lock'); flock($one, LOCK_UN); fclose($one); fclose($two); unlink($lockPath);
echo "PASS {$checks} deadline cache preparer cases\n";
