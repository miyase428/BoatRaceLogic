<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/web/logic/TenkaiAiLiveDeadlineGuard.php';

$root = sys_get_temp_dir() . '/tenkai-live-guard-' . bin2hex(random_bytes(5));
mkdir($root, 0700, true);
file_put_contents($root . '/deadlines_20261023.json', json_encode(['deadlines' => ['20261023TMG02' => '11:00']], JSON_THROW_ON_ERROR));
$before = new TenkaiAiLiveDeadlineGuard($root, static fn() => new DateTimeImmutable('2026-10-23T10:59:59+09:00'));
$after = new TenkaiAiLiveDeadlineGuard($root, static fn() => new DateTimeImmutable('2026-10-23T11:00:00+09:00'));
$checks = [
    'before_deadline_allowed' => $before->reason('20261023TMG02') === null,
    'at_deadline_blocked' => $after->reason('20261023TMG02') === 'POSTDEADLINE_REFERENCE_REQUIRED',
    'missing_deadline_fail_closed' => $before->reason('20261023TMG03') === 'OFFICIAL_DEADLINE_UNAVAILABLE',
    'wall_api_guarded' => str_contains((string)file_get_contents(dirname(__DIR__) . '/web/course34_wall_api.php'), 'TenkaiAiLiveDeadlineGuard'),
    'attack_api_guarded' => str_contains((string)file_get_contents(dirname(__DIR__) . '/web/course4_attack_type_api.php'), 'TenkaiAiLiveDeadlineGuard'),
];
@unlink($root . '/deadlines_20261023.json'); @rmdir($root);
if (!array_product(array_map(static fn(bool $value): int => $value ? 1 : 0, $checks))) throw new RuntimeException(json_encode($checks));
echo json_encode(['passed' => count($checks), 'total' => count($checks), 'checks' => $checks]), PHP_EOL;
