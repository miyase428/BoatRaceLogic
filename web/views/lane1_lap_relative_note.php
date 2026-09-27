<?php
$lane1LapNote = is_array($lane1_lap_relative_data ?? null) ? $lane1_lap_relative_data : [];
if (empty($lane1LapNote['show'])) {
    return;
}

$lane1Lap = number_format((float)($lane1LapNote['lane1_lap'] ?? 0), 2);
$averageLap = number_format((float)($lane1LapNote['average_lap'] ?? 0), 2);
$delta = number_format((float)($lane1LapNote['delta'] ?? 0), 2);
?>
<div style="margin:0 10px 12px;padding:10px 12px;border:1px solid #e6bf8d;border-radius:8px;background:#fff6e8;color:#684617;">
    <div style="font-weight:700;">⚠ 1号艇・周回弱気</div>
    <div style="margin-top:4px;font-size:12px;line-height:1.55;">
        1号艇の周回 <?= htmlspecialchars($lane1Lap, ENT_QUOTES, 'UTF-8') ?> は、6艇平均 <?= htmlspecialchars($averageLap, ENT_QUOTES, 'UTF-8') ?> より
        <strong>+<?= htmlspecialchars($delta, ENT_QUOTES, 'UTF-8') ?>秒</strong>遅めです。インを過信せず、相手・展開も確認してください。
    </div>
    <div style="margin-top:4px;font-size:11px;color:#886b42;">
        相対周回の表示専用サインです。SUM点・AI確率・買い目は変更しません。
    </div>
</div>
