<?php

declare(strict_types=1);

/**
 * 2026-10-08 多摩川だけの、低負荷な展示情報取得。
 *
 * Strict Forward schedulerとは完全に分離する。既存の画面更新経路を最大2回/レースで
 * 呼び出すが、ACTIVE Windowのarmed_atより後に締切を迎えるレースだけを対象にする。
 */

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/api/ApiClientProduction.php';
require_once __DIR__ . '/../web/logic/FormalMeasurementWindowContext.php';

const TMG_1008_TARGET_DATE = '2026-10-08';
const TMG_1008_TARGET_PLACE = 'TMG';
const TMG_1008_FIRST_WINDOW_START_SECONDS = 8 * 60;
const TMG_1008_FIRST_WINDOW_END_SECONDS = 5 * 60;
const TMG_1008_RETRY_WINDOW_START_SECONDS = 5 * 60;
const TMG_1008_SAFE_CUTOFF_SECONDS = 2 * 60;

/** @return array{attempts:int,last_action:string,last_attempt_at:string,last_result:string} */
function tmg1008StateForRace(array $state, string $raceCode): array
{
    $row = $state['races'][$raceCode] ?? [];
    return [
        'attempts' => max(0, (int)($row['attempts'] ?? 0)),
        'last_action' => (string)($row['last_action'] ?? ''),
        'last_attempt_at' => (string)($row['last_attempt_at'] ?? ''),
        'last_result' => (string)($row['last_result'] ?? ''),
    ];
}

/** @return array{action:string,reason:string} */
function tmg1008Action(int $secondsToCutoff, bool $complete, int $attempts): array
{
    if ($complete) return ['action' => 'SKIP', 'reason' => 'ALREADY_CAPTURED'];
    if ($secondsToCutoff <= TMG_1008_SAFE_CUTOFF_SECONDS) return ['action' => 'SKIP', 'reason' => 'SAFE_CUTOFF_REACHED'];
    if ($attempts >= 2) return ['action' => 'SKIP', 'reason' => 'RETRY_LIMIT_REACHED'];
    if ($attempts === 0 && $secondsToCutoff <= TMG_1008_FIRST_WINDOW_START_SECONDS && $secondsToCutoff > TMG_1008_FIRST_WINDOW_END_SECONDS) {
        return ['action' => 'FETCH', 'reason' => 'FIRST_FETCH_WINDOW'];
    }
    if ($attempts === 1 && $secondsToCutoff <= TMG_1008_RETRY_WINDOW_START_SECONDS && $secondsToCutoff > TMG_1008_SAFE_CUTOFF_SECONDS) {
        return ['action' => 'FETCH', 'reason' => 'RETRY_FETCH_WINDOW'];
    }
    return ['action' => 'SKIP', 'reason' => 'OUTSIDE_FETCH_WINDOW'];
}

function tmg1008StatePath(): string
{
    return __DIR__ . '/../var/cache/tmg_exhibition_auto_20261008_state.json';
}

function tmg1008IsTargetRaceCode(string $raceCode): bool
{
    return preg_match('/^20261008TMG(0[1-9]|1[0-2])$/', $raceCode) === 1;
}

function tmg1008IsProspective(DateTimeImmutable $cutoff, string $armedAt): bool
{
    try {
        return $armedAt !== '' && $cutoff > new DateTimeImmutable($armedAt);
    } catch (Throwable) {
        return false;
    }
}

/** @return array<string,mixed> */
function tmg1008ReadState(string $path): array
{
    if (!is_file($path)) return ['schema_version' => 'tmg-exhibition-auto/v1', 'target_date' => TMG_1008_TARGET_DATE, 'races' => []];
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : ['schema_version' => 'tmg-exhibition-auto/v1', 'target_date' => TMG_1008_TARGET_DATE, 'races' => []];
}

/** @param array<string,mixed> $state */
function tmg1008WriteState(string $path, array $state): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) throw new RuntimeException('state_directory_unavailable');
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($json)) throw new RuntimeException('state_encode_failed');
    $tmp = $path . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('state_write_failed');
    }
}

/** @return array<string,string> */
function tmg1008DeadlineMap(): array
{
    $cachePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/boatrace_official_deadlines/deadlines_20261008.json';
    if (!is_file($cachePath)) return [];
    $payload = json_decode((string)file_get_contents($cachePath), true);
    $deadlines = is_array($payload['deadlines'] ?? null) ? $payload['deadlines'] : [];
    $out = [];
    foreach ($deadlines as $raceCode => $time) {
        if (tmg1008IsTargetRaceCode((string)$raceCode) && preg_match('/^\d{2}:\d{2}$/', (string)$time)) $out[(string)$raceCode] = (string)$time;
    }
    ksort($out, SORT_NATURAL);
    return $out;
}

/** @return array<string,true> */
function tmg1008EntryRaceCodes(PDO $pdo): array
{
    $stmt = $pdo->prepare(<<<'SQL'
SELECT DISTINCT race_code
FROM boat_race.race_entry
WHERE race_date = CAST(:race_date AS date)
  AND SUBSTRING(race_code FROM 9 FOR 3) = :place
SQL);
    $stmt->execute([':race_date' => TMG_1008_TARGET_DATE, ':place' => TMG_1008_TARGET_PLACE]);
    return array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
}

function tmg1008ExhibitionComplete(PDO $pdo, string $raceCode): bool
{
    $stmt = $pdo->prepare(<<<'SQL'
SELECT COUNT(DISTINCT entry_course)
FROM boat_race.exhibition_live
WHERE race_code = :race_code
  AND entry_course BETWEEN 1 AND 6
  AND exhibition_time IS NOT NULL
  AND start_timing IS NOT NULL
  AND lap_time IS NOT NULL
  AND around_time IS NOT NULL
  AND straight_time IS NOT NULL
SQL);
    $stmt->execute([':race_code' => $raceCode]);
    return (int)$stmt->fetchColumn() === 6;
}

function tmg1008Log(array $event): void
{
    $event['timestamp'] = $event['timestamp'] ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM);
    echo json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

/** @return array{dry_run:bool,now:DateTimeImmutable} */
function tmg1008Arguments(array $argv): array
{
    $dryRun = false;
    $nowText = null;
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--dry-run') $dryRun = true;
        elseif (str_starts_with($argument, '--now=')) $nowText = substr($argument, strlen('--now='));
        else throw new InvalidArgumentException('unknown_argument:' . $argument);
    }
    $timezone = new DateTimeZone('Asia/Tokyo');
    $now = $nowText === null ? new DateTimeImmutable('now', $timezone) : new DateTimeImmutable($nowText, $timezone);
    if ($nowText !== null && !$dryRun) throw new InvalidArgumentException('now_override_requires_dry_run');
    return ['dry_run' => $dryRun, 'now' => $now->setTimezone($timezone)];
}

/** @return array{accepted:bool,armed_at:string,reason:string} */
function tmg1008ActiveWindow(): array
{
    try {
        $context = FormalMeasurementWindowContext::production(dirname(__DIR__));
        $resolved = $context->resolve();
        $manifest = is_array($resolved['manifest'] ?? null) ? $resolved['manifest'] : [];
        $armedAt = trim((string)($manifest['armed_at'] ?? ''));
        if (empty($resolved['accepted']) || ($manifest['status'] ?? '') !== 'ACTIVE' || $armedAt === '') {
            return ['accepted' => false, 'armed_at' => '', 'reason' => 'FORMAL_WINDOW_NOT_ACTIVE'];
        }
        return ['accepted' => true, 'armed_at' => $armedAt, 'reason' => ''];
    } catch (Throwable) {
        return ['accepted' => false, 'armed_at' => '', 'reason' => 'FORMAL_WINDOW_CONTEXT_UNAVAILABLE'];
    }
}

function tmg1008Main(array $argv): int
{
    try {
        ['dry_run' => $dryRun, 'now' => $now] = tmg1008Arguments($argv);
    } catch (Throwable $error) {
        tmg1008Log(['action' => 'SKIP', 'reason' => 'INVALID_ARGUMENT', 'detail' => $error->getMessage()]);
        return 2;
    }
    if ($now->format('Y-m-d') !== TMG_1008_TARGET_DATE) {
        tmg1008Log(['action' => 'SKIP', 'reason' => 'DATE_GUARD', 'target_date' => TMG_1008_TARGET_DATE, 'now' => $now->format(DATE_ATOM)]);
        return 0;
    }
    $window = tmg1008ActiveWindow();
    if (!$window['accepted']) {
        tmg1008Log(['action' => 'SKIP', 'reason' => $window['reason']]);
        return 0;
    }
    $deadlines = tmg1008DeadlineMap();
    if ($deadlines === []) {
        tmg1008Log(['action' => 'SKIP', 'reason' => 'OFFICIAL_DEADLINE_CACHE_UNAVAILABLE', 'place' => TMG_1008_TARGET_PLACE]);
        return 0;
    }
    try {
        $pdo = getPDO();
        $entryRaceCodes = tmg1008EntryRaceCodes($pdo);
    } catch (Throwable $error) {
        tmg1008Log(['action' => 'SKIP', 'reason' => 'DB_UNAVAILABLE', 'detail' => get_class($error)]);
        return 1;
    }
    $statePath = tmg1008StatePath();
    $state = tmg1008ReadState($statePath);
    $state['schema_version'] = 'tmg-exhibition-auto/v1';
    $state['target_date'] = TMG_1008_TARGET_DATE;
    $state['races'] = is_array($state['races'] ?? null) ? $state['races'] : [];
    $api = null;
    foreach ($deadlines as $raceCode => $time) {
        if (!isset($entryRaceCodes[$raceCode])) {
            tmg1008Log(['race_code' => $raceCode, 'action' => 'SKIP', 'reason' => 'VENUE_OR_ENTRY_GUARD']);
            continue;
        }
        $deadline = new DateTimeImmutable(TMG_1008_TARGET_DATE . ' ' . $time, new DateTimeZone('Asia/Tokyo'));
        if (!tmg1008IsProspective($deadline, $window['armed_at'])) {
            tmg1008Log(['race_code' => $raceCode, 'action' => 'SKIP', 'reason' => 'PRE_ARMED_RACE']);
            continue;
        }
        $secondsToCutoff = $deadline->getTimestamp() - $now->getTimestamp();
        $complete = tmg1008ExhibitionComplete($pdo, $raceCode);
        $raceState = tmg1008StateForRace($state, $raceCode);
        $decision = tmg1008Action($secondsToCutoff, $complete, $raceState['attempts']);
        if ($decision['action'] !== 'FETCH') {
            tmg1008Log(['race_code' => $raceCode, 'action' => 'SKIP', 'reason' => $decision['reason'], 'seconds_to_cutoff' => $secondsToCutoff, 'attempts' => $raceState['attempts']]);
            continue;
        }
        if ($dryRun) {
            tmg1008Log(['race_code' => $raceCode, 'action' => 'FETCH_CANDIDATE', 'reason' => $decision['reason'], 'seconds_to_cutoff' => $secondsToCutoff, 'attempts' => $raceState['attempts']]);
            continue;
        }
        $api ??= new ApiClientProduction();
        $attempt = $raceState['attempts'] + 1;
        [$message] = $api->updateExhibition($raceCode);
        $completeAfter = tmg1008ExhibitionComplete($pdo, $raceCode);
        $state['races'][$raceCode] = [
            'attempts' => $attempt,
            'last_action' => $decision['reason'],
            'last_attempt_at' => $now->format(DATE_ATOM),
            'last_result' => $completeAfter ? 'SUCCESS' : 'FAILED',
        ];
        tmg1008WriteState($statePath, $state);
        tmg1008Log(['race_code' => $raceCode, 'action' => $completeAfter ? 'FETCH_SUCCESS' : 'FETCH_FAILED', 'reason' => $decision['reason'], 'attempt' => $attempt, 'message' => $message]);
    }
    return 0;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) exit(tmg1008Main($argv));
