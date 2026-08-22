<?php

declare(strict_types=1);

/**
 * 場別 2着TOP4拡張 条件探索
 *
 * 場別イン補正後、2着候補TOP3が狭すぎる問題に対して、
 * 常時TOP4へ広げるのではなく「どの条件だけTOP4化するか」を探索する。
 *
 * 対象戦略
 *   戸田A : Web非1時、別本命-1の一次差2～5だけ非1許可。それ以外は1へ戻す。
 *   多摩川: Web非1時、一次差5～10×二次差5～10だけ非1許可。それ以外は1へ戻す。
 *   大村  : Web非1なら1へ戻す。
 *   下関  : Web非1時、一次差5～10だけ非1許可。それ以外は1へ戻す。
 *
 * 比較するTOP4化条件
 *   - 常時TOP4
 *   - 頭補正が入ったレースだけTOP4
 *   - 補正後の頭が1号艇の時だけTOP4
 *   - 3番手と4番手のfinal3差が一定以下
 *   - 3番手と4番手の二次スコア差が一定以下
 *
 * 出力
 *   - P1/P2ごとのHit率・平均点数・拡張R
 *   - 追加Hit数 / 追加点数
 *   - 1追加Hitを得るのに必要な追加点数
 *
 * DBにはアクセスしない。
 *
 * Usage:
 *   php analysis/explore_stadium_second_top4_conditions.php \
 *     analysis/output/final_prediction_races_20260615_20260714.csv \
 *     analysis/output/final_prediction_boats_20260615_20260714.csv \
 *     analysis/output/final_prediction_races_20260715_20260814.csv \
 *     analysis/output/final_prediction_boats_20260715_20260814.csv
 */

if ($argc < 5) {
    echo PHP_EOL;
    echo "使用方法:" . PHP_EOL;
    echo "  php analysis/explore_stadium_second_top4_conditions.php <P1_races> <P1_boats> <P2_races> <P2_boats>" . PHP_EOL;
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

    $header[0] = preg_replace('/^\\xEF\\xBB\\xBF/', '', (string)$header[0]);
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

function loadPeriod(string $raceCsv, string $boatCsv): array
{
    $raceRows = readCsvAssoc($raceCsv, [
        'race_code', 'race_date', 'stadium_name', 'honmei_head',
        'actual_1st', 'actual_2nd', 'actual_3rd', 'actual_trifecta',
    ]);

    $boatRows = readCsvAssoc($boatCsv, [
        'race_code', 'stadium_name', 'lane_number',
        'first_total_score', 'second_score', 'final3', 'final_rank', 'kiru',
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
        $actualTri = trim((string)$row['actual_trifecta']);
        $boats = $boatsByRace[$raceCode] ?? [];

        if (
            $raceCode === '' ||
            $honmei < 1 || $honmei > 6 ||
            $actual1 < 1 || $actual1 > 6 ||
            $actual2 < 1 || $actual2 > 6 ||
            $actual3 < 1 || $actual3 > 6 ||
            $actualTri === '' ||
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
            'actual_tri' => $actualTri,
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

function adjustedHead(array $race): int
{
    $stadium = $race['stadium'];
    $head = (int)$race['honmei'];
    if ($head === 1) {
        return 1;
    }

    $g = currentHeadGaps($race);

    if ($stadium === '戸田') {
        return inRange($g['primary'], 2.0, 5.0) ? $head : 1;
    }

    if ($stadium === '多摩川') {
        $keep = inRange($g['primary'], 5.0, 10.0)
            && inRange($g['secondary'], 5.0, 10.0);
        return $keep ? $head : 1;
    }

    if ($stadium === '大村') {
        return 1;
    }

    if ($stadium === '下関') {
        return inRange($g['primary'], 5.0, 10.0) ? $head : 1;
    }

    return $head;
}

function finalOrder(array $boats): array
{
    $rows = [];
    foreach ($boats as $lane => $row) {
        $rank = isset($row['final_rank']) && is_numeric($row['final_rank'])
            ? (int)$row['final_rank']
            : 999;
        $rows[] = ['lane' => (int)$lane, 'rank' => $rank];
    }

    usort($rows, static function (array $a, array $b): int {
        $cmp = $a['rank'] <=> $b['rank'];
        return $cmp !== 0 ? $cmp : ($a['lane'] <=> $b['lane']);
    });

    return array_column($rows, 'lane');
}

function adjustedOrder(array $race, int $head): array
{
    $order = finalOrder($race['boats']);
    $order = array_values(array_filter(
        $order,
        static fn(int $lane): bool => $lane !== $head
    ));
    array_unshift($order, $head);
    return $order;
}

function eligibleAfterHead(array $race, int $head): array
{
    $order = adjustedOrder($race, $head);
    $eligible = [];

    foreach ($order as $lane) {
        if ($lane === $head) {
            continue;
        }
        $kiru = (int)($race['boats'][$lane]['kiru'] ?? 0);
        if ($kiru === 1) {
            continue;
        }
        $eligible[] = $lane;
    }

    return $eligible;
}

function buildBets(array $race, int $head, int $secondLimit): array
{
    $eligible = eligibleAfterHead($race, $head);
    $second = array_slice($eligible, 0, $secondLimit);
    $third = $eligible;

    $bets = [];
    foreach ($second as $b) {
        foreach ($third as $c) {
            if ($head === $b || $head === $c || $b === $c) {
                continue;
            }
            $bets[] = "{$head}-{$b}-{$c}";
        }
    }

    return array_values(array_unique($bets));
}

function candidateGap(array $race, int $head, string $scoreKey): ?float
{
    $eligible = eligibleAfterHead($race, $head);
    if (count($eligible) < 4) {
        return null;
    }

    $lane3 = $eligible[2];
    $lane4 = $eligible[3];
    $s3 = num($race['boats'][$lane3] ?? [], $scoreKey);
    $s4 = num($race['boats'][$lane4] ?? [], $scoreKey);

    if ($s3 === null || $s4 === null) {
        return null;
    }

    return abs($s3 - $s4);
}

function policies(): array
{
    return [
        'base' => [
            'label' => '現行TOP3',
            'expand' => static fn(array $race, int $head, bool $changed): bool => false,
        ],
        'always' => [
            'label' => '常時TOP4',
            'expand' => static fn(array $race, int $head, bool $changed): bool => true,
        ],
        'changed_only' => [
            'label' => '頭補正あり時だけTOP4',
            'expand' => static fn(array $race, int $head, bool $changed): bool => $changed,
        ],
        'lane1_head' => [
            'label' => '補正後頭=1時だけTOP4',
            'expand' => static fn(array $race, int $head, bool $changed): bool => $head === 1,
        ],
        'final3_05' => [
            'label' => 'final3差<=0.5でTOP4',
            'expand' => static function (array $race, int $head, bool $changed): bool {
                $g = candidateGap($race, $head, 'final3');
                return $g !== null && $g <= 0.5;
            },
        ],
        'final3_10' => [
            'label' => 'final3差<=1.0でTOP4',
            'expand' => static function (array $race, int $head, bool $changed): bool {
                $g = candidateGap($race, $head, 'final3');
                return $g !== null && $g <= 1.0;
            },
        ],
        'final3_20' => [
            'label' => 'final3差<=2.0でTOP4',
            'expand' => static function (array $race, int $head, bool $changed): bool {
                $g = candidateGap($race, $head, 'final3');
                return $g !== null && $g <= 2.0;
            },
        ],
        'final3_30' => [
            'label' => 'final3差<=3.0でTOP4',
            'expand' => static function (array $race, int $head, bool $changed): bool {
                $g = candidateGap($race, $head, 'final3');
                return $g !== null && $g <= 3.0;
            },
        ],
        'second_10' => [
            'label' => '二次差<=1.0でTOP4',
            'expand' => static function (array $race, int $head, bool $changed): bool {
                $g = candidateGap($race, $head, 'second_score');
                return $g !== null && $g <= 1.0;
            },
        ],
        'second_20' => [
            'label' => '二次差<=2.0でTOP4',
            'expand' => static function (array $race, int $head, bool $changed): bool {
                $g = candidateGap($race, $head, 'second_score');
                return $g !== null && $g <= 2.0;
            },
        ],
        'second_30' => [
            'label' => '二次差<=3.0でTOP4',
            'expand' => static function (array $race, int $head, bool $changed): bool {
                $g = candidateGap($race, $head, 'second_score');
                return $g !== null && $g <= 3.0;
            },
        ],
        'second_50' => [
            'label' => '二次差<=5.0でTOP4',
            'expand' => static function (array $race, int $head, bool $changed): bool {
                $g = candidateGap($race, $head, 'second_score');
                return $g !== null && $g <= 5.0;
            },
        ],
    ];
}

function evaluate(array $period, string $stadium, array $policy): array
{
    $expandFn = $policy['expand'];
    $out = [
        'n' => 0,
        'hit' => 0,
        'bet_count' => 0,
        'expanded_races' => 0,
        'added_bets' => 0,
        'incremental_hits' => 0,
        'base_hit' => 0,
        'base_bet_count' => 0,
    ];

    foreach ($period['races'] as $race) {
        if ($race['stadium'] !== $stadium) {
            continue;
        }

        $head = adjustedHead($race);
        $changed = $head !== (int)$race['honmei'];

        $baseBets = buildBets($race, $head, 3);
        $expand = (bool)$expandFn($race, $head, $changed);
        $bets = $expand ? buildBets($race, $head, 4) : $baseBets;

        $baseHit = in_array($race['actual_tri'], $baseBets, true);
        $hit = in_array($race['actual_tri'], $bets, true);

        $out['n']++;
        $out['base_bet_count'] += count($baseBets);
        $out['bet_count'] += count($bets);
        if ($baseHit) $out['base_hit']++;
        if ($hit) $out['hit']++;

        if ($expand && count($bets) > count($baseBets)) {
            $out['expanded_races']++;
            $out['added_bets'] += count($bets) - count($baseBets);
        }

        if (!$baseHit && $hit) {
            $out['incremental_hits']++;
        }
    }

    return $out;
}

function mergeStats(array $a, array $b): array
{
    $out = [];
    foreach ($a as $key => $value) {
        $out[$key] = (int)$value + (int)($b[$key] ?? 0);
    }
    return $out;
}

function hitRate(array $s): float
{
    return pct((int)$s['hit'], (int)$s['n']);
}

function baseHitRate(array $s): float
{
    return pct((int)$s['base_hit'], (int)$s['n']);
}

function avgBets(array $s): float
{
    return avg((int)$s['bet_count'], (int)$s['n']);
}

function baseAvgBets(array $s): float
{
    return avg((int)$s['base_bet_count'], (int)$s['n']);
}

function betsPerAddedHit(array $s): string
{
    $hits = (int)$s['incremental_hits'];
    if ($hits <= 0) {
        return '-';
    }
    return number_format((int)$s['added_bets'] / $hits, 1);
}

try {
    $p1 = loadPeriod($raceCsv1, $boatCsv1);
    $p2 = loadPeriod($raceCsv2, $boatCsv2);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$policyDefs = policies();
$labels = [
    '戸田' => '戸田A',
    '多摩川' => '多摩川',
    '大村' => '大村',
    '下関' => '下関',
];

echo PHP_EOL;
echo str_repeat('=', 154) . PHP_EOL;
echo "場別 2着TOP4拡張 条件探索" . PHP_EOL;
echo str_repeat('=', 154) . PHP_EOL;
echo "P1 : {$p1['start_date']} ～ {$p1['end_date']}" . PHP_EOL;
echo "P2 : {$p2['start_date']} ～ {$p2['end_date']}" . PHP_EOL;
echo "頭補正: 戸田A / 多摩川 / 大村 / 下関 を固定" . PHP_EOL;
echo "目的  : 常時TOP4ではなく、点数増を抑えつつHitを拾える条件を探す" . PHP_EOL;

foreach (TARGET_STADIUMS as $stadium) {
    echo PHP_EOL;
    echo str_repeat('=', 154) . PHP_EOL;
    echo "【{$labels[$stadium]}】" . PHP_EOL;
    echo str_repeat('=', 154) . PHP_EOL;

    $base1 = evaluate($p1, $stadium, $policyDefs['base']);
    $base2 = evaluate($p2, $stadium, $policyDefs['base']);
    $baseAll = mergeStats($base1, $base2);

    echo sprintf(
        "基準: P1 Hit %s / 平均点数 %.2f | P2 Hit %s / 平均点数 %.2f | 合算 Hit %s / 平均点数 %.2f\n",
        fmtPct(hitRate($base1)), baseAvgBets($base1),
        fmtPct(hitRate($base2)), baseAvgBets($base2),
        fmtPct(hitRate($baseAll)), baseAvgBets($baseAll)
    );
    echo PHP_EOL;

    echo sprintf(
        "%-28s %7s %9s %9s %9s %9s %8s %8s %8s %8s %10s\n",
        '条件', '拡張R', 'P1Hit', 'P1差', 'P2Hit', 'P2差', '合算Hit', 'Hit差', '平均点', '点数差', '追加点/Hit'
    );
    echo str_repeat('-', 154) . PHP_EOL;

    foreach ($policyDefs as $key => $policy) {
        if ($key === 'base') {
            continue;
        }

        $s1 = evaluate($p1, $stadium, $policy);
        $s2 = evaluate($p2, $stadium, $policy);
        $all = mergeStats($s1, $s2);

        $p1Diff = hitRate($s1) - baseHitRate($s1);
        $p2Diff = hitRate($s2) - baseHitRate($s2);
        $allDiff = hitRate($all) - baseHitRate($all);
        $pointDiff = avgBets($all) - baseAvgBets($all);

        echo sprintf(
            "%-28s %3d/%-3d %9s %+8.2fpt %9s %+8.2fpt %8s %+7.2fpt %8.2f %+7.2f %10s\n",
            $policy['label'],
            $s1['expanded_races'],
            $s2['expanded_races'],
            fmtPct(hitRate($s1)),
            $p1Diff,
            fmtPct(hitRate($s2)),
            $p2Diff,
            fmtPct(hitRate($all)),
            $allDiff,
            avgBets($all),
            $pointDiff,
            betsPerAddedHit($all)
        );
    }

    echo PHP_EOL;
    echo "※ 追加点/Hit = 現行TOP3から新たに1Hit増やすために必要だった追加購入点数。小さいほど効率的。" . PHP_EOL;
}

echo PHP_EOL;
echo str_repeat('=', 154) . PHP_EOL;
echo "見方" . PHP_EOL;
echo str_repeat('=', 154) . PHP_EOL;
echo "・P1/P2ともHit差がプラスの条件を優先する。" . PHP_EOL;
echo "・常時TOP4より点数差を抑えつつ、Hit差を大きく残せる条件が本命候補。" . PHP_EOL;
echo "・追加点/Hitが大きすぎる条件は、的中率が上がっても買い目効率が悪い。" . PHP_EOL;
echo "・この段階では回収率を見ない。6か月CSV完成後に固定条件で再検証してから払戻込み検証へ進む。" . PHP_EOL;
echo PHP_EOL;
