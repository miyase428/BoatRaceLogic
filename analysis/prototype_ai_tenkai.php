<?php
/**
 * AI展開予想・超簡易プロトタイプ
 *
 * 使い方:
 *   php analysis/prototype_ai_tenkai.php 2026-09-12 TMG 11
 *
 * 目的:
 * - Web本番のIndexControllerから「補正後1着率 / 基本1着率 / 決まり手」を再利用
 * - 各艇の1着率を、その艇の今回コースにおける決まり手構成比へ配分
 * - BOATERS風の「1が逃げ xx% / 3がまくり差し xx%」をまず試しに見る
 *
 * 注意:
 * - これは表示イメージ確認用の試作で、学習済みAIではない
 * - 展示補正・SUM・スリット等は補正後1着率に含まれる範囲だけ間接反映
 * - 決まり手側は直近6ヶ月（なければ1年）を使用
 * - 「抜き / 恵まれ」はまだ扱わない
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI専用です。\n");
    exit(1);
}

$date = $argv[1] ?? date('Y-m-d');
$place = strtoupper((string)($argv[2] ?? 'TMG'));
$race = (int)($argv[3] ?? 11);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    fwrite(STDERR, "日付は YYYY-MM-DD で指定してください。\n");
    exit(1);
}
if (!preg_match('/^[A-Z0-9]{3}$/', $place)) {
    fwrite(STDERR, "場コードは3文字で指定してください。例: TMG\n");
    exit(1);
}
if ($race < 1 || $race > 12) {
    fwrite(STDERR, "レース番号は1～12で指定してください。\n");
    exit(1);
}

$_GET = [
    'date' => $date,
    'place' => $place,
    'race' => (string)$race,
];
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once __DIR__ . '/../web/controllers/IndexController.php';

try {
    $controller = new IndexController();
    $data = $controller->handle();
} catch (Throwable $e) {
    fwrite(STDERR, "IndexController実行エラー: {$e->getMessage()}\n");
    exit(2);
}

$raceCode = (string)($data['race_code'] ?? (date('Ymd', strtotime($date)) . $place . sprintf('%02d', $race)));
$courseByBoat = is_array($data['prediction_course_by_boat'] ?? null)
    ? $data['prediction_course_by_boat']
    : array_combine(range(1, 6), range(1, 6));
$boatByCourse = is_array($data['prediction_boat_by_course'] ?? null)
    ? $data['prediction_boat_by_course']
    : array_combine(range(1, 6), range(1, 6));
$kimarite = is_array($data['kimarite_data'] ?? null) ? $data['kimarite_data'] : [];
$corrected = is_array($data['corrected_win_rate_data']['boats'] ?? null)
    ? $data['corrected_win_rate_data']['boats']
    : [];
$base = is_array($data['base_win_rate_data']['boats'] ?? null)
    ? $data['base_win_rate_data']['boats']
    : [];

$entryOrder = '';
for ($course = 1; $course <= 6; $course++) {
    $entryOrder .= (string)($boatByCourse[$course] ?? $course);
}

$labels = [
    'nige' => '逃げ',
    'sashi' => '差し',
    'makuri' => 'まくり',
    'makurizashi' => 'まくり差し',
    'unknown' => 'その他',
];

function boatRow(array $rows, int $boat): array
{
    $row = $rows[$boat] ?? $rows[(string)$boat] ?? [];
    return is_array($row) ? $row : [];
}

function courseKimarite(array $kimarite, int $course): array
{
    $root = $kimarite[$course] ?? $kimarite[(string)$course] ?? [];
    if (!is_array($root)) return [[], 'none'];

    foreach (['6month', '1year'] as $period) {
        $row = $root[$period] ?? [];
        if (is_array($row) && $row) return [$row, $period];
    }

    // 古い内部形式への保険
    return [$root, 'legacy'];
}

function winRateForBoat(array $corrected, array $base, int $boat): array
{
    $c = boatRow($corrected, $boat);
    if (isset($c['corrected_rate']) && is_numeric($c['corrected_rate'])) {
        return [(float)$c['corrected_rate'], '補正後1着率'];
    }

    $b = boatRow($base, $boat);
    if (isset($b['normalized_rate']) && is_numeric($b['normalized_rate'])) {
        return [(float)$b['normalized_rate'], '基本1着率'];
    }

    return [0.0, '取得不可'];
}

$events = [];
$boatDetails = [];
$rateSourceSet = [];
$periodSet = [];

for ($boat = 1; $boat <= 6; $boat++) {
    $course = (int)($courseByBoat[$boat] ?? $boat);
    [$pWin, $rateSource] = winRateForBoat($corrected, $base, $boat);
    [$krow, $period] = courseKimarite($kimarite, $course);

    $rateSourceSet[$rateSource] = true;
    $periodSet[$period] = true;

    if ($course === 1) {
        $weights = ['nige' => 1.0];
        $raw = ['nige' => (float)($krow['nige'] ?? 0.0)];
    } else {
        $raw = [
            'sashi' => max(0.0, (float)($krow['sashi'] ?? 0.0)),
            'makuri' => max(0.0, (float)($krow['makuri'] ?? 0.0)),
            'makurizashi' => max(0.0, (float)($krow['makurizashi'] ?? 0.0)),
        ];
        $sum = array_sum($raw);
        if ($sum > 0.0) {
            $weights = [];
            foreach ($raw as $key => $value) {
                if ($value <= 0.0) continue;
                $weights[$key] = $value / $sum;
            }
        } else {
            $weights = ['unknown' => 1.0];
        }
    }

    $boatDetails[$boat] = [
        'course' => $course,
        'p_win' => $pWin,
        'rate_source' => $rateSource,
        'period' => $period,
        'raw' => $raw,
    ];

    foreach ($weights as $key => $share) {
        $prob = $pWin * $share;
        if ($prob <= 0.0) continue;
        $events[] = [
            'boat' => $boat,
            'course' => $course,
            'tech_key' => $key,
            'tech' => $labels[$key] ?? $key,
            'prob' => $prob,
            'share' => $share * 100.0,
            'period' => $period,
        ];
    }
}

usort($events, static fn(array $a, array $b): int => $b['prob'] <=> $a['prob']);

$placeNames = is_array($data['place_names'] ?? null) ? $data['place_names'] : [];
$placeName = (string)($placeNames[$place] ?? $place);

$line = str_repeat('=', 94);
echo $line . PHP_EOL;
echo "AI展開予想・超簡易プロトタイプ（表示イメージ確認用）" . PHP_EOL;
echo $line . PHP_EOL;
printf("race_code : %s\n", $raceCode);
printf("対象      : %s %s %dR\n", $date, $placeName, $race);
printf("予想進入  : %s\n", $entryOrder);
printf("1着率     : %s\n", implode(' / ', array_keys($rateSourceSet)));
printf("決まり手  : %s\n", implode(' / ', array_keys($periodSet)));
echo "方式      : 各艇1着率 × 当該コースの決まり手構成比（コース内で再正規化）" . PHP_EOL;
echo "注意      : 学習済みAIではなく、まず見た目と方向性を見るための試作" . PHP_EOL;

echo PHP_EOL . "【AI展開予想 TOP10】" . PHP_EOL;
$top = array_slice($events, 0, 10);
foreach ($top as $i => $event) {
    printf(
        "%2d位  %d号艇 / %dC  %-10s  %6.2f%%   （勝つ場合の構成比 %5.1f%%）\n",
        $i + 1,
        $event['boat'],
        $event['course'],
        $event['tech'],
        $event['prob'],
        $event['share']
    );
}

$sumEvent = array_sum(array_column($events, 'prob'));
echo PHP_EOL;
printf("展開確率合計: %.2f%%\n", $sumEvent);

echo PHP_EOL . "【艇別の元データ】" . PHP_EOL;
foreach ($boatDetails as $boat => $detail) {
    $rawParts = [];
    foreach ($detail['raw'] as $key => $value) {
        $rawParts[] = ($labels[$key] ?? $key) . '=' . number_format((float)$value, 1) . '%';
    }
    printf(
        "%d号艇/%dC  1着=%6.2f%%  [%s]  決まり手(%s): %s\n",
        $boat,
        $detail['course'],
        $detail['p_win'],
        $detail['rate_source'],
        $detail['period'],
        implode(' / ', $rawParts)
    );
}

echo PHP_EOL;
echo "※ 次に本気で検証するなら、場平均・ST差・二次評価・SUM・スリットを別々に足して前方検証します。" . PHP_EOL;
