<?php
declare(strict_types=1);
require_once __DIR__ . '/../logic/Course4TenkaiDeadlineCachePreparer.php';
$checks = 0;
function check(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException($name); $checks++; }
$codes = ['20990101AMG01','20990101AMG02','20990101TMG01'];
$loaderCalls = 0;
$ready = new Course4TenkaiDeadlineCachePreparer(
    static fn(string $_): array => $GLOBALS['codes'],
    static function (string $date, array $venues) use (&$loaderCalls): array { $loaderCalls++; return ['deadlines'=>[$date.'AMG01'=>'10:00',$date.'AMG02'=>'10:30',$date.'TMG01'=>'11:00'],'fetched_places'=>$venues,'cache_places'=>[]]; },
);
$r = $ready->prepare('2099-01-01'); check($r['ok'] && $r['status']==='READY' && $r['race_count']===3 && $loaderCalls===1, 'ready_exact_races');
$none = new Course4TenkaiDeadlineCachePreparer(static fn(string $_): array => [], static function (): array { throw new RuntimeException('must_not_load'); });
$r = $none->prepare('20990101'); check($r['ok'] && $r['status']==='NO_RACES', 'no_races_no_http');
$bad = new Course4TenkaiDeadlineCachePreparer(static fn(string $_): array => ['20990101AMG01'], static fn(string $_, array $_v): array => ['deadlines'=>[]]);
$r = $bad->prepare('20990101'); check(!$r['ok'] && $r['status']==='OFFICIAL_DEADLINE_INCOMPLETE', 'incomplete_fails_closed');
$r = $ready->prepare('bad'); check(!$r['ok'] && $r['status']==='INVALID_DATE', 'invalid_date');
$throws = new Course4TenkaiDeadlineCachePreparer(static fn(string $_): array => ['20990101AMG01'], static function (): array { throw new RuntimeException('fixture'); });
$r = $throws->prepare('20990101'); check(!$r['ok'] && $r['status']==='OFFICIAL_DEADLINE_UNAVAILABLE', 'loader_error_fails_closed');
echo "PASS {$checks} deadline cache preparer cases\n";
