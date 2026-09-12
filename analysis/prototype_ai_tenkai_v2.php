<?php
/**
 * AI展開予想プロトタイプ v2
 *
 * 使い方:
 *   php analysis/prototype_ai_tenkai_v2.php 2026-09-12 TMG 11
 *
 * v1からの変更:
 * - 直近6ヶ月だけでなく、直近1年も使用
 * - 6ヶ月を強めに重み付け（直近6ヶ月=2倍、7〜12ヶ月=1倍）
 * - win件数との差分を「その他（抜き・恵まれ等）」として保持
 * - 場×コースの直近1年決まり手分布を弱い事前分布(K=5)として平滑化
 * - 「2まくり100%」「5まくり差し100%」のような小標本の極端化を抑える
 *
 * 注意:
 * - 学習済みAIではない
 * - 補正後1着率を勝者確率として使い、その勝ち方だけを平滑化して配分する試作
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
    'other' => 'その他',
];

function boatRow(array $rows, int $boat): array
{
    $row = $rows[$boat] ?? $rows[(string)$boat] ?? [];
    return is_array($row) ? $row : [];
}

function periodRow(array $kimarite, int $course, string $period): array
{
    $root = $kimarite[$course] ?? $kimarite[(string)$course] ?? [];
    if (!is_array($root)) return [];
    $row = $root[$period] ?? [];
    return is_array($row) ? $row : [];
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

function countsFromPeriod(array $row, int $course): array
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

function loadVenueCoursePrior(PDO $pdo, string $date, string $place): array
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
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $course = (int)($row['course'] ?? 0);
        if ($course < 1 || $course > 6) continue;
        $technique = trim((string)($row['technique'] ?? ''));
        $raw[$course][$technique] = (int)($row['n'] ?? 0);
    }

    $out = [];
    for ($course = 1; $course <= 6; $course++) {
        $rows = $raw[$course] ?? [];
        if ($course === 1) {
            $nige = (int)($rows['逃げ'] ?? 0);
            $total = array_sum($rows);
            $dist = [
                'nige' => $total > 0 ? $nige / $total : 0.90,
                'other' => $total > 0 ? max(0, $total - $nige) / $total : 0.10,
            ];
        } else {
            $sashi = (int)($rows['差し'] ?? 0);
            $makuri = (int)($rows['まくり'] ?? 0);
            $makurizashi = (int)($rows['まくり差し'] ?? 0);
            $total = array_sum($rows);
            if ($total > 0) {
                $dist = [
                    'sashi' => $sashi / $total,
                    'makuri' => $makuri / $total,
                    'makurizashi' => $makurizashi / $total,
                    'other' => max(0, $total - $sashi - $makuri - $makurizashi) / $total,
                ];
            } else {
                $dist = ['sashi' => 0.25, 'makuri' => 0.35, 'makurizashi' => 0.30, 'other' => 0.10];
            }
        }
        $out[$course] = ['n' => array_sum($rows), 'dist' => $dist];
    }

    return $out;
}

try {
    $venuePrior = loadVenueCoursePrior(getPDO(), $date, $place);
} catch (Throwable $e) {
    fwrite(STDERR, "場×コース事前分布取得エラー: {$e->getMessage()}\n");
    exit(3);
}

$recentWeight = 2.0;
$priorK = 5.0;
$events = [];
$details = [];
$rateSources = [];

for ($boat = 1; $boat <= 6; $boat++) {
    $course = (int)($courseByBoat[$boat] ?? $boat);
    [$pWin, $rateSource] = winRateForBoat($corrected, $base, $boat);
    $rateSources[$rateSource] = true;

    $row6 = periodRow($kimarite, $course, '6month');
    $row12 = periodRow($kimarite, $course, '1year');
    $c6 = countsFromPeriod($row6, $course);
    $c12 = countsFromPeriod($row12, $course);

    $olderWin = max(0, $c12['win'] - $c6['win']);
    $effectiveWin = $recentWeight * $c6['win'] + $olderWin;

    $keys = array_keys($venuePrior[$course]['dist'] ?? []);
    $effectiveTech = [];
    foreach ($keys as $key) {
        $recent = (int)($c6['tech'][$key] ?? 0);
        $older = max(0, (int)($c12['tech'][$key] ?? 0) - $recent);
        $effectiveTech[$key] = $recentWeight * $recent + $older;
    }

    $priorDist = $venuePrior[$course]['dist'] ?? [];
    $den = $effectiveWin + $priorK;
    $shares = [];
    foreach ($keys as $key) {
        $priorCount = $priorK * (float)($priorDist[$key] ?? 0.0);
        $shares[$key] = $den > 0.0 ? (($effectiveTech[$key] ?? 0.0) + $priorCount) / $den : (float)($priorDist[$key] ?? 0.0);
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
        'source' => $rateSource,
        'n6' => (int)($row6['_sample_n'] ?? 0),
        'n12' => (int)($row12['_sample_n'] ?? 0),
        'wins6' => $c6['win'],
        'wins12' => $c12['win'],
        'venue_n' => (int)($venuePrior[$course]['n'] ?? 0),
        'shares' => $shares,
        'prior' => $priorDist,
    ];

    foreach ($shares as $key => $share) {
        $prob = $pWin * $share;
        if ($prob <= 0.001) continue;
        $events[] = [
            'boat' => $boat,
            'course' => $course,
            'tech' => $labels[$key] ?? $key,
            'key' => $key,
            'prob' => $prob,
            'share' => $share * 100.0,
            'venue_share' => ((float)($priorDist[$key] ?? 0.0)) * 100.0,
        ];
    }
}

usort($events, static fn(array $a, array $b): int => $b['prob'] <=> $a['prob']);

$placeNames = is_array($data['place_names'] ?? null) ? $data['place_names'] : [];
$placeName = (string)($placeNames[$place] ?? $place);
$line = str_repeat('=', 110);

echo $line . PHP_EOL;
echo "AI展開予想プロトタイプ v2（6ヶ月+1年 + 場×コース事前分布で平滑化）" . PHP_EOL;
echo $line . PHP_EOL;
printf("race_code : %s\n", $raceCode);
printf("対象      : %s %s %dR\n", $date, $placeName, $race);
printf("予想進入  : %s\n", $entryOrder);
printf("1着率     : %s\n", implode(' / ', array_keys($rateSources)));
printf("履歴重み  : 直近6ヶ月 x %.1f / 7〜12ヶ月 x 1.0\n", $recentWeight);
printf("平滑化    : %s 場×コース直近1年を K=%.1f の事前分布として使用\n", $placeName, $priorK);
echo "その他    : win件数 - 逃げ/差し/まくり/まくり差し = 抜き・恵まれ等をまとめて保持" . PHP_EOL;
echo "注意      : 学習済みAIではなく、展開確率の見せ方・平滑化確認用" . PHP_EOL;

echo PHP_EOL . "【AI展開予想 TOP12】" . PHP_EOL;
foreach (array_slice($events, 0, 12) as $i => $event) {
    printf(
        "%2d位  %d号艇 / %dC  %-10s  %6.2f%%   （勝つ場合 %5.1f%% / 場構成 %5.1f%%）\n",
        $i + 1,
        $event['boat'],
        $event['course'],
        $event['tech'],
        $event['prob'],
        $event['share'],
        $event['venue_share']
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
        "%d号艇/%dC  1着=%6.2f%%  N6=%d 勝%d / N1年=%d 勝%d / 場N=%d  [%s]\n",
        $boat,
        $d['course'],
        $d['p_win'],
        $d['n6'],
        $d['wins6'],
        $d['n12'],
        $d['wins12'],
        $d['venue_n'],
        implode(' / ', $parts)
    );
}

echo PHP_EOL;
echo "※ v1と比較して極端な100%がどの程度抑えられるかを見るのが今回の目的です。" . PHP_EOL;
