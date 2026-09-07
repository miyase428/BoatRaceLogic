<?php

declare(strict_types=1);

/**
 * 結果非参照の穴目予想スナップショットを、BOAT RACE公式の
 * 「締切予定時刻」順に並べ替える補助スクリプト。
 *
 * 重要:
 * - 元の予想スナップショットは変更しない。
 * - race_result_detail / race_payouts は参照しない。
 * - 公式サイトから読むのはレース一覧ページの締切予定時刻だけ。
 * - 公式時刻を取得できない場合は「--:--」として末尾に回す。
 *
 * Usage:
 *   php analysis/sort_hole_prediction_snapshot_by_time.php 2026-09-07
 *   php analysis/sort_hole_prediction_snapshot_by_time.php analysis/output/payout_signal_hole_predictions_20260907_20260907_163246.txt
 */

date_default_timezone_set('Asia/Tokyo');

function failTimeline(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

/** @return array<string,string> */
function officialJcdMapTimeline(): array
{
    return [
        '桐生'=>'01','戸田'=>'02','江戸川'=>'03','平和島'=>'04','多摩川'=>'05','浜名湖'=>'06',
        '蒲郡'=>'07','常滑'=>'08','津'=>'09','三国'=>'10','びわこ'=>'11','住之江'=>'12',
        '尼崎'=>'13','鳴門'=>'14','丸亀'=>'15','児島'=>'16','宮島'=>'17','徳山'=>'18',
        '下関'=>'19','若松'=>'20','芦屋'=>'21','福岡'=>'22','唐津'=>'23','大村'=>'24',
    ];
}

function httpGetTimeline(string $url): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; BoatRaceLogic timeline helper)',
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
            'header' => "User-Agent: Mozilla/5.0 (compatible; BoatRaceLogic timeline helper)\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    return is_string($body) && $body !== '' ? $body : null;
}

/** @return array<int,string> raceNo => HH:MM */
function fetchOfficialTimesTimeline(string $ymd, string $jcd): array
{
    $url = 'https://www.boatrace.jp/owpc/pc/race/raceindex?hd=' . rawurlencode($ymd)
        . '&jcd=' . rawurlencode($jcd);
    $html = httpGetTimeline($url);
    if ($html === null) return [];

    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/[\x{00A0}\s]+/u', ' ', $text) ?? $text;

    $out = [];
    for ($race = 1; $race <= 12; $race++) {
        $pattern = '/(?:^|\s)' . $race . 'R\s+(\d{1,2}:\d{2})(?=\s|$)/u';
        if (preg_match($pattern, $text, $m)) {
            $out[$race] = sprintf('%02d:%02d', (int)substr($m[1], 0, strpos($m[1], ':')), (int)substr($m[1], strpos($m[1], ':') + 1));
        }
    }
    return $out;
}

/** @return array<int,array{place:string,race_no:int,block:string}> */
function parseSnapshotBlocksTimeline(string $text): array
{
    $chunks = preg_split('/\R{2,}/u', trim($text)) ?: [];
    $rows = [];
    foreach ($chunks as $chunk) {
        $chunk = trim($chunk);
        if (!preg_match('/^(\S+)\s+(\d{1,2})R\s+/u', $chunk, $m)) continue;
        $rows[] = [
            'place' => (string)$m[1],
            'race_no' => (int)$m[2],
            'block' => $chunk,
        ];
    }
    return $rows;
}

function resolveSnapshotPathTimeline(string $arg): array
{
    $root = dirname(__DIR__);
    if (is_file($arg)) {
        $path = realpath($arg) ?: $arg;
        if (!preg_match('/payout_signal_hole_predictions_(\d{8})_/u', basename($path), $m)) {
            failTimeline('穴目予想スナップショット名から日付を判定できません: ' . $path);
        }
        return [$path, $m[1]];
    }

    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $arg);
    if ($dt === false || $dt->format('Y-m-d') !== $arg) {
        failTimeline('Usage: php analysis/sort_hole_prediction_snapshot_by_time.php YYYY-MM-DD | snapshot.txt');
    }
    $ymd = $dt->format('Ymd');
    $files = glob($root . '/analysis/output/payout_signal_hole_predictions_' . $ymd . '_*.txt') ?: [];
    $files = array_values(array_filter($files, static fn(string $p): bool => !str_ends_with($p, '_timeline.txt')));
    if ($files === []) failTimeline('対象日の穴目予想スナップショットが見つかりません: ' . $arg);
    usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    return [$files[0], $ymd];
}

$arg = trim((string)($argv[1] ?? ''));
if ($arg === '') failTimeline('Usage: php analysis/sort_hole_prediction_snapshot_by_time.php YYYY-MM-DD | snapshot.txt');

[$snapshotPath, $ymd] = resolveSnapshotPathTimeline($arg);
$text = file_get_contents($snapshotPath);
if (!is_string($text) || trim($text) === '') failTimeline('スナップショットを読めません: ' . $snapshotPath);

$rows = parseSnapshotBlocksTimeline($text);
if ($rows === []) failTimeline('スナップショットからレースブロックを抽出できませんでした');

$jcdMap = officialJcdMapTimeline();
$places = [];
foreach ($rows as $r) $places[$r['place']] = true;

$timesByPlace = [];
foreach (array_keys($places) as $place) {
    $jcd = $jcdMap[$place] ?? null;
    if ($jcd === null) {
        $timesByPlace[$place] = [];
        continue;
    }
    fwrite(STDERR, $place . " の公式締切予定時刻を取得中...\n");
    $timesByPlace[$place] = fetchOfficialTimesTimeline($ymd, $jcd);
    usleep(150000);
}

foreach ($rows as &$r) {
    $time = $timesByPlace[$r['place']][$r['race_no']] ?? null;
    $r['time'] = is_string($time) ? $time : null;
    $r['sort_key'] = is_string($time) ? str_replace(':', '', $time) : '9999';
}
unset($r);

usort($rows, static function (array $a, array $b): int {
    $cmp = strcmp((string)$a['sort_key'], (string)$b['sort_key']);
    if ($cmp !== 0) return $cmp;
    $cmp = strcmp((string)$a['place'], (string)$b['place']);
    if ($cmp !== 0) return $cmp;
    return (int)$a['race_no'] <=> (int)$b['race_no'];
});

$line = str_repeat('=', 126);
$out = [];
$out[] = $line;
$out[] = 'イン崩壊：穴目予想スナップショット 時系列表示';
$out[] = '対象日     : ' . DateTimeImmutable::createFromFormat('!Ymd', $ymd)?->format('Y-m-d');
$out[] = '並び順     : BOAT RACE公式「締切予定時刻」昇順';
$out[] = '元ファイル : ' . $snapshotPath;
$out[] = '重要       : 元予想は変更せず、結果・払戻も参照しません';
$out[] = $line;

$unknown = 0;
foreach ($rows as $r) {
    $time = $r['time'] ?? null;
    if ($time === null) $unknown++;
    $prefix = ($time ?? '--:--') . '  ';
    $blockLines = preg_split('/\R/u', (string)$r['block']) ?: [];
    foreach ($blockLines as $i => $blockLine) {
        $out[] = ($i === 0 ? $prefix : str_repeat(' ', strlen($prefix))) . $blockLine;
    }
    $out[] = '';
}

$out[] = $line;
$out[] = '候補=' . count($rows) . 'R / 時刻取得失敗=' . $unknown . 'R';
$out[] = '※時刻はBOAT RACE公式の締切予定時刻。変更・遅延がある場合は公式表示を優先してください。';
$out[] = $line;

$outText = implode("\n", $out) . "\n";
$outPath = preg_replace('/\.txt$/', '_timeline.txt', $snapshotPath) ?: ($snapshotPath . '_timeline.txt');
file_put_contents($outPath, $outText);
echo $outText;
echo '保存: ' . $outPath . PHP_EOL;
