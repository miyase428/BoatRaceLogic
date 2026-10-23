<?php

declare(strict_types=1);

require_once __DIR__ . '/../logic/OfficialOmuraDeadlineCache.php';
require_once __DIR__ . '/../logic/OfficialOmuraExhibitionStorage.php';
require_once __DIR__ . '/../logic/OfficialExhibitionManualRoute.php';
require_once __DIR__ . '/../logic/NodeOfficialOmuraPayloadFetcher.php';
require_once __DIR__ . '/../logic/OfficialOmuraManualExhibitionUpdate.php';

function deadlineAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function completeDeadlinePayload(string $date): array
{
    $deadlines = [];
    for ($race = 1; $race <= 12; $race++) {
        $deadlines[$date . 'OMR' . sprintf('%02d', $race)] = sprintf('%02d:%02d', 10 + intdiv($race, 6), $race);
    }
    return ['date' => $date, 'deadlines' => $deadlines];
}

/** @return DateTimeImmutable|null */
function resolveDeadline(string $directory, Closure $loader, string $raceCode): ?DateTimeImmutable
{
    $reflection = new ReflectionClass(OfficialOmuraManualExhibitionUpdate::class);
    $service = $reflection->newInstanceWithoutConstructor();
    foreach (['deadlineDirectory' => $directory, 'deadlineLoader' => $loader] as $name => $value) {
        $property = $reflection->getProperty($name);
        $property->setValue($service, $value);
    }
    $method = $reflection->getMethod('deadline');
    return $method->invoke($service, $raceCode);
}

$cases = 0;
$date = '20990101';
$dir = sys_get_temp_dir() . '/manual-deadline-' . bin2hex(random_bytes(5));
mkdir($dir, 0700, true);

$calls = 0;
$payload = completeDeadlinePayload($date);
file_put_contents($dir . '/deadlines_' . $date . '.json', json_encode($payload, JSON_THROW_ON_ERROR));
$deadline = resolveDeadline($dir, static function () use (&$calls): array { $calls++; throw new RuntimeException('loader must not run'); }, $date . 'OMR03');
deadlineAssert($deadline?->format('Y-m-d H:i') === '2099-01-01 10:03' && $calls === 0, 'complete cache is used without official deadline HTTP'); $cases++;

unlink($dir . '/deadlines_' . $date . '.json');
$deadline = resolveDeadline($dir, static function (string $requested) use (&$calls, $payload, $date): array { $calls++; deadlineAssert($requested === $date, 'requested date'); return $payload; }, $date . 'OMR12');
deadlineAssert($deadline?->format('H:i') === '12:12' && $calls === 1, 'missing cache uses the returned existing deadline result'); $cases++;
deadlineAssert(!file_exists($dir . '/deadlines_' . $date . '.json'), 'fallback does not require cache write access'); $cases++;

$incomplete = $payload;
unset($incomplete['deadlines'][$date . 'OMR12']);
deadlineAssert(resolveDeadline($dir, static fn(): array => $incomplete, $date . 'OMR01') === null, 'incomplete 12-race result fails closed'); $cases++;

$invalid = $payload;
$invalid['deadlines'][$date . 'OMR02'] = '25:99';
deadlineAssert(resolveDeadline($dir, static fn(): array => $invalid, $date . 'OMR01') === null, 'invalid deadline time fails closed'); $cases++;

rmdir($dir);
echo "PASS {$cases} manual deadline resolution cases\n";
