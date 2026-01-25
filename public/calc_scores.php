<?php
header("Content-Type: application/json; charset=utf-8");

// ------------------------------------------------------------
// 1. race_code を受け取る
// ------------------------------------------------------------
$race_code = $_GET['race_code'] ?? null;

if (!$race_code) {
    echo json_encode([
        "status" => "error",
        "message" => "race_code is required"
    ]);
    exit;
}

// ------------------------------------------------------------
// 2. Raspberry Pi の API から事実データを取得
// ------------------------------------------------------------
$pi_url = "http://192.168.0.205/api/get_input_data.php?race_code=" . urlencode($race_code);

$pi_response = @file_get_contents($pi_url);

if ($pi_response === false) {
    echo json_encode([
        "status" => "error",
        "message" => "Failed to fetch data from Raspberry Pi API",
        "pi_url" => $pi_url
    ]);
    exit;
}

$input_data = json_decode($pi_response, true);

// ------------------------------------------------------------
// 3. Pi のレスポンス形式チェック
// ------------------------------------------------------------
if (!isset($input_data["entries"]) || !is_array($input_data["entries"])) {
    echo json_encode([
        "status" => "error",
        "message" => "Invalid response format from Raspberry Pi API",
        "raw" => $pi_response
    ]);
    exit;
}

$entries = $input_data["entries"];

// ------------------------------------------------------------
// 4. ロジック計算
// ------------------------------------------------------------
$results = [];

foreach ($entries as $row) {

    // 元データ
    $lane  = intval($row["lane_number"]);
    $nation = floatval($row["national_win_rate"]);
    $local  = floatval($row["local_win_rate"]);
    $motor  = floatval($row["motor_exacta_rate"]);
    $boat   = floatval($row["boat_exacta_rate"]);
    $st     = floatval($row["average_start"]);

    // 勝率評価(10)
    $shoritsu_score = min(10, max(1, $nation * 1.2));

    // 当地評価(5)
    $tochi_score = min(5, max(1, ($local - 4) * 2));

    // モーター評価(5)
    $motor_score = min(5, max(1, ($motor - 25) / 4));

    // ボート評価(5)
    $boat_score = min(5, max(1, ($boat - 25) / 4));

    // ST評価(5)
    $st_score = min(5, max(1, 8 - ($st * 30)));

    // 地力スコア = 勝率 + 当地
    $jiryoku_score = $shoritsu_score + $tochi_score;

    // 足スコア = モーター + ボート
    $ashi_score = $motor_score + $boat_score;

    // スタートスコア = ST評価
    $start_score = $st_score;

    // 一次総合スコア = 地力 + 足 + スタート
    $total_score = $jiryoku_score + $ashi_score + $start_score;

    // --------------------------------------------------------
    // タイプ分類（Excel IFS を完全移植）
    // --------------------------------------------------------
    $R = $total_score;
    $J = $jiryoku_score;
    $P = $ashi_score;
    $N = $st_score;

    $type = null;

    // 1コース特別ルール
    if ($lane === 1) {
        if ($R >= 22)      $type = 4;
        elseif ($R >= 18) $type = 5;
        else              $type = 6;
    }
    // 6コース特別ルール
    elseif ($lane === 6) {
        if ($R >= 22)      $type = 11;
        elseif ($R >= 18) $type = 12;
        else              $type = 13;
    }
    // R>=22
    elseif ($R >= 22) {
        $type = ($J >= 8) ? 1 : 2;
    }
    // R>=20
    elseif ($R >= 20) {
        $type = 3;
    }
    // 地力×足の特殊パターン
    elseif ($J <= 3 && $P >= 7) {
        $type = 14;
    }
    elseif ($J >= 7 && $P <= 5) {
        $type = 15;
    }
    // ST評価が5
    elseif ($N == 5) {
        $type = 17;
    }
    // R>=18
    elseif ($R >= 18) {
        $type = 8;
    }
    // R>=15
    elseif ($R >= 15) {
        $type = 9;
    }
    // その他
    else {
        $type = 10;
    }

    // --------------------------------------------------------
    // 一次評価（タイプ分類 → 記号）
    // --------------------------------------------------------
    $ichiji_map = [
        1 => "◎", 2 => "○", 3 => "○", 4 => "◎", 5 => "○",
        6 => "×", 7 => "△", 8 => "○", 9 => "△", 10 => "×",
        11 => "△", 12 => "△", 13 => "×", 14 => "△", 15 => "△",
        16 => "△", 17 => "○", 18 => "△"
    ];

    $ichiji_eval = $ichiji_map[$type] ?? "×";

    // --------------------------------------------------------
    // 結果を格納
    // --------------------------------------------------------
    $results[] = [
        "lane"   => $lane,
        "player" => $row["player_name"],

        "shoritsu_score" => $shoritsu_score,
        "tochi_score"    => $tochi_score,
        "motor_score"    => $motor_score,
        "boat_score"     => $boat_score,
        "st_score"       => $st_score,
        "jiryoku_score"  => $jiryoku_score,
        "ashi_score"     => $ashi_score,
        "start_score"    => $start_score,
        "total_score"    => $total_score,
        "type"           => $type,
        "ichiji_eval"    => $ichiji_eval
    ];
}

// ------------------------------------------------------------
// 5. Excel に返す JSON
// ------------------------------------------------------------
echo json_encode([
    "status" => "ok",
    "race_code" => $race_code,
    "entries" => $entries,
    "results" => $results
], JSON_PRETTY_PRINT);