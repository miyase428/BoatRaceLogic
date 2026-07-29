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

    // 条件なしで強制実行 ＆ escapeshellarg を使わないテスト
    $python = "/usr/bin/python3";
    $script = $base . "new_sam.py";

    // 環境変数 + シンプルな文字列結合
    $cmd = "HOME=/home/miyazaki PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin cd " . $base . " && " . $python . " " . $script . " " . $jyo . " 2>&1";

    $output = [];
    $return_code = 0;
    exec($cmd, $output, $return_code);

    // 確実にログを残す
    file_put_contents($base . "exec_debug.log", "CMD: $cmd\nRETURN: $return_code\nLOG:\n" . implode("\n", $output) . "\n\n", FILE_APPEND);

    if (file_exists($json_path)) {
        echo file_get_contents($json_path);
        exit;
    } else {
        http_response_code(500);
        echo json_encode([
            "error" => "失敗しました",
            "cmd" => $cmd,
            "return_code" => $return_code,
            "log" => $output
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

// ★ JSON返却
echo file_get_contents($json_path);
exit;