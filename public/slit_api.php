<?php
header("Content-Type: application/json; charset=UTF-8");

$race_code = $_POST["race_code"] ?? "";

if ($race_code === "") {
    echo json_encode(["error" => "race_code がありません"]);
    exit;
}

if (!preg_match('/^[0-9A-Z]+$/', $race_code)) {
    echo json_encode(["error" => "不正な race_code"]);
    exit;
}

$base = __DIR__ . "/../theories/course_correction/";
$python = "/usr/bin/python3";
$script = escapeshellarg($base . "predict_pattern.py");

$cmd = "$python $script " . escapeshellarg($race_code);

// Python 実行
$log = shell_exec($cmd . " 2>&1");

// JSON パース
$predict = json_decode($log, true);

if ($predict === null) {
    echo json_encode([
        "error" => "predict_pattern.py の JSON パースに失敗",
        "raw" => $log
    ]);
    exit;
}

// pattern_id を取得
$pattern_id = $predict["pattern_id"];

$features = $predict["features"] ?? [];

// buff_debuff_slit.json を読み込む
$buff_path = $base . "buff_debuff_slit.json";
$buff_json = json_decode(file_get_contents($buff_path), true);

// pattern_id の部分だけ抽出
$buff = $buff_json[strval($pattern_id)] ?? null;

// 最終的に返す JSON
$response = [
    "race_code"   => $race_code,
    "pattern_id"  => $pattern_id,
    "features"    => $features,
    "buff_debuff" => $buff,
    "predict_detail" => $predict
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;
