<?php

declare(strict_types=1);

/**
 * 4場 イン補正候補 本命3連単買い目シミュレーション
 *
 * P1/P2で再現性が確認できた5候補を固定し、
 * 本命の頭を補正した場合に「本命買い目」の的中率・点数がどう変わるかを見る。
 * DBにはアクセスしないため、6か月CSV生成中でも並行実行できる。
 *
 * 検証候補
 *   戸田A : Web非1時、別本命-1の一次差2～5だけ非1許可。それ以外は1へ戻す。
 *   戸田B : Web非1時、一次差2～5×二次差5～10だけ非1許可。それ以外は1へ戻す。
 *   多摩川: Web非1時、一次差5～10×二次差5～10だけ非1許可。それ以外は1へ戻す。
 *   大村  : Web非1なら1へ戻す。
 *   下関  : Web非1時、一次差5～10だけ非1許可。それ以外は1へ戻す。
 *
 * 補正後フォーメーションは、CSVのfinal_rank（STEP4適用後順位）を基準に
 * 補正後の頭を先頭へ移動し、現行buildSummaryと同じく
 *   - 切る艇を除外
 *   - 2着候補は残り上位3艇
 *   - 3着候補は切る艇以外すべて
 * で再構築する。
 *
 * Usage:
 *   php analysis/simulate_stadium_lane1_bet_impact.php \
 *     analysis/output/final_prediction_races_20260615_20260714.csv \
 *     analysis/output/final_prediction_boats_20260615_20260714.csv \
 *     analysis/output/final_prediction_races_20260715_20260814.csv \
 *     analysis/output/final_prediction_boats_20260715_20260814.csv
 */

if ($argc < 5) {
    echo PHP_EOL;
    echo "使用方法:" . PHP_EOL;
    echo "  php analysis/simulate_stadium_lane1_bet_impact.php <P1_races> <P1_boats> <P2_races> <P2_boats>" . PHP_EOL;
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

function loadPeriod(string $raceCsv, string $boatCsv): array
{
    $raceRows = readCsvAssoc($raceCsv, [
        'race_code', 'race_date', 'stadium_name',
        'honmei_head', 'honmei_kai',
        'actual_1st', 'actual_2nd', 'actual_3rd', 'actual_trifecta',
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
        $raceDate = trim((string)$row['race_date']);
        $honmei = (int)$row['honmei_head'];
        $honmeiKai = trim((string)$row['honmei_kai']);
        $actual1 = (int)$row['actual_1st'];
        $actual2 = (int)$row['actual_2nd'];
        $actual3 = (int)$row['actual_3rd'];
        $actualTrifecta = trim((string)$row['actual_trifecta']);
        $boats = $boatsByRace[$raceCode] ?? [];

        if (
            $raceCode === '' ||
            $honmei < 1 || $honmei > 6 ||
            $honmeiKai === '' ||
            $actual1 < 1 || $actual1 > 6 ||
            $actual2 < 1 || $actual2 > 6 ||
            $actual3 < 1 || $actual3 > 6 ||
            $actualTrifecta === '' ||
            count($boats) !== 6
        ) {
            continue;
        }

        $validBoatData = true;
        for ($lane = 1; $lane <= 6; $lane++) {
            $b = $boats[$lane] ?? [];
            if (
                !is_numeric($b['final_rank'] ?? null) ||
                !is_numeric($b['kiru'] ?? null) ||
                !is_numeric($b['first_total_score'] ?? null) ||
                !is_numeric($b['second_score'] ?? null)
            ) {
                $validBoatData = false;
                break;
            }
        }
        if (!$validBoatData) {
            continue;
        }

        $races[] = [
            'race_code' => $raceCode,
            'race_date' => $raceDate,
            'stadium' => $stadium,
            'honmei' => $honmei,
            'honmei_kai' => $honmeiKai,
            'actual1' => $actual1,
            'actual2' => $actual2,
            'actual3' => $actual3,
            'actual_trifecta' => $actualTrifecta,
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

function strategies(): array
{
    return [
        'toda_primary_2_5' => [
            'stadium' => '戸田',
            'label' => '戸田A 一次差2～5だけ非1許可',
            'adjust' => static function (array $race): int {
                $head = (int)$race['honmei'];
                if ($head === 1) return 1;
                $g = currentHeadGaps($race);
                return inRange($g['primary'], 2.0, 5.0) ? $head : 1;
            },
        ],
        'toda_primary_2_5_secondary_5_10' => [
            'stadium' => '戸田',
            'label' => '戸田B 一次差2～5×二次差5～10だけ非1許可',
            'adjust' => static function (array $race): int {
                $head = (int)$race['honmei'];
                if ($head === 1) return 1;
                $g = currentHeadGaps($race);
                $keep = inRange($g['primary'], 2.0, 5.0)
                    && inRange($g['secondary'], 5.0, 10.0);
                return $keep ? $head : 1;
            },
        ],
        'tamagawa_primary_5_10_secondary_5_10' => [
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
        'omura_force_lane1' => [
            'stadium' => '大村',
            'label' => '大村 Web非1なら1へ戻す',
            'adjust' => static function (array $race): int {
                return 1;
            },
        ],
        'shimonoseki_primary_5_10' => [
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
 * 3連単フォーメーションを点数へ展開する。
 */
function expandTrifecta(string $formation): array
{
    $formation = trim($formation);
    if ($formation === '') return [];

    $parts = explode('-', $formation);
    if (count($parts) !== 3) return [];

    $first = str_split(trim($parts[0]));
    $second = str_split(trim($parts[1]));
    $third = str_split(trim($parts[2]));

    $bets = [];
    foreach ($first as $a) {
        foreach ($second as $b) {
            foreach ($third as $c) {
                if ($a === $b || $a === $c || $b === $c) {
                    continue;
                }
                $bets[] = "{$a}-{$b}-{$c}";
            }
        }
    }

    $bets = array_values(array_unique($bets));
    sort($bets);
    return $bets;
}

/**
 * final_rank順の艇番を返す。
 * final_rankはExporter上でSTEP4適用後のrank_boatsを保存している。
 */
function finalRankBoats(array $boats): array
{
    $rows = [];
    for ($lane = 1; $lane <= 6; $lane++) {
        $rank = (int)($boats[$lane]['final_rank'] ?? 99);
        $rows[] = ['lane' => $lane, 'rank' => $rank];
    }

    usort($rows, static function (array $a, array $b): int {
        $cmp = $a['rank'] <=> $b['rank'];
        if ($cmp !== 0) return $cmp;
        return $a['lane'] <=> $b['lane'];
    });

    return array_column($rows, 'lane');
}

/**
 * 現行buildSummary相当の本命フォーメーションを、指定headで再構築する。
 */
function buildHonmeiFormation(array $race, int $head): string
{
    $boats = $race['boats'];
    $rankBoats = finalRankBoats($boats);

    // 指定した頭を先頭へ移し、それ以外の順位関係は維持する。
    $rankBoats = array_values(array_filter(
        $rankBoats,
        static fn(int $lane): bool => $lane !== $head
    ));
    array_unshift($rankBoats, $head);

    $aite = [];
    $third = [];

    foreach ($rankBoats as $lane) {
        if ($lane === $head) {
            continue;
        }

        $kiru = (int)($boats[$lane]['kiru'] ?? 0);
        if ($kiru === 1) {
            continue;
        }

        $third[] = $lane;
        if (count($aite) < 3) {
            $aite[] = $lane;
        }
    }

    sort($aite);
    sort($third);

    return $head
        . '-'
        . implode('', $aite)
        . '-'
        . implode('', $third);
}

function sameBetSet(string $a, string $b): bool
{
    return expandTrifecta($a) === expandTrifecta($b);
}

function emptyStats(): array
{
    return [
        'total' => 0,
        'changed' => 0,
        'current_hit' => 0,
        'adjusted_hit' => 0,
        'current_points' => 0,
        'adjusted_points' => 0,
        'changed_current_hit' => 0,
        'changed_adjusted_hit' => 0,
        'changed_current_points' => 0,
        'changed_adjusted_points' => 0,
        'gained_hit' => 0,
        'lost_hit' => 0,
        'adjusted_head_hit' => 0,
        'adjusted_head_hit_bet_miss' => 0,
        'changed_adjusted_head_hit' => 0,
        'changed_adjusted_head_hit_bet_miss' => 0,
        'rebuild_mismatch' => 0,
    ];
}

function evaluateStrategy(array $period, array $strategy): array
{
    $out = emptyStats();
    $stadium = $strategy['stadium'];
    $adjust = $strategy['adjust'];

    foreach ($period['races'] as $race) {
        if ($race['stadium'] !== $stadium) {
            continue;
        }

        $currentHead = (int)$race['honmei'];
        $adjustedHead = (int)$adjust($race);
        $actual1 = (int)$race['actual1'];
        $actualTrifecta = (string)$race['actual_trifecta'];

        $currentFormation = (string)$race['honmei_kai'];
        $rebuiltCurrent = buildHonmeiFormation($race, $currentHead);
        if (!sameBetSet($currentFormation, $rebuiltCurrent)) {
            $out['rebuild_mismatch']++;
        }

        // 変更しないレースはCSVの現行買い目をそのまま使う。
        $adjustedFormation = ($adjustedHead === $currentHead)
            ? $currentFormation
            : buildHonmeiFormation($race, $adjustedHead);

        $currentBets = expandTrifecta($currentFormation);
        $adjustedBets = expandTrifecta($adjustedFormation);

        $currentHit = in_array($actualTrifecta, $currentBets, true);
        $adjustedHit = in_array($actualTrifecta, $adjustedBets, true);
        $adjustedHeadHit = ($adjustedHead === $actual1);

        $out['total']++;
        $out['current_points'] += count($currentBets);
        $out['adjusted_points'] += count($adjustedBets);
        if ($currentHit) $out['current_hit']++;
        if ($adjustedHit) $out['adjusted_hit']++;
        if ($adjustedHeadHit) {
            $out['adjusted_head_hit']++;
            if (!$adjustedHit) {
                $out['adjusted_head_hit_bet_miss']++;
            }
        }

        if ($adjustedHead !== $currentHead) {
            $out['changed']++;
            $out['changed_current_points'] += count($currentBets);
            $out['changed_adjusted_points'] += count($adjustedBets);
            if ($currentHit) $out['changed_current_hit']++;
            if ($adjustedHit) $out['changed_adjusted_hit']++;
            if (!$currentHit && $adjustedHit) $out['gained_hit']++;
            if ($currentHit && !$adjustedHit) $out['lost_hit']++;

            if ($adjustedHeadHit) {
                $out['changed_adjusted_head_hit']++;
                if (!$adjustedHit) {
                    $out['changed_adjusted_head_hit_bet_miss']++;
                }
            }
        }
    }

    return $out;
}

function combineStats(array $a, array $b): array
{
    $out = emptyStats();
    foreach ($out as $key => $_) {
        $out[$key] = (int)($a[$key] ?? 0) + (int)($b[$key] ?? 0);
    }
    return $out;
}

function hitDelta(array $s): float
{
    return pct($s['adjusted_hit'], $s['total']) - pct($s['current_hit'], $s['total']);
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
    $r1 = evaluateStrategy($p1, $strategy);
    $r2 = evaluateStrategy($p2, $strategy);
    $results[$key] = [
        'strategy' => $strategy,
        'p1' => $r1,
        'p2' => $r2,
        'combined' => combineStats($r1, $r2),
    ];
}

echo PHP_EOL;
echo str_repeat('=', 150) . PHP_EOL;
echo "4場 イン補正候補 本命3連単買い目シミュレーション" . PHP_EOL;
echo str_repeat('=', 150) . PHP_EOL;
echo "P1 : {$p1['start_date']} ～ {$p1['end_date']}" . PHP_EOL;
echo "P2 : {$p2['start_date']} ～ {$p2['end_date']}" . PHP_EOL;
echo "対象: 本命買い目のみ / 払戻・回収率はDB負荷回避のため未集計" . PHP_EOL;
echo PHP_EOL;

echo "【場全体：本命買い目的中率】" . PHP_EOL;
echo sprintf(
    "%-45s %7s %9s %9s %9s %9s %9s %9s %12s\n",
    '戦略', '変更R', 'P1現行', 'P1補正', 'P1差', 'P2現行', 'P2補正', 'P2差', '買目再現'
);
echo str_repeat('-', 150) . PHP_EOL;

foreach ($results as $item) {
    $label = $item['strategy']['label'];
    $a = $item['p1'];
    $b = $item['p2'];

    $p1Current = pct($a['current_hit'], $a['total']);
    $p1Adjusted = pct($a['adjusted_hit'], $a['total']);
    $p2Current = pct($b['current_hit'], $b['total']);
    $p2Adjusted = pct($b['adjusted_hit'], $b['total']);

    $repro = '○';
    if ($a['rebuild_mismatch'] > 0 || $b['rebuild_mismatch'] > 0) {
        $repro = '要確認';
    }

    echo sprintf(
        "%-45s %3d/%-3d %9s %9s %+8.2fpt %9s %9s %+8.2fpt %12s\n",
        $label,
        $a['changed'],
        $b['changed'],
        fmtPct($p1Current),
        fmtPct($p1Adjusted),
        $p1Adjusted - $p1Current,
        fmtPct($p2Current),
        fmtPct($p2Adjusted),
        $p2Adjusted - $p2Current,
        $repro
    );
}

echo str_repeat('-', 150) . PHP_EOL;
echo PHP_EOL;

echo "【場全体：平均購入点数】" . PHP_EOL;
echo sprintf(
    "%-45s %15s %15s %15s %15s\n",
    '戦略', 'P1 現行→補正', 'P1点数差', 'P2 現行→補正', 'P2点数差'
);
echo str_repeat('-', 120) . PHP_EOL;

foreach ($results as $item) {
    $label = $item['strategy']['label'];
    $a = $item['p1'];
    $b = $item['p2'];

    $aCur = avg($a['current_points'], $a['total']);
    $aAdj = avg($a['adjusted_points'], $a['total']);
    $bCur = avg($b['current_points'], $b['total']);
    $bAdj = avg($b['adjusted_points'], $b['total']);

    echo sprintf(
        "%-45s %6.2f→%-6.2f %+10.2f %6.2f→%-6.2f %+10.2f\n",
        $label,
        $aCur,
        $aAdj,
        $aAdj - $aCur,
        $bCur,
        $bAdj,
        $bAdj - $bCur
    );
}

echo str_repeat('-', 120) . PHP_EOL;
echo PHP_EOL;

echo "【変更対象レースだけ：買い目の勝ち負け】" . PHP_EOL;
echo "※ 『獲得』= 現行外れ→補正的中、『喪失』= 現行的中→補正外れ。" . PHP_EOL;
echo sprintf(
    "%-45s %6s %9s %9s %8s %8s %6s %9s %9s %8s %8s\n",
    '戦略', 'P1 N', 'P1現行', 'P1補正', '獲得', '喪失', 'P2 N', 'P2現行', 'P2補正', '獲得', '喪失'
);
echo str_repeat('-', 145) . PHP_EOL;

foreach ($results as $item) {
    $label = $item['strategy']['label'];
    $a = $item['p1'];
    $b = $item['p2'];

    echo sprintf(
        "%-45s %6d %9s %9s %8d %8d %6d %9s %9s %8d %8d\n",
        $label,
        $a['changed'],
        fmtPct(pct($a['changed_current_hit'], $a['changed'])),
        fmtPct(pct($a['changed_adjusted_hit'], $a['changed'])),
        $a['gained_hit'],
        $a['lost_hit'],
        $b['changed'],
        fmtPct(pct($b['changed_current_hit'], $b['changed'])),
        fmtPct(pct($b['changed_adjusted_hit'], $b['changed'])),
        $b['gained_hit'],
        $b['lost_hit']
    );
}

echo str_repeat('-', 145) . PHP_EOL;
echo PHP_EOL;

echo "【2期間合算】" . PHP_EOL;
foreach ($results as $item) {
    $label = $item['strategy']['label'];
    $s = $item['combined'];

    $curHit = pct($s['current_hit'], $s['total']);
    $adjHit = pct($s['adjusted_hit'], $s['total']);
    $curPts = avg($s['current_points'], $s['total']);
    $adjPts = avg($s['adjusted_points'], $s['total']);

    $changedCur = pct($s['changed_current_hit'], $s['changed']);
    $changedAdj = pct($s['changed_adjusted_hit'], $s['changed']);
    $headMissRate = pct(
        $s['changed_adjusted_head_hit_bet_miss'],
        $s['changed_adjusted_head_hit']
    );

    echo $label . PHP_EOL;
    echo sprintf(
        "  全体 %dR: Hit %s → %s (%s) / 平均点数 %.2f → %.2f (%+.2f)\n",
        $s['total'],
        fmtPct($curHit),
        fmtPct($adjHit),
        fmtPt($adjHit - $curHit),
        $curPts,
        $adjPts,
        $adjPts - $curPts
    );
    echo sprintf(
        "  変更 %dR: Hit %s → %s / 獲得 %d / 喪失 %d\n",
        $s['changed'],
        fmtPct($changedCur),
        fmtPct($changedAdj),
        $s['gained_hit'],
        $s['lost_hit']
    );
    echo sprintf(
        "  補正頭が正解なのに本命3連単を外した: %d/%dR (%s)\n",
        $s['changed_adjusted_head_hit_bet_miss'],
        $s['changed_adjusted_head_hit'],
        fmtPct($headMissRate)
    );
    echo sprintf(
        "  現行買い目再構築差異: %dR\n",
        $s['rebuild_mismatch']
    );
}

echo PHP_EOL;
echo "見方:" . PHP_EOL;
echo "  ・頭1着率の改善が、3連単Hitにもつながるかを確認する。" . PHP_EOL;
echo "  ・平均点数が増えずHitが上がる候補ほど有望。" . PHP_EOL;
echo "  ・『補正頭が正解なのに3連単外れ』が多ければ、次は2・3着候補の場別最適化を疑う。" . PHP_EOL;
echo "  ・現行買い目再構築差異が0Rでない場合は、シミュレーションの買い目再構築ロジックを先に確認する。" . PHP_EOL;
echo "  ・回収率は6か月CSV生成中のDB負荷を避けるため、この段階では見ない。" . PHP_EOL;
echo PHP_EOL;
