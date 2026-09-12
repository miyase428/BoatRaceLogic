<?php
/**
 * AI展開予想プロトタイプ v3
 *
 * 使い方:
 *   php analysis/prototype_ai_tenkai_v3.php 2026-09-12 TMG 11
 *
 * v2からの変更:
 * - 展示情報が揃い、補正後1着率が計算できたレースだけを対象にする
 * - BOATERS風に「今レース」と「場平均」を同じ展開確率の尺度で比較する
 * - 場平均は対象日前日までの直近1年、当該場で実際に発生した勝ち方 / 全レース
 * - 場×コースの勝ち方構成は、従来どおり選手履歴の平滑化事前分布にも使用する
 *
 * 注意:
 * - 学習済みAIではない
 * - 今レース = 補正後1着率 × 平滑化した選手×コース勝ち方構成
 * - 「その他」は抜き・恵まれ等をまとめた暫定カテゴリ
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
$correctedRoot = is_array($data['corrected_win_rate_data'] ?? null) ? $data['corrected_win_rate_data'] : [];
$corrected = is_array($correctedRoot['boats'] ?? null) ? $correctedRoot['boats'] : [];

$entryOrder = '';
for ($course = 1; $course <= 6; $course++) {
    $entryOrder .= (string)($boatByCourse[$course] ?? $course);
}

$labels = [
    'nige' => '逃げ',
    'sashi' => '差し',
    'makuri' => 'まくり',
    'makurizashi' => 'まくり差し',
    'other' => 'その他',
];

function boatRowV3(array $rows, int $boat): array
{
    $row = $rows[$boat] ?? $rows[(string)$boat] ?? [];
    return is_array($row) ? $row : [];
}

function periodRowV3(array $kimarite, int $course, string $period): array
{
    $root = $kimarite[$course] ?? $kimarite[(string)$course] ?? [];
    if (!is_array($root)) return [];
    $row = $root[$period] ?? [];
    return is_array($row) ? $row : [];
}

function countsFromPeriodV3(array $row, int $course): array
{
    $counts = is_array($row['_counts'] ?? null) ? $row['_counts'] : [];
    $win = max(0, (int)($counts['win'] ?? 0));

    if ($course === 1) {
        $nige = max(0, min($win, (int)($counts['nige'] ?? 0)));
        return [
            'win' => $win,
            'tech' => [
                'nige' => $nige,
                'other' => max(0, $win - $nige),
            ],
        ];
    }

    $sashi = max(0, (int)($counts['sashi'] ?? 0));
    $makuri = max(0, (int)($counts['makuri'] ?? 0));
    $makurizashi = max(0, (int)($counts['makurizashi'] ?? 0));
    $known = min($win, $sashi + $makuri + $makurizashi);

    return [
        'win' => $win,
        'tech' => [
            'sashi' => $sashi,
            'makuri' => $makuri,
            'makurizashi' => $makurizashi,
            'other' => max(0, $win - $known),
        ],
    ];
}

function loadVenueStatsV3(PDO $pdo, string $date, string $place): array
{
    $sql = <<<'SQL'
SELECT
    rrd.entry_course::integer AS course,
    TRIM(COALESCE(rrd.technique, '')) AS technique,
    COUNT(*)::integer AS n
FROM boat_race.race_result_detail rrd
JOIN boat_race.race_master rm
  ON rm.race_code = rrd.race_code
WHERE TRIM(rrd.rank) = '1'
  AND rrd.entry_course BETWEEN 1 AND 6
  AND rm.race_date >= :target_date::date - INTERVAL '12 months'
  AND rm.race_date < :target_date::date
  AND SUBSTRING(rm.race_code FROM 9 FOR 3) = :place
GROUP BY rrd.entry_course, TRIM(COALESCE(rrd.technique, ''))
ORDER BY course, technique
SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':target_date' => $date,
        ':place' => $place,
    ]);

    $raw = [];
    $totalRaces = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $course = (int)($row['course'] ?? 0);
        if ($course < 1 || $course > 6) continue;
        $technique = trim((string)($row['technique'] ?? ''));
        $n = (int)($row['n'] ?? 0);
        $raw[$course][$technique] = $n;
        $totalRaces += $n;
    }

    $courses = [];
    for ($course = 1; $course <= 6; $course++) {
        $rows = $raw[$course] ?? [];
        $courseWins = array_sum($rows);

        if ($course === 1) {
            $counts = [
                'nige' => (int)($rows['逃げ'] ?? 0),
            ];
            $counts['other'] = max(0, $courseWins - $counts['nige']);
            $fallback = ['nige' => 0.90, 'other' => 0.10];
        } else {
            $counts = [
                'sashi' => (int)($rows['差し'] ?? 0),
                'makuri' => (int)($rows['まくり'] ?? 0),
                'makurizashi' => (int)($rows['まくり差し'] ?? 0),
            ];
            $known = $counts['sashi'] + $counts['makuri'] + $counts['makurizashi'];
            $counts['other'] = max(0, $courseWins - $known);
            $fallback = ['sashi' => 0.25, 'makuri' => 0.35, 'makurizashi' => 0.30, 'other' => 0.10];
        }

        $dist = [];
        $avg = [];
        foreach ($counts as $key => $count) {
            $dist[$key] = $courseWins > 0 ? $count / $courseWins : ($fallback[$key] ?? 0.0);
            $avg[$key] = $totalRaces > 0 ? $count / $totalRaces : 0.0;
        }

        $courses[$course] = [
            'win_n' => $courseWins,
            'counts' => $counts,
            'dist' => $dist,
            'avg' => $avg,
        ];
    }

    return [
        'total_races' => $totalRaces,
        'courses' => $courses,
    ];
}

// BOATERSと同じ考え方に寄せ、展示が揃って補正後1着率が出たときだけ計算する。
$correctedReady = (($correctedRoot['status'] ?? '') === 'ok') && count($corrected) === 6;
if ($correctedReady) {
    for ($boat = 1; $boat <= 6; $boat++) {
        $row = boatRowV3($corrected, $boat);
        if (!isset($row['corrected_rate']) || !is_numeric($row['corrected_rate'])) {
            $correctedReady = false;
            break;
        }
    }
}

$placeNames = is_array($data['place_names'] ?? null) ? $data['place_names'] : [];
$placeName = (string)($placeNames[$place] ?? $place);

if (!$correctedReady) {
    echo str_repeat('=', 96) . PHP_EOL;
    echo "AI展開予想プロトタイプ v3" . PHP_EOL;
    echo str_repeat('=', 96) . PHP_EOL;
    printf("対象      : %s %s %dR\n", $date, $placeName, $race);
    echo "状態      : 展示情報待ち\n";
    echo "説明      : 補正後1着率が6艇分そろってからAI展開予想を表示します。\n";
    exit(0);
}

try {
    $venueStats = loadVenueStatsV3(getPDO(), $date, $place);
} catch (Throwable $e) {
    fwrite(STDERR, "場平均取得エラー: {$e->getMessage()}\n");
    exit(3);
}

$recentWeight = 2.0;
$priorK = 5.0;
$events = [];
$details = [];

for ($boat = 1; $boat <= 6; $boat++) {
    $course = (int)($courseByBoat[$boat] ?? $boat);
    $cRow = boatRowV3($corrected, $boat);
    $pWin = (float)$cRow['corrected_rate'];

    $row6 = periodRowV3($kimarite, $course, '6month');
    $row12 = periodRowV3($kimarite, $course, '1year');
    $c6 = countsFromPeriodV3($row6, $course);
    $c12 = countsFromPeriodV3($row12, $course);

    $venueCourse = $venueStats['courses'][$course] ?? ['dist' => [], 'avg' => [], 'win_n' => 0];
    $priorDist = is_array($venueCourse['dist'] ?? null) ? $venueCourse['dist'] : [];
    $venueAvg = is_array($venueCourse['avg'] ?? null) ? $venueCourse['avg'] : [];

    $olderWin = max(0, $c12['win'] - $c6['win']);
    $effectiveWin = $recentWeight * $c6['win'] + $olderWin;

    $keys = array_keys($priorDist);
    $effectiveTech = [];
    foreach ($keys as $key) {
        $recent = (int)($c6['tech'][$key] ?? 0);
        $older = max(0, (int)($c12['tech'][$key] ?? 0) - $recent);
        $effectiveTech[$key] = $recentWeight * $recent + $older;
    }

    $den = $effectiveWin + $priorK;
    $shares = [];
    foreach ($keys as $key) {
        $priorCount = $priorK * (float)($priorDist[$key] ?? 0.0);
        $shares[$key] = $den > 0.0
            ? (($effectiveTech[$key] ?? 0.0) + $priorCount) / $den
            : (float)($priorDist[$key] ?? 0.0);
    }

    $shareSum = array_sum($shares);
    if ($shareSum > 0.0) {
        foreach ($shares as $key => $value) {
            $shares[$key] = $value / $shareSum;
        }
    }

    $details[$boat] = [
        'course' => $course,
        'p_win' => $pWin,
        'n6' => (int)($row6['_sample_n'] ?? 0),
        'n12' => (int)($row12['_sample_n'] ?? 0),
        'wins6' => $c6['win'],
        'wins12' => $c12['win'],
        'venue_win_n' => (int)($venueCourse['win_n'] ?? 0),
        'shares' => $shares,
        'venue_avg' => $venueAvg,
    ];

    foreach ($shares as $key => $share) {
        $prob = $pWin * $share;
        if ($prob <= 0.001) continue;
        $avgProb = ((float)($venueAvg[$key] ?? 0.0)) * 100.0;
        $events[] = [
            'boat' => $boat,
            'course' => $course,
            'key' => $key,
            'tech' => $labels[$key] ?? $key,
            'prob' => $prob,
            'share' => $share * 100.0,
            'venue_avg' => $avgProb,
            'diff' => $prob - $avgProb,
        ];
    }
}

usort($events, static fn(array $a, array $b): int => $b['prob'] <=> $a['prob']);

$line = str_repeat('=', 116);
echo $line . PHP_EOL;
echo "AI展開予想プロトタイプ v3（展示後限定 + BOATERS風 場平均比較）" . PHP_EOL;
echo $line . PHP_EOL;
printf("race_code : %s\n", $raceCode);
printf("対象      : %s %s %dR\n", $date, $placeName, $race);
printf("予想進入  : %s\n", $entryOrder);
echo "1着率     : 補正後1着率（展示後限定）" . PHP_EOL;
printf("場平均    : %s 対象日前日まで直近1年 / %dR\n", $placeName, (int)($venueStats['total_races'] ?? 0));
printf("履歴重み  : 直近6ヶ月 x %.1f / 7〜12ヶ月 x 1.0\n", $recentWeight);
printf("平滑化    : 場×コース勝ち方構成を K=%.1f の事前分布として使用\n", $priorK);
echo "尺度      : 今レースも場平均も『そのレースでその艇・コースがその勝ち方をする確率』" . PHP_EOL;
echo "注意      : 学習済みAIではなく、展開確率の比較方法を確認する試作" . PHP_EOL;

echo PHP_EOL . "【BOATERS風 AI展開予想 TOP3】" . PHP_EOL;
foreach (array_slice($events, 0, 3) as $i => $event) {
    printf(
        "%d. %d号艇 / %dC が「%s」の確率   今レース %5.1f%%   場平均 %5.1f%%   差 %+5.1fpt\n",
        $i + 1,
        $event['boat'],
        $event['course'],
        $event['tech'],
        $event['prob'],
        $event['venue_avg'],
        $event['diff']
    );
}

echo PHP_EOL . "【AI展開予想 TOP12】" . PHP_EOL;
foreach (array_slice($events, 0, 12) as $i => $event) {
    printf(
        "%2d位  %d号艇 / %dC  %-10s  今 %6.2f%% / 場平均 %6.2f%% / 差 %+6.2fpt  （勝つ場合 %5.1f%%）\n",
        $i + 1,
        $event['boat'],
        $event['course'],
        $event['tech'],
        $event['prob'],
        $event['venue_avg'],
        $event['diff'],
        $event['share']
    );
}

printf("\n展開確率合計: %.2f%%\n", array_sum(array_column($events, 'prob')));

echo PHP_EOL . "【艇別 平滑化後の勝ち方構成】" . PHP_EOL;
foreach ($details as $boat => $d) {
    $parts = [];
    foreach ($d['shares'] as $key => $share) {
        $parts[] = ($labels[$key] ?? $key) . '=' . number_format($share * 100.0, 1) . '%';
    }
    printf(
        "%d号艇/%dC  1着=%6.2f%%  N6=%d 勝%d / N1年=%d 勝%d / 場コース勝=%d  [%s]\n",
        $boat,
        $d['course'],
        $d['p_win'],
        $d['n6'],
        $d['wins6'],
        $d['n12'],
        $d['wins12'],
        $d['venue_win_n'],
        implode(' / ', $parts)
    );
}

echo PHP_EOL;
echo "※ 場平均は『コースが勝ったときの構成比』ではなく、『全レース中、その展開が実際に起きた割合』です。" . PHP_EOL;
echo "※ BOATERSの公開値そのものを再現するのではなく、同じ比較軸を自前DBで作っています。" . PHP_EOL;
