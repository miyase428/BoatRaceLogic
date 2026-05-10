<?php
header("Content-Type: application/json; charset=UTF-8");

// race_code を受け取る
$race_code = $_POST["race_code"] ?? "";

if ($race_code === "") {
    echo json_encode(["error" => "race_code がありません"]);
    exit;
}

// race_code の安全チェック（英数字のみ）
if (!preg_match('/^[0-9A-Z]+$/', $race_code)) {
    echo json_encode(["error" => "不正な race_code"]);
    exit;
}

// Python スクリプトのパス
$base = __DIR__ . "/../theories/course_correction/";
$python = "/usr/bin/python3";
$script = escapeshellarg($base . "predict_pattern.py");

// コマンド生成
$cmd = "$python $script " . escapeshellarg($race_code);

// 実行（標準エラーも取得）
$log = shell_exec($cmd . " 2>&1");

// ログ保存（必要なら）
file_put_contents("/tmp/slit_api.log", "CMD: $cmd\nLOG:\n$log\n\n", FILE_APPEND);

// Python が JSON を返すのでそのまま返却
echo $log;
exit;
