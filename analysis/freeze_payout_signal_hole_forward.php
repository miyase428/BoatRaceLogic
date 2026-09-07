<?php

declare(strict_types=1);

/**
 * 穴目予想の前方検証用スナップショットを、レース単位・段階単位で不変保存する。
 *
 * stage:
 * - provisional : 「暫定」の予想だけ保存
 * - exhibition  : 「展示反映済」の予想だけ保存
 *
 * 重要:
 * - 実際の予想生成は list_payout_signal_hole_predictions.php を使う。
 * - その生成スクリプト自体が race_result_detail / race_payouts を参照しない。
 * - BOAT RACE公式から読むのは締切予定時刻だけ。
 * - 締切予定時刻を取得できないレース、保存時刻が締切以後のレースは保存しない。
 * - 同じ race_code + stage は fopen(..., 'x') で一度だけ保存し、上書きしない。
 * - exhibition は何度実行しても、展示反映済になった未保存レースだけが追加される。
 * - GAP5_INNER は「B寄り参考」のままで、表示入替ルールとしては未採用。
 *
 * Usage:
 *   php analysis/freeze_payout_signal_hole_forward.php 2026-09-08 provisional
 *   php analysis/freeze_payout_signal_hole_forward.php 2026-09-08 exhibition
 */

date_default_timezone_set('Asia/Tokyo');

function failHoleForward(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

/** @return array<string,string> */
function placeCodeMapHoleForward(): array
{
    return [
        '桐生'=>'KRY','戸田'=>'TDA','江戸川'=>'EDG','平和島'=>'HWJ','多摩川'=>'TMG','浜名湖'=>'HMN',
        '蒲郡'=>'GMG','常滑'=>'TKN','津'=>'TSU','三国'=>'MKN','びわこ'=>'BWK','住之江'=>'SME',
        '尼崎'=>'AMG','鳴門'=>'NRT','丸亀'=>'MRG','児島'=>'KJM','宮島'=>'MYJ','徳山'=>'TKY',
        '下関'=>'SMS','若松'=>'WKM','芦屋'=>'ASY','福岡'=>'FKO','唐津'=>'KRT','大村'=>'OMR',
    ];
}

/** @return array<string,string> */
function officialJcdMapHoleForward(): array
{
    return [
        '桐生'=>'01','戸田'=>'02','江戸川'=>'03','平和島'=>'04','多摩川'=>'05','浜名湖'=>'06',
        '蒲郡'=>'07','常滑'=>'08','津'=>'09','三国'=>'10','びわこ'=>'11','住之江'=>'12',
        '尼崎'=>'13','鳴門'=>'14','丸亀'=>'15','児島'=>'16','宮島'=>'17','徳山'=>'18',
        '下関'=>'19','若松'=>'20','芦屋'=>'21','福岡'=>'22','唐津'=>'23','大村'=>'24',
    ];
}

function httpGetHoleForward(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; BoatRaceLogic hole-forward snapshot)',
                CURLOPT_ENCODING => '',
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if (is_string($body) && $body !== '' && $status >= 200 && $status < 400) {
                return $body;
            }
        }
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 10,
            'header' => "User-Agent: Mozilla/5.0 (compatible; BoatRaceLogic hole-forward snapshot)\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    return is_string($body) && $body !== '' ? $body : null;
}

/** @return array<int,string> raceNo => HH:MM */
function fetchOfficialTimesHoleForward(string $ymd, string $jcd): array
{
    $url = 'https://www.boatrace.jp/owpc/pc/race/raceindex?hd=' . rawurlencode($ymd)
        . '&jcd=' . rawurlencode($jcd);
    $html = httpGetHoleForward($url);
    if ($html === null) return [];

    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[\x{00A0}\s]+/u', ' ', $text) ?? $text;

    $out = [];
    for ($race = 1; $race <= 12; $race++) {
        $pattern = '/(?:^|\s)' . $race . 'R\s+(\d{1,2}:\d{2})(?=\s|$)/u';
        if (preg_match($pattern, $text, $m)) {
            [$h, $min] = array_map('intval', explode(':', (string)$m[1], 2));
            $out[$race] = sprintf('%02d:%02d', $h, $min);
        }
    }
    return $out;
}

/** @return array<int,string> */
function parseBetListHoleForward(string $text): array
{
    $text = trim($text);
    if ($text === '' || $text === '-') return [];
    $bets = [];
    foreach (explode(',', $text) as $raw) {
        $bet = trim($raw);
        if (preg_match('/^[1-6]-[1-6]-[1-6]$/', $bet)) {
            $bets[] = $bet;
        }
    }
    return array_values(array_unique($bets));
}

/**
 * @return array<int,array<string,mixed>>
 */
function parsePredictionBlocksHoleForward(string $text): array
{
    $chunks = preg_split('/\R{2,}/u', trim($text)) ?: [];
    $rows = [];

    foreach ($chunks as $chunk) {
        $lines = preg_split('/\R/u', trim($chunk)) ?: [];
        if (count($lines) < 4) continue;

        if (!preg_match(
            '/^(\S+)\s+(\d{1,2})R\s+(暫定|展示反映済)\s+\/\s+1C=(\d+)\s+\/\s+cut=(.*)$/u',
            trim((string)$lines[0]),
            $m1
        )) {
            continue;
        }

        if (!preg_match(
            '/^穴本命A=(\d+)（(\d+)C AI3=([0-9]+(?:\.[0-9]+)?)）\s+\/\s+穴対抗B=(\d+)（(\d+)C AI3=([0-9]+(?:\.[0-9]+)?)）\s+\/\s+差=([0-9]+(?:\.[0-9]+)?)(?:\s+\/\s+★B寄り参考)?$/u',
            trim((string)$lines[1]),
            $m2
        )) {
            continue;
        }

        if (!preg_match('/^A\s+(\d+)点:\s*(.*)$/u', trim((string)$lines[2]), $m3)) continue;
        if (!preg_match('/^B\s+(\d+)点:\s*(.*)$/u', trim((string)$lines[3]), $m4)) continue;

        $cutText = trim((string)$m1[5]);
        $cut = [];
        if ($cutText !== '' && $cutText !== '-') {
            foreach (explode(',', $cutText) as $v) {
                $n = (int)trim($v);
                if ($n >= 1 && $n <= 6) $cut[$n] = $n;
            }
        }

        $aBets = parseBetListHoleForward((string)$m3[2]);
        $bBets = parseBetListHoleForward((string)$m4[2]);
        $bLean = str_contains((string)$lines[1], '★B寄り参考');

        $rows[] = [
            'place' => (string)$m1[1],
            'race_no' => (int)$m1[2],
            'mode' => (string)$m1[3],
            'in_boat' => (int)$m1[4],
            'cut' => array_values($cut),
            'a_boat' => (int)$m2[1],
            'a_course' => (int)$m2[2],
            'ai_a' => (float)$m2[3],
            'b_boat' => (int)$m2[4],
            'b_course' => (int)$m2[5],
            'ai_b' => (float)$m2[6],
            'ai_gap' => (float)$m2[7],
            'b_lean_reference' => $bLean,
            'a_points' => (int)$m3[1],
            'a_bets' => $aBets,
            'b_points' => (int)$m4[1],
            'b_bets' => $bBets,
        ];
    }

    return $rows;
}

function sourceSnapshotTimeHoleForward(string $text): DateTimeImmutable
{
    if (preg_match('/^作成時刻\s+:\s+(.+)$/mu', $text, $m)) {
        try {
            return new DateTimeImmutable(trim((string)$m[1]));
        } catch (Throwable) {
            // fall through
        }
    }
    return new DateTimeImmutable('now');
}

function runPredictionGeneratorHoleForward(string $dateText): array
{
    $script = __DIR__ . '/list_payout_signal_hole_predictions.php';
    if (!is_file($script)) failHoleForward('予想生成スクリプトが見つかりません: ' . $script);

    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($dateText);
    $out = shell_exec($cmd . ' 2>/dev/null');
    if (!is_string($out) || trim($out) === '') {
        failHoleForward('穴目予想の生成に失敗しました');
    }

    if (!preg_match('/^保存:\s+(.+)$/mu', $out, $m)) {
        failHoleForward('生成した予想スナップショットの保存先を取得できませんでした');
    }

    $path = trim((string)$m[1]);
    if (!is_file($path)) {
        failHoleForward('生成スナップショットが見つかりません: ' . $path);
    }

    $text = file_get_contents($path);
    if (!is_string($text) || trim($text) === '') {
        failHoleForward('生成スナップショットを読めません: ' . $path);
    }

    return [$path, $text];
}

$dateText = trim((string)($argv[1] ?? ''));
$stage = strtolower(trim((string)($argv[2] ?? '')));

$dt = DateTimeImmutable::createFromFormat('!Y-m-d', $dateText);
if ($dt === false || $dt->format('Y-m-d') !== $dateText || !in_array($stage, ['provisional', 'exhibition'], true)) {
    failHoleForward('Usage: php analysis/freeze_payout_signal_hole_forward.php YYYY-MM-DD provisional|exhibition');
}

$wantMode = $stage === 'provisional' ? '暫定' : '展示反映済';
$stageLabel = $stage === 'provisional' ? '暫定' : '展示反映済';
$ymd = $dt->format('Ymd');

[$sourcePath, $sourceText] = runPredictionGeneratorHoleForward($dateText);
$sourceAt = sourceSnapshotTimeHoleForward($sourceText);
$rows = parsePredictionBlocksHoleForward($sourceText);
if ($rows === []) failHoleForward('生成スナップショットから予想レースを抽出できませんでした');

$places = [];
foreach ($rows as $row) $places[(string)$row['place']] = true;
$jcdMap = officialJcdMapHoleForward();
$timesByPlace = [];
foreach (array_keys($places) as $place) {
    $jcd = $jcdMap[$place] ?? null;
    if ($jcd === null) {
        $timesByPlace[$place] = [];
        continue;
    }
    fwrite(STDERR, $place . " の締切予定時刻を取得中...\n");
    $timesByPlace[$place] = fetchOfficialTimesHoleForward($ymd, $jcd);
    usleep(150000);
}

$outDir = __DIR__ . '/output/hole_forward/' . $ymd;
if (!is_dir($outDir) && !@mkdir($outDir, 0775, true) && !is_dir($outDir)) {
    failHoleForward('保存先を作成できません: ' . $outDir);
}

$placeCodes = placeCodeMapHoleForward();
$newRows = [];
$modeSkipped = 0;
$deadlineSkipped = 0;
$unknownDeadline = 0;
$already = 0;
$errors = 0;

foreach ($rows as $row) {
    if ((string)$row['mode'] !== $wantMode) {
        $modeSkipped++;
        continue;
    }

    $place = (string)$row['place'];
    $raceNo = (int)$row['race_no'];
    $placeCode = $placeCodes[$place] ?? null;
    if ($placeCode === null) {
        $errors++;
        continue;
    }

    $time = $timesByPlace[$place][$raceNo] ?? null;
    if (!is_string($time)) {
        $unknownDeadline++;
        continue;
    }

    $deadline = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $dateText . ' ' . $time);
    if ($deadline === false) {
        $unknownDeadline++;
        continue;
    }

    // 予想ファイル全体の作成時刻を保守的に採用。締切以後なら前方保存しない。
    if ($sourceAt >= $deadline) {
        $deadlineSkipped++;
        continue;
    }

    $raceCode = $ymd . $placeCode . sprintf('%02d', $raceNo);
    $savePath = $outDir . '/' . $raceCode . '_' . $stage . '.json';
    if (is_file($savePath)) {
        $already++;
        continue;
    }

    $record = [
        'schema_version' => 1,
        'logic_version' => 'hole-s3t3-ab-forward-v1-20260907',
        'target_date' => $dateText,
        'stage' => $stage,
        'stage_label' => $stageLabel,
        'mode' => (string)$row['mode'],
        'snapshot_at' => $sourceAt->format(DATE_ATOM),
        'deadline_at' => $deadline->format(DATE_ATOM),
        'source_snapshot' => $sourcePath,
        'result_tables_referenced' => false,
        'race_code' => $raceCode,
        'place' => $place,
        'place_code' => $placeCode,
        'race_no' => $raceNo,
        'in_boat' => (int)$row['in_boat'],
        'cut' => $row['cut'],
        'head_definition' => [
            'A' => '非インAI3連対率1位',
            'B' => '非インAI3連対率2位',
        ],
        'bet_definition' => '各頭 S3_T3 = 2着Top3 × 条件付き3着Top3（最大9点）',
        'b_swap_reference' => [
            'name' => 'GAP5_INNER',
            'condition' => 'AI3差<5 かつ BがAより内',
            'adopted_for_display_swap' => false,
            'matched' => (bool)$row['b_lean_reference'],
        ],
        'A' => [
            'boat' => (int)$row['a_boat'],
            'course' => (int)$row['a_course'],
            'ai3_rate' => (float)$row['ai_a'],
            'points' => (int)$row['a_points'],
            'bets' => $row['a_bets'],
        ],
        'B' => [
            'boat' => (int)$row['b_boat'],
            'course' => (int)$row['b_course'],
            'ai3_rate' => (float)$row['ai_b'],
            'points' => (int)$row['b_points'],
            'bets' => $row['b_bets'],
        ],
        'ai3_gap_a_minus_b' => (float)$row['ai_gap'],
    ];

    $json = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        $errors++;
        continue;
    }

    $fp = @fopen($savePath, 'x');
    if ($fp === false) {
        if (is_file($savePath)) $already++;
        else $errors++;
        continue;
    }
    fwrite($fp, $json . PHP_EOL);
    fflush($fp);
    fclose($fp);
    @chmod($savePath, 0664);

    $newRows[] = [
        'deadline' => $time,
        'place' => $place,
        'race_no' => $raceNo,
        'race_code' => $raceCode,
        'a_boat' => (int)$row['a_boat'],
        'b_boat' => (int)$row['b_boat'],
        'gap' => (float)$row['ai_gap'],
        'b_lean' => (bool)$row['b_lean_reference'],
        'path' => $savePath,
    ];
}

usort($newRows, static function (array $a, array $b): int {
    $cmp = strcmp((string)$a['deadline'], (string)$b['deadline']);
    if ($cmp !== 0) return $cmp;
    $cmp = strcmp((string)$a['place'], (string)$b['place']);
    if ($cmp !== 0) return $cmp;
    return (int)$a['race_no'] <=> (int)$b['race_no'];
});

$line = str_repeat('=', 126);
echo $line . PHP_EOL;
echo '穴目予想 前方スナップショット保存' . PHP_EOL;
echo '対象日     : ' . $dateText . PHP_EOL;
echo '保存段階   : ' . $stage . ' / ' . $stageLabel . PHP_EOL;
echo '元予想時刻 : ' . $sourceAt->format('Y-m-d H:i:s T') . PHP_EOL;
echo '保存先     : ' . $outDir . PHP_EOL;
echo '重要       : 締切前・段階一致のレースだけを初回1回のみ保存。結果・払戻は参照しません' . PHP_EOL;
echo $line . PHP_EOL;

foreach ($newRows as $r) {
    printf(
        "%s  %-6s %2dR  A=%d / B=%d / 差=%.2f%s\n",
        $r['deadline'],
        $r['place'],
        $r['race_no'],
        $r['a_boat'],
        $r['b_boat'],
        $r['gap'],
        $r['b_lean'] ? ' / ★B寄り参考' : ''
    );
}

if ($newRows === []) echo "新規保存レースはありません。\n";

echo $line . PHP_EOL;
echo '新規保存=' . count($newRows)
    . 'R / 既保存=' . $already
    . 'R / 段階不一致=' . $modeSkipped
    . 'R / 締切後=' . $deadlineSkipped
    . 'R / 締切不明=' . $unknownDeadline
    . 'R / エラー=' . $errors . 'R' . PHP_EOL;
echo '※provisional は朝に1回、exhibition は展示取得後に何度でも再実行できます。既保存ファイルは上書きしません。' . PHP_EOL;
echo $line . PHP_EOL;
