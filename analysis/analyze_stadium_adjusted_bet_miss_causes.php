<?php

declare(strict_types=1);

/**
 * 場別イン補正後の3連単外れ原因分析
 *
 * P1/P2で再現性が確認でき、買い目的中率も改善した4候補について、
 * 「補正後の頭は正解したのに本命3連単を外したレース」を分解する。
 *
 * 対象戦略
 *   戸田A : Web非1時、別本命-1の一次差2～5だけ非1許可。それ以外は1へ戻す。
 *   多摩川: Web非1時、一次差5～10×二次差5～10だけ非1許可。それ以外は1へ戻す。
 *   大村  : Web非1なら1へ戻す。
 *   下関  : Web非1時、一次差5～10だけ非1許可。それ以外は1へ戻す。
 *
 * 見るもの
 *   - 補正頭正解時の本命買い目的中率
 *   - 2着艇が外れた理由（切る艇 / 2着候補TOP3外）
 *   - 3着艇が外れた理由（切る艇）
 *   - 実2着/3着艇の最終順位分布
 *   - 構造だけ広げた場合のHit改善と平均点数
 *       ① 現行構造
 *       ② 2着候補をTOP4へ拡張
 *       ③ 2着候補を切る艇以外すべてへ拡張
 *       ④ 切る艇を無視（2着TOP3 / 3着全艇）
 *       ⑤ 2着TOP4 + 切る艇を無視
 *
 * DBにはアクセスしない。
 *
 * Usage:
 *   php analysis/analyze_stadium_adjusted_bet_miss_causes.php \
 *     analysis/output/final_prediction_races_20260615_20260714.csv \
 *     analysis/output/final_prediction_boats_20260615_20260714.csv \
 *     analysis/output/final_prediction_races_20260715_20260814.csv \
 *     analysis/output/final_prediction_boats_20260715_20260814.csv
 */

if ($argc < 5) {
    echo PHP_EOL;
    echo "使用方法:" . PHP_EOL;
    echo "  php analysis/analyze_stadium_adjusted_bet_miss_causes.php <P1_races> <P1_boats> <P2_races> <P2_boats>" . PHP_EOL;
    echo PHP_EOL;
    exit(1);
}

[$script, $raceCsv1, $boatCsv1, $raceCsv2, $boatCsv2] = $argv;

foreach ([$raceCsv1, $boatCsv1, $raceCsv2, $boatCsv2] as $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "CSVが見つかりません: {$path}" . PHP_EOL);
        exit(1);
    }
}

const TARGET_STADIUMS = ['戸田', '多摩川', '大村', '下関'];

function pct(int $count, int $total): float
{
    return $total > 0 ? ($count / $total) * 100.0 : 0.0;
}

function fmtPct(float $value): string
{
    return number_format($value, 2) . '%';
}

function fmtPt(float $value): string
{
    return sprintf('%+.2fpt', $value);
}

function avg(float|int $sum, int $n): float
{
    return $n > 0 ? ((float)$sum / $n) : 0.0;
}

function readCsvAssoc(string $path, array $required): array
{
    $fp = fopen($path, 'rb');
    if ($fp === false) {
        throw new RuntimeException("CSVを開けません: {$path}");
    }

    $header = fgetcsv($fp);
    if ($header === false) {
        fclose($fp);
        throw new RuntimeException("CSVヘッダーを読めません: {$path}");
    }

    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);

    $map = [];
    foreach ($header as $i => $name) {
        $map[(string)$name] = $i;
    }

    foreach ($required as $column) {
        if (!array_key_exists($column, $map)) {
            fclose($fp);
            throw new RuntimeException("必要な列がありません: {$column} ({$path})");
        }
    }

    $rows = [];
    while (($row = fgetcsv($fp)) !== false) {
        if (count($row) < count($header)) {
            continue;
        }

        $assoc = [];
        foreach ($map as $name => $i) {
            $assoc[$name] = $row[$i] ?? '';
        }
        $rows[] = $assoc;
    }

    fclose($fp);
    return $rows;
}

function num(array $row, string $key): ?float
{
    $value = $row[$key] ?? null;
    return is_numeric($value) ? (float)$value : null;
}

function intValOrNull(array $row, string $key): ?int
{
    $value = $row[$key] ?? null;
    return is_numeric($value) ? (int)$value : null;
}

function loadPeriod(string $raceCsv, string $boatCsv): array
{
    $raceRows = readCsvAssoc($raceCsv, [
        'race_code', 'race_date', 'stadium_name',
        'honmei_head', 'actual_1st', 'actual_2nd', 'actual_3rd', 'actual_trifecta',
    ]);

    $boatRows = readCsvAssoc($boatCsv, [
        'race_code', 'stadium_name', 'lane_number',
        'first_total_score', 'second_score', 'final_rank', 'kiru',
    ]);

    $boatsByRace = [];
    foreach ($boatRows as $row) {
        $stadium = trim((string)$row['stadium_name']);
        if (!in_array($stadium, TARGET_STADIUMS, true)) {
            continue;
        }

        $raceCode = trim((string)$row['race_code']);
        $lane = (int)$row['lane_number'];
        if ($raceCode === '' || $lane < 1 || $lane > 6) {
            continue;
        }

        $boatsByRace[$raceCode][$lane] = $row;
    }

    $races = [];
    $startDate = null;
    $endDate = null;

    foreach ($raceRows as $row) {
        $stadium = trim((string)$row['stadium_name']);
        if (!in_array($stadium, TARGET_STADIUMS, true)) {
            continue;
        }

        $raceCode = trim((string)$row['race_code']);
        $date = trim((string)$row['race_date']);
        $honmei = (int)$row['honmei_head'];
        $actual1 = (int)$row['actual_1st'];
        $actual2 = (int)$row['actual_2nd'];
        $actual3 = (int)$row['actual_3rd'];
        $actualTrifecta = trim((string)$row['actual_trifecta']);
        $boats = $boatsByRace[$raceCode] ?? [];

        if (
            $raceCode === '' ||
            $honmei < 1 || $honmei > 6 ||
            $actual1 < 1 || $actual1 > 6 ||
            $actual2 < 1 || $actual2 > 6 ||
            $actual3 < 1 || $actual3 > 6 ||
            $actualTrifecta === '' ||
            count($boats) !== 6
        ) {
            continue;
        }

        $races[] = [
            'race_code' => $raceCode,
            'race_date' => $date,
            'stadium' => $stadium,
            'honmei' => $honmei,
            'actual1' => $actual1,
            'actual2' => $actual2,
            'actual3' => $actual3,
            'actual_trifecta' => $actualTrifecta,
            'boats' => $boats,
        ];

        if ($date !== '') {
            if ($startDate === null || $date < $startDate) $startDate = $date;
            if ($endDate === null || $date > $endDate) $endDate = $date;
        }
    }

    return [
        'races' => $races,
        'start_date' => $startDate,
        'end_date' => $endDate,
    ];
}

function inRange(?float $value, float $min, float $max): bool
{
    return $value !== null && $value >= $min && $value < $max;
}

function currentHeadGaps(array $race): array
{
    $head = (int)$race['honmei'];
    if ($head === 1) {
        return ['primary' => null, 'secondary' => null];
    }

    $boats = $race['boats'];
    $lane1 = $boats[1] ?? [];
    $headRow = $boats[$head] ?? [];

    $p1 = num($lane1, 'first_total_score');
    $ph = num($headRow, 'first_total_score');
    $s1 = num($lane1, 'second_score');
    $sh = num($headRow, 'second_score');

    return [
        'primary' => ($p1 !== null && $ph !== null) ? ($ph - $p1) : null,
        'secondary' => ($s1 !== null && $sh !== null) ? ($sh - $s1) : null,
    ];
}

function strategies(): array
{
    return [
        'toda' => [
            'stadium' => '戸田',
            'label' => '戸田A 一次差2～5だけ非1許可',
            'adjust' => static function (array $race): int {
                $head = (int)$race['honmei'];
                if ($head === 1) return 1;
                $g = currentHeadGaps($race);
                return inRange($g['primary'], 2.0, 5.0) ? $head : 1;
            },
        ],
        'tamagawa' => [
            'stadium' => '多摩川',
            'label' => '多摩川 一次差5～10×二次差5～10だけ非1許可',
            'adjust' => static function (array $race): int {
                $head = (int)$race['honmei'];
                if ($head === 1) return 1;
                $g = currentHeadGaps($race);
                $keep = inRange($g['primary'], 5.0, 10.0)
                    && inRange($g['secondary'], 5.0, 10.0);
                return $keep ? $head : 1;
            },
        ],
        'omura' => [
            'stadium' => '大村',
            'label' => '大村 Web非1なら1へ戻す',
            'adjust' => static fn(array $race): int => 1,
        ],
        'shimonoseki' => [
            'stadium' => '下関',
            'label' => '下関 一次差5～10だけ非1許可',
            'adjust' => static function (array $race): int {
                $head = (int)$race['honmei'];
                if ($head === 1) return 1;
                $g = currentHeadGaps($race);
                return inRange($g['primary'], 5.0, 10.0) ? $head : 1;
            },
        ],
    ];
}

/**
 * final_rank順の艇番リストを作る。
 */
function finalOrder(array $boats): array
{
    $rows = [];
    for ($lane = 1; $lane <= 6; $lane++) {
        $rank = intValOrNull($boats[$lane] ?? [], 'final_rank');
        if ($rank === null) {
            return [];
        }
        $rows[] = ['lane' => $lane, 'rank' => $rank];
    }

    usort($rows, static function (array $a, array $b): int {
        $cmp = $a['rank'] <=> $b['rank'];
        if ($cmp !== 0) return $cmp;
        return $a['lane'] <=> $b['lane'];
    });

    return array_column($rows, 'lane');
}

function moveHeadFirst(array $order, int $head): array
{
    $out = [$head];
    foreach ($order as $lane) {
        $lane = (int)$lane;
        if ($lane !== $head) {
            $out[] = $lane;
        }
    }
    return $out;
}

/**
 * フォーメーションを作る。
 *
 * $secondLimit:
 *   3 / 4 = 上位N艇
 *   99    = 切る艇以外すべて
 *
 * $ignoreKiru=true の場合は切る艇を無視する。
 */
function buildFormation(
    array $race,
    int $head,
    int $secondLimit = 3,
    bool $ignoreKiru = false
): array {
    $boats = $race['boats'];
    $order = finalOrder($boats);
    if (count($order) !== 6) {
        return ['bets' => [], 'second' => [], 'third' => []];
    }

    $order = moveHeadFirst($order, $head);

    $second = [];
    $third = [];

    foreach ($order as $lane) {
        $lane = (int)$lane;
        if ($lane === $head) {
            continue;
        }

        $kiru = (int)($boats[$lane]['kiru'] ?? 0) === 1;
        if (!$ignoreKiru && $kiru) {
            continue;
        }

        $third[] = $lane;

        if ($secondLimit >= 99 || count($second) < $secondLimit) {
            $second[] = $lane;
        }
    }

    $bets = [];
    foreach ($second as $b) {
        foreach ($third as $c) {
            if ($head === $b || $head === $c || $b === $c) {
                continue;
            }
            $bets[] = "{$head}-{$b}-{$c}";
        }
    }

    return [
        'bets' => array_values(array_unique($bets)),
        'second' => $second,
        'third' => $third,
    ];
}

function emptyAggregate(): array
{
    return [
        'races' => 0,
        'head_correct' => 0,
        'base_hit' => 0,
        'head_correct_miss' => 0,
        'second_kiru' => 0,
        'second_outside_top3' => 0,
        'third_kiru' => 0,
        'both_second_and_third_issue' => 0,
        'actual2_final_rank' => array_fill(1, 6, 0),
        'actual3_final_rank' => array_fill(1, 6, 0),
        'sim' => [
            'base' => ['hit' => 0, 'points' => 0],
            'top4' => ['hit' => 0, 'points' => 0],
            'all_non_kiru' => ['hit' => 0, 'points' => 0],
            'ignore_kiru_top3' => ['hit' => 0, 'points' => 0],
            'ignore_kiru_top4' => ['hit' => 0, 'points' => 0],
        ],
    ];
}

function addRankCount(array &$counts, ?int $rank): void
{
    if ($rank !== null && $rank >= 1 && $rank <= 6) {
        $counts[$rank]++;
    }
}

function evaluate(array $period, array $strategy): array
{
    $out = emptyAggregate();
    $stadium = $strategy['stadium'];
    $adjust = $strategy['adjust'];

    foreach ($period['races'] as $race) {
        if ($race['stadium'] !== $stadium) {
            continue;
        }

        $out['races']++;
        $head = (int)$adjust($race);
        $actual1 = (int)$race['actual1'];
        $actual2 = (int)$race['actual2'];
        $actual3 = (int)$race['actual3'];
        $actualTrifecta = (string)$race['actual_trifecta'];
        $boats = $race['boats'];

        $forms = [
            'base' => buildFormation($race, $head, 3, false),
            'top4' => buildFormation($race, $head, 4, false),
            'all_non_kiru' => buildFormation($race, $head, 99, false),
            'ignore_kiru_top3' => buildFormation($race, $head, 3, true),
            'ignore_kiru_top4' => buildFormation($race, $head, 4, true),
        ];

        foreach ($forms as $key => $formation) {
            $bets = $formation['bets'];
            $out['sim'][$key]['points'] += count($bets);
            if (in_array($actualTrifecta, $bets, true)) {
                $out['sim'][$key]['hit']++;
            }
        }

        $base = $forms['base'];
        $baseHit = in_array($actualTrifecta, $base['bets'], true);
        if ($baseHit) {
            $out['base_hit']++;
        }

        if ($head !== $actual1) {
            continue;
        }

        $out['head_correct']++;

        if ($baseHit) {
            continue;
        }

        $out['head_correct_miss']++;

        $secondKiru = (int)($boats[$actual2]['kiru'] ?? 0) === 1;
        $thirdKiru = (int)($boats[$actual3]['kiru'] ?? 0) === 1;
        $secondIn = in_array($actual2, $base['second'], true);

        if ($secondKiru) {
            $out['second_kiru']++;
        } elseif (!$secondIn) {
            $out['second_outside_top3']++;
        }

        if ($thirdKiru) {
            $out['third_kiru']++;
        }

        $secondIssue = $secondKiru || !$secondIn;
        if ($secondIssue && $thirdKiru) {
            $out['both_second_and_third_issue']++;
        }

        addRankCount($out['actual2_final_rank'], intValOrNull($boats[$actual2] ?? [], 'final_rank'));
        addRankCount($out['actual3_final_rank'], intValOrNull($boats[$actual3] ?? [], 'final_rank'));
    }

    return $out;
}

function combine(array $a, array $b): array
{
    $out = emptyAggregate();

    foreach (['races','head_correct','base_hit','head_correct_miss','second_kiru','second_outside_top3','third_kiru','both_second_and_third_issue'] as $key) {
        $out[$key] = (int)$a[$key] + (int)$b[$key];
    }

    for ($rank = 1; $rank <= 6; $rank++) {
        $out['actual2_final_rank'][$rank] = (int)$a['actual2_final_rank'][$rank] + (int)$b['actual2_final_rank'][$rank];
        $out['actual3_final_rank'][$rank] = (int)$a['actual3_final_rank'][$rank] + (int)$b['actual3_final_rank'][$rank];
    }

    foreach (array_keys($out['sim']) as $key) {
        $out['sim'][$key]['hit'] = (int)$a['sim'][$key]['hit'] + (int)$b['sim'][$key]['hit'];
        $out['sim'][$key]['points'] = (int)$a['sim'][$key]['points'] + (int)$b['sim'][$key]['points'];
    }

    return $out;
}

function rankDistribution(array $counts, int $total): string
{
    $parts = [];
    for ($rank = 1; $rank <= 6; $rank++) {
        $n = (int)($counts[$rank] ?? 0);
        if ($n <= 0) continue;
        $parts[] = $rank . '位 ' . $n . 'R(' . number_format(pct($n, $total), 1) . '%)';
    }
    return $parts ? implode(' / ', $parts) : '-';
}

try {
    $p1 = loadPeriod($raceCsv1, $boatCsv1);
    $p2 = loadPeriod($raceCsv2, $boatCsv2);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$strategies = strategies();

$results = [];
foreach ($strategies as $key => $strategy) {
    $r1 = evaluate($p1, $strategy);
    $r2 = evaluate($p2, $strategy);
    $results[$key] = [
        'strategy' => $strategy,
        'p1' => $r1,
        'p2' => $r2,
        'all' => combine($r1, $r2),
    ];
}

echo PHP_EOL;
echo str_repeat('=', 150) . PHP_EOL;
echo "場別イン補正後 3連単外れ原因分析" . PHP_EOL;
echo str_repeat('=', 150) . PHP_EOL;
echo "P1 : {$p1['start_date']} ～ {$p1['end_date']}" . PHP_EOL;
echo "P2 : {$p2['start_date']} ～ {$p2['end_date']}" . PHP_EOL;
echo "対象: 戸田A / 多摩川 / 大村 / 下関" . PHP_EOL;
echo PHP_EOL;

echo "【補正頭が正解したレースの3連単状況】" . PHP_EOL;
echo sprintf(
    "%-48s %7s %10s %10s %10s %10s\n",
    '戦略', '全R', '頭正解R', '買目Hit', '頭正解Hit', '頭正解Miss'
);
echo str_repeat('-', 115) . PHP_EOL;

foreach ($results as $item) {
    $s = $item['strategy'];
    $a = $item['all'];
    $headHit = $a['head_correct'] - $a['head_correct_miss'];
    echo sprintf(
        "%-48s %7d %10d %9s %9s %10d\n",
        $s['label'],
        $a['races'],
        $a['head_correct'],
        fmtPct(pct($a['base_hit'], $a['races'])),
        fmtPct(pct($headHit, $a['head_correct'])),
        $a['head_correct_miss']
    );
}

echo str_repeat('-', 115) . PHP_EOL;
echo PHP_EOL;

foreach ($results as $item) {
    $s = $item['strategy'];
    $a = $item['all'];
    $miss = $a['head_correct_miss'];

    echo str_repeat('=', 150) . PHP_EOL;
    echo "【{$s['label']}】" . PHP_EOL;
    echo str_repeat('=', 150) . PHP_EOL;
    echo "全体 {$a['races']}R / 補正頭正解 {$a['head_correct']}R / 頭正解なのに3連単外れ {$miss}R" . PHP_EOL;
    echo PHP_EOL;

    echo "■ 外れ原因（重複あり）" . PHP_EOL;
    echo "  実2着が切る艇              : {$a['second_kiru']}R (" . fmtPct(pct($a['second_kiru'], $miss)) . ")" . PHP_EOL;
    echo "  実2着が非切りだがTOP3外    : {$a['second_outside_top3']}R (" . fmtPct(pct($a['second_outside_top3'], $miss)) . ")" . PHP_EOL;
    echo "  実3着が切る艇              : {$a['third_kiru']}R (" . fmtPct(pct($a['third_kiru'], $miss)) . ")" . PHP_EOL;
    echo "  2着側と3着側の両方に問題   : {$a['both_second_and_third_issue']}R (" . fmtPct(pct($a['both_second_and_third_issue'], $miss)) . ")" . PHP_EOL;
    echo PHP_EOL;

    echo "■ 頭正解・買い目外れ時の実2着艇 最終順位" . PHP_EOL;
    echo "  " . rankDistribution($a['actual2_final_rank'], $miss) . PHP_EOL;
    echo "■ 頭正解・買い目外れ時の実3着艇 最終順位" . PHP_EOL;
    echo "  " . rankDistribution($a['actual3_final_rank'], $miss) . PHP_EOL;
    echo PHP_EOL;
}

$simLabels = [
    'base' => '現行構造（2着TOP3・切り除外）',
    'top4' => '2着TOP4へ拡張',
    'all_non_kiru' => '2着を非切り全艇へ拡張',
    'ignore_kiru_top3' => '切り無視・2着TOP3',
    'ignore_kiru_top4' => '切り無視・2着TOP4',
];

echo str_repeat('=', 150) . PHP_EOL;
echo "【構造変更シミュレーション：2期間合算】" . PHP_EOL;
echo "※ 頭補正ルールは固定。2着候補数と切る艇の扱いだけを変える。" . PHP_EOL;
echo str_repeat('=', 150) . PHP_EOL;

foreach ($results as $item) {
    $s = $item['strategy'];
    $a = $item['all'];
    $baseHitRate = pct($a['sim']['base']['hit'], $a['races']);
    $basePoints = avg($a['sim']['base']['points'], $a['races']);

    echo PHP_EOL;
    echo "■ {$s['label']}" . PHP_EOL;
    echo sprintf("%-34s %10s %10s %10s %10s\n", '構造', 'Hit率', 'Hit差', '平均点数', '点数差');
    echo str_repeat('-', 82) . PHP_EOL;

    foreach ($simLabels as $key => $label) {
        $hitRate = pct($a['sim'][$key]['hit'], $a['races']);
        $points = avg($a['sim'][$key]['points'], $a['races']);
        echo sprintf(
            "%-34s %10s %+9.2fpt %10.2f %+9.2f\n",
            $label,
            fmtPct($hitRate),
            $hitRate - $baseHitRate,
            $points,
            $points - $basePoints
        );
    }
}

echo PHP_EOL;
echo str_repeat('=', 150) . PHP_EOL;
echo "見方" . PHP_EOL;
echo str_repeat('=', 150) . PHP_EOL;
echo "・実2着TOP3外が多い → 2着候補を4艇へ広げる価値を確認。" . PHP_EOL;
echo "・実2/3着の切る艇が多い → 場別の切り保護を疑う。" . PHP_EOL;
echo "・Hit増に対して点数増が小さい構造だけ次の候補に残す。" . PHP_EOL;
echo "・ここも探索段階。6か月データ完成後に固定条件で再検証する。" . PHP_EOL;
echo PHP_EOL;
