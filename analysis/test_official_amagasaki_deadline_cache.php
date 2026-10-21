<?php

declare(strict_types=1);

require_once __DIR__ . '/../logic/OfficialAmagasakiDeadlineCache.php';

function deadlineAssert(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
function deadlinePayload(string $date): array { $deadlines = []; for ($i = 1; $i <= 12; $i++) $deadlines[$date . 'AMG' . sprintf('%02d', $i)] = sprintf('%02d:00', $i); return ['date' => $date, 'deadlines' => $deadlines]; }
$dir = sys_get_temp_dir() . '/official-amagasaki-deadline-cache-' . bin2hex(random_bytes(5));
mkdir($dir, 0700, true); $date = '20261021'; $calls = 0;
$loader = static function (string $requested) use (&$calls, $dir): array { $calls++; $payload = deadlinePayload($requested); file_put_contents(OfficialAmagasakiDeadlineCache::path($dir, $requested), json_encode($payload)); return $payload; };
$preparer = new OfficialAmagasakiDeadlineCachePreparer($dir, $loader(...)); $cases = 0;
$result = $preparer->prepare($date, false, false); deadlineAssert($result['status'] === 'dry_run' && $calls === 0, 'missing cache dry-run does not fetch'); $cases++;
$result = $preparer->prepare($date, true, false); deadlineAssert($result['status'] === 'blocked' && $calls === 0, 'unapproved execute does not fetch'); $cases++;
$result = $preparer->prepare($date, true, true); deadlineAssert($result['status'] === 'prepared' && $calls === 1, 'approved execution prepares twelve deadlines'); $cases++;
$result = $preparer->prepare($date, true, true); deadlineAssert($result['status'] === 'ready' && $calls === 1, 'complete same-day cache is never re-fetched'); $cases++;
$payload = deadlinePayload($date); unset($payload['deadlines'][$date . 'AMG12']); file_put_contents(OfficialAmagasakiDeadlineCache::path($dir, $date), json_encode($payload));
deadlineAssert(OfficialAmagasakiDeadlineCache::readComplete($dir, $date) === null, 'missing AMG deadline is rejected'); $cases++;
echo "PASS {$cases} isolated AMG deadline cache cases\n";
