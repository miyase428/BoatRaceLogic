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
// ★ statsが無い場合のみ生成
if (!file_exists($json_path)) {

    $python = "/usr/bin/python3";
    $script = escapeshellarg($base . "new_sam.py");
    $arg_jyo = escapeshellarg($jyo);

    // Playwrightの時と同様の環境変数＋カレントディレクトリ移動
    $env_prefix = "HOME=/home/miyazaki PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin";
    $cmd = "$env_prefix cd " . escapeshellarg($base) . " && $python $script $arg_jyo 2>&1";

    $output = [];
    $return_code = 0;
    exec($cmd, $output, $return_code);

    $log = implode("\n", $output);
    
    // ★ ログ保存先を /tmp ではなく、確実に書き込める theories/new_sam/ 配下にしてみる
    $debug_log_path = $base . "api_debug.log";
    file_put_contents($debug_log_path, "TIME: " . date('Y-m-d H:i:s') . "\nCMD: $cmd\nRETURN: $return_code\nLOG:\n$log\n\n", FILE_APPEND);

    // まだファイルができていない場合は、ブラウザにエラーを丸ごと返す
    if (!file_exists($json_path)) {
        http_response_code(500);
        echo json_encode([
            "error" => "Pythonの実行に失敗しました",
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