<?php
declare(strict_types=1);
require_once __DIR__ . '/../common/db_connect.php';

$from = $argv[1] ?? '2026-06-15';
$to   = $argv[2] ?? '2026-07-14';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from > $to) {
    fwrite(STDERR, "日付指定エラー\n");
    exit(1);
}

$pdo = getPDO();

$rq = $pdo->prepare(
    "SELECT DISTINCT race_code
     FROM boat_race.race_entry
     WHERE race_date BETWEEN :f AND :t
     ORDER BY race_code"
);
$rq->execute([':f' => $from, ':t' => $to]);
$races = $rq->fetchAll(PDO::FETCH_COLUMN);

$entryQ = $pdo->prepare(
    "SELECT player_id
     FROM boat_race.race_entry
     WHERE race_code = :r"
);

$resQ = $pdo->prepare(
    "SELECT player_id, lane_number, entry_course, start_timing, rank
     FROM boat_race.race_result_detail
     WHERE race_code = :r
     ORDER BY lane_number NULLS LAST, entry_course NULLS LAST"
);

function blankStats(): array {
    return [
        'target' => 0,
        'raw0' => 0,
        'raw6' => 0,
        'rawOther' => 0,
        'pid6' => 0,
        'match6' => 0,
        'match0' => 0,
        'matchPartial' => 0,
        'st6' => 0,
    ];
}

function bump(array &$a, string $k): void {
    $a[$k] = ($a[$k] ?? 0) + 1;
}

$total = blankStats();
$byVenue = [];
$samples = [];

foreach ($races as $raceCode) {
    $venue = substr((string)$raceCode, 8, 3);
    if (!isset($byVenue[$venue])) $byVenue[$venue] = blankStats();
    bump($total, 'target');
    bump($byVenue[$venue], 'target');

    $entryQ->execute([':r' => $raceCode]);
    $entryRows = $entryQ->fetchAll(PDO::FETCH_ASSOC);
    $entryIds = [];
    foreach ($entryRows as $row) {
        if ($row['player_id'] !== null && $row['player_id'] !== '') {
            $entryIds[(string)$row['player_id']] = true;
        }
    }

    $resQ->execute([':r' => $raceCode]);
    $resRows = $resQ->fetchAll(PDO::FETCH_ASSOC);
    $rawN = count($resRows);

    if ($rawN === 0) {
        bump($total, 'raw0'); bump($byVenue[$venue], 'raw0');
    } elseif ($rawN === 6) {
        bump($total, 'raw6'); bump($byVenue[$venue], 'raw6');
    } else {
        bump($total, 'rawOther'); bump($byVenue[$venue], 'rawOther');
    }

    $resultIds = [];
    $nonEmptyPid = 0;
    $realStN = 0;
    foreach ($resRows as $row) {
        if ($row['player_id'] !== null && $row['player_id'] !== '') {
            $pid = (string)$row['player_id'];
            $resultIds[$pid] = true;
            $nonEmptyPid++;
        }
        if ($row['start_timing'] !== null && $row['start_timing'] !== '' && is_numeric($row['start_timing'])) {
            $realStN++;
        }
    }

    if ($rawN === 6 && $nonEmptyPid === 6) {
        bump($total, 'pid6'); bump($byVenue[$venue], 'pid6');
    }
    if ($rawN === 6 && $realStN === 6) {
        bump($total, 'st6'); bump($byVenue[$venue], 'st6');
    }

    $matchN = 0;
    foreach ($entryIds as $pid => $_) {
        if (isset($resultIds[$pid])) $matchN++;
    }

    if ($matchN === 6) {
        bump($total, 'match6'); bump($byVenue[$venue], 'match6');
    } elseif ($matchN === 0) {
        bump($total, 'match0'); bump($byVenue[$venue], 'match0');
    } else {
        bump($total, 'matchPartial'); bump($byVenue[$venue], 'matchPartial');
    }

    if ($rawN > 0 && $matchN < 6 && count($samples[$venue] ?? []) < 2) {
        $samples[$venue][] = [
            'race_code' => (string)$raceCode,
            'raw_n' => $rawN,
            'entry_pid_n' => count($entryIds),
            'result_pid_n' => count($resultIds),
            'match_n' => $matchN,
            'real_st_n' => $realStN,
            'result_rows' => array_map(static function(array $r): array {
                return [
                    'player_id' => $r['player_id'],
                    'lane_number' => $r['lane_number'],
                    'entry_course' => $r['entry_course'],
                    'start_timing' => $r['start_timing'],
                    'rank' => $r['rank'],
                ];
            }, $resRows),
        ];
    }
}

function pctN(int $v, int $n): string {
    return $n ? sprintf('%6.2f%%', $v / $n * 100) : '  0.00%';
}

function printStats(string $name, array $s): void {
    $n = (int)$s['target'];
    printf(
        "%s R=%4d raw6=%s pid6=%s match6=%s match0=%s st6=%s raw0=%s other=%s\n",
        $name,
        $n,
        pctN((int)$s['raw6'], $n),
        pctN((int)$s['pid6'], $n),
        pctN((int)$s['match6'], $n),
        pctN((int)$s['match0'], $n),
        pctN((int)$s['st6'], $n),
        pctN((int)$s['raw0'], $n),
        pctN((int)$s['rawOther'], $n)
    );
}

echo "========================================\n";
echo "race_result_detail 結果対応診断\n";
echo "========================================\n";
echo "期間 : {$from} ～ {$to}\n\n";

echo "【全体】\n";
printStats('ALL', $total);

echo "\n【場別】\n";
ksort($byVenue);
foreach ($byVenue as $venue => $s) printStats($venue, $s);

echo "\n指標:\n";
echo "raw6   = race_codeだけで結果行が6艇ある\n";
echo "pid6   = 6結果行すべてにplayer_idがある\n";
echo "match6 = race_entryの6人とresultのplayer_idが全員一致\n";
echo "match0 = player_id一致が0人\n";
echo "st6    = 結果6行すべてに本番STがある\n";
echo "raw0   = race_code自体の結果行が0件\n";
echo "other  = 結果行数が1～5件または7件以上\n";

echo "\n【不一致サンプル（各場最大2R）】\n";
ksort($samples);
foreach ($samples as $venue => $rows) {
    echo "[$venue]\n";
    foreach ($rows as $x) {
        printf(
            "  %s raw=%d entryPid=%d resultPid=%d match=%d realST=%d\n",
            $x['race_code'], $x['raw_n'], $x['entry_pid_n'], $x['result_pid_n'], $x['match_n'], $x['real_st_n']
        );
        foreach ($x['result_rows'] as $r) {
            printf(
                "    pid=%s lane=%s entry=%s st=%s rank=%s\n",
                var_export($r['player_id'], true),
                var_export($r['lane_number'], true),
                var_export($r['entry_course'], true),
                var_export($r['start_timing'], true),
                var_export($r['rank'], true)
            );
        }
    }
}

echo "========================================\n";
