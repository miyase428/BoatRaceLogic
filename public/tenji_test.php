<?php
header('Content-Type: application/json; charset=utf-8');

// パラメータ取得
$race_code = $_GET['race_code'] ?? null;
$tenji = [];
for ($i = 1; $i <= 6; $i++) {
    $tenji[$i] = $_GET["tenji$i"] ?? null;
}

// --- DB接続（宮崎さんの環境） ---
$dsn = "pgsql:host=192.168.0.205;port=5432;dbname=devdb;";
$user = "miyase428";
$pass = "herunia0113";

try {
    $pdo = new PDO($dsn, $user, $pass);
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// SQL準備（3ヶ月 + 6ヶ月）
$sql = "
WITH tenji AS (
    SELECT 1 AS wakuban, :tenji1 AS teiban
    UNION ALL SELECT 2, :tenji2
    UNION ALL SELECT 3, :tenji3
    UNION ALL SELECT 4, :tenji4
    UNION ALL SELECT 5, :tenji5
    UNION ALL SELECT 6, :tenji6
),
entry AS (
    SELECT
        t.wakuban,
        e.player_id
    FROM tenji t
    JOIN boat_race.race_entry e
      ON e.race_code = :race_code
     AND e.lane_number = t.teiban::integer
),
recent_6m AS (
    SELECT
        r.player_id,
        r.rank
    FROM boat_race.race_result_detail r
    JOIN entry e
      ON r.player_id = e.player_id
    WHERE TO_DATE(SUBSTRING(r.race_code, 1, 8), 'YYYYMMDD')
          >= CURRENT_DATE - INTERVAL '6 months'
),
recent_3m AS (
    SELECT
        r.player_id,
        r.rank
    FROM boat_race.race_result_detail r
    JOIN entry e
      ON r.player_id = e.player_id
    WHERE TO_DATE(SUBSTRING(r.race_code, 1, 8), 'YYYYMMDD')
          >= CURRENT_DATE - INTERVAL '3 months'
)
SELECT
    e.wakuban,
    e.player_id,

    -- 6ヶ月3連対率
    COUNT(*) FILTER (WHERE r6.rank IN ('1','2','3'))::float
        / NULLIF(COUNT(r6.rank), 0) AS three_in_rate_6m,

    -- 3ヶ月3連対率
    COUNT(*) FILTER (WHERE r3.rank IN ('1','2','3'))::float
        / NULLIF(COUNT(r3.rank), 0) AS three_in_rate_3m

FROM entry e
LEFT JOIN recent_6m r6 ON e.player_id = r6.player_id
LEFT JOIN recent_3m r3 ON e.player_id = r3.player_id
GROUP BY e.wakuban, e.player_id
ORDER BY e.wakuban;
";

// 実行
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':race_code' => $race_code,
    ':tenji1' => $tenji[1],
    ':tenji2' => $tenji[2],
    ':tenji3' => $tenji[3],
    ':tenji4' => $tenji[4],
    ':tenji5' => $tenji[5],
    ':tenji6' => $tenji[6],
]);

// 結果取得
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 出力
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);