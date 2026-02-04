<?php

// ------------------------------------------------------------
// 0. start_timing の変換関数（F.04 → -0.04）
// ------------------------------------------------------------
function convertStartTiming($value)
{
    $value = trim($value);

    if ($value === "" || $value === "--") {
        return null;
    }

    if (str_starts_with($value, "F")) {
        return -1 * floatval(substr($value, 1));
    }

    if (str_starts_with($value, "L")) {
        return floatval(substr($value, 1));
    }

    return floatval($value);
}

// ------------------------------------------------------------
// 1. スクレイピング対象レース情報
// ------------------------------------------------------------
$race_date = "20260131";
$place_no  = 2;
$race_no   = 2;

$url = "https://kyoteibiyori.com/race_shusso.php"
     . "?place_no={$place_no}"
     . "&race_no={$race_no}"
     . "&hiduke={$race_date}"
     . "&slider=4";

// ------------------------------------------------------------
// 2. Playwright 実行
// ------------------------------------------------------------
$cmd = "node D:\\BoatRaceLogic\\playwright\\exhibition_live_scraper.js " . escapeshellarg($url);
exec($cmd, $output, $return_var);

if ($return_var !== 0) {
    echo "Playwright error: {$return_var}\n";
    exit;
}

// ------------------------------------------------------------
// 3. JSON を配列に変換
// ------------------------------------------------------------
$json = implode("\n", $output);
$data = json_decode($json, true);

if ($data === null) {
    echo "JSON decode error\n";
    echo $json;
    exit;
}

// ------------------------------------------------------------
// 4. place_map 読み込み
// ------------------------------------------------------------
$placeMap = require __DIR__ . '/../config/place_map.php';

if (!isset($placeMap[$place_no])) {
    echo "Unknown place_no: {$place_no}\n";
    exit;
}

$place_code = $placeMap[$place_no];

// ------------------------------------------------------------
// 5. race_code 生成
// ------------------------------------------------------------
$race_no2   = str_pad($race_no, 2, '0', STR_PAD_LEFT);
$race_code  = $race_date . $place_code . $race_no2;

// ------------------------------------------------------------
// 6. PostgreSQL 接続
// ------------------------------------------------------------
$pdo = new PDO(
    "pgsql:host=192.168.0.205;dbname=devdb",
    "miyase428",
    "herunia0113",
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ------------------------------------------------------------
// 7. 過去の場平均を取得（Excel版と同じロジック）
// ------------------------------------------------------------
$place = substr($race_code, 8, 3);

$avg_sql = "
    SELECT
        AVG(exhibition_time) AS avg_exh,
        AVG(lap_time) AS avg_lap,
        AVG(around_time) AS avg_around,
        AVG(straight_time) AS avg_straight
    FROM boat_race.exhibition_live
    WHERE race_code LIKE :place_prefix
";

$avg_stmt = $pdo->prepare($avg_sql);
$avg_stmt->execute([':place_prefix' => "%{$place}%"]);
$avg = $avg_stmt->fetch(PDO::FETCH_ASSOC);

$avg_exh      = $avg['avg_exh']      ?? 0;
$avg_lap      = $avg['avg_lap']      ?? 0;
$avg_around   = $avg['avg_around']   ?? 0;
$avg_straight = $avg['avg_straight'] ?? 0;

// ------------------------------------------------------------
// 8. INSERT 文（スコア・タイプ含む）
// ------------------------------------------------------------
$sql = "
    INSERT INTO boat_race.exhibition_live (
        race_code,
        entry_course,
        player_id,
        exhibition_time,
        start_timing,
        lap_time,
        around_time,
        straight_time,
        exhibition_score,
        exhibition_type,
        created_date
    ) VALUES (
        :race_code,
        :entry_course,
        :player_id,
        :exhibition_time,
        :start_timing,
        :lap_time,
        :around_time,
        :straight_time,
        :exhibition_score,
        :exhibition_type,
        NOW()
    )
    ON CONFLICT (race_code, entry_course)
    DO UPDATE SET
        player_id = EXCLUDED.player_id,
        exhibition_time = EXCLUDED.exhibition_time,
        start_timing = EXCLUDED.start_timing,
        lap_time = EXCLUDED.lap_time,
        around_time = EXCLUDED.around_time,
        straight_time = EXCLUDED.straight_time,
        exhibition_score = EXCLUDED.exhibition_score,
        exhibition_type = EXCLUDED.exhibition_type,
        created_date = NOW()
";

$stmt = $pdo->prepare($sql);

// ------------------------------------------------------------
// 9. 6艇分のデータを登録（スコア計算＋タイプ分類）
// ------------------------------------------------------------
foreach ($data as $row) {

    $start_timing = convertStartTiming($row['start_timing']);

    $diff_straight = $avg_straight - floatval($row['straight_time']);
    $diff_around   = $avg_around   - floatval($row['around_time']);
    $diff_lap      = $avg_lap      - floatval($row['lap_time']);
    $diff_exh      = $avg_exh      - floatval($row['exhibition_time']);

    $score =
        $diff_straight * 0.4 +
        $diff_around   * 0.3 +
        $diff_lap      * 0.2 +
        $diff_exh      * 0.1;

    if ($diff_straight > 0.10) {
        $type = '伸び型';
    } elseif ($diff_around > 0.10) {
        $type = '差し型';
    } else {
        $type = 'バランス';
    }

    $stmt->execute([
        ':race_code'        => $race_code,
        ':entry_course'     => $row['entry_course'],
        ':player_id'        => $row['player_id'],
        ':exhibition_time'  => $row['exhibition_time'],
        ':start_timing'     => $start_timing,
        ':lap_time'         => $row['lap_time'],
        ':around_time'      => $row['around_time'],
        ':straight_time'    => $row['straight_time'],
        ':exhibition_score' => $score,
        ':exhibition_type'  => $type
    ]);
}

echo "<pre>";
echo "race_code: {$race_code}\n";
echo "DB 登録完了\n\n";
print_r($data);
echo "</pre>";