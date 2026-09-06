<?php

declare(strict_types=1);

/**
 * 当日の配当サイン候補から、穴目方式が固定済みの
 * 「イン崩壊」「ヒモ荒れ」を1レース診断と同じ条件で一括確認する。
 *
 * - 候補抽出は list_today_payout_signals.php の共通判定をそのまま利用する。
 * - 個別診断は inspect_payout_signal_hole_bet.php を利用する。
 * - 画面ロジックや本命/対抗/既存買い目は変更しない。
 * - 全詳細は analysis/output/payout_signal_hole_bets_YYYYMMDD.txt に保存する。
 *
 * Usage:
 *   php analysis/inspect_today_payout_signal_hole_bets.php
 *   php analysis/inspect_today_payout_signal_hole_bets.php 2026-09-06
 *   php analysis/inspect_today_payout_signal_hole_bets.php 2026-09-06 finished
 *   php analysis/inspect_today_payout_signal_hole_bets.php 2026-09-06 all
 *
 * mode:
 *   finished : 結果済みだけ（答え合わせ向け）
 *   all      : 結果前も含む（今日の穴目候補確認向け）
 */

require_once __DIR__ . '/../common/db_connect.php';

date_default_timezone_set('Asia/Tokyo');

$date = trim((string)($argv[1] ?? date('Y-m-d')));
$mode = strtolower(trim((string)($argv[2] ?? 'finished')));
if (!in_array($mode, ['finished', 'all'], true)) {
    fwrite(STDERR, "mode は finished または all を指定してください。\n");
    exit(1);
}

$dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if ($dt === false || $dt->format('Y-m-d') !== $date) {
    fwrite(STDERR, "日付は YYYY-MM-DD 形式で指定してください。\n");
    exit(1);
}

$placeCodes = [
    '桐生'=>'KRY','戸田'=>'TDA','江戸川'=>'EDG','平和島'=>'HWJ','多摩川'=>'TMG','浜名湖'=>'HMN',
    '蒲郡'=>'GMG','常滑'=>'TKN','津'=>'TSU','三国'=>'MKN','びわこ'=>'BWK','住之江'=>'SME',
    '尼崎'=>'AMG','鳴門'=>'NRT','丸亀'=>'MRG','児島'=>'KJM','宮島'=>'MYJ','徳山'=>'TKY',
    '下関'=>'SMS','若松'=>'WKM','芦屋'=>'ASY','福岡'=>'FKO','唐津'=>'KRT','大村'=>'OMR',
];

$listScript = __DIR__ . '/list_today_payout_signals.php';
$inspectScript = __DIR__ . '/inspect_payout_signal_hole_bet.php';
if (!is_file($listScript) || !is_file($inspectScript)) {
    fwrite(STDERR, "必要な診断スクリプトが見つかりません。\n");
    exit(1);
}

$cmd = escapeshellarg(PHP_BINARY)
    . ' ' . escapeshellarg($listScript)
    . ' ' . escapeshellarg($date)
    . ' all';
$listOutput = shell_exec($cmd . ' 2>&1');
if (!is_string($listOutput) || trim($listOutput) === '') {
    fwrite(STDERR, "配当サイン候補一覧を取得できませんでした。\n");
    exit(1);
}

$candidates = [];
foreach (preg_split('/\R/u', $listOutput) ?: [] as $line) {
    // 例:
    // 鳴門    2R Web反映 | ... | イン崩壊 | ...
    if (!preg_match('/^\s*(\S+)\s+(\d{1,2})R\s+(Web反映|暫定)\s+\|.*\|\s*(イン崩壊|ヒモ荒れ|複合高配当|平常)\s*\|/u', $line, $m)) {
        continue;
    }
    $placeName = (string)$m[1];
    $raceNo = (int)$m[2];
    $state = (string)$m[3];
    $chaos = (string)$m[4];
    if (!in_array($chaos, ['イン崩壊', 'ヒモ荒れ'], true)) {
        continue;
    }
    $placeCode = $placeCodes[$placeName] ?? null;
    if ($placeCode === null) {
        continue;
    }
    $raceCode = $dt->format('Ymd') . $placeCode . sprintf('%02d', $raceNo);
    $candidates[$raceCode] = [
        'race_code' => $raceCode,
        'place' => $placeName,
        'race_no' => $raceNo,
        'state' => $state,
        'chaos' => $chaos,
    ];
}

if ($candidates === []) {
    echo "穴目方式が固定済みの候補（イン崩壊/ヒモ荒れ）はありません。\n";
    exit(0);
}

// 結果済み判定をまとめて取得する。
$finishedMap = [];
try {
    $pdo = getPDO();
    $prefix = $dt->format('Ymd') . '%';
    $stmt = $pdo->prepare(
        "SELECT race_code, COUNT(*)::int AS n\n"
        . "FROM boat_race.race_result_detail\n"
        . "WHERE race_code LIKE :prefix\n"
        . "GROUP BY race_code"
    );
    $stmt->execute([':prefix' => $prefix]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $finishedMap[(string)$row['race_code']] = ((int)($row['n'] ?? 0)) > 0;
    }
} catch (Throwable $e) {
    fwrite(STDERR, "結果済み判定の取得に失敗: {$e->getMessage()}\n");
    exit(1);
}

$targets = [];
foreach ($candidates as $code => $row) {
    $finished = !empty($finishedMap[$code]);
    if ($mode === 'finished' && !$finished) {
        continue;
    }
    $row['finished'] = $finished;
    $targets[] = $row;
}

usort($targets, static function (array $a, array $b): int {
    $typeOrder = ['イン崩壊' => 0, 'ヒモ荒れ' => 1];
    $ta = $typeOrder[$a['chaos']] ?? 9;
    $tb = $typeOrder[$b['chaos']] ?? 9;
    if ($ta !== $tb) return $ta <=> $tb;
    if ($a['place'] !== $b['place']) return strcmp((string)$a['place'], (string)$b['place']);
    return (int)$a['race_no'] <=> (int)$b['race_no'];
});

$outputDir = __DIR__ . '/output';
if (!is_dir($outputDir)) {
    @mkdir($outputDir, 0775, true);
}
$outPath = $outputDir . '/payout_signal_hole_bets_' . $dt->format('Ymd') . '.txt';
$full = [];
$summaryRows = [];

foreach ($targets as $idx => $target) {
    $raceCode = (string)$target['race_code'];
    fprintf(STDERR, "[%d/%d] %s %dR %s を診断中...\n",
        $idx + 1,
        count($targets),
        (string)$target['place'],
        (int)$target['race_no'],
        (string)$target['chaos']
    );

    $inspectCmd = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($inspectScript)
        . ' ' . escapeshellarg($raceCode);
    $detail = shell_exec($inspectCmd . ' 2>&1');
    if (!is_string($detail)) {
        $detail = '';
    }
    $full[] = trim($detail);

    $modeText = '-';
    $payoutText = '-';
    $strategy = '-';
    $betForms = [];
    $points = null;
    $actual = '-';
    $hit = '-';

    if (preg_match('/^判定モード\s*:\s*(.+)$/mu', $detail, $m)) {
        $modeText = trim((string)$m[1]);
    }
    if (preg_match('/^配当傾向\s*:\s*(.+)$/mu', $detail, $m)) {
        $payoutText = trim((string)$m[1]);
    }
    if (preg_match('/^穴目方式\s*:\s*(.+)$/mu', $detail, $m)) {
        $strategy = trim((string)$m[1]);
    }
    if (preg_match_all('/^頭\s+\d+号艇\([^)]*\):\s*([^\s]+)\s+\d+点$/mu', $detail, $m)) {
        $betForms = array_values(array_filter(array_map('trim', $m[1] ?? [])));
    }
    if (preg_match('/^合計\s*:\s*(\d+)点/mu', $detail, $m)) {
        $points = (int)$m[1];
    }
    if (preg_match('/^実3連単\s*:\s*([^\s]+)(?:\s*\/\s*([\d,]+)円)?$/mu', $detail, $m)) {
        $actual = trim((string)$m[1]);
        if (isset($m[2]) && $m[2] !== '') {
            $actual .= ' / ' . $m[2] . '円';
        }
    }
    if (preg_match('/^穴目\s*:\s*(的中|不的中)$/mu', $detail, $m)) {
        $hit = (string)$m[1];
    }

    $summaryRows[] = [
        'place' => (string)$target['place'],
        'race_no' => (int)$target['race_no'],
        'finished' => !empty($target['finished']),
        'chaos' => (string)$target['chaos'],
        'mode' => $modeText,
        'payout' => $payoutText,
        'strategy' => $strategy,
        'bet' => $betForms === [] ? '-' : implode(' / ', $betForms),
        'points' => $points,
        'actual' => $actual,
        'hit' => $hit,
    ];
}

file_put_contents($outPath, implode("\n\n", $full) . "\n");

$line = str_repeat('=', 174);
echo $line . "\n";
echo "当日 配当サイン連動・穴目一括診断\n";
echo "日付       : {$date}\n";
echo "対象       : " . ($mode === 'finished' ? '結果済みのみ' : '結果前を含む') . "\n";
echo "候補       : " . count($targets) . "R（イン崩壊/ヒモ荒れのみ）\n";
echo "詳細保存   : {$outPath}\n";
echo $line . "\n";
printf("%-8s %3s %-10s %-12s %-12s %-30s %5s %-22s %-8s\n",
    '場', 'R', '荒れ方', 'モード', '結果状態', '穴目', '点数', '実3連単', '判定'
);
echo str_repeat('-', 174) . "\n";

$hits = 0;
$finishedN = 0;
foreach ($summaryRows as $r) {
    if ($r['finished']) $finishedN++;
    if ($r['hit'] === '的中') $hits++;
    printf("%-8s %2dR %-10s %-12s %-12s %-30s %5s %-22s %-8s\n",
        $r['place'],
        $r['race_no'],
        $r['chaos'],
        $r['mode'],
        $r['finished'] ? '結果済' : '結果前',
        $r['bet'],
        $r['points'] === null ? '-' : ((string)$r['points'] . '点'),
        $r['actual'],
        $r['hit']
    );
}

echo str_repeat('-', 174) . "\n";
echo "結果済み   : {$finishedN}R\n";
if ($finishedN > 0) {
    echo "穴目的中   : {$hits}R / {$finishedN}R = " . number_format($hits / $finishedN * 100.0, 2) . "%\n";
}
echo "※個別のAI3連対率・2着条件付き率・全買い目は詳細保存ファイルにあります。\n";
echo $line . "\n";
