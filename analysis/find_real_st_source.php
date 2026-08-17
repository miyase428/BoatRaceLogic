<?php
declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

$pdo = getPDO();
$raceCodes = array_values(array_filter(array_slice($argv, 1), static fn($v) => preg_match('/^[0-9]{8}[A-Z]{3}[0-9]{2}$/', (string)$v)));
if (!$raceCodes) {
    $raceCodes = ['20260715ASY01'];
}

function qi(string $name): string {
    return '"' . str_replace('"', '""', $name) . '"';
}

$sql = "
    SELECT table_name, column_name
    FROM information_schema.columns
    WHERE table_schema = 'boat_race'
    ORDER BY table_name, ordinal_position
";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$tables = [];
foreach ($rows as $row) {
    $tables[$row['table_name']][] = $row['column_name'];
}

$candidates = [];
foreach ($tables as $table => $cols) {
    if (!in_array('race_code', $cols, true)) {
        continue;
    }

    $stCols = [];
    foreach ($cols as $col) {
        if (preg_match('/start|timing|(^|_)st($|_)/i', $col)) {
            $stCols[] = $col;
        }
    }

    if ($stCols) {
        $candidates[$table] = [
            'columns' => $cols,
            'st_columns' => $stCols,
        ];
    }
}

ksort($candidates);

echo "========================================\n";
echo "本番ST 候補データ源診断\n";
echo "========================================\n";
echo "対象schema : boat_race\n";
echo "候補条件   : race_code列あり + start/timing/ST系列あり\n\n";

echo "【候補テーブル】\n";
foreach ($candidates as $table => $meta) {
    echo sprintf("%-30s : %s\n", $table, implode(', ', $meta['st_columns']));
}

foreach ($raceCodes as $raceCode) {
    echo "\n========================================\n";
    echo "サンプルrace_code : {$raceCode}\n";
    echo "========================================\n";

    foreach ($candidates as $table => $meta) {
        $tableQ = qi($table);
        $selects = ['COUNT(*) AS total_rows'];
        foreach ($meta['st_columns'] as $col) {
            $colQ = qi($col);
            $alias = 'nn_' . preg_replace('/[^A-Za-z0-9_]/', '_', $col);
            $selects[] = "COUNT({$colQ}) AS " . qi($alias);
        }

        $q = $pdo->prepare(
            'SELECT ' . implode(', ', $selects) .
            ' FROM boat_race.' . $tableQ .
            ' WHERE race_code = :r'
        );
        $q->execute([':r' => $raceCode]);
        $summary = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int)($summary['total_rows'] ?? 0);
        if ($total === 0) {
            continue;
        }

        $parts = [];
        foreach ($meta['st_columns'] as $col) {
            $alias = 'nn_' . preg_replace('/[^A-Za-z0-9_]/', '_', $col);
            $parts[] = $col . '=' . (int)($summary[$alias] ?? 0);
        }
        echo "\n[{$table}] rows={$total} nonnull{" . implode(', ', $parts) . "}\n";

        $displayCols = [];
        foreach (['player_id', 'lane_number', 'entry_course', 'rank'] as $col) {
            if (in_array($col, $meta['columns'], true)) {
                $displayCols[] = $col;
            }
        }
        foreach ($meta['st_columns'] as $col) {
            if (!in_array($col, $displayCols, true)) {
                $displayCols[] = $col;
            }
        }
        if (!$displayCols) {
            continue;
        }

        $orderCol = null;
        foreach (['entry_course', 'lane_number', 'player_id'] as $col) {
            if (in_array($col, $meta['columns'], true)) {
                $orderCol = $col;
                break;
            }
        }

        $detailSql = 'SELECT ' . implode(', ', array_map('qi', $displayCols)) .
            ' FROM boat_race.' . $tableQ . ' WHERE race_code = :r';
        if ($orderCol !== null) {
            $detailSql .= ' ORDER BY ' . qi($orderCol);
        }
        $detailSql .= ' LIMIT 12';

        $dq = $pdo->prepare($detailSql);
        $dq->execute([':r' => $raceCode]);
        foreach ($dq->fetchAll(PDO::FETCH_ASSOC) as $detail) {
            $vals = [];
            foreach ($detail as $k => $v) {
                $vals[] = $k . '=' . var_export($v, true);
            }
            echo '  ' . implode(' ', $vals) . "\n";
        }
    }
}

echo "\n見るポイント:\n";
echo "・race_result_detail以外に6艇分の本番STを持つテーブルがあるか\n";
echo "・上位4着のみ保存の場でも、別テーブルなら6行揃うか\n";
echo "・exhibition_liveは展示STなので、本番ST候補とは区別する\n";
echo "========================================\n";
