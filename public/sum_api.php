<?php
/**
 * サム理論 API（Excel 用）
 * -----------------------------------------
 * Excel から「場コード（例：OMR）」を受け取り、
 * stats_場コード.json が無ければ new_sam.py を実行して生成し、
 * その内容を返す。
 */

header("Content-Type: application/json; charset=UTF-8");

$jyo = $_POST["jyo"] ?? "";

if ($jyo === "") {
    echo json_encode(["error" => "jyo（場コード）がありません"]);
    exit;
}

$base = __DIR__ . "/../theories/new_sam/";
$json_path = $base . "stats_" . $jyo . ".json";

// ★ 1. stats_◯◯.json が無ければ new_sam.py を実行して生成
if (!file_exists($json_path)) {

    //$python = escapeshellcmd("python3");
    $python = "/usr/bin/python3";
    $script = escapeshellarg($base . "new_sam.py");

    $cmd = "$python $script " . escapeshellarg($jyo);
    shell_exec($cmd);
    // ログを保存
    file_put_contents("/tmp/sam_api.log", "CMD: $cmd\nLOG:\n$log\n\n", FILE_APPEND);

    // 生成されたか再チェック
    if (!file_exists($json_path)) {
        echo json_encode(["error" => "stats ファイルが生成されませんでした"]);
        exit;
    }
}

// ★ 2. ここまで来たら stats_◯◯.json は必ず存在する
echo file_get_contents($json_path);
exit;
