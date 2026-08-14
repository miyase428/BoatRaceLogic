<?php

/**
 * health_check_bet_structure.php
 *
 * STEP 3
 * 現行最終予想の買い目構造 比較検証
 *
 * A : 現行本命買い目
 * B : 現行対抗買い目
 * C : 本命＋対抗（重複除外）
 * D : 最終評価TOP3 3艇BOX
 * E : 最終評価TOP4 4艇BOX
 * F : 本命＋対抗＋最終評価上位2艇 4艇BOX
 * G : 本命＋対抗＋最終評価上位2艇の3艇BOX ×2
 * H : 本命1着固定
 * I : 対抗1着固定
 *
 * Gの順位決定：
 * 本命・対抗を除いた艇の最終評価順位を見る。
 * 最終評価順位が同じ場合は一次評価順位で決定。
 */

require_once __DIR__ . '/../common/db_connect.php';


// ============================================================
// 引数
// ============================================================

if ($argc < 2) {
    echo "使い方:\n";
    echo "php analysis/health_check_bet_structure.php ";
    echo "analysis/output/final_prediction_races_20260801_20260808.csv\n";
    exit(1);
}

$csv_file = $argv[1];

if (!file_exists($csv_file)) {
    echo "CSVが見つかりません: {$csv_file}\n";
    exit(1);
}


// ============================================================
// DB
// ============================================================

$pdo = getPDO();


// ============================================================
// CSV読み込み
// ============================================================

function readCsv(string $file): array
{
    $fp = fopen($file, 'r');

    if ($fp === false) {
        throw new RuntimeException("CSVを開けません: {$file}");
    }

    $header = fgetcsv($fp);

    if ($header === false) {
        fclose($fp);
        throw new RuntimeException("CSVが空です: {$file}");
    }

    // BOM除去
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

    $rows = [];

    while (($row = fgetcsv($fp)) !== false) {

        if (count($row) !== count($header)) {
            continue;
        }

        $data = [];

        foreach ($header as $i => $key) {
            $data[$key] = $row[$i];
        }

        $rows[] = $data;
    }

    fclose($fp);

    return $rows;
}

$rows = readCsv($csv_file);


// ============================================================
// 基本関数
// ============================================================

function normalizeBoatString(?string $value): string
{
    if ($value === null) {
        return '';
    }

    return preg_replace('/[^1-6]/', '', $value);
}


/**
 * 「1-235-23456」のような買い目を展開
 */
function expandBetString(string $bet_string): array
{
    $bet_string = trim($bet_string);

    if ($bet_string === '') {
        return [];
    }

    $parts = explode('-', $bet_string);

    if (count($parts) !== 3) {
        return [];
    }

    $first  = str_split(normalizeBoatString($parts[0]));
    $second = str_split(normalizeBoatString($parts[1]));
    $third  = str_split(normalizeBoatString($parts[2]));

    $bets = [];

    foreach ($first as $a) {
        foreach ($second as $b) {
            foreach ($third as $c) {

                // 3連単なので同じ艇は不可
                if ($a === $b || $a === $c || $b === $c) {
                    continue;
                }

                $bets[] = "{$a}-{$b}-{$c}";
            }
        }
    }

    return array_values(array_unique($bets));
}


/**
 * 3艇BOX
 */
function makeTrifectaBox(array $boats): array
{
    $boats = array_values(array_unique($boats));

    if (count($boats) !== 3) {
        return [];
    }

    $bets = [];

    foreach ($boats as $a) {
        foreach ($boats as $b) {
            foreach ($boats as $c) {

                if ($a === $b || $a === $c || $b === $c) {
                    continue;
                }

                $bets[] = "{$a}-{$b}-{$c}";
            }
        }
    }

    return array_values(array_unique($bets));
}


/**
 * 4艇BOX
 */
function makeFourBoatBox(array $boats): array
{
    $boats = array_values(array_unique($boats));

    if (count($boats) !== 4) {
        return [];
    }

    $bets = [];

    foreach ($boats as $a) {
        foreach ($boats as $b) {
            foreach ($boats as $c) {
                foreach ($boats as $d) {

                    if (
                        $a === $b ||
                        $a === $c ||
                        $a === $d ||
                        $b === $c ||
                        $b === $d ||
                        $c === $d
                    ) {
                        continue;
                    }

                    $bets[] = "{$a}-{$b}-{$c}";
                }
            }
        }
    }

    return array_values(array_unique($bets));
}


/**
 * 本命1着固定
 *
 * 1着固定＋残り5艇から2艇
 */
function makeFixedHeadBet(int $head): array
{
    $boats = [1, 2, 3, 4, 5, 6];

    $bets = [];

    foreach ($boats as $second) {

        if ($second === $head) {
            continue;
        }

        foreach ($boats as $third) {

            if (
                $third === $head ||
                $third === $second
            ) {
                continue;
            }

            $bets[] = "{$head}-{$second}-{$third}";
        }
    }

    return $bets;
}


/**
 * 買い目の重複除外
 */
function uniqueBets(array $bets): array
{
    return array_values(array_unique($bets));
}


/**
 * 実際の3連単が買い目に含まれているか
 */
function isHit(array $bets, string $actual): bool
{
    return in_array($actual, $bets, true);
}


/**
 * 金額計算
 */
function calcAmount(int $count, int $unit = 100): int
{
    return $count * $unit;
}


// ============================================================
// 払戻取得
// ============================================================
//
// race_payouts のカラム名が環境によって多少違っても対応できるように、
// information_schemaから候補を探す。
// ============================================================

function findColumn(PDO $pdo, string $table, array $candidates): ?string
{
    $sql = "
        SELECT column_name
        FROM information_schema.columns
        WHERE table_name = :table
        ORDER BY ordinal_position
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':table' => $table]);

    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($candidates as $candidate) {
        foreach ($columns as $column) {
            if (strtolower($column) === strtolower($candidate)) {
                return $column;
            }
        }
    }

    return null;
}

$raceCodeColumn = findColumn(
    $pdo,
    'race_payouts',
    [
        'race_code',
        'racecode'
    ]
);

$betTypeColumn = findColumn(
    $pdo,
    'race_payouts',
    [
        'bet_type',
        'bettype',
        'ticket_type',
        'type',
        'shikibetsu'
    ]
);

$combinationColumn = findColumn(
    $pdo,
    'race_payouts',
    [
        'combination',
        'bet_combination',
        'kumi',
        'kumiban',
        'combination_number'
    ]
);

$payoutColumn = findColumn(
    $pdo,
    'race_payouts',
    [
        'payout',
        'payoff',
        'refund',
        'amount',
        'return_amount'
    ]
);


// ============================================================
// 払戻キャッシュ
// ============================================================

$payoutCache = [];


/**
 * 3連単払戻を取得
 */
function getTrifectaPayout(
    PDO $pdo,
    string $race_code,
    string $combination,
    ?string $raceCodeColumn,
    ?string $betTypeColumn,
    ?string $combinationColumn,
    ?string $payoutColumn,
    array &$cache
): int {

    $cacheKey = $race_code . '|' . $combination;

    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    if (
        !$raceCodeColumn ||
        !$combinationColumn ||
        !$payoutColumn
    ) {
        $cache[$cacheKey] = 0;
        return 0;
    }

    $sql = "
        SELECT \"{$payoutColumn}\"
        FROM race_payouts
        WHERE \"{$raceCodeColumn}\" = :race_code
          AND \"{$combinationColumn}\" = :combination
    ";

    if ($betTypeColumn) {
        $sql .= "
          AND (
                \"{$betTypeColumn}\" = '3連単'
                OR \"{$betTypeColumn}\" = 'trifecta'
                OR \"{$betTypeColumn}\" = '3tan'
              )
        ";
    }

    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ':race_code'  => $race_code,
        ':combination' => $combination
    ]);

    $value = $stmt->fetchColumn();

    if ($value === false || $value === null) {
        $cache[$cacheKey] = 0;
        return 0;
    }

    // 「12,340円」のような形式にも対応
    $value = preg_replace('/[^\d]/', '', (string)$value);

    $payout = (int)$value;

    $cache[$cacheKey] = $payout;

    return $payout;
}


// ============================================================
// レースごとに処理
// ============================================================

$strategies = [
    'A' => '現行本命買い目',
    'B' => '現行対抗買い目',
    'C' => '本命＋対抗',
    'D' => '最終評価TOP3 BOX',
    'E' => '最終評価TOP4 BOX',
    'F' => '本命＋対抗＋上位2艇 4艇BOX',
    'G' => '本命＋対抗＋上位2艇 3艇BOX×2',
    'H' => '本命1着固定',
    'I' => '対抗1着固定',
];

$stats = [];

foreach ($strategies as $key => $name) {

    $stats[$key] = [
        'name'         => $name,
        'races'        => 0,
        'total_bets'   => 0,
        'hit'          => 0,
        'purchase'     => 0,
        'payout'       => 0,
    ];
}


// ============================================================
// 出力CSV
// ============================================================

$outputFile =
    preg_replace(
        '/\.csv$/',
        '_bet_structure.csv',
        $csv_file
    );

$outFp = fopen($outputFile, 'w');

if ($outFp === false) {
    throw new RuntimeException(
        "出力CSVを作成できません: {$outputFile}"
    );
}

fputcsv($outFp, [
    'race_code',
    'strategy',
    'strategy_name',
    'bets',
    'bet_count',
    'hit',
    'actual_trifecta',
    'purchase_amount',
    'payout',
]);


// ============================================================
// メイン処理
// ============================================================

foreach ($rows as $row) {

    $raceCode = trim($row['race_code'] ?? '');

    if ($raceCode === '') {
        continue;
    }

    $actual = trim($row['actual_trifecta'] ?? '');

    if ($actual === '') {
        continue;
    }


    // --------------------------------------------------------
    // 本命・対抗
    // --------------------------------------------------------

    $honmei = (int)($row['honmei_head'] ?? 0);
    $taikou = (int)($row['taikou_head'] ?? 0);

    if ($honmei < 1 || $honmei > 6) {
        continue;
    }

    if ($taikou < 1 || $taikou > 6) {
        continue;
    }


    // --------------------------------------------------------
    // A
    // --------------------------------------------------------

    $betsA = expandBetString(
        (string)($row['honmei_kai'] ?? '')
    );


    // --------------------------------------------------------
    // B
    // --------------------------------------------------------

    $betsB = expandBetString(
        (string)($row['taikou_kai'] ?? '')
    );


    // --------------------------------------------------------
    // C
    // --------------------------------------------------------

    $betsC = uniqueBets(
        array_merge($betsA, $betsB)
    );


    // --------------------------------------------------------
    // 艇別情報
    //
    // このCSVでは別CSVのため、
    // race別CSVだけでは最終評価順位が取れない。
    //
    // そのため後述の艇別CSVを事前に読み込む。
    // --------------------------------------------------------

    $strategiesForRace = [
        'A' => $betsA,
        'B' => $betsB,
        'C' => $betsC,
    ];

    // D～Iは後で艇別データから作成


    foreach ($strategiesForRace as $key => $bets) {

        $bets = uniqueBets($bets);

        $hit = isHit($bets, $actual);

        $payout = 0;

        if ($hit) {
            $payout = getTrifectaPayout(
                $pdo,
                $raceCode,
                $actual,
                $raceCodeColumn,
                $betTypeColumn,
                $combinationColumn,
                $payoutColumn,
                $payoutCache
            );
        }

        $stats[$key]['races']++;
        $stats[$key]['total_bets'] += count($bets);
        $stats[$key]['purchase'] += calcAmount(count($bets));
        $stats[$key]['payout'] += $payout;

        if ($hit) {
            $stats[$key]['hit']++;
        }

        fputcsv($outFp, [
            $raceCode,
            $key,
            $strategies[$key],
            implode(',', $bets),
            count($bets),
            $hit ? 1 : 0,
            $actual,
            calcAmount(count($bets)),
            $payout,
        ]);
    }
}


// ============================================================
// 艇別CSVを読み込み
// ============================================================

$boatCsvFile = preg_replace(
    '/final_prediction_races_/',
    'final_prediction_boats_',
    $csv_file
);

if (!file_exists($boatCsvFile)) {

    fclose($outFp);

    echo "\n";
    echo "艇別CSVが見つかりません。\n";
    echo "必要ファイル:\n";
    echo $boatCsvFile . "\n";
    exit(1);
}

$boatRows = readCsv($boatCsvFile);


// race_codeごとに整理
$boatsByRace = [];

foreach ($boatRows as $boat) {

    $raceCode = trim($boat['race_code'] ?? '');

    if ($raceCode === '') {
        continue;
    }

    $boatsByRace[$raceCode][] = $boat;
}


// ============================================================
// D～Iを処理
// ============================================================

foreach ($rows as $row) {

    $raceCode = trim($row['race_code'] ?? '');
    $actual   = trim($row['actual_trifecta'] ?? '');

    if ($raceCode === '' || $actual === '') {
        continue;
    }

    $honmei = (int)($row['honmei_head'] ?? 0);
    $taikou = (int)($row['taikou_head'] ?? 0);

    if (
        !isset($boatsByRace[$raceCode]) ||
        count($boatsByRace[$raceCode]) < 6
    ) {
        continue;
    }

    $boats = $boatsByRace[$raceCode];


    // --------------------------------------------------------
    // 最終評価順位 → 一次評価順位でタイブレーク
    // --------------------------------------------------------

    usort(
        $boats,
        function ($a, $b) {

            $finalA = (int)($a['final_rank'] ?? 999);
            $finalB = (int)($b['final_rank'] ?? 999);

            if ($finalA !== $finalB) {
                return $finalA <=> $finalB;
            }

            $firstA = (int)($a['first_rank'] ?? 999);
            $firstB = (int)($b['first_rank'] ?? 999);

            if ($firstA !== $firstB) {
                return $firstA <=> $firstB;
            }

            return ((int)($a['lane_number'] ?? 0))
                <=> ((int)($b['lane_number'] ?? 0));
        }
    );


    $finalTop = [];

    foreach ($boats as $boat) {
        $finalTop[] = (int)$boat['lane_number'];
    }


    // --------------------------------------------------------
    // D 最終評価TOP3 BOX
    // --------------------------------------------------------

    $top3 = array_slice($finalTop, 0, 3);

    $betsD = makeTrifectaBox($top3);


    // --------------------------------------------------------
    // E 最終評価TOP4 BOX
    // --------------------------------------------------------

    $top4 = array_slice($finalTop, 0, 4);

    $betsE = makeFourBoatBox($top4);


    // --------------------------------------------------------
    // F
    //
    // 本命・対抗を含めた4艇
    // ＋残りから最終評価上位2艇
    // --------------------------------------------------------

    $remaining = [];

    foreach ($finalTop as $lane) {

        if ($lane === $honmei || $lane === $taikou) {
            continue;
        }

        $remaining[] = $lane;
    }

    $gTop2 = array_slice($remaining, 0, 2);

    $fBoats = uniqueBets([
        (string)$honmei,
        (string)$taikou,
        (string)$gTop2[0],
        (string)$gTop2[1],
    ]);

    $fBoats = array_map(
        'intval',
        $fBoats
    );

    $betsF = makeFourBoatBox($fBoats);


    // --------------------------------------------------------
    // G
    //
    // 本命・対抗・残り最終評価1位
    // 本命・対抗・残り最終評価2位
    //
    // それぞれ3艇BOX
    // --------------------------------------------------------

    $betsG1 = [];

    $betsG2 = [];

    if (isset($gTop2[0])) {

        $betsG1 = makeTrifectaBox([
            $honmei,
            $taikou,
            $gTop2[0],
        ]);
    }

    if (isset($gTop2[1])) {

        $betsG2 = makeTrifectaBox([
            $honmei,
            $taikou,
            $gTop2[1],
        ]);
    }

    $betsG = uniqueBets(
        array_merge($betsG1, $betsG2)
    );


    // --------------------------------------------------------
    // H 本命1着固定
    // --------------------------------------------------------

    $betsH = makeFixedHeadBet($honmei);


    // --------------------------------------------------------
    // I 対抗1着固定
    // --------------------------------------------------------

    $betsI = makeFixedHeadBet($taikou);


    $strategyBets = [
        'D' => $betsD,
        'E' => $betsE,
        'F' => $betsF,
        'G' => $betsG,
        'H' => $betsH,
        'I' => $betsI,
    ];


    // --------------------------------------------------------
    // 集計
    // --------------------------------------------------------

    foreach ($strategyBets as $key => $bets) {

        $bets = uniqueBets($bets);

        $hit = isHit($bets, $actual);

        $payout = 0;

        if ($hit) {

            $payout = getTrifectaPayout(
                $pdo,
                $raceCode,
                $actual,
                $raceCodeColumn,
                $betTypeColumn,
                $combinationColumn,
                $payoutColumn,
                $payoutCache
            );
        }

        $stats[$key]['races']++;
        $stats[$key]['total_bets'] += count($bets);
        $stats[$key]['purchase'] += calcAmount(count($bets));
        $stats[$key]['payout'] += $payout;

        if ($hit) {
            $stats[$key]['hit']++;
        }

        fputcsv($outFp, [
            $raceCode,
            $key,
            $strategies[$key],
            implode(',', $bets),
            count($bets),
            $hit ? 1 : 0,
            $actual,
            calcAmount(count($bets)),
            $payout,
        ]);
    }
}

fclose($outFp);


// ============================================================
// 結果表示
// ============================================================

echo "\n";
echo "========================================\n";
echo "STEP 3 買い目構造 比較検証\n";
echo "========================================\n";
echo "対象CSV     : {$csv_file}\n";
echo "対象レース  : " . count($rows) . "\n";
echo "========================================\n\n";


foreach ($strategies as $key => $name) {

    $s = $stats[$key];

    $races = $s['races'];

    if ($races === 0) {
        continue;
    }

    $hitRate =
        ($s['hit'] / $races) * 100;

    $avgBets =
        $s['total_bets'] / $races;

    $recovery =
        $s['purchase'] > 0
            ? ($s['payout'] / $s['purchase']) * 100
            : 0;

    $avgPayout =
        $s['hit'] > 0
            ? $s['payout'] / $s['hit']
            : 0;


    echo "----------------------------------------\n";
    echo "{$key} : {$name}\n";
    echo "----------------------------------------\n";

    printf(
        "平均購入点数 : %.2f 点\n",
        $avgBets
    );

    printf(
        "的中         : %d / %d (%.2f%%)\n",
        $s['hit'],
        $races,
        $hitRate
    );

    printf(
        "購入金額     : %s 円\n",
        number_format($s['purchase'])
    );

    printf(
        "払戻         : %s 円\n",
        number_format($s['payout'])
    );

    printf(
        "回収率       : %.2f%%\n",
        $recovery
    );

    printf(
        "的中時平均払戻: %s 円\n",
        number_format((int)$avgPayout)
    );

    echo "\n";
}


// ============================================================
// 比較表
// ============================================================

echo "========================================\n";
echo "STEP 3 比較表\n";
echo "========================================\n\n";

printf(
    "%-3s %-34s %8s %8s %10s %10s %8s\n",
    "No",
    "買い方",
    "平均点数",
    "的中率",
    "購入金額",
    "払戻",
    "回収率"
);

echo str_repeat('-', 95) . "\n";

foreach ($strategies as $key => $name) {

    $s = $stats[$key];

    if ($s['races'] === 0) {
        continue;
    }

    $avgBets =
        $s['total_bets'] / $s['races'];

    $hitRate =
        ($s['hit'] / $s['races']) * 100;

    $recovery =
        $s['purchase'] > 0
            ? ($s['payout'] / $s['purchase']) * 100
            : 0;

    printf(
        "%-3s %-34s %8.2f %7.2f%% %10s %10s %7.2f%%\n",
        $key,
        $name,
        $avgBets,
        $hitRate,
        number_format($s['purchase']),
        number_format($s['payout']),
        $recovery
    );
}

echo "\n";

echo "========================================\n";
echo "出力CSV\n";
echo "========================================\n";
echo $outputFile . "\n";
echo "\n";

echo "# STEP 3 完了\n";