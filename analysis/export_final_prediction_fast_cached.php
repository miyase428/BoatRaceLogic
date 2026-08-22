<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/FinalPredictionExporter.php';
require_once __DIR__ . '/lib/CachedKimariteApiClientProduction.php';
require_once __DIR__ . '/../common/db_connect.php';

/**
 * FinalPredictionExporter の予想ロジックは変更せず、
 * 1) kimariteを対象期間まとめてキャッシュ生成
 * 2) そのキャッシュを各workerから利用
 * 3) レース単位は4worker前後で並列実行
 * する高速版。
 *
 * 使い方:
 *   php analysis/export_final_prediction_fast_cached.php 2026-08-01 2026-08-02
 *   php analysis/export_final_prediction_fast_cached.php 2026-08-01 2026-08-02 4
 *
 * 出力:
 *   analysis/output/final_prediction_boats_fast_cached_YYYYMMDD_YYYYMMDD.csv
 *   analysis/output/final_prediction_races_fast_cached_YYYYMMDD_YYYYMMDD.csv
 */

function usage(): never
{
    fwrite(STDERR,
        "使用方法:\n" .
        "  php analysis/export_final_prediction_fast_cached.php START_DATE END_DATE [WORKERS]\n\n" .
        "例:\n" .
        "  php analysis/export_final_prediction_fast_cached.php 2026-08-14 2026-08-14 4\n"
    );
    exit(1);
}

function boatsHeader(): array
{
    return [
        'race_code','race_date','stadium_name','race_number',
        'lane_number','player_id','player_name',
        'first_total_score','first_type','first_eval','first_rank',
        'three_in_rate_6m','three_in_rate_3m',
        'second_score','second_rank',
        'kitai','final_type','type_bonus','final3','get_bonus','kiru','final_rank',
        'actual_rank',
    ];
}

function racesHeader(): array
{
    return [
        'race_code','race_date','stadium_name','race_number',
        'honmei_head','taikou_head',
        'honmei_aite_str','taikou_aite_str','kiru_str',
        'honmei_kai','taikou_kai',
        'actual_1st','actual_2nd','actual_3rd','actual_trifecta',
    ];
}

function writeExportRows($boatsFp, $racesFp, string $raceCode, array $data): void
{
    $master = $data['race_master'];
    $boats = $data['boats'];
    $ranks = $data['ranks'];
    $summary = $data['summary'];
    $actual = $data['actual'];

    foreach ($boats as $lane => $boat) {
        fputcsv($boatsFp, [
            $raceCode,
            $master['race_date'],
            $master['stadium_name'],
            $master['race_number'],
            $lane,
            $boat['player_id'],
            $boat['player_name'],
            $boat['first_total_score'],
            $boat['first_type'],
            $boat['first_eval'],
            $ranks['first'][$lane] ?? '',
            $boat['three_in_rate_6m'],
            $boat['three_in_rate_3m'],
            $boat['second_score'],
            $ranks['second'][$lane] ?? '',
            $boat['kitai'],
            $boat['final_type'],
            $boat['type_bonus'],
            $boat['final3'],
            $boat['get_bonus'],
            $boat['kiru'],
            $ranks['final'][$lane] ?? '',
            $boat['actual_rank'],
        ]);
    }

    fputcsv($racesFp, [
        $raceCode,
        $master['race_date'],
        $master['stadium_name'],
        $master['race_number'],
        $summary['honmei_head'] ?? '',
        $summary['taikou_head'] ?? '',
        $summary['honmei_aite_str'] ?? '',
        $summary['taikou_aite_str'] ?? '',
        $summary['kiru_str'] ?? '',
        $summary['honmei_kai'] ?? '',
        $summary['taikou_kai'] ?? '',
        $actual['first'] ?? '',
        $actual['second'] ?? '',
        $actual['third'] ?? '',
        $actual['trifecta'] ?? '',
    ]);
}

function generateKimariteCache(string $startDate, string $endDate, string $expectedPath): void
{
    $script = __DIR__ . '/export_kimarite_cache.py';
    if (!is_file($script)) {
        throw new RuntimeException("決まり手キャッシュ生成スクリプトがありません: {$script}");
    }

    echo "決まり手キャッシュを生成中...\n";
    $cmd = '/usr/bin/python3 '
        . escapeshellarg($script) . ' '
        . escapeshellarg($startDate) . ' '
        . escapeshellarg($endDate);

    passthru($cmd, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException("決まり手キャッシュ生成に失敗しました (exit={$exitCode})");
    }

    if (!is_file($expectedPath)) {
        throw new RuntimeException("生成後の決まり手キャッシュが見つかりません: {$expectedPath}");
    }
}

function runWorker(
    int $workerNo,
    array $raceMasters,
    string $tmpPrefix,
    string $kimariteCachePath,
    int $progressEvery = 100
): int {
    $boatsPart = "{$tmpPrefix}.w{$workerNo}.boats.csv";
    $racesPart = "{$tmpPrefix}.w{$workerNo}.races.csv";
    $statPart = "{$tmpPrefix}.w{$workerNo}.stat.json";

    $boatsFp = fopen($boatsPart, 'wb');
    $racesFp = fopen($racesPart, 'wb');
    if ($boatsFp === false || $racesFp === false) {
        fwrite(STDERR, "worker {$workerNo}: 一時CSVを作成できません。\n");
        return 2;
    }

    // fork後に各worker専用PDOを持つ。kimariteだけは期間キャッシュから取得する。
    $apiClient = new CachedKimariteApiClientProduction($kimariteCachePath);
    $exporter = new FinalPredictionExporter($apiClient);

    $success = 0;
    $errors = [];
    $total = count($raceMasters);

    foreach ($raceMasters as $i => $raceMaster) {
        $raceCode = (string)$raceMaster['race_code'];

        try {
            $data = $exporter->exportRace($raceCode);
            writeExportRows($boatsFp, $racesFp, $raceCode, $data);
            $success++;
        } catch (Throwable $e) {
            $errors[] = [
                'race_code' => $raceCode,
                'error' => $e->getMessage(),
            ];
        }

        $done = $i + 1;
        if ($done % $progressEvery === 0 || $done === $total) {
            echo sprintf(
                "[worker %d] %d/%d 完了 (成功%d / エラー%d)\n",
                $workerNo,
                $done,
                $total,
                $success,
                count($errors)
            );
        }
    }

    fclose($boatsFp);
    fclose($racesFp);

    file_put_contents($statPart, json_encode([
        'worker' => $workerNo,
        'total' => $total,
        'success' => $success,
        'error_count' => count($errors),
        'errors' => $errors,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    return 0;
}

function appendFileToStream(string $path, $destFp): void
{
    $src = fopen($path, 'rb');
    if ($src === false) {
        throw new RuntimeException("一時ファイルを開けません: {$path}");
    }
    stream_copy_to_stream($src, $destFp);
    fclose($src);
}

$args = array_slice($argv, 1);
if (count($args) < 2 || count($args) > 3) {
    usage();
}

$startDate = trim((string)$args[0]);
$endDate = trim((string)$args[1]);
$workers = isset($args[2]) ? (int)$args[2] : 4;
$workers = max(1, min(8, $workers));

if ($startDate > $endDate) {
    fwrite(STDERR, "開始日が終了日より後です。\n");
    exit(1);
}

if ($workers > 1 && !function_exists('pcntl_fork')) {
    fwrite(STDERR,
        "pcntl拡張がありません。worker=1なら実行できます。\n" .
        "確認: php -m | grep pcntl\n"
    );
    exit(1);
}

$pdo = getPDO();
$stmt = $pdo->prepare(<<<SQL
    SELECT race_code, race_date, stadium_name, race_number
    FROM boat_race.race_master
    WHERE race_date >= :start_date
      AND race_date <= :end_date
    ORDER BY race_date, race_code
SQL);
$stmt->execute([
    ':start_date' => $startDate,
    ':end_date' => $endDate,
]);
$raceMasters = $stmt->fetchAll(PDO::FETCH_ASSOC);
$pdo = null;

if (!$raceMasters) {
    fwrite(STDERR, "指定期間にレースがありません。\n");
    exit(1);
}

$totalRaces = count($raceMasters);
$workers = min($workers, $totalRaces);
$chunkSize = (int)ceil($totalRaces / $workers);
$chunks = [];
for ($w = 0; $w < $workers; $w++) {
    $chunk = array_slice($raceMasters, $w * $chunkSize, $chunkSize);
    if ($chunk) {
        $chunks[] = $chunk;
    }
}
$workers = count($chunks);

$outputDir = __DIR__ . '/output';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0775, true);
}

$startLabel = str_replace('-', '', $startDate);
$endLabel = str_replace('-', '', $endDate);
$boatsCsv = "{$outputDir}/final_prediction_boats_fast_cached_{$startLabel}_{$endLabel}.csv";
$racesCsv = "{$outputDir}/final_prediction_races_fast_cached_{$startLabel}_{$endLabel}.csv";
$kimariteCachePath = "{$outputDir}/kimarite_cache_{$startLabel}_{$endLabel}.json";
$tmpPrefix = "{$outputDir}/.tmp_prediction_fast_cached_" . getmypid();

$startedAt = microtime(true);

try {
    // 毎回再生成して、DB修正後に古いキャッシュを誤利用しないようにする。
    generateKimariteCache($startDate, $endDate, $kimariteCachePath);
} catch (Throwable $e) {
    fwrite(STDERR, "エラー: {$e->getMessage()}\n");
    exit(2);
}

echo "\n";
echo "============================================================\n";
echo "現行最終予想 決まり手キャッシュ+並列高速CSV出力\n";
echo "============================================================\n";
echo "期間      : {$startDate} ～ {$endDate}\n";
echo "対象      : {$totalRaces}レース\n";
echo "workers   : {$workers}\n";
echo "kimarite  : 期間キャッシュ使用\n";
echo "他ロジック: FinalPredictionExporterと同一\n";
echo "============================================================\n\n";

$childPids = [];
$workerExitFailed = false;

if ($workers === 1) {
    $code = runWorker(1, $chunks[0], $tmpPrefix, $kimariteCachePath);
    if ($code !== 0) {
        exit($code);
    }
} else {
    foreach ($chunks as $idx => $chunk) {
        $workerNo = $idx + 1;
        $pid = pcntl_fork();

        if ($pid === -1) {
            fwrite(STDERR, "worker {$workerNo} のforkに失敗しました。\n");
            $workerExitFailed = true;
            break;
        }

        if ($pid === 0) {
            try {
                $code = runWorker(
                    $workerNo,
                    $chunk,
                    $tmpPrefix,
                    $kimariteCachePath
                );
                exit($code);
            } catch (Throwable $e) {
                fwrite(STDERR, "worker {$workerNo} fatal: {$e->getMessage()}\n");
                exit(2);
            }
        }

        $childPids[$pid] = $workerNo;
    }

    foreach ($childPids as $pid => $workerNo) {
        $status = 0;
        pcntl_waitpid((int)$pid, $status);
        $exitCode = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 2;
        if ($exitCode !== 0) {
            fwrite(STDERR, "worker {$workerNo} が異常終了しました (exit={$exitCode})。\n");
            $workerExitFailed = true;
        }
    }
}

if ($workerExitFailed) {
    fwrite(STDERR, "worker異常終了のため最終CSVを結合しません。\n");
    exit(2);
}

$boatsFp = fopen($boatsCsv, 'wb');
$racesFp = fopen($racesCsv, 'wb');
if ($boatsFp === false || $racesFp === false) {
    fwrite(STDERR, "最終CSVを作成できません。\n");
    exit(2);
}

fwrite($boatsFp, "\xEF\xBB\xBF");
fwrite($racesFp, "\xEF\xBB\xBF");
fputcsv($boatsFp, boatsHeader());
fputcsv($racesFp, racesHeader());

$successCount = 0;
$errorCount = 0;
$errors = [];

for ($workerNo = 1; $workerNo <= $workers; $workerNo++) {
    $boatsPart = "{$tmpPrefix}.w{$workerNo}.boats.csv";
    $racesPart = "{$tmpPrefix}.w{$workerNo}.races.csv";
    $statPart = "{$tmpPrefix}.w{$workerNo}.stat.json";

    appendFileToStream($boatsPart, $boatsFp);
    appendFileToStream($racesPart, $racesFp);

    $stat = json_decode((string)file_get_contents($statPart), true) ?: [];
    $successCount += (int)($stat['success'] ?? 0);
    $errorCount += (int)($stat['error_count'] ?? 0);
    foreach (($stat['errors'] ?? []) as $error) {
        $errors[] = $error;
    }

    @unlink($boatsPart);
    @unlink($racesPart);
    @unlink($statPart);
}

fclose($boatsFp);
fclose($racesFp);

$elapsed = microtime(true) - $startedAt;
$perRace = $totalRaces > 0 ? $elapsed / $totalRaces : 0.0;

echo "\n";
echo "============================================================\n";
echo "決まり手キャッシュ+並列高速CSV出力完了\n";
echo "============================================================\n";
echo "対象       : {$totalRaces}\n";
echo "成功       : {$successCount}\n";
echo "エラー     : {$errorCount}\n";
echo sprintf("総所要時間 : %.1f秒 (%.3f秒/レース)\n", $elapsed, $perRace);
echo "艇別CSV    : {$boatsCsv}\n";
echo "レースCSV  : {$racesCsv}\n";

if ($errors) {
    echo "\n【エラー一覧】\n";
    foreach ($errors as $error) {
        echo ($error['race_code'] ?? '') . ' : ' . ($error['error'] ?? '') . "\n";
    }
}

echo "\n";
