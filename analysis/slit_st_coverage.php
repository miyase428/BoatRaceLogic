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

$exQ = $pdo->prepare(
    "SELECT player_id, entry_course, start_timing
     FROM boat_race.exhibition_live
     WHERE race_code = :r"
);

$resQ = $pdo->prepare(
    "SELECT player_id, start_timing, rank
     FROM boat_race.race_result_detail
     WHERE race_code = :r"
);

function freshCounter(): array {
    return [
        'target' => 0,
        'not_6_entry' => 0,
        'not_6_exhibition' => 0,
        'missing_ex_st' => 0,
        'missing_result_row' => 0,
        'missing_real_st' => 0,
        'complete_ex_st' => 0,
        'complete_real_st' => 0,
        'complete_both_st' => 0,
    ];
}

function bump(array &$c, string $key): void {
    $c[$key] = ($c[$key] ?? 0) + 1;
}

$total = freshCounter();
$byVenue = [];

foreach ($races as $raceCode) {
    $venue = substr((string)$raceCode, 8, 3);
    if (!isset($byVenue[$venue])) {
        $byVenue[$venue] = freshCounter();
    }

    bump($total, 'target');
    bump($byVenue[$venue], 'target');

    $entryQ->execute([':r' => $raceCode]);
    $entryRows = $entryQ->fetchAll(PDO::FETCH_ASSOC);
    $entryPlayers = [];
    foreach ($entryRows as $row) {
        $entryPlayers[(string)$row['player_id']] = true;
    }

    if (count($entryPlayers) !== 6) {
        bump($total, 'not_6_entry');
        bump($byVenue[$venue], 'not_6_entry');
        continue;
    }

    $exQ->execute([':r' => $raceCode]);
    $exRows = $exQ->fetchAll(PDO::FETCH_ASSOC);
    $exByPlayer = [];
    foreach ($exRows as $row) {
        $pid = (string)$row['player_id'];
        if (!isset($entryPlayers[$pid])) {
            continue;
        }
        $exByPlayer[$pid] = [
            'course' => $row['entry_course'],
            'st' => $row['start_timing'],
        ];
    }

    if (count($exByPlayer) !== 6) {
        bump($total, 'not_6_exhibition');
        bump($byVenue[$venue], 'not_6_exhibition');
        continue;
    }

    $missingExSt = false;
    foreach (array_keys($entryPlayers) as $pid) {
        $st = $exByPlayer[$pid]['st'] ?? null;
        if ($st === null || $st === '' || !is_numeric($st)) {
            $missingExSt = true;
            break;
        }
    }

    if ($missingExSt) {
        bump($total, 'missing_ex_st');
        bump($byVenue[$venue], 'missing_ex_st');
    } else {
        bump($total, 'complete_ex_st');
        bump($byVenue[$venue], 'complete_ex_st');
    }

    $resQ->execute([':r' => $raceCode]);
    $resRows = $resQ->fetchAll(PDO::FETCH_ASSOC);
    $resByPlayer = [];
    foreach ($resRows as $row) {
        $pid = (string)$row['player_id'];
        if (!isset($entryPlayers[$pid])) {
            continue;
        }
        $resByPlayer[$pid] = [
            'st' => $row['start_timing'],
            'rank' => $row['rank'],
        ];
    }

    if (count($resByPlayer) !== 6) {
        bump($total, 'missing_result_row');
        bump($byVenue[$venue], 'missing_result_row');
        continue;
    }

    $missingRealSt = false;
    foreach (array_keys($entryPlayers) as $pid) {
        $st = $resByPlayer[$pid]['st'] ?? null;
        if ($st === null || $st === '' || !is_numeric($st)) {
            $missingRealSt = true;
            break;
        }
    }

    if ($missingRealSt) {
        bump($total, 'missing_real_st');
        bump($byVenue[$venue], 'missing_real_st');
    } else {
        bump($total, 'complete_real_st');
        bump($byVenue[$venue], 'complete_real_st');
    }

    if (!$missingExSt && !$missingRealSt) {
        bump($total, 'complete_both_st');
        bump($byVenue[$venue], 'complete_both_st');
    }
}

function printCounter(array $c): void {
    $target = max(1, (int)$c['target']);
    foreach ([
        'target',
        'not_6_entry',
        'not_6_exhibition',
        'missing_ex_st',
        'missing_result_row',
        'missing_real_st',
        'complete_ex_st',
        'complete_real_st',
        'complete_both_st',
    ] as $key) {
        $value = (int)($c[$key] ?? 0);
        if ($key === 'target') {
            printf("%-24s : %d\n", $key, $value);
        } else {
            printf("%-24s : %5d (%6.2f%%)\n", $key, $value, $value / $target * 100);
        }
    }
}

echo "========================================\n";
echo "スリット ST カバレッジ診断\n";
echo "========================================\n";
echo "期間 : {$from} ～ {$to}\n\n";

echo "【全体】\n";
printCounter($total);

echo "\n【場別】\n";
ksort($byVenue);
foreach ($byVenue as $venue => $counter) {
    printf(
        "%s R=%4d ex6=%6.2f%% exST=%6.2f%% realST=%6.2f%% both=%6.2f%%\n",
        $venue,
        $counter['target'],
        ($counter['target'] ? (($counter['target'] - $counter['not_6_entry'] - $counter['not_6_exhibition']) / $counter['target'] * 100) : 0),
        ($counter['target'] ? ($counter['complete_ex_st'] / $counter['target'] * 100) : 0),
        ($counter['target'] ? ($counter['complete_real_st'] / $counter['target'] * 100) : 0),
        ($counter['target'] ? ($counter['complete_both_st'] / $counter['target'] * 100) : 0)
    );
}

echo "\n見るポイント:\n";
echo "・missing_ex_st が大きいか\n";
echo "・missing_real_st が大きいか\n";
echo "・場ごとに realST の保存率が偏っていないか\n";
echo "========================================\n";
