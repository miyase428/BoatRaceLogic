<?php

declare(strict_types=1);

require_once __DIR__ . '/../logic/OfficialAmagasakiLateHistoryImporter.php';

function lateAssert(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
function latePayload(): array {
    $boats = [];
    foreach (range(1, 6) as $lane) $boats[] = ['lane' => $lane, 'entry_course' => $lane, 'player_id' => (string)(7100 + $lane), 'exhibition_time' => '6.8' . $lane, 'start_timing' => $lane === 6 ? 'F.04' : '.0' . $lane, 'lap_time' => '37.' . (10 + $lane), 'around_time' => '11.' . (20 + $lane), 'straight_time' => null];
    return ['schema' => 'official-exhibition/v1', 'status' => 'published', 'source' => 'official_amagasaki', 'source_url' => 'https://fixture.invalid/amg', 'race_date' => '20261022', 'race_no' => 1, 'race_code' => '20261022AMG01', 'fetched_at' => '2026-10-22T04:35:17.572Z', 'page_fetched_at' => ['direct_info' => '2026-10-22T04:35:17.446Z', 'start_display' => '2026-10-22T04:35:17.496Z', 'original_data' => '2026-10-22T04:35:17.554Z'], 'boats' => $boats];
}

$cases = 0;
OfficialAmagasakiLateHistoryImporter::assertLatePayloadIdentity(latePayload()); $cases++;
foreach (['race_code' => '20261022AMG02', 'fetched_at' => '2026-10-22T01:36:00.000Z'] as $field => $value) {
    $bad = latePayload(); $bad[$field] = $value;
    try { OfficialAmagasakiLateHistoryImporter::assertLatePayloadIdentity($bad); throw new RuntimeException('invalid late payload accepted'); } catch (InvalidArgumentException) { $cases++; }
}

$validator = new OfficialAmagasakiExhibitionValidator();
$entries = array_map(static fn(int $lane): array => ['lane_number' => $lane, 'player_id' => (string)(7100 + $lane)], range(1, 6));
$converted = $validator->validateAndConvert(latePayload(), OfficialAmagasakiLateHistoryImporter::RACE_CODE, $entries);
lateAssert($converted[5]['start_timing'] === -0.04, 'late history keeps F.04 as numeric negative start timing'); $cases++;

echo "PASS {$cases} late-history identity and conversion cases\n";
