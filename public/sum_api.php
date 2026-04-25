<?php
header("Content-Type: application/json; charset=UTF-8");

$jyo = $_POST["jyo"] ?? "";

if ($jyo === "") {
    echo json_encode(["error" => "jyo（場コード）がありません"]);
    exit;
}

// 安全チェック（重要）
if (!preg_match('/^[A-Z0-9]+$/', $jyo)) {
    echo json_encode(["error" => "不正な場コード"]);
    exit;
}

$base = __DIR__ . "/../theories/new_sam/";
$json_path = $base . "stats_" . $jyo . ".json";

// ★ statsが無い場合のみ生成
if (!file_exists($json_path)) {

    // フルパス指定（重要）
    $python = "/usr/bin/python3";
    $script = escapeshellarg($base . "new_sam.py");

    $cmd = "$python $script " . escapeshellarg($jyo);

    // ★ 実行結果（標準エラー含む）取得
    $log = shell_exec($cmd . " 2>&1");

    // ログ保存
    file_put_contents("/tmp/sam_api.log", "CMD: $cmd\nLOG:\n$log\n\n", FILE_APPEND);

    // ★ デバッグ用（最初はこれONにする）
    if (!file_exists($json_path)) {
        echo json_encode([
            "error" => "stats ファイルが生成されませんでした",
            "cmd" => $cmd,
            "log" => $log
        ]);
        exit;
    }
}

// ★ JSON返却
echo file_get_contents($json_path);
exit;