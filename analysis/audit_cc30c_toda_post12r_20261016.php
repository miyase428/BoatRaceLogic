<?php

declare(strict_types=1);

/**
 * 2026-10-16 戸田の post-12R natural-chain audit。
 *
 * scheduler、collector、Candidate、formal evidence を一切起動せず、既存の自然
 * 生成物を集計する。--watch は条件成立時に一度だけ report/marker を保存する。
 */

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/logic/OfficialEpochWindowBindingRuntimeAuthorization.php';

const TODA_AUDIT_DATE = '2026-10-16';
const TODA_AUDIT_VENUE = 'TDA';
const TODA_AUDIT_EVIDENCE_WINDOW_ID = 'successor-v3-prepared-20261015084342-8f2a97e7';
const TODA_AUDIT_MARKER = __DIR__ . '/output/cc30c_toda_post12r_20261016.done.json';
const TODA_AUDIT_JSON = __DIR__ . '/output/cc30c_toda_post12r_20261016.json';
const TODA_AUDIT_TEXT = __DIR__ . '/output/cc30c_toda_post12r_20261016.txt';
const TODA_AUDIT_STATUS = __DIR__ . '/output/cc30c_toda_20261016_status.json';
const TODA_AUDIT_CORRECTED_V2_JSON = __DIR__ . '/output/cc30c_toda_post12r_20261016_corrected_v2.json';
const TODA_AUDIT_CORRECTED_V2_TEXT = __DIR__ . '/output/cc30c_toda_post12r_20261016_corrected_v2.txt';
const TODA_CAPTURE_LOG = __DIR__ . '/../logs/tda_exhibition_auto_20261016.log';
const TODA_COLLECTOR_LOG = __DIR__ . '/../logs/strict_forward_exhibition_collector.log';

function todaAuditWrite(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('OUTPUT_DIRECTORY_UNAVAILABLE');
    $tmp = $path . '.' . getmypid() . '.tmp';
    if (file_put_contents($tmp, $contents, LOCK_EX) === false || !rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('OUTPUT_WRITE_FAILED'); }
}
function todaAuditJson(string $path): array
{
    if (!is_file($path)) return [];
    try { $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { return []; }
    return is_array($value) ? $value : [];
}
function todaAuditJsonLines(string $path): array
{
    if (!is_file($path)) return [];
    $rows = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        try { $value = json_decode($line, true, 512, JSON_THROW_ON_ERROR); } catch (Throwable) { continue; }
        if (is_array($value)) $rows[] = $value;
    }
    return $rows;
}
function todaAuditDeadline(): array
{
    $path = sys_get_temp_dir() . '/boatrace_official_deadlines/deadlines_20261016.json';
    $payload = todaAuditJson($path);
    $deadlines = is_array($payload['deadlines'] ?? null) ? $payload['deadlines'] : [];
    $map = [];
    foreach ($deadlines as $race => $time) {
        if (preg_match('/^20261016TDA(0[1-9]|1[0-2])$/', (string) $race) === 1 && preg_match('/^\d{2}:\d{2}$/', (string) $time)) $map[(string) $race] = (string) $time;
    }
    ksort($map, SORT_NATURAL);
    return ['path' => $path, 'exists' => is_file($path), 'schema_ok' => $payload !== [] && is_array($payload['deadlines'] ?? null), 'map' => $map, 'payload' => $payload];
}
function todaAuditDb(): array
{
    $pdo = getPDO();
    $entry = $pdo->prepare("SELECT race_code, count(*) AS rows, count(DISTINCT player_id) AS players, count(DISTINCT lane_number) AS lanes, count(*) FILTER (WHERE player_id IS NULL OR lane_number IS NULL) AS nulls FROM boat_race.race_entry WHERE race_date=CAST(:date AS date) AND SUBSTRING(race_code FROM 9 FOR 3)=:venue GROUP BY race_code ORDER BY race_code");
    $entry->execute([':date' => TODA_AUDIT_DATE, ':venue' => TODA_AUDIT_VENUE]);
    $entryRows = $entry->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $ex = $pdo->prepare("SELECT race_code, COUNT(DISTINCT entry_course) FILTER (WHERE entry_course BETWEEN 1 AND 6 AND player_id IS NOT NULL AND exhibition_time IS NOT NULL AND exhibition_time>0 AND start_timing IS NOT NULL AND lap_time IS NOT NULL AND lap_time>0 AND around_time IS NOT NULL AND around_time>0 AND straight_time IS NOT NULL AND straight_time>0) AS boats FROM boat_race.exhibition_live WHERE SUBSTRING(race_code,1,8)='20261016' AND SUBSTRING(race_code FROM 9 FOR 3)=:venue GROUP BY race_code ORDER BY race_code");
    $ex->execute([':venue' => TODA_AUDIT_VENUE]); $exRows = $ex->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $entryBy = []; $exBy = [];
    foreach ($entryRows as $r) $entryBy[(string) $r['race_code']] = ['rows'=>(int)$r['rows'], 'complete'=>(int)$r['rows']===6 && (int)$r['players']===6 && (int)$r['lanes']===6 && (int)$r['nulls']===0];
    foreach ($exRows as $r) $exBy[(string) $r['race_code']] = (int) $r['boats'] === 6;
    return ['entry_by_race'=>$entryBy, 'exhibition_by_race'=>$exBy, 'entry_rows'=>array_sum(array_column($entryRows, 'rows'))];
}
function todaAuditFiles(string $root, string $relative): array
{
    $path = $root . '/' . $relative;
    if (!is_dir($path)) return [];
    $out=[]; $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f instanceof SplFileInfo && $f->isFile() && strtolower($f->getExtension()) === 'json') { $row=todaAuditJson($f->getPathname()); if ($row!==[]) $out[]=$row; }
    return $out;
}
function todaAuditCapture12Complete(DateTimeImmutable $deadline): bool
{
    foreach (todaAuditJsonLines(TODA_CAPTURE_LOG) as $row) {
        if (($row['race_code'] ?? '') !== '20261016TDA12') continue;
        try { if (new DateTimeImmutable((string)($row['timestamp'] ?? '')) > $deadline) return true; } catch (Throwable) { }
    }
    return false;
}

/**
 * v2 is a report-only, deterministic reconstruction.  It intentionally reads
 * the immutable raw ledger without changing it and writes to new output names;
 * the historical v1/196 report remains untouched.
 *
 * @return list<array<string,mixed>>
 */
function todaAuditCanonicalFormalMembership(string $root): array
{
    $rows = [];
    $prefix = str_replace('-', '', TODA_AUDIT_DATE) . TODA_AUDIT_VENUE;
    foreach (todaAuditFiles($root, 'ledger/records') as $record) {
        if (!str_starts_with((string)($record['race_code'] ?? ''), $prefix)) continue;
        $captured = (string)($record['captured_at'] ?? '');
        $cutoff = (string)($record['cutoff_timestamp'] ?? '');
        $stable = (string)($record['stable_unique_key'] ?? '');
        try {
            $eligible = $stable !== ''
                && (new DateTimeImmutable($captured)) < (new DateTimeImmutable($cutoff));
        } catch (Throwable) {
            $eligible = false;
        }
        if (!$eligible) continue;
        $rows[] = [
            'stable_key' => $stable,
            'race_code' => (string)$record['race_code'],
            'captured_at' => $captured,
            'cutoff_timestamp' => $cutoff,
            'capture_source' => (string)($record['capture_source'] ?? ''),
            'mode' => (string)($record['capture_mode'] ?? ''),
        ];
    }
    usort($rows, static fn(array $a, array $b): int => [
        $a['captured_at'], $a['stable_key']
    ] <=> [
        $b['captured_at'], $b['stable_key']
    ]);
    $seen = [];
    foreach ($rows as $row) {
        if (isset($seen[$row['stable_key']])) throw new RuntimeException('CORRECTED_V2_DUPLICATE_STABLE_KEY');
        $seen[$row['stable_key']] = true;
    }
    return $rows;
}

function todaAuditCorrectedV2(): int
{
    // This correction reads the immutable Window that produced the original
    // raw-216 audit. It must not follow a later mutable operational pointer.
    $root = dirname(__DIR__) . '/var/strict_forward_live_readiness/windows/' . TODA_AUDIT_EVIDENCE_WINDOW_ID;
    if (!is_dir($root)) throw new RuntimeException('CORRECTED_V2_EVIDENCE_WINDOW_UNAVAILABLE');
    $membership = todaAuditCanonicalFormalMembership($root);
    $rawPrefix = str_replace('-', '', TODA_AUDIT_DATE) . TODA_AUDIT_VENUE;
    $raw = array_values(array_filter(todaAuditFiles($root, 'ledger/records'), static fn(array $row): bool =>
        str_starts_with((string)($row['race_code'] ?? ''), $rawPrefix)
    ));
    $framed = '';
    foreach ($membership as $row) $framed .= $row['stable_key'] . "\0" . $row['captured_at'] . "\0" . $row['cutoff_timestamp'] . "\n";
    $report = [
        'schema_version' => 'cc30c-toda-post12r-audit/v2-canonical-membership',
        'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format(DATE_ATOM),
        'source_window_id' => TODA_AUDIT_EVIDENCE_WINDOW_ID,
        'source_raw_rule' => 'race_code prefix 20261016TDA from immutable ledger/records',
        'formal_rule' => 'nonempty stable_unique_key AND captured_at < cutoff_timestamp',
        'raw_record_count' => count($raw),
        'formal_membership_count' => count($membership),
        'membership_sha256' => 'sha256:' . hash('sha256', $framed),
        'first_timestamp' => $membership === [] ? null : $membership[0]['captured_at'],
        'last_timestamp' => $membership === [] ? null : $membership[count($membership) - 1]['captured_at'],
        'formal_membership' => $membership,
        'historical_v1_preserved_path' => 'analysis/output/cc30c_toda_post12r_20261016.json',
        'historical_v1_not_overwritten' => true,
    ];
    todaAuditWrite(TODA_AUDIT_CORRECTED_V2_JSON, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    $readback = todaAuditJson(TODA_AUDIT_CORRECTED_V2_JSON);
    if ($readback !== $report) throw new RuntimeException('CORRECTED_V2_READBACK_MISMATCH');
    todaAuditWrite(TODA_AUDIT_CORRECTED_V2_TEXT, "CC30C Toda post-12R corrected v2\nraw_records=" . count($raw) . "\nformal_membership=" . count($membership) . "\nfirst_timestamp=" . ($report['first_timestamp'] ?? '') . "\nlast_timestamp=" . ($report['last_timestamp'] ?? '') . "\nmembership_sha256=" . $report['membership_sha256'] . "\n");
    return 0;
}

function todaAuditMain(array $argv): int
{
    if (in_array('--corrected-v2', array_slice($argv, 1), true)) return todaAuditCorrectedV2();
    $watch = in_array('--watch', array_slice($argv, 1), true);
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
    if ($now->format('Y-m-d') !== TODA_AUDIT_DATE) return 0;
    if ($watch && is_file(TODA_AUDIT_MARKER)) return 0;

    $deadline = todaAuditDeadline();
    $twelve = $deadline['map']['20261016TDA12'] ?? '';
    $passed = false; $attemptComplete = false;
    if ($twelve !== '') { $cutoff = new DateTimeImmutable(TODA_AUDIT_DATE . ' ' . $twelve, new DateTimeZone('Asia/Tokyo')); $passed = $now > $cutoff; $attemptComplete = $passed && todaAuditCapture12Complete($cutoff); }
    if ($watch && (!$passed || !$attemptComplete)) return 0;

    $runtime = (new OfficialEpochWindowBindingRuntimeAuthorization(dirname(__DIR__)))->authorizeOperationalPointerWindow();
    $root = ''; $window = (string)($runtime['window_id'] ?? '');
    if (($runtime['kind'] ?? '') === 'ACTIVE' && isset($runtime['context'])) $root = dirname($runtime['context']->windowManifestPath());
    try { $db = todaAuditDb(); $dbError = ''; } catch (Throwable $e) { $db = ['entry_by_race'=>[], 'exhibition_by_race'=>[], 'entry_rows'=>0]; $dbError = get_class($e); }
    $candidateRows = $root === '' ? [] : todaAuditFiles($root, 'opportunity/candidates');
    $candidates = array_values(array_filter($candidateRows, static fn(array $r): bool => ($r['venue'] ?? '')===TODA_AUDIT_VENUE && ($r['target_date'] ?? '')===TODA_AUDIT_DATE && ($r['record_type'] ?? '')==='SCHEDULER_CANDIDATE_UNIVERSE'));
    usort($candidates, static fn(array $a,array $b): int => strcmp((string)($a['universe_recorded_at']??''),(string)($b['universe_recorded_at']??'')));
    $collector = array_values(array_filter(todaAuditJsonLines(TODA_COLLECTOR_LOG), static fn(array $r): bool => ($r['window_id'] ?? '') === $window && str_starts_with((string)($r['race_code'] ?? ''), '20261016TDA')));
    $records = $root === '' ? [] : todaAuditFiles($root, 'ledger/records');
    $formal = array_values(array_filter($records, static function(array $r) use ($candidates): bool {
        $race=(string)($r['race_code']??''); if (!str_starts_with($race,'20261016TDA')) return false;
        try { return (new DateTimeImmutable((string)($r['recorded_at']??''))) < (new DateTimeImmutable((string)($r['cutoff_at']??''))); } catch (Throwable) { return false; }
    }));
    $entryComplete = count(array_filter($db['entry_by_race'], static fn(array $r): bool => $r['complete']));
    $exComplete = count(array_filter($db['exhibition_by_race']));
    $success = count(array_filter($collector, static fn(array $r): bool => in_array(($r['status']??''), ['FETCH_SUCCESS','ALREADY_SATISFIED'], true)));
    $errors = count(array_filter($collector, static fn(array $r): bool => in_array(($r['status']??''), ['FETCH_FAILED','GLOBAL_FATAL','PARTIAL_RACE_FAILURE'], true)));
    $status = $db['entry_rows']===0 ? 'NO_NATURAL_INPUT' : (count($candidates)===0 ? 'NATURAL_INPUT_READY' : (count($formal)>0 ? 'FORMAL_EVIDENCE_CAPTURED' : 'NATURAL_CHAIN_IN_PROGRESS'));
    $gate = count($formal)>0 && count($candidates)>0 && $success>0 ? 'PASS' : 'WAIT';
    $perRace=[]; for($n=1;$n<=12;$n++){ $race='20261016TDA'.str_pad((string)$n,2,'0',STR_PAD_LEFT); $perRace[$race]=['entry_complete'=>(bool)($db['entry_by_race'][$race]['complete']??false),'exhibition_complete'=>(bool)($db['exhibition_by_race'][$race]??false)]; }
    $report=['schema_version'=>'cc30c-toda-post12r-audit/v1','generated_at'=>$now->format(DATE_ATOM),'date'=>TODA_AUDIT_DATE,'venue'=>TODA_AUDIT_VENUE,'window_id'=>$window,'deadline'=>['exists'=>$deadline['exists'],'schema_ok'=>$deadline['schema_ok'],'toda_12r'=>$twelve,'all_12_present'=>count($deadline['map'])===12],'toda_12r_finished'=>$passed,'toda_12r_capture_attempt_complete'=>$attemptComplete,'race_entry'=>['row_count'=>$db['entry_rows'],'distinct_race_count'=>count($db['entry_by_race']),'complete_race_count'=>$entryComplete,'per_race'=>$perRace,'db_error'=>$dbError],'exhibition'=>['race_count'=>count($db['exhibition_by_race']),'complete_race_count'=>$exComplete],'candidate'=>['count'=>count($candidates),'race_codes'=>array_values(array_map(static fn(array $r)=>(string)$r['race_code'],$candidates)),'first_timestamp'=>$candidates===[]?null:($candidates[0]['universe_recorded_at']??null),'last_timestamp'=>$candidates===[]?null:($candidates[count($candidates)-1]['universe_recorded_at']??null),'manual_candidate'=>0],'collector'=>['processed'=>count($collector),'success'=>$success,'pending'=>count($collector)-$success-$errors,'error'=>$errors,'manual_collector_run'=>0],'downstream'=>['expected_count'=>count($root===''?[]:todaAuditFiles($root,'ledger/expected'))],'formal_evidence'=>['count'=>count($formal),'race_codes'=>array_values(array_unique(array_map(static fn(array $r)=>(string)$r['race_code'],$formal))),'first_timestamp'=>$formal===[]?null:($formal[0]['recorded_at']??null),'future_only'=>count($formal)>0],'classification'=>$status,'cc30c_4o_gate'=>$gate,'cc30c_4o_2_auto_run'=>'NO_UNVERIFIED_READ_ONLY_CONTRACT','next_action'=>$gate==='PASS'?'CC30C_4O_READY':'WAIT_NATURAL_CHAIN'];
    $json=json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
    todaAuditWrite(TODA_AUDIT_JSON,$json); todaAuditWrite(TODA_AUDIT_STATUS,json_encode(['status'=>$status,'toda_12r_finished'=>$passed,'race_entry_complete'=>$entryComplete,'exhibition_complete'=>$exComplete,'formal_evidence_count'=>count($formal),'cc30c_4o_gate'=>$gate,'next_action'=>$report['next_action'],'updated_at'=>$report['generated_at']],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL);
    todaAuditWrite(TODA_AUDIT_TEXT,"CC30C Toda post-12R\nstatus={$status}\nentry_complete={$entryComplete}\nexhibition_complete={$exComplete}\ncandidates=".count($candidates)."\nformal_evidence=".count($formal)."\nCC30C_4O_GATE={$gate}\nNEXT_ACTION={$report['next_action']}\n");
    if ($watch) todaAuditWrite(TODA_AUDIT_MARKER,json_encode(['completed_at'=>$report['generated_at'],'report'=>basename(TODA_AUDIT_JSON),'cc30c_4o_gate'=>$gate],JSON_PRETTY_PRINT).PHP_EOL);
    return 0;
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) exit(todaAuditMain($argv));
