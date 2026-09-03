<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

/**
 * 払戻が存在するのに race_result_detail の1～3着が欠けるレースを診断する。
 *
 * race_payouts.trifecta_combination を正解の艇番順として使い、
 * 欠けた着順の艇・選手名を race_entry から復元する。
 *
 * Pi側 chg_csvfile_detail.py の現行選手名正規表現:
 *   [\u4E00-\u9FFF\u3040-\u30FF\uFF01-\uFFE5\s]+
 * に入らない文字を含む選手名が欠損に偏っているかを確認する。
 *
 * Usage:
 *   php analysis/diagnose_top3_missing_parser.php 2026-08-01 2026-09-02 [表示上限]
 */

$from = trim((string)($argv[1] ?? ''));
$to = trim((string)($argv[2] ?? ''));
$limit = max(1, (int)($argv[3] ?? 50));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    fwrite(STDERR, "Usage: php {$argv[0]} YYYY-MM-DD YYYY-MM-DD [表示上限]\n");
    exit(1);
}
if ($from > $to) {
    fwrite(STDERR, "開始日は終了日以前にしてください。\n");
    exit(1);
}

$pdo = getPDO();

$sql = <<<SQL
WITH result_rank AS (
    SELECT
        race_code,
        COUNT(*) FILTER (WHERE TRIM(rank) = '1')::int AS r1,
        COUNT(*) FILTER (WHERE TRIM(rank) = '2')::int AS r2,
        COUNT(*) FILTER (WHERE TRIM(rank) = '3')::int AS r3
    FROM boat_race.race_result_detail
    WHERE SUBSTRING(race_code, 1, 8) BETWEEN REPLACE(:from, '-', '') AND REPLACE(:to, '-', '')
    GROUP BY race_code
), payout AS (
    SELECT
        race_code,
        MAX(COALESCE(trifecta_combination, '')) AS trifecta_combination,
        MAX(COALESCE(trifecta_payout, 0))::numeric AS trifecta_payout
    FROM boat_race.race_payouts
    WHERE SUBSTRING(race_code, 1, 8) BETWEEN REPLACE(:from, '-', '') AND REPLACE(:to, '-', '')
    GROUP BY race_code
)
SELECT
    p.race_code,
    p.trifecta_combination,
    p.trifecta_payout,
    COALESCE(r.r1, 0) AS r1,
    COALESCE(r.r2, 0) AS r2,
    COALESCE(r.r3, 0) AS r3
FROM payout p
LEFT JOIN result_rank r ON r.race_code = p.race_code
WHERE p.trifecta_payout > 0
  AND (
      COALESCE(r.r1, 0) <> 1
      OR COALESCE(r.r2, 0) <> 1
      OR COALESCE(r.r3, 0) <> 1
  )
ORDER BY p.race_code
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute([':from' => $from, ':to' => $to]);
$suspicious = $stmt->fetchAll(PDO::FETCH_ASSOC);

$entryStmt = $pdo->prepare(
    'SELECT lane_number::int AS lane_number, player_id::text AS player_id, player_name
       FROM boat_race.race_entry
      WHERE race_code = ?
      ORDER BY lane_number'
);
$resultStmt = $pdo->prepare(
    "SELECT TRIM(rank) AS rank, lane_number::int AS lane_number,
            player_id::text AS player_id, player_name, entry_course
       FROM boat_race.race_result_detail
      WHERE race_code = ?
      ORDER BY CASE WHEN TRIM(rank) ~ '^[0-9]+$' THEN TRIM(rank)::int ELSE 99 END,
               lane_number"
);

function parseTrifecta(string $value): array
{
    preg_match_all('/[1-6]/', $value, $m);
    $boats = array_map('intval', array_slice($m[0] ?? [], 0, 3));
    if (count($boats) !== 3 || count(array_unique($boats)) !== 3) {
        return [];
    }
    return $boats;
}

function parserNameOk(string $name): bool
{
    if ($name === '') return false;
    return preg_match('/^[\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{FF01}-\x{FFE5}\s]+$/u', $name) === 1;
}

function unsupportedChars(string $name): string
{
    $bad = preg_replace('/[\x{4E00}-\x{9FFF}\x{3040}-\x{30FF}\x{FF01}-\x{FFE5}\s]/u', '', $name);
    if ($bad === null || $bad === '') return '-';
    $chars = preg_split('//u', $bad, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return implode('', array_values(array_unique($chars)));
}

$byRank = [1 => 0, 2 => 0, 3 => 0];
$byVenue = [];
$missingEvents = [];
$unsupportedN = 0;
$parserOkN = 0;
$comboBad = 0;
$nameFreq = [];
$unsupportedFreq = [];

foreach ($suspicious as $row) {
    $raceCode = (string)$row['race_code'];
    $combo = parseTrifecta((string)$row['trifecta_combination']);
    if (count($combo) !== 3) {
        $comboBad++;
        continue;
    }

    $entryStmt->execute([$raceCode]);
    $entries = [];
    foreach ($entryStmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $entries[(int)$e['lane_number']] = $e;
    }

    $resultStmt->execute([$raceCode]);
    $resultRows = $resultStmt->fetchAll(PDO::FETCH_ASSOC);
    $resultsByRank = [];
    foreach ($resultRows as $r) {
        $rank = trim((string)$r['rank']);
        if (in_array($rank, ['1', '2', '3'], true)) {
            $resultsByRank[(int)$rank][] = $r;
        }
    }

    for ($rank = 1; $rank <= 3; $rank++) {
        $expectedLane = $combo[$rank - 1];
        $rowsAtRank = $resultsByRank[$rank] ?? [];
        $rankCorrect = count($rowsAtRank) === 1 && (int)$rowsAtRank[0]['lane_number'] === $expectedLane;
        if ($rankCorrect) continue;

        $entry = $entries[$expectedLane] ?? [];
        $name = trim((string)($entry['player_name'] ?? ''));
        $playerId = trim((string)($entry['player_id'] ?? ''));
        $nameOk = parserNameOk($name);
        $badChars = unsupportedChars($name);

        $byRank[$rank]++;
        $venue = strlen($raceCode) >= 11 ? substr($raceCode, 8, 3) : '???';
        $byVenue[$venue] = ($byVenue[$venue] ?? 0) + 1;
        $nameFreq[$name] = ($nameFreq[$name] ?? 0) + 1;

        if ($nameOk) {
            $parserOkN++;
        } else {
            $unsupportedN++;
            $unsupportedFreq[$badChars] = ($unsupportedFreq[$badChars] ?? 0) + 1;
        }

        $existing = [];
        foreach ($resultRows as $rr) {
            if ((int)$rr['lane_number'] === $expectedLane) {
                $existing[] = sprintf(
                    'rank=%s/name=%s',
                    trim((string)$rr['rank']),
                    trim((string)$rr['player_name'])
                );
            }
        }

        $missingEvents[] = [
            'race_code' => $raceCode,
            'rank' => $rank,
            'lane' => $expectedLane,
            'player_id' => $playerId,
            'name' => $name,
            'parser_ok' => $nameOk,
            'bad_chars' => $badChars,
            'existing' => $existing ? implode(';', $existing) : '-',
            'combo' => implode('-', $combo),
            'payout' => (int)$row['trifecta_payout'],
        ];
    }
}

arsort($byVenue);
arsort($nameFreq);
arsort($unsupportedFreq);
$totalEvents = count($missingEvents);

function pct2(int $n, int $d): string
{
    return $d > 0 ? number_format($n * 100 / $d, 2) . '%' : '-';
}

echo str_repeat('=', 144) . "\n";
echo "払戻あり＋Top3欠損：Pi結果パーサ選手名診断\n";
echo "期間: {$from} ～ {$to}\n";
echo "疑わしいレース: " . count($suspicious) . "R\n";
echo str_repeat('=', 144) . "\n\n";

printf("欠損イベント合計             : %d\n", $totalEvents);
printf("Pi現行名前regex外            : %d / %d = %s\n", $unsupportedN, $totalEvents, pct2($unsupportedN, $totalEvents));
printf("Pi現行名前regex内            : %d / %d = %s\n", $parserOkN, $totalEvents, pct2($parserOkN, $totalEvents));
printf("3連単組合せ解析不能          : %dR\n", $comboBad);
printf("欠損着順                     : 1着=%d / 2着=%d / 3着=%d\n", $byRank[1], $byRank[2], $byRank[3]);

echo "\n【場コード別 欠損イベント】\n";
foreach ($byVenue as $venue => $n) {
    printf("%-4s %4d\n", $venue, $n);
}

echo "\n【regex外文字 上位】\n";
if (!$unsupportedFreq) {
    echo "なし\n";
} else {
    $i = 0;
    foreach ($unsupportedFreq as $chars => $n) {
        printf("%-12s %4d\n", $chars, $n);
        if (++$i >= 20) break;
    }
}

echo "\n【欠損選手名 上位】\n";
$i = 0;
foreach ($nameFreq as $name => $n) {
    printf("%-24s %4d | parser=%s | regex外=%s\n",
        $name,
        $n,
        parserNameOk($name) ? 'OK' : 'NG',
        unsupportedChars($name)
    );
    if (++$i >= 30) break;
}

echo "\n【欠損明細（最大{$limit}件）】\n";
foreach (array_slice($missingEvents, 0, $limit) as $e) {
    printf(
        "%s | %d着=%d号艇 | %s(%s) | parser=%s regex外=%s | DB既存=%s | 3T=%s/%d\n",
        $e['race_code'],
        $e['rank'],
        $e['lane'],
        $e['name'],
        $e['player_id'],
        $e['parser_ok'] ? 'OK' : 'NG',
        $e['bad_chars'],
        $e['existing'],
        $e['combo'],
        $e['payout']
    );
}
if ($totalEvents > $limit) {
    echo '... 他 ' . ($totalEvents - $limit) . "件\n";
}

echo "\n【判定目安】\n";
echo "- regex外が大半なら、Pi側 chg_csvfile_detail.py の選手名文字クラスが主因の可能性が高い。\n";
echo "- regex内が多い場合は、選手名以外（列幅・数値形式・レースタイム等）の正規表現も確認する。\n";
echo "- DB既存が '-' なら、その艇の行自体がCSV化前後で欠けた可能性が高い。\n";
echo "- 原因確認前にDBを自動補完しない。まず取得パーサを直して再取得可能性を確認する。\n";
