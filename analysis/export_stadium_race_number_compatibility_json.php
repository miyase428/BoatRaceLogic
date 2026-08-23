<?php

declare(strict_types=1);

/**
 * 場×R予想相性の画面表示用JSONを生成する。
 *
 * analyze_stadium_race_number_compatibility.php を全場モードで1回だけ実行し、
 * その集計結果を config/stadium_race_number_compatibility.local.json に保存する。
 * local.json はGit管理外で、画面側は存在すればこちらを優先して読む。
 *
 * Usage:
 * php analysis/export_stadium_race_number_compatibility_json.php \
 *   analysis/output/final_prediction_races_fast_cached_20250815_20260814.csv \
 *   analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv
 */

if ($argc !== 3) {
    fwrite(STDERR,
        "使用方法:\n" .
        "  php {$argv[0]} RACES_CSV BOATS_CSV\n"
    );
    exit(1);
}

$racesPath = $argv[1];
$boatsPath = $argv[2];

foreach ([$racesPath, $boatsPath] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException("必要ファイルがありません: {$path}");
    }
}

$analyzerPath = __DIR__ . '/analyze_stadium_race_number_compatibility.php';
if (!is_file($analyzerPath)) {
    throw new RuntimeException("分析スクリプトがありません: {$analyzerPath}");
}

$affinityPath = __DIR__ . '/../config/stadium_affinity.json';
if (!is_file($affinityPath)) {
    throw new RuntimeException("場コード対応用JSONがありません: {$affinityPath}");
}

$affinityJson = file_get_contents($affinityPath);
$affinity = is_string($affinityJson) ? json_decode($affinityJson, true) : null;
if (!is_array($affinity)) {
    throw new RuntimeException('stadium_affinity.json を読み込めません。');
}

$nameToCode = [];
foreach (($affinity['stadiums'] ?? []) as $code => $row) {
    if (!is_array($row)) {
        continue;
    }
    $name = trim((string)($row['name'] ?? ''));
    if ($name !== '') {
        $nameToCode[$name] = (string)$code;
    }
}

$command = escapeshellarg(PHP_BINARY)
    . ' ' . escapeshellarg($analyzerPath)
    . ' ' . escapeshellarg($racesPath)
    . ' ' . escapeshellarg($boatsPath);

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open($command, $descriptors, $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('場×R相性分析を起動できません。');
}

fclose($pipes[0]);
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

if ($exitCode !== 0) {
    fwrite(STDERR, (string)$stderr);
    throw new RuntimeException("場×R相性分析に失敗しました (exit={$exitCode})");
}

function pctValue(string $value): float
{
    return (float)rtrim(trim($value), '%');
}

function sourceDateRange(string $path): array
{
    $base = basename($path);
    if (preg_match('/_(\d{8})_(\d{8})\.csv$/', $base, $m) !== 1) {
        return ['', ''];
    }

    $format = static function (string $yyyymmdd): string {
        return substr($yyyymmdd, 0, 4)
            . '-' . substr($yyyymmdd, 4, 2)
            . '-' . substr($yyyymmdd, 6, 2);
    };

    return [$format($m[1]), $format($m[2])];
}

[$sourceStart, $sourceEnd] = sourceDateRange($racesPath);

$stadiums = [];
$currentCode = null;

foreach (preg_split('/\R/u', (string)$stdout) as $line) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }

    if (preg_match(
        '/^【(.+?)】\s+全体N=(\d+)\s+\/\s+直近約6か月=([^～]+)～([^\s]+)\s+\(N=(\d+)\)$/u',
        $line,
        $m
    ) === 1) {
        $name = trim($m[1]);
        $code = $nameToCode[$name] ?? null;
        $currentCode = is_string($code) ? $code : null;

        if ($currentCode !== null) {
            $stadiums[$currentCode] = [
                'name' => $name,
                'total_n' => (int)$m[2],
                'recent_start' => trim($m[3]),
                'recent_end' => trim($m[4]),
                'recent_n' => (int)$m[5],
                'place_average' => [],
                'races' => [],
            ];
        }
        continue;
    }

    if ($currentCode === null) {
        continue;
    }

    if (str_starts_with($line, '場平均:')) {
        if (preg_match(
            '/本命1着=([\d.]+)%.*TOP3≧2艇=([\d.]+)%.*TOP3=3艇=([\d.]+)%.*買い目的中=([\d.]+)%.*回収率=([\d.]+)%/u',
            $line,
            $m
        ) === 1) {
            $stadiums[$currentCode]['place_average'] = [
                'honmei_first_rate' => (float)$m[1],
                'top3_2plus_rate' => (float)$m[2],
                'top3_3_rate' => (float)$m[3],
                'bet_hit_rate' => (float)$m[4],
                'roi' => (float)$m[5],
            ];
        }
        continue;
    }

    $parts = preg_split('/\s+/u', $line);
    if (!is_array($parts) || count($parts) < 12 || preg_match('/^(\d{1,2})R$/', $parts[0], $rm) !== 1) {
        continue;
    }

    $raceNo = (int)$rm[1];
    if ($raceNo < 1 || $raceNo > 12) {
        continue;
    }

    $grade = '参考';
    $score = null;
    if (preg_match('/^(A|B|C|D)(?:\((\d+)\/6\))?$/u', $parts[11], $gm) === 1) {
        $grade = $gm[1];
        $score = isset($gm[2]) && $gm[2] !== '' ? (int)$gm[2] : null;
    } elseif ($parts[11] === '参考') {
        $grade = '参考';
    }

    $stadiums[$currentCode]['races'][(string)$raceNo] = [
        'n' => (int)$parts[1],
        'honmei_first_rate' => pctValue($parts[2]),
        'honmei_top3_rate' => pctValue($parts[3]),
        'top3_2plus_rate' => pctValue($parts[4]),
        'top3_3_rate' => pctValue($parts[5]),
        'bet_hit_rate' => pctValue($parts[6]),
        'roi' => pctValue($parts[7]),
        'recent_n' => (int)$parts[8],
        'recent_honmei_first_rate' => pctValue($parts[9]),
        'recent_bet_hit_rate' => pctValue($parts[10]),
        'grade' => $grade,
        'score' => $score,
    ];
}

foreach ($stadiums as &$stadium) {
    ksort($stadium['races'], SORT_NUMERIC);
}
unset($stadium);
ksort($stadiums, SORT_STRING);

if ($stadiums === []) {
    throw new RuntimeException('分析結果から場データを抽出できませんでした。');
}

$output = [
    'meta' => [
        'label' => '過去1年＋直近約6か月',
        'start_date' => $sourceStart,
        'end_date' => $sourceEnd,
        'generated_at' => date(DATE_ATOM),
        'generated_from' => 'analyze_stadium_race_number_compatibility.php',
        'stadium_count' => count($stadiums),
        'note' => 'A/Bは現行Webとの噛み合いやすさの参考。回収率100%以上を意味しない。',
    ],
    'stadiums' => $stadiums,
];

$outputPath = __DIR__ . '/../config/stadium_race_number_compatibility.local.json';
$json = json_encode(
    $output,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);

if (!is_string($json)) {
    throw new RuntimeException('JSON生成に失敗しました。');
}

if (file_put_contents($outputPath, $json . PHP_EOL) === false) {
    throw new RuntimeException("JSONを書き込めません: {$outputPath}");
}

echo PHP_EOL;
echo "========================================" . PHP_EOL;
echo "場×R予想相性 JSON出力完了" . PHP_EOL;
echo "========================================" . PHP_EOL;
echo "場数 : " . count($stadiums) . PHP_EOL;
echo "出力 : {$outputPath}" . PHP_EOL;

if (count($stadiums) < 24) {
    echo "注意 : 24場未満です。元CSVに含まれる開催数を確認してください。" . PHP_EOL;
}
