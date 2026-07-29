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
// ★ statsが無い場合のみ生成
if (!file_exists($json_path)) {

    $python = "/usr/bin/python3";
    $script = escapeshellarg($base . "new_sam.py");
    $arg_jyo = escapeshellarg($jyo);

    // ★ ターミナルと同じHOME環境変数や必要なパスを頭につけて実行する
    $env_prefix = "HOME=/home/miyazaki PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin";
    
    // ディレクトリを移動しつつ、環境変数を引き継いでPythonを実行
    $cmd = "$env_prefix cd " . escapeshellarg($base) . " && $python $script $arg_jyo 2>&1";

    // 実行とログ保存
    $output = [];
    $return_code = 0;
    exec($cmd, $output, $return_code);

    $log = implode("\n", $output);
    file_put_contents("/tmp/sam_api.log", "CMD: $cmd\nRETURN: $return_code\nLOG:\n$log\n\n", FILE_APPEND);

    if (!file_exists($json_path) || $return_code !== 0) {
        http_response_code(500);
        echo json_encode([
            "error" => "stats ファイルが生成されませんでした",
            "return_code" => $return_code,
            "cmd" => $cmd,
            "log" => $log
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

// ★ JSON返却
echo file_get_contents($json_path);
exit;