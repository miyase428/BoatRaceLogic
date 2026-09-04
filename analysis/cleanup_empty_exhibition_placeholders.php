<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/db_connect.php';

/**
 * exhibition_live に残った「6艇分あるが全項目NULL」の空プレースホルダーを除去する。
 *
 * 過去の Playwright スクレイパーは展示情報が無いページでも
 * entry_course=1..6 の6行を返していたため、player_id/展示指標がすべてNULLの
 * 6行が保存されることがあった。
 *
 * 既定は DRY-RUN。--apply のときだけ削除する。
 * 削除対象は以下をすべて満たす race_code のみ。
 * - exhibition_live がちょうど6行
 * - entry_course が1～6の6種類
 * - 6行すべて player_id IS NULL
 * - 6行すべて exhibition_time/start_timing/lap_time/around_time/straight_time IS NULL
 *
 * Usage:
 *   php analysis/cleanup_empty_exhibition_placeholders.php 2026-08-01 2026-09-02
 *   php analysis/cleanup_empty_exhibition_placeholders.php 2026-08-01 2026-09-02 --apply
 */

$from = trim((string)($argv[1] ?? ''));
$to = trim((string)($argv[2] ?? ''));
$apply = in_array('--apply', array_slice($argv, 3), true);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    fwrite(STDERR, "Usage: php {$argv[0]} YYYY-MM-DD YYYY-MM-DD [--apply]\n");
    exit(1);
}
if ($from > $to) {
    fwrite(STDERR, "開始日は終了日以前にしてください。\n");
    exit(1);
}

$pdo = getPDO();

$sql = <<<SQL
SELECT
    el.race_code,
    COUNT(*)::int AS rows,
    COUNT(DISTINCT el.entry_course)::int AS course_count,
    COUNT(*) FILTER (WHERE el.entry_course::text ~ '^[1-6]$')::int AS valid_course_rows,
    COUNT(*) FILTER (WHERE el.player_id IS NULL)::int AS null_player_rows,
    COUNT(*) FILTER (
        WHERE el.exhibition_time IS NULL
          AND el.start_timing IS NULL
          AND el.lap_time IS NULL
          AND el.around_time IS NULL
          AND el.straight_time IS NULL
    )::int AS all_metric_null_rows
FROM boat_race.exhibition_live el
WHERE SUBSTRING(el.race_code, 1, 8)
      BETWEEN REPLACE(:from, '-', '') AND REPLACE(:to, '-', '')
GROUP BY el.race_code
HAVING COUNT(*) = 6
   AND COUNT(DISTINCT el.entry_course) = 6
   AND COUNT(*) FILTER (WHERE el.entry_course::text ~ '^[1-6]$') = 6
   AND COUNT(*) FILTER (WHERE el.player_id IS NULL) = 6
   AND COUNT(*) FILTER (
        WHERE el.exhibition_time IS NULL
          AND el.start_timing IS NULL
          AND el.lap_time IS NULL
          AND el.around_time IS NULL
          AND el.straight_time IS NULL
   ) = 6
ORDER BY el.race_code
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute([':from' => $from, ':to' => $to]);
$targets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = count($targets);

echo str_repeat('=', 120) . "\n";
echo "空の展示6行プレースホルダー診断／除去\n";
echo "期間             : {$from} ～ {$to}\n";
echo "対象レース       : {$total}R\n";
echo "モード           : " . ($apply ? 'APPLY（DB更新あり）' : 'DRY-RUN（DB更新なし）') . "\n";
echo str_repeat('=', 120) . "\n";

if ($total === 0) {
    echo "対象なし\n";
    exit(0);
}

foreach ($targets as $row) {
    printf(
        "%s | rows=%d course=%d valid=%d nullPlayer=%d metricNull=%d\n",
        $row['race_code'],
        (int)$row['rows'],
        (int)$row['course_count'],
        (int)$row['valid_course_rows'],
        (int)$row['null_player_rows'],
        (int)$row['all_metric_null_rows']
    );
}

if (!$apply) {
    echo "\nDRY-RUNで終了しました。内容確認後、--apply を付けて実行してください。\n";
    exit(0);
}

$deleteSql = <<<SQL
DELETE FROM boat_race.exhibition_live
WHERE race_code = :race_code
  AND player_id IS NULL
  AND exhibition_time IS NULL
  AND start_timing IS NULL
  AND lap_time IS NULL
  AND around_time IS NULL
  AND straight_time IS NULL
SQL;
$deleteStmt = $pdo->prepare($deleteSql);

$deletedRaces = 0;
$deletedRows = 0;
$failed = 0;

foreach ($targets as $row) {
    $code = (string)$row['race_code'];
    try {
        $pdo->beginTransaction();
        $deleteStmt->execute([':race_code' => $code]);
        $n = $deleteStmt->rowCount();

        if ($n !== 6) {
            throw new RuntimeException("削除行数が6ではありません: {$n}");
        }

        $pdo->commit();
        $deletedRaces++;
        $deletedRows += $n;
        echo "DELETE OK {$code}: {$n}行\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $failed++;
        echo "DELETE NG {$code}: {$e->getMessage()}\n";
    }
}

echo "\n" . str_repeat('=', 120) . "\n";
echo "除去結果\n";
printf("成功レース       : %dR\n", $deletedRaces);
printf("失敗レース       : %dR\n", $failed);
printf("削除行           : %d行\n", $deletedRows);
echo str_repeat('=', 120) . "\n";
