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

// 今日の日付（00:00）
$today = strtotime("today");

// ★ ファイルが存在していて、更新日が今日より古い場合 → 再生成する
if (file_exists($json_path)) {
    $mtime = filemtime($json_path);

    if ($mtime < $today) {
        // 古いので削除して再生成させる
        unlink($json_path);
    }
}

// ★ statsが無い場合のみ生成（古い場合は上で削除されている）
if (!file_exists($json_path)) {

    $python = "/usr/bin/python3";
    $script = $base . "new_sam.py";

    $cmd = "HOME=/home/miyazaki PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
            cd {$base} && /usr/bin/python3 {$script} {$jyo} 2>&1";

    $output = [];
    $return_code = 0;
    exec($cmd, $output, $return_code);

    file_put_contents($base . "exec_debug.log",
        "CMD: $cmd\nRETURN: $return_code\nLOG:\n" . implode("\n", $output) . "\n\n",
        FILE_APPEND
    );

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
