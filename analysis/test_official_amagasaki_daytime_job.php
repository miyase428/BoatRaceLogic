<?php

declare(strict_types=1);

require_once __DIR__ . '/../logic/OfficialAmagasakiDaytimeJob.php';

function jobAssert(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
$builder = new OfficialAmagasakiDaytimeCandidateBuilder();
$rows = [];
foreach (range(1, 6) as $lane) $rows[] = ['race_code' => '20261021AMG02', 'lane_number' => $lane, 'player_id' => (string)(8000 + $lane)];
foreach (range(1, 6) as $lane) $rows[] = ['race_code' => '20261021TMG02', 'lane_number' => $lane, 'player_id' => (string)(9000 + $lane)];
$cases = 0;
$candidates = $builder->build('20261021', $rows, ['20261021AMG02' => '12:00'], static fn(string $race): string => 'none');
jobAssert(count($candidates) === 1 && $candidates[0]['race_code'] === '20261021AMG02', 'candidate builder restricts job to six-boat AMG races'); $cases++;
jobAssert($candidates[0]['prediction_cutoff'] === '2026-10-21T02:58:00.000Z', 'prediction cutoff is the explicit two-minute safety boundary'); $cases++;
$missing = $builder->build('20261021', array_slice($rows, 0, 5), ['20261021AMG02' => '12:00'], static fn(string $race): string => 'none');
jobAssert($missing === [], 'incomplete race entry never becomes a candidate'); $cases++;
$badDeadline = $builder->build('20261021', $rows, ['20261021AMG02' => 'bad'], static fn(string $race): string => 'none');
jobAssert($badDeadline === [], 'invalid deadline never becomes a candidate'); $cases++;
$single = OfficialAmagasakiDaytimeJob::selectCandidates([
    ['race_code' => '20261021AMG01'],
    ['race_code' => '20261021AMG04'],
    ['race_code' => '20261021AMG12'],
], '20261021', 4);
jobAssert(count($single) === 1 && $single[0]['race_code'] === '20261021AMG04', 'only-race passes exactly one AMG candidate to the integration boundary'); $cases++;
jobAssert(OfficialAmagasakiDaytimeJob::selectCandidates([['race_code' => '20261021AMG01']], '20261021', 4) === [], 'unavailable only-race cannot fall through to another race'); $cases++;
try {
    OfficialAmagasakiDaytimeJob::selectCandidates([], '20261021', 13);
    throw new RuntimeException('invalid only-race unexpectedly accepted');
} catch (InvalidArgumentException) {
    $cases++;
}
echo "PASS {$cases} isolated daytime job planning cases\n";
