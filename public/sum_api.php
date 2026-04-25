<?php
/**
 * サム理論 API（Excel 用）
 * -----------------------------------------
 * Excel から「場コード（例：OMR）」を受け取り、
 * theories/new_sam/stats_OMR.json を読み込んで
 * その内容をそのまま JSON として返す。
 *
 * new_sam.py は別途実行して stats_OMR.json を生成しておく。
 */

header("Content-Type: application/json; charset=UTF-8");

$jyo = $_POST["jyo"] ?? "";

if ($jyo === "") {
    echo json_encode(["error" => "jyo（場コード）がありません"]);
    exit;
}

$json_path = __DIR__ . "/../theories/new_sam/stats_" . $jyo . ".json";

if (!file_exists($json_path)) {
    echo json_encode(["error" => "stats ファイルがありません: " . $json_path]);
    exit;
}

echo file_get_contents($json_path);
exit;
