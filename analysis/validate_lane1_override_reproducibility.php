<?php

declare(strict_types=1);

/**
 * 4場 イン補正候補 P1/P2再現性検証
 *
 * 探索で見つかった候補条件を固定し、期間1・期間2で同じ方向に効くかを確認する。
 * DBにはアクセスしない。
 *
 * 検証する暫定戦略
 *   戸田A : Webが1以外を本命にした場合、
 *           「別本命-1の一次差 2～5」だけ現行別本命を許可し、それ以外は1へ戻す。
 *   戸田B : Webが1以外を本命にした場合、
 *           「一次差 2～5 × 二次差 5～10」だけ現行別本命を許可し、それ以外は1へ戻す。
 *   多摩川: Webが1以外を本命にした場合、
 *           「一次差 5～10 × 二次差 5～10」だけ現行別本命を許可し、それ以外は1へ戻す。
 *   大村  : Webが1以外を本命にした場合は原則1へ戻す。
 *   下関  : Webが1以外を本命にした場合、
 *           「一次差 5～10」だけ現行別本命を許可し、それ以外は1へ戻す。
 *
 * Usage:
 *   php analysis/validate_lane1_override_reproducibility.php \
 *     analysis/output/final_prediction_races_20260615_20260714.csv \
 *     analysis/output/final_prediction_boats_20260615_20260714.csv \
 *     analysis/output/final_prediction_races_20260715_20260814.csv \
 *     analysis/output/final_prediction_boats_20260715_20260814.csv
 */

if ($argc < 5) {
    echo PHP_EOL;
    echo "使用方法:" . PHP_EOL;
    echo "  php analysis/validate_lane1_override_reproducibility.php <P1_races> <P1_boats> <P2_races> <P2_boats>" . PHP_EOL;
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

function loadPeriod(string $raceCsv, string $boatCsv): array
{
    $raceRows = readCsvAssoc($raceCsv, [
        'race_code', 'race_date', 'stadium_name', 'honmei_head', 'actual_1st',
    ]);

    $boatRows = readCsvAssoc($boatCsv, [
        'race_code', 'stadium_name', 'lane_number',
        'first_total_score', 'second_score',
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
        $raceDate = trim((string)$row['race_date']);
        $honmei = (int)$row['honmei_head'];
        $actual1 = (int)$row['actual_1st'];
        $boats = $boatsByRace[$raceCode] ?? [];

        if (
            $raceCode === '' ||
            $honmei < 1 || $honmei > 6 ||
            $actual1 < 1 || $actual1 > 6 ||
            count($boats) !== 6
        ) {
            continue;
        }

        $races[] = [
            'race_code' => $raceCode,
            'race_date' => $raceDate,
            'stadium' => $stadium,
            'honmei' => $honmei,
            'actual1' => $actual1,
            'boats' => $boats,
        ];

        if ($raceDate !== '') {
            if ($startDate === null || $raceDate < $startDate) $startDate = $raceDate;
            if ($endDate === null || $raceDate > $endDate) $endDate = $raceDate;
        }
    }

    return [
        'races' => $races,
        'start_date' => $startDate,
        'end_date' => $endDate,
    ];
}

function num(array $row, string $key): ?float
{
    $value = $row[$key] ?? null;
    return is_numeric($value) ? (float)$value : null;
}

/**
 * 現行本命 - 1号艇 の一次/二次スコア差を返す。
 * Web本命が1号艇ならnull。
 */
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

function inRange(?float $value, float $min, float $max): bool
{
    return $value !== null && $value >= $min && $value < $max;
}

/**
 * 戦略定義。
 * adjust: レースに戦略を適用した頭を返す。
 * exception: 「1以外を許可する例外条件」に該当するか。
 */
function strategies(): array
{
    return [
        'toda_primary_2_5' => [
            'stadium' => '戸田',
            'label' => '戸田A 一次差2～5だけ非1許可',
            'description' => 'Web非1時、一次差2～5なら現行別本命、それ以外は1へ戻す',
            'adjust' => static function (array $race): int {
                $head = (int)$race['honmei'];
                if ($head === 1) return 1;
                $g = currentHeadGaps($race);
                return inRange($g['primary'], 2.0, 5.0) ? $head : 1;
            },
            'exception' => static function (array $race): bool {
                if ((int)$race['honmei'] === 1) return false;
                $g = currentHeadGaps($race);
                return inRange($g['primary'], 2.0, 5.0);
            },
        ],
        'toda_primary_2_5_secondary_5_10' => [
            'stadium' => '戸田',
            'label' => '戸田B 一次差2～5×二次差5～10だけ非1許可',
            'description' => 'Web非1時、一次差2～5かつ二次差5～10なら現行別本命、それ以外は1へ戻す',
            'adjust' => static function (array $race): int {
                $head = (int)$race['honmei'];
                if ($head === 1) return 1;
                $g = currentHeadGaps($race);
                $keep = inRange($g['primary'], 2.0, 5.0)
                    && inRange($g['secondary'], 5.0, 10.0);
                return $keep ? $head : 1;
            },
            'exception' => static function (array $race): bool {
                if ((int)$race['honmei'] === 1) return false;
                $g = currentHeadGaps($race);
                return inRange($g['primary'], 2.0, 5.0)
                    && inRange($g['secondary'], 5.0, 10.0);
            },
        ],
        'tamagawa_primary_5_10_secondary_5_10' => [
            'stadium' => '多摩川',
            'label' => '多摩川 一次差5～10×二次差5～10だけ非1許可',
            'description' => 'Web非1時、一次差5～10かつ二次差5～10なら現行別本命、それ以外は1へ戻す',
            'adjust' => static function (array $race): int {
                $head = (int)$race['honmei'];
                if ($head === 1) return 1;
                $g = currentHeadGaps($race);
                $keep = inRange($g['primary'], 5.0, 10.0)
                    && inRange($g['secondary'], 5.0, 10.0);
                return $keep ? $head : 1;
            },
            'exception' => static function (array $race): bool {
                if ((int)$race['honmei'] === 1) return false;
                $g = currentHeadGaps($race);
                return inRange($g['primary'], 5.0, 10.0)
                    && inRange($g['secondary'], 5.0, 10.0);
            },
        ],
        'omura_force_lane1' => [
            'stadium' => '大村',
            'label' => '大村 Web非1なら1へ戻す',
            'description' => 'Webが1以外を本命にしたレースは1号艇へ戻す',
            'adjust' => static function (array $race): int {
                return ((int)$race['honmei'] === 1) ? 1 : 1;
            },
            'exception' => null,
        ],
        'shimonoseki_primary_5_10' => [
            'stadium' => '下関',
            'label' => '下関 一次差5～10だけ非1許可',
            'description' => 'Web非1時、一次差5～10なら現行別本命、それ以外は1へ戻す',
            'adjust' => static function (array $race): int {
                $head = (int)$race['honmei'];
                if ($head === 1) return 1;
                $g = currentHeadGaps($race);
                return inRange($g['primary'], 5.0, 10.0) ? $head : 1;
            },
            'exception' => static function (array $race): bool {
                if ((int)$race['honmei'] === 1) return false;
                $g = currentHeadGaps($race);
                return inRange($g['primary'], 5.0, 10.0);
            },
        ],
    ];
}

function evaluateStrategy(array $period, array $strategy): array
{
    $stadium = $strategy['stadium'];
    $adjust = $strategy['adjust'];
    $exception = $strategy['exception'];

    $out = [
        'total' => 0,
        'current_hit' => 0,
        'adjusted_hit' => 0,
        'changed' => 0,
        'changed_current_hit' => 0,
        'changed_adjusted_hit' => 0,
        'non1' => 0,
        'exception_n' => 0,
        'exception_current_hit' => 0,
        'exception_lane1_hit' => 0,
    ];

    foreach ($period['races'] as $race) {
        if ($race['stadium'] !== $stadium) {
            continue;
        }

        $out['total']++;
        $current = (int)$race['honmei'];
        $actual = (int)$race['actual1'];
        $adjusted = (int)$adjust($race);

        if ($current !== 1) {
            $out['non1']++;
        }

        if ($current === $actual) {
            $out['current_hit']++;
        }
        if ($adjusted === $actual) {
            $out['adjusted_hit']++;
        }

        if ($adjusted !== $current) {
            $out['changed']++;
            if ($current === $actual) $out['changed_current_hit']++;
            if ($adjusted === $actual) $out['changed_adjusted_hit']++;
        }

        if (is_callable($exception) && $exception($race)) {
            $out['exception_n']++;
            if ($current === $actual) $out['exception_current_hit']++;
            if ($actual === 1) $out['exception_lane1_hit']++;
        }
    }

    return $out;
}

function combineEval(array $a, array $b): array
{
    $out = [];
    foreach ($a as $key => $value) {
        $out[$key] = (int)$value + (int)($b[$key] ?? 0);
    }
    return $out;
}

function evalRates(array $e): array
{
    $current = pct($e['current_hit'], $e['total']);
    $adjusted = pct($e['adjusted_hit'], $e['total']);
    $changedCurrent = pct($e['changed_current_hit'], $e['changed']);
    $changedAdjusted = pct($e['changed_adjusted_hit'], $e['changed']);
    $exceptionCurrent = pct($e['exception_current_hit'], $e['exception_n']);
    $exceptionLane1 = pct($e['exception_lane1_hit'], $e['exception_n']);

    return [
        'current' => $current,
        'adjusted' => $adjusted,
        'overall_delta' => $adjusted - $current,
        'changed_current' => $changedCurrent,
        'changed_adjusted' => $changedAdjusted,
        'changed_delta' => $changedAdjusted - $changedCurrent,
        'exception_current' => $exceptionCurrent,
        'exception_lane1' => $exceptionLane1,
        'exception_delta' => $exceptionCurrent - $exceptionLane1,
    ];
}

function directionLabel(float $p1Delta, float $p2Delta): string
{
    $eps = 0.00001;
    $p1Pos = $p1Delta > $eps;
    $p2Pos = $p2Delta > $eps;
    $p1Neg = $p1Delta < -$eps;
    $p2Neg = $p2Delta < -$eps;

    if ($p1Pos && $p2Pos) return '◎ 両期間改善';
    if (($p1Pos && !$p2Neg) || ($p2Pos && !$p1Neg)) return '○ 改善＋横ばい';
    if ($p1Neg && $p2Neg) return '× 両期間悪化';
    if (($p1Neg && !$p2Pos) || ($p2Neg && !$p1Pos)) return '△ 悪化＋横ばい';
    return '△ 方向不一致';
}

try {
    $p1 = loadPeriod($raceCsv1, $boatCsv1);
    $p2 = loadPeriod($raceCsv2, $boatCsv2);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

$strategies = strategies();
$evaluated = [];

foreach ($strategies as $key => $strategy) {
    $e1 = evaluateStrategy($p1, $strategy);
    $e2 = evaluateStrategy($p2, $strategy);
    $ec = combineEval($e1, $e2);

    $evaluated[$key] = [
        'strategy' => $strategy,
        'p1' => $e1,
        'p2' => $e2,
        'combined' => $ec,
        'r1' => evalRates($e1),
        'r2' => evalRates($e2),
        'rc' => evalRates($ec),
    ];
}

echo PHP_EOL;
echo str_repeat('=', 132) . PHP_EOL;
echo "4場 イン補正候補 P1/P2再現性検証" . PHP_EOL;
echo str_repeat('=', 132) . PHP_EOL;
echo "P1 : {$p1['start_date']} ～ {$p1['end_date']}" . PHP_EOL;
echo "P2 : {$p2['start_date']} ～ {$p2['end_date']}" . PHP_EOL;
echo "判定: 各期間で『補正後の頭1着率 - 現行本命1着率』が同じ方向かを見る" . PHP_EOL;
echo PHP_EOL;

echo "【戦略適用後の場全体 本命1着率】" . PHP_EOL;
echo sprintf(
    "%-42s %6s %10s %10s %10s %10s %10s %10s %-16s\n",
    '戦略', '変更R', 'P1現行', 'P1補正', 'P1差', 'P2現行', 'P2補正', 'P2差', '再現性'
);
echo str_repeat('-', 132) . PHP_EOL;

foreach ($evaluated as $item) {
    $s = $item['strategy'];
    $e1 = $item['p1'];
    $e2 = $item['p2'];
    $r1 = $item['r1'];
    $r2 = $item['r2'];

    echo sprintf(
        "%-42s %3d/%-3d %10s %10s %+9.2fpt %10s %10s %+9.2fpt %-16s\n",
        $s['label'],
        $e1['changed'],
        $e2['changed'],
        fmtPct($r1['current']),
        fmtPct($r1['adjusted']),
        $r1['overall_delta'],
        fmtPct($r2['current']),
        fmtPct($r2['adjusted']),
        $r2['overall_delta'],
        directionLabel($r1['overall_delta'], $r2['overall_delta'])
    );
}

echo str_repeat('-', 132) . PHP_EOL;
echo PHP_EOL;

echo "【変更対象レースだけで比較】" . PHP_EOL;
echo "※ 実際に『現行別本命 → 1号艇』へ変更したレースだけの比較。" . PHP_EOL;
echo sprintf(
    "%-42s %6s %10s %10s %10s %6s %10s %10s %10s\n",
    '戦略', 'P1 N', 'P1現行', 'P1補正', 'P1差', 'P2 N', 'P2現行', 'P2補正', 'P2差'
);
echo str_repeat('-', 126) . PHP_EOL;

foreach ($evaluated as $item) {
    $s = $item['strategy'];
    $e1 = $item['p1'];
    $e2 = $item['p2'];
    $r1 = $item['r1'];
    $r2 = $item['r2'];

    echo sprintf(
        "%-42s %6d %10s %10s %+9.2fpt %6d %10s %10s %+9.2fpt\n",
        $s['label'],
        $e1['changed'],
        fmtPct($r1['changed_current']),
        fmtPct($r1['changed_adjusted']),
        $r1['changed_delta'],
        $e2['changed'],
        fmtPct($r2['changed_current']),
        fmtPct($r2['changed_adjusted']),
        $r2['changed_delta']
    );
}

echo str_repeat('-', 126) . PHP_EOL;
echo PHP_EOL;

echo "【非1を許可する『例外条件』そのものの再現性】" . PHP_EOL;
echo "※ 例外条件内で『現行の別本命』と『1号艇固定』のどちらが多く勝ったか。プラスなら非1許可を支持。" . PHP_EOL;
echo sprintf(
    "%-42s %6s %10s %10s %10s %6s %10s %10s %10s %-16s\n",
    '例外条件', 'P1 N', 'P1別本命', 'P1 1号', 'P1差', 'P2 N', 'P2別本命', 'P2 1号', 'P2差', '再現性'
);
echo str_repeat('-', 136) . PHP_EOL;

foreach ($evaluated as $item) {
    $s = $item['strategy'];
    if (!is_callable($s['exception'])) {
        continue;
    }

    $e1 = $item['p1'];
    $e2 = $item['p2'];
    $r1 = $item['r1'];
    $r2 = $item['r2'];

    echo sprintf(
        "%-42s %6d %10s %10s %+9.2fpt %6d %10s %10s %+9.2fpt %-16s\n",
        $s['label'],
        $e1['exception_n'],
        fmtPct($r1['exception_current']),
        fmtPct($r1['exception_lane1']),
        $r1['exception_delta'],
        $e2['exception_n'],
        fmtPct($r2['exception_current']),
        fmtPct($r2['exception_lane1']),
        $r2['exception_delta'],
        directionLabel($r1['exception_delta'], $r2['exception_delta'])
    );
}

echo str_repeat('-', 136) . PHP_EOL;
echo PHP_EOL;

echo "【2期間合算】" . PHP_EOL;
foreach ($evaluated as $item) {
    $s = $item['strategy'];
    $e = $item['combined'];
    $r = $item['rc'];

    echo sprintf(
        "%-42s 全体 %3dR: %s → %s (%s) / 変更 %3dR: %s → %s (%s)\n",
        $s['label'],
        $e['total'],
        fmtPct($r['current']),
        fmtPct($r['adjusted']),
        fmtPt($r['overall_delta']),
        $e['changed'],
        fmtPct($r['changed_current']),
        fmtPct($r['changed_adjusted']),
        fmtPt($r['changed_delta'])
    );
}

echo PHP_EOL;
echo "見方:" . PHP_EOL;
echo "  ・『◎ 両期間改善』は6か月検証へ進める優先候補。" . PHP_EOL;
echo "  ・『△ 方向不一致』は探索期間への過適合を疑い、現時点では採用しない。" . PHP_EOL;
echo "  ・例外条件が両期間プラスなら『その条件だけ非1本命を許可』する根拠が強まる。" . PHP_EOL;
echo "  ・大村は例外なしで、Web非1を1へ戻す戦略そのものの再現性を見る。" . PHP_EOL;
echo "  ・本番ロジックへの反映はまだ行わず、6か月CSVで再検証してから判断する。" . PHP_EOL;
echo PHP_EOL;
