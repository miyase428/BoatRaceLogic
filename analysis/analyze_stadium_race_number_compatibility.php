<?php

declare(strict_types=1);

/**
 * 場 × レース番号 Web予想相性分析 v2
 *
 * v1でCSVの race_number / 着順列に「1R」「1着」などの文字が含まれると
 * is_numeric() 判定で0扱いになり、N=0になるケースがあったため、数値抽出を柔軟化。
 * race_number は race_code 末尾2桁からも補完し、実着順は艇別CSV actual_rank からも補完する。
 *
 * Usage:
 * php analysis/analyze_stadium_race_number_compatibility.php \
 *   analysis/output/final_prediction_races_fast_cached_20250815_20260814.csv \
 *   analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv \
 *   大村
 */

if ($argc < 3 || $argc > 4) {
    fwrite(STDERR,
        "使用方法:\n" .
        "  php {$argv[0]} RACES_CSV BOATS_CSV [場名]\n\n" .
        "例:\n" .
        "  php {$argv[0]} \\\n" .
        "    analysis/output/final_prediction_races_fast_cached_20250815_20260814.csv \\\n" .
        "    analysis/output/final_prediction_boats_fast_cached_20250815_20260814.csv \\\n" .
        "    大村\n"
    );
    exit(1);
}

$racesPath = $argv[1];
$boatsPath = $argv[2];
$stadiumFilter = isset($argv[3]) ? trim((string)$argv[3]) : '';

foreach ([$racesPath, $boatsPath] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException("必要ファイルがありません: {$path}");
    }
}

require_once __DIR__ . '/../common/db_connect.php';

function readCsvAssoc(string $path): array
{
    $fp = fopen($path, 'rb');
    if ($fp === false) {
        throw new RuntimeException("CSVを開けません: {$path}");
    }

    $header = fgetcsv($fp);
    if ($header === false) {
        fclose($fp);
        return [];
    }

    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    }

    $rows = [];
    while (($cols = fgetcsv($fp)) !== false) {
        if (count($cols) !== count($header)) {
            continue;
        }
        $assoc = array_combine($header, $cols);
        if (is_array($assoc)) {
            $rows[] = $assoc;
        }
    }

    fclose($fp);
    return $rows;
}

/**
 * 「1」「1R」「1着」「第1レース」などから整数を取り出す。
 */
function looseInt(mixed $value, int $default = 0): int
{
    if (is_int($value)) {
        return $value;
    }
    if (is_float($value)) {
        return (int)$value;
    }

    $text = trim((string)$value);
    if ($text === '') {
        return $default;
    }
    if (is_numeric($text)) {
        return (int)$text;
    }
    if (preg_match('/-?\d+/u', $text, $m) === 1) {
        return (int)$m[0];
    }

    return $default;
}

function rowInt(array $row, string $key, int $default = 0): int
{
    return looseInt($row[$key] ?? null, $default);
}

function raceNoFromRow(array $race): int
{
    $raceNo = rowInt($race, 'race_number');
    if ($raceNo >= 1 && $raceNo <= 12) {
        return $raceNo;
    }

    $raceCode = trim((string)($race['race_code'] ?? ''));
    if ($raceCode !== '' && preg_match('/(\d{1,2})$/', $raceCode, $m) === 1) {
        $raceNo = (int)$m[1];
        if ($raceNo >= 1 && $raceNo <= 12) {
            return $raceNo;
        }
    }

    return 0;
}

function pct(int $num, int $den): float
{
    return $den > 0 ? 100.0 * $num / $den : 0.0;
}

function fmtPct(int $num, int $den): string
{
    return $den > 0 ? number_format(pct($num, $den), 1) . '%' : '-';
}

function expandTrifecta(string $formation): array
{
    $formation = trim($formation);
    if ($formation === '') {
        return [];
    }

    $parts = explode('-', $formation);
    if (count($parts) !== 3) {
        return [];
    }

    [$firstPart, $secondPart, $thirdPart] = array_map('trim', $parts);
    if ($firstPart === '' || $secondPart === '' || $thirdPart === '') {
        return [];
    }

    $bets = [];
    foreach (str_split($firstPart) as $a) {
        foreach (str_split($secondPart) as $b) {
            foreach (str_split($thirdPart) as $c) {
                if ($a === $b || $a === $c || $b === $c) {
                    continue;
                }
                if (!ctype_digit($a) || !ctype_digit($b) || !ctype_digit($c)) {
                    continue;
                }
                $bets[] = "{$a}-{$b}-{$c}";
            }
        }
    }

    return array_values(array_unique($bets));
}

function emptyStat(): array
{
    return [
        'n' => 0,
        'honmei_n' => 0,
        'honmei_first' => 0,
        'honmei_top3' => 0,
        'top3_n' => 0,
        'top3_match_2plus' => 0,
        'top3_match_3' => 0,
        'bet_n' => 0,
        'bet_hit' => 0,
        'investment' => 0,
        'payout' => 0,
    ];
}

function addRaceToStat(
    array &$stat,
    int $honmei,
    array $actualTop3,
    array $predictedTop3,
    array $honmeiBets,
    string $actualTrifecta,
    ?int $payout
): void {
    $stat['n']++;

    if ($honmei >= 1 && $honmei <= 6) {
        $stat['honmei_n']++;
        if (($actualTop3[0] ?? 0) === $honmei) {
            $stat['honmei_first']++;
        }
        if (in_array($honmei, $actualTop3, true)) {
            $stat['honmei_top3']++;
        }
    }

    if (count($predictedTop3) === 3) {
        $stat['top3_n']++;
        $matches = count(array_intersect($predictedTop3, $actualTop3));
        if ($matches >= 2) {
            $stat['top3_match_2plus']++;
        }
        if ($matches === 3) {
            $stat['top3_match_3']++;
        }
    }

    if ($honmeiBets !== []) {
        $stat['bet_n']++;
        $isHit = in_array($actualTrifecta, $honmeiBets, true);
        if ($isHit) {
            $stat['bet_hit']++;
        }

        if ($payout !== null) {
            $stat['investment'] += count($honmeiBets) * 100;
            if ($isHit) {
                $stat['payout'] += $payout;
            }
        }
    }
}

function statRates(array $stat): array
{
    return [
        'honmei_first' => pct($stat['honmei_first'], $stat['honmei_n']),
        'honmei_top3' => pct($stat['honmei_top3'], $stat['honmei_n']),
        'top3_2plus' => pct($stat['top3_match_2plus'], $stat['top3_n']),
        'top3_3' => pct($stat['top3_match_3'], $stat['top3_n']),
        'bet_hit' => pct($stat['bet_hit'], $stat['bet_n']),
        'roi' => $stat['investment'] > 0
            ? 100.0 * $stat['payout'] / $stat['investment']
            : 0.0,
    ];
}

function compatibilityScore(
    array $allStat,
    array $allPlace,
    array $recentStat,
    array $recentPlace
): array {
    if ($allStat['n'] < 30 || $recentStat['n'] < 15) {
        return ['score' => null, 'grade' => '参考'];
    }

    $a = statRates($allStat);
    $ap = statRates($allPlace);
    $r = statRates($recentStat);
    $rp = statRates($recentPlace);

    $score = 0;
    $score += (int)($a['honmei_first'] >= $ap['honmei_first']);
    $score += (int)($a['top3_2plus'] >= $ap['top3_2plus']);
    $score += (int)($a['bet_hit'] >= $ap['bet_hit']);
    $score += (int)($a['roi'] >= 100.0);
    $score += (int)($r['honmei_first'] >= $rp['honmei_first']);
    $score += (int)($r['bet_hit'] >= $rp['bet_hit']);

    $grade = match (true) {
        $score >= 5 => 'A',
        $score === 4 => 'B',
        $score >= 2 => 'C',
        default => 'D',
    };

    return ['score' => $score, 'grade' => $grade];
}

$races = readCsvAssoc($racesPath);
$boats = readCsvAssoc($boatsPath);

if ($races === []) {
    throw new RuntimeException('レース別CSVにデータがありません。');
}
if ($boats === []) {
    throw new RuntimeException('艇別CSVにデータがありません。');
}

$predictedTop3Ranked = [];
$actualTop3Ranked = [];
foreach ($boats as $boat) {
    $raceCode = trim((string)($boat['race_code'] ?? ''));
    $lane = rowInt($boat, 'lane_number');
    if ($raceCode === '' || $lane < 1 || $lane > 6) {
        continue;
    }

    $finalRank = rowInt($boat, 'final_rank', 99);
    if ($finalRank >= 1 && $finalRank <= 3) {
        $predictedTop3Ranked[$raceCode][$finalRank] = $lane;
    }

    $actualRank = rowInt($boat, 'actual_rank', 99);
    if ($actualRank >= 1 && $actualRank <= 3) {
        $actualTop3Ranked[$raceCode][$actualRank] = $lane;
    }
}

$predictedTop3ByRace = [];
foreach ($predictedTop3Ranked as $raceCode => $ranked) {
    ksort($ranked, SORT_NUMERIC);
    $values = array_values(array_map('intval', $ranked));
    if (count($values) === 3 && count(array_unique($values)) === 3) {
        $predictedTop3ByRace[$raceCode] = $values;
    }
}

$actualTop3ByRaceFromBoats = [];
foreach ($actualTop3Ranked as $raceCode => $ranked) {
    ksort($ranked, SORT_NUMERIC);
    $values = array_values(array_map('intval', $ranked));
    if (count($values) === 3 && count(array_unique($values)) === 3) {
        $actualTop3ByRaceFromBoats[$raceCode] = $values;
    }
}

$filteredRaces = [];
$stadiums = [];
$maxDateByStadium = [];

foreach ($races as $race) {
    $stadium = trim((string)($race['stadium_name'] ?? ''));
    $date = trim((string)($race['race_date'] ?? ''));

    if ($stadium === '') {
        continue;
    }
    if ($stadiumFilter !== '' && $stadium !== $stadiumFilter) {
        continue;
    }

    $filteredRaces[] = $race;
    $stadiums[$stadium] = true;

    if ($date !== '' && (!isset($maxDateByStadium[$stadium]) || $date > $maxDateByStadium[$stadium])) {
        $maxDateByStadium[$stadium] = $date;
    }
}

if ($filteredRaces === []) {
    $suffix = $stadiumFilter !== '' ? "（場名: {$stadiumFilter}）" : '';
    throw new RuntimeException("対象レースがありません{$suffix}。");
}

$recentStartByStadium = [];
foreach ($maxDateByStadium as $stadium => $maxDate) {
    try {
        $recentStartByStadium[$stadium] = (new DateTimeImmutable($maxDate))
            ->modify('-182 days')
            ->format('Y-m-d');
    } catch (Throwable) {
        $recentStartByStadium[$stadium] = '';
    }
}

$pdo = getPDO();
$payoutStmt = $pdo->prepare(
    'SELECT trifecta_payout FROM boat_race.race_payouts WHERE race_code = :race_code'
);

$all = [];
$recent = [];
$placeAll = [];
$placeRecent = [];
$skip = [
    'race_no' => 0,
    'actual' => 0,
];

foreach ($filteredRaces as $race) {
    $raceCode = trim((string)($race['race_code'] ?? ''));
    $stadium = trim((string)($race['stadium_name'] ?? ''));
    $raceNo = raceNoFromRow($race);
    $date = trim((string)($race['race_date'] ?? ''));

    if ($raceCode === '' || $raceNo < 1 || $raceNo > 12) {
        $skip['race_no']++;
        continue;
    }

    $actual1 = rowInt($race, 'actual_1st');
    $actual2 = rowInt($race, 'actual_2nd');
    $actual3 = rowInt($race, 'actual_3rd');
    $actualTop3 = [$actual1, $actual2, $actual3];

    if (
        $actual1 < 1 || $actual1 > 6 ||
        $actual2 < 1 || $actual2 > 6 ||
        $actual3 < 1 || $actual3 > 6 ||
        count(array_unique($actualTop3)) !== 3
    ) {
        $actualTop3 = $actualTop3ByRaceFromBoats[$raceCode] ?? [];
    }

    if (count($actualTop3) !== 3) {
        $skip['actual']++;
        continue;
    }

    [$actual1, $actual2, $actual3] = $actualTop3;
    $actualTrifecta = "{$actual1}-{$actual2}-{$actual3}";

    $honmei = rowInt($race, 'honmei_head');
    $honmeiBets = expandTrifecta((string)($race['honmei_kai'] ?? ''));
    $predictedTop3 = $predictedTop3ByRace[$raceCode] ?? [];

    $payout = null;
    if ($honmeiBets !== []) {
        $payoutStmt->execute([':race_code' => $raceCode]);
        $value = $payoutStmt->fetchColumn();
        if ($value !== false && $value !== null) {
            $normalized = str_replace(',', '', trim((string)$value));
            if (is_numeric($normalized)) {
                $payout = (int)$normalized;
            }
        }
    }

    $all[$stadium][$raceNo] ??= emptyStat();
    $placeAll[$stadium] ??= emptyStat();

    addRaceToStat($all[$stadium][$raceNo], $honmei, $actualTop3, $predictedTop3, $honmeiBets, $actualTrifecta, $payout);
    addRaceToStat($placeAll[$stadium], $honmei, $actualTop3, $predictedTop3, $honmeiBets, $actualTrifecta, $payout);

    $recentStart = $recentStartByStadium[$stadium] ?? '';
    if ($recentStart !== '' && $date >= $recentStart) {
        $recent[$stadium][$raceNo] ??= emptyStat();
        $placeRecent[$stadium] ??= emptyStat();

        addRaceToStat($recent[$stadium][$raceNo], $honmei, $actualTop3, $predictedTop3, $honmeiBets, $actualTrifecta, $payout);
        addRaceToStat($placeRecent[$stadium], $honmei, $actualTop3, $predictedTop3, $honmeiBets, $actualTrifecta, $payout);
    }
}

$stadiumNames = array_keys($stadiums);
sort($stadiumNames, SORT_STRING);

echo str_repeat('=', 150) . PHP_EOL;
echo "場 × レース番号 Web予想相性分析 v2" . PHP_EOL;
echo str_repeat('=', 150) . PHP_EOL;
echo "RACES : {$racesPath}" . PHP_EOL;
echo "BOATS : {$boatsPath}" . PHP_EOL;
if ($stadiumFilter !== '') {
    echo "場    : {$stadiumFilter}" . PHP_EOL;
}
echo "入力対象: " . count($filteredRaces) . "R / 除外 race_number={$skip['race_no']} / 実着順={$skip['actual']}" . PHP_EOL;
echo "判定A～Dは『場平均より良いか＋回収率100%以上＋直近6か月でも良いか』の参考スコア。予測確率ではありません。" . PHP_EOL;

foreach ($stadiumNames as $stadium) {
    $allPlace = $placeAll[$stadium] ?? emptyStat();
    $recentPlace = $placeRecent[$stadium] ?? emptyStat();
    $allPlaceRates = statRates($allPlace);
    $recentStart = $recentStartByStadium[$stadium] ?? '-';

    echo PHP_EOL . str_repeat('=', 150) . PHP_EOL;
    echo "【{$stadium}】 全体N={$allPlace['n']} / 直近約6か月={$recentStart}～" . ($maxDateByStadium[$stadium] ?? '-') . " (N={$recentPlace['n']})" . PHP_EOL;
    echo "場平均: 本命1着=" . number_format($allPlaceRates['honmei_first'], 1) . "%"
        . " / TOP3≧2艇=" . number_format($allPlaceRates['top3_2plus'], 1) . "%"
        . " / TOP3=3艇=" . number_format($allPlaceRates['top3_3'], 1) . "%"
        . " / 買い目的中=" . number_format($allPlaceRates['bet_hit'], 1) . "%"
        . " / 回収率=" . number_format($allPlaceRates['roi'], 1) . "%" . PHP_EOL;
    echo str_repeat('-', 150) . PHP_EOL;
    echo " R   N   本命1着  本命3連  TOP3≧2  TOP3=3  買い目的中  回収率   直近N  直近本命1着  直近的中  判定" . PHP_EOL;
    echo str_repeat('-', 150) . PHP_EOL;

    $ranking = [];

    for ($raceNo = 1; $raceNo <= 12; $raceNo++) {
        $a = $all[$stadium][$raceNo] ?? emptyStat();
        $r = $recent[$stadium][$raceNo] ?? emptyStat();
        $rates = statRates($a);
        $recentRates = statRates($r);
        $compat = compatibilityScore($a, $allPlace, $r, $recentPlace);

        printf(
            "%2dR %4d  %7s  %7s  %7s  %7s  %9s  %7s  %6d  %10s  %8s  %s%s\n",
            $raceNo,
            $a['n'],
            fmtPct($a['honmei_first'], $a['honmei_n']),
            fmtPct($a['honmei_top3'], $a['honmei_n']),
            fmtPct($a['top3_match_2plus'], $a['top3_n']),
            fmtPct($a['top3_match_3'], $a['top3_n']),
            fmtPct($a['bet_hit'], $a['bet_n']),
            $a['investment'] > 0 ? number_format($rates['roi'], 1) . '%' : '-',
            $r['n'],
            fmtPct($r['honmei_first'], $r['honmei_n']),
            fmtPct($r['bet_hit'], $r['bet_n']),
            $compat['grade'],
            $compat['score'] !== null ? "({$compat['score']}/6)" : ''
        );

        $ranking[] = [
            'race_no' => $raceNo,
            'n' => $a['n'],
            'grade' => $compat['grade'],
            'score' => $compat['score'] ?? -1,
            'recent_hit' => $recentRates['bet_hit'],
            'all_hit' => $rates['bet_hit'],
            'roi' => $rates['roi'],
            'honmei_first' => $rates['honmei_first'],
            'top3_2plus' => $rates['top3_2plus'],
        ];
    }

    usort($ranking, static function (array $x, array $y): int {
        return [$y['score'], $y['recent_hit'], $y['all_hit'], $y['honmei_first'], $y['top3_2plus'], $y['roi']]
            <=> [$x['score'], $x['recent_hit'], $x['all_hit'], $x['honmei_first'], $x['top3_2plus'], $x['roi']];
    });

    echo PHP_EOL . "参考ランキング（相性スコア → 直近的中率 → 全期間的中率の順）" . PHP_EOL;
    $rankNo = 1;
    foreach ($ranking as $item) {
        if ($item['score'] < 0) {
            continue;
        }
        printf(
            "  %2d位: %2dR  %s(%d/6)  N=%d  本命1着=%.1f%%  TOP3≧2=%.1f%%  的中=%.1f%%  直近=%.1f%%  回収率=%.1f%%\n",
            $rankNo,
            $item['race_no'],
            $item['grade'],
            $item['score'],
            $item['n'],
            $item['honmei_first'],
            $item['top3_2plus'],
            $item['all_hit'],
            $item['recent_hit'],
            $item['roi']
        );
        $rankNo++;
    }

    echo PHP_EOL . "見方:" . PHP_EOL;
    echo "- 本命1着: Web本命艇が実際に1着だった率。" . PHP_EOL;
    echo "- TOP3≧2: Web最終順位TOP3のうち、実際の1～3着に2艇以上入った率。" . PHP_EOL;
    echo "- TOP3=3: 順不同で3艇すべて捕捉した率。" . PHP_EOL;
    echo "- 買い目的中/回収率: 現行の本命買い目を1点100円で買った場合。" . PHP_EOL;
    echo "- A/Bは『その場の中では相対的に噛み合いやすい候補』。単独で購入判断には使わず、当日の展示・進入と併用する。" . PHP_EOL;
}
