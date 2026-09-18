<?php

declare(strict_types=1);

/**
 * 未採点の前向きスナップショットへ、実着順・3連単払戻・採点結果を付ける。
 *
 * Usage:
 *   php analysis/grade_prediction_forward_snapshots.php
 *   php analysis/grade_prediction_forward_snapshots.php --through 2026-09-17
 */

require_once __DIR__ . '/../common/db_connect.php';
require_once __DIR__ . '/../web/logic/PredictionForwardSnapshotStore.php';

date_default_timezone_set('Asia/Tokyo');

$through = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
if (($argv[1] ?? '') === '--through') {
    $through = trim((string)($argv[2] ?? ''));
}
$throughDate = DateTimeImmutable::createFromFormat('!Y-m-d', $through);
if ($throughDate === false || $throughDate->format('Y-m-d') !== $through) {
    fwrite(STDERR, "Usage: php analysis/grade_prediction_forward_snapshots.php [--through YYYY-MM-DD]\n");
    exit(2);
}

$pdo = getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$store = new PredictionForwardSnapshotStore($pdo);
if (!$store->isReady()) {
    fwrite(STDERR, "自動前向き検証テーブルが未作成です。\n");
    exit(1);
}

$stmt = $pdo->prepare(<<<'SQL'
SELECT id, race_code, stage, component, payload
FROM boat_race.prediction_forward_snapshots
WHERE graded_at IS NULL
  AND race_date <= :through::date
ORDER BY race_date, race_code, captured_at
SQL);
$stmt->execute([':through' => $through]);
$snapshots = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($snapshots === []) {
    echo "採点対象はありません（{$through}まで）\n";
    exit(0);
}

$raceCodes = array_values(array_unique(array_map(
    static fn(array $row): string => (string)$row['race_code'],
    $snapshots
)));
$placeholders = implode(',', array_fill(0, count($raceCodes), '?'));

$resultStmt = $pdo->prepare(<<<SQL
SELECT race_code, player_id::text AS player_id, lane_number, rank
FROM boat_race.race_result_detail
WHERE race_code IN ({$placeholders})
SQL);
$resultStmt->execute($raceCodes);

$outcomes = [];
foreach ($resultStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $raceCode = (string)$row['race_code'];
    $rankText = preg_replace('/[^0-9]/', '', trim((string)($row['rank'] ?? '')));
    $rank = $rankText !== '' ? (int)$rankText : 0;
    if ($rank < 1 || $rank > 6) {
        continue;
    }
    $playerId = trim((string)($row['player_id'] ?? ''));
    $lane = (int)($row['lane_number'] ?? 0);
    if ($playerId !== '') {
        $outcomes[$raceCode]['rank_by_player'][$playerId] = $rank;
    }
    if ($lane >= 1 && $lane <= 6) {
        $outcomes[$raceCode]['rank_by_lane'][$lane] = $rank;
        $outcomes[$raceCode]['lane_by_rank'][$rank] = $lane;
    }
}

$payoutStmt = $pdo->prepare(<<<SQL
SELECT race_code, trifecta_payout
FROM boat_race.race_payouts
WHERE race_code IN ({$placeholders})
SQL);
$payoutStmt->execute($raceCodes);
$payouts = [];
foreach ($payoutStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if ($row['trifecta_payout'] !== null) {
        $payouts[(string)$row['race_code']] = (int)$row['trifecta_payout'];
    }
}

$update = $pdo->prepare(<<<'SQL'
UPDATE boat_race.prediction_forward_snapshots
SET actual_result = :actual_result,
    trifecta_payout = :payout,
    grade = CAST(:grade AS jsonb),
    graded_at = NOW()
WHERE id = :id
  AND graded_at IS NULL
SQL);

$graded = 0;
$waitingResult = 0;
$waitingPayout = 0;
$pdo->beginTransaction();
try {
    foreach ($snapshots as $snapshot) {
        $raceCode = (string)$snapshot['race_code'];
        $outcome = $outcomes[$raceCode] ?? [];
        $laneByRank = $outcome['lane_by_rank'] ?? [];
        if (!isset($laneByRank[1], $laneByRank[2], $laneByRank[3])) {
            $waitingResult++;
            continue;
        }
        $actual = $laneByRank[1] . '-' . $laneByRank[2] . '-' . $laneByRank[3];
        $payload = json_decode((string)$snapshot['payload'], true);
        if (!is_array($payload)) {
            continue;
        }

        $component = (string)$snapshot['component'];
        $payout = $payouts[$raceCode] ?? null;
        if (in_array($component, ['prediction', 'hole_prediction'], true) && $payout === null) {
            $waitingPayout++;
            continue;
        }

        if ($component === 'course_signals') {
            $rows = [];
            $first = $top2 = $top3 = 0;
            foreach ((array)($payload['signals'] ?? []) as $signal) {
                if (!is_array($signal)) {
                    continue;
                }
                $playerId = trim((string)($signal['player_id'] ?? ''));
                $course = (int)($signal['course'] ?? 0);
                $rank = $playerId !== ''
                    ? (int)($outcome['rank_by_player'][$playerId] ?? 0)
                    : (int)($outcome['rank_by_lane'][$course] ?? 0);
                $isFirst = $rank === 1;
                $isTop2 = $rank >= 1 && $rank <= 2;
                $isTop3 = $rank >= 1 && $rank <= 3;
                $first += (int)$isFirst;
                $top2 += (int)$isTop2;
                $top3 += (int)$isTop3;
                $rows[] = [
                    'type' => (string)($signal['type'] ?? ''),
                    'course' => $course,
                    'player_id' => $playerId,
                    'star_level' => (int)($signal['star_level'] ?? 1),
                    'actual_rank' => $rank > 0 ? $rank : null,
                    'first' => $isFirst,
                    'top2' => $isTop2,
                    'top3' => $isTop3,
                ];
            }
            $grade = [
                'signal_count' => count($rows),
                'first' => $first,
                'top2' => $top2,
                'top3' => $top3,
                'signals' => $rows,
            ];
        } elseif ($component === 'prediction') {
            $honmei = PredictionForwardSnapshotStore::expandTrifecta((string)($payload['honmei_kai'] ?? ''));
            $taikou = PredictionForwardSnapshotStore::expandTrifecta((string)($payload['taikou_kai'] ?? ''));
            $combined = array_values(array_unique(array_merge($honmei, $taikou)));
            $honmeiHit = in_array($actual, $honmei, true);
            $taikouHit = in_array($actual, $taikou, true);
            $combinedHit = in_array($actual, $combined, true);
            $grade = [
                'actual_first' => (int)$laneByRank[1],
                'honmei_head_first' => (int)($payload['honmei_head'] ?? 0) === (int)$laneByRank[1],
                'taikou_head_first' => (int)($payload['taikou_head'] ?? 0) === (int)$laneByRank[1],
                'honmei' => selfGradeBet($honmei, $honmeiHit, (int)$payout),
                'taikou' => selfGradeBet($taikou, $taikouHit, (int)$payout),
                'combined' => selfGradeBet($combined, $combinedHit, (int)$payout),
            ];
        } elseif ($component === 'hole_prediction') {
            $aPayload = (array)($payload['A'] ?? []);
            $bPayload = (array)($payload['B'] ?? []);
            $aBets = normalizeExplicitBets((array)($aPayload['bets'] ?? []));
            $bBets = normalizeExplicitBets((array)($bPayload['bets'] ?? []));
            $combined = array_values(array_unique(array_merge($aBets, $bBets)));
            $grade = [
                'actual_first' => (int)$laneByRank[1],
                'a_head_first' => (int)($aPayload['boat'] ?? 0) === (int)$laneByRank[1],
                'b_head_first' => (int)($bPayload['boat'] ?? 0) === (int)$laneByRank[1],
                'A' => selfGradeBet($aBets, in_array($actual, $aBets, true), (int)$payout),
                'B' => selfGradeBet($bBets, in_array($actual, $bBets, true), (int)$payout),
                'combined' => selfGradeBet($combined, in_array($actual, $combined, true), (int)$payout),
            ];
        } else {
            continue;
        }

        $gradeJson = json_encode($grade, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($gradeJson)) {
            continue;
        }
        $update->execute([
            ':actual_result' => $actual,
            ':payout' => $payout,
            ':grade' => $gradeJson,
            ':id' => (int)$snapshot['id'],
        ]);
        $graded += $update->rowCount();
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

echo "採点完了: {$graded}件 / 結果待ち: {$waitingResult}件 / 払戻待ち: {$waitingPayout}件（{$through}まで）\n";

function selfGradeBet(array $bets, bool $hit, int $payout): array
{
    $investment = count($bets) * 100;
    $returned = $hit ? $payout : 0;
    return [
        'points' => count($bets),
        'hit' => $hit,
        'investment' => $investment,
        'return' => $returned,
        'profit' => $returned - $investment,
        'roi' => $investment > 0 ? 100.0 * $returned / $investment : 0.0,
    ];
}

function normalizeExplicitBets(array $bets): array
{
    $out = [];
    foreach ($bets as $bet) {
        $bet = trim((string)$bet);
        if (preg_match('/^[1-6]-[1-6]-[1-6]$/', $bet) === 1) {
            $parts = explode('-', $bet);
            if (count(array_unique($parts)) === 3) {
                $out[] = $bet;
            }
        }
    }
    return array_values(array_unique($out));
}
