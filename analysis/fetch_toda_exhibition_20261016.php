<?php

declare(strict_types=1);

/**
 * 2026-10-16 戸田限定の低負荷な展示取得。
 *
 * race_entry は通常の入力経路だけを読む。この処理は Candidate、formal evidence、
 * lifecycle を作らず、既存の画面更新経路で exhibition_live だけを更新する。
 */

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/api/ApiClientProduction.php';
require_once __DIR__ . '/../web/logic/OfficialEpochWindowBindingRuntimeAuthorization.php';

const TDA_1016_DATE = '2026-10-16';
const TDA_1016_PLACE = 'TDA';
const TDA_1016_FIRST_OPEN = 8 * 60;
const TDA_1016_FIRST_CLOSE = 5 * 60;
const TDA_1016_RETRY_OPEN = 5 * 60;
const TDA_1016_SAFE_CUTOFF = 2 * 60;

function tda1016Log(array $row): void
{
    $row['timestamp'] = $row['timestamp'] ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM);
    echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

function tda1016StatePath(): string { return __DIR__ . '/../var/cache/tda_exhibition_auto_20261016_state.json'; }
function tda1016TargetRace(string $race): bool { return preg_match('/^20261016TDA(0[1-9]|1[0-2])$/', $race) === 1; }

function tda1016ReadState(): array
{
    $path = tda1016StatePath();
    if (!is_file($path)) return ['schema_version' => 'one-day-exhibition-auto/v1', 'target_date' => TDA_1016_DATE, 'races' => []];
    $value = json_decode((string) file_get_contents($path), true);
    return is_array($value) ? $value : ['schema_version' => 'one-day-exhibition-auto/v1', 'target_date' => TDA_1016_DATE, 'races' => []];
}

function tda1016WriteState(array $state): void
{
    $path = tda1016StatePath();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('STATE_DIRECTORY_UNAVAILABLE');
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) throw new RuntimeException('STATE_ENCODE_FAILED');
    $tmp = $path . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('STATE_WRITE_FAILED');
    }
}

function tda1016Action(int $seconds, bool $complete, int $attempts): array
{
    if ($complete) return ['action' => 'SKIP', 'reason' => 'ALREADY_CAPTURED'];
    if ($seconds <= TDA_1016_SAFE_CUTOFF) return ['action' => 'SKIP', 'reason' => 'SAFE_CUTOFF_REACHED'];
    if ($attempts >= 2) return ['action' => 'SKIP', 'reason' => 'RETRY_LIMIT_REACHED'];
    if ($attempts === 0 && $seconds <= TDA_1016_FIRST_OPEN && $seconds > TDA_1016_FIRST_CLOSE) return ['action' => 'FETCH', 'reason' => 'FIRST_FETCH_WINDOW'];
    if ($attempts === 1 && $seconds <= TDA_1016_RETRY_OPEN && $seconds > TDA_1016_SAFE_CUTOFF) return ['action' => 'FETCH', 'reason' => 'RETRY_FETCH_WINDOW'];
    return ['action' => 'SKIP', 'reason' => 'OUTSIDE_FETCH_WINDOW'];
}

function tda1016Arguments(array $argv): array
{
    $dry = false; $nowText = null;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') $dry = true;
        elseif (str_starts_with($arg, '--now=')) $nowText = substr($arg, 6);
        else throw new InvalidArgumentException('UNKNOWN_ARGUMENT');
    }
    if ($nowText !== null && !$dry) throw new InvalidArgumentException('NOW_OVERRIDE_REQUIRES_DRY_RUN');
    $tz = new DateTimeZone('Asia/Tokyo');
    return ['dry' => $dry, 'now' => ($nowText === null ? new DateTimeImmutable('now', $tz) : new DateTimeImmutable($nowText, $tz))->setTimezone($tz)];
}

function tda1016DeadlineMap(): array
{
    $path = sys_get_temp_dir() . '/boatrace_official_deadlines/deadlines_20261016.json';
    if (!is_file($path)) return [];
    try { $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { return []; }
    $out = [];
    foreach ((is_array($payload['deadlines'] ?? null) ? $payload['deadlines'] : []) as $race => $time) {
        if (tda1016TargetRace((string) $race) && preg_match('/^\d{2}:\d{2}$/', (string) $time)) $out[(string) $race] = (string) $time;
    }
    ksort($out, SORT_NATURAL);
    return $out;
}

function tda1016EntryRaces(PDO $pdo): array
{
    $q = $pdo->prepare("SELECT DISTINCT race_code FROM boat_race.race_entry WHERE race_date = CAST(:d AS date) AND SUBSTRING(race_code FROM 9 FOR 3) = :p");
    $q->execute([':d' => TDA_1016_DATE, ':p' => TDA_1016_PLACE]);
    return array_fill_keys(array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
}

function tda1016ExhibitionComplete(PDO $pdo, string $race): bool
{
    $q = $pdo->prepare("SELECT COUNT(DISTINCT entry_course) FROM boat_race.exhibition_live WHERE race_code=:race AND entry_course BETWEEN 1 AND 6 AND player_id IS NOT NULL AND exhibition_time IS NOT NULL AND exhibition_time > 0 AND start_timing IS NOT NULL AND lap_time IS NOT NULL AND lap_time > 0 AND around_time IS NOT NULL AND around_time > 0 AND straight_time IS NOT NULL AND straight_time > 0");
    $q->execute([':race' => $race]);
    return (int) $q->fetchColumn() === 6;
}

function tda1016Main(array $argv): int
{
    try { ['dry' => $dry, 'now' => $now] = tda1016Arguments($argv); }
    catch (Throwable $e) { tda1016Log(['action' => 'SKIP', 'reason' => 'INVALID_ARGUMENT']); return 2; }
    if ($now->format('Y-m-d') !== TDA_1016_DATE) { tda1016Log(['action' => 'SKIP', 'reason' => 'DATE_GUARD']); return 0; }

    $runtime = (new OfficialEpochWindowBindingRuntimeAuthorization(dirname(__DIR__)))->authorizeOperationalPointerWindow();
    if (($runtime['kind'] ?? '') !== 'ACTIVE') { tda1016Log(['action' => 'SKIP', 'reason' => (string) ($runtime['reason'] ?? 'NO_ACTIVE_WINDOW')]); return 0; }
    $deadlines = tda1016DeadlineMap();
    if ($deadlines === []) { tda1016Log(['action' => 'SKIP', 'reason' => 'OFFICIAL_DEADLINE_CACHE_UNAVAILABLE', 'place' => TDA_1016_PLACE]); return 0; }

    try { $pdo = getPDO(); $entries = tda1016EntryRaces($pdo); }
    catch (Throwable) { tda1016Log(['action' => 'SKIP', 'reason' => 'DB_UNAVAILABLE']); return 1; }

    $state = tda1016ReadState();
    $state['schema_version'] = 'one-day-exhibition-auto/v1'; $state['target_date'] = TDA_1016_DATE;
    $state['races'] = is_array($state['races'] ?? null) ? $state['races'] : [];
    $api = null;
    foreach ($deadlines as $race => $time) {
        if (!isset($entries[$race])) { tda1016Log(['race_code' => $race, 'action' => 'SKIP', 'reason' => 'VENUE_OR_ENTRY_GUARD']); continue; }
        $deadline = new DateTimeImmutable(TDA_1016_DATE . ' ' . $time, new DateTimeZone('Asia/Tokyo'));
        $seconds = $deadline->getTimestamp() - $now->getTimestamp();
        $prior = is_array($state['races'][$race] ?? null) ? $state['races'][$race] : [];
        $attempts = max(0, (int) ($prior['attempts'] ?? 0));
        $decision = tda1016Action($seconds, tda1016ExhibitionComplete($pdo, $race), $attempts);
        if ($decision['action'] !== 'FETCH') { tda1016Log(['race_code' => $race, 'action' => 'SKIP', 'reason' => $decision['reason'], 'attempts' => $attempts, 'seconds_to_cutoff' => $seconds]); continue; }
        if ($dry) { tda1016Log(['race_code' => $race, 'action' => 'FETCH_CANDIDATE', 'reason' => $decision['reason']]); continue; }
        $api ??= new ApiClientProduction();
        [$message] = $api->updateExhibition($race);
        $complete = tda1016ExhibitionComplete($pdo, $race);
        $state['races'][$race] = ['attempts' => $attempts + 1, 'last_action' => $decision['reason'], 'last_attempt_at' => $now->format(DATE_ATOM), 'last_result' => $complete ? 'SUCCESS' : 'FAILED'];
        tda1016WriteState($state);
        tda1016Log(['race_code' => $race, 'action' => $complete ? 'FETCH_SUCCESS' : 'FETCH_FAILED', 'reason' => $decision['reason'], 'attempt' => $attempts + 1, 'message' => $message]);
    }
    return 0;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) exit(tda1016Main($argv));
