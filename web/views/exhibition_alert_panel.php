<?php
// 展示アラートは展示タイムの隣接差を読む表示専用サイン。
$exhibitionAlert = is_array($exhibition_alert_data ?? null) ? $exhibition_alert_data : [];
$exhibitionAlertStatus = (string)($exhibitionAlert['status'] ?? 'waiting');
$exhibitionAlerts = is_array($exhibitionAlert['alerts'] ?? null) ? $exhibitionAlert['alerts'] : [];
$exhibitionAlertMain = is_array($exhibitionAlert['main_alert'] ?? null) ? $exhibitionAlert['main_alert'] : null;

$exhibitionAlertTone = static function (string $tone): array {
    return match ($tone) {
        'danger' => ['#fff1f2', '#e11d48', '#9f1239'],
        'warning' => ['#fff7ed', '#ea580c', '#9a3412'],
        default => ['#fffbeb', '#ca8a04', '#854d0e'],
    };
};
$exhibitionAlertBoat = static function (array $item): string {
    return (int)($item['course'] ?? 0) . 'C・' . (int)($item['boat'] ?? 0) . '号艇';
};
?>
<section id="exhibition-alert-panel" style="margin:12px 0 16px;border:1px solid #f0c98c;border-radius:10px;overflow:hidden;background:#fffdf8;">
    <div style="padding:11px 13px 8px;background:#fff7e8;border-bottom:1px solid #f3dfbd;">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
            <h2 style="margin:0;font-size:17px;color:#9a5b18;">🚨 展示アラート</h2>
            <span style="font-size:11px;color:#8a765d;white-space:nowrap;">表示専用・買い目未反映</span>
        </div>
        <div style="margin-top:4px;font-size:12px;color:#756654;line-height:1.55;">展示進入の隣接艇で展示タイム差0.10秒以上を検知。速い艇の浮上と、遅い隣艇の警戒を見る参考サインです。</div>
    </div>

    <?php if ($exhibitionAlertStatus !== 'ok'): ?>
        <div style="padding:12px 13px;color:#7a8794;font-size:13px;">展示アラート：<?= htmlspecialchars((string)($exhibitionAlert['error'] ?? '展示タイム待ち'), ENT_QUOTES, 'UTF-8') ?></div>
    <?php elseif ($exhibitionAlertMain === null): ?>
        <div style="padding:12px 13px;color:#52735e;font-size:13px;">展示アラート：該当なし（隣接艇の展示タイム差0.10秒未満）</div>
    <?php else: ?>
        <?php [$alertBg, $alertBorder, $alertText] = $exhibitionAlertTone((string)($exhibitionAlertMain['tone'] ?? 'standard')); ?>
        <div style="margin:10px;padding:11px;border:1px solid <?= htmlspecialchars($alertBorder, ENT_QUOTES, 'UTF-8') ?>;border-radius:8px;background:<?= htmlspecialchars($alertBg, ENT_QUOTES, 'UTF-8') ?>;">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                <strong style="font-size:15px;color:<?= htmlspecialchars($alertText, ENT_QUOTES, 'UTF-8') ?>;"><?= htmlspecialchars((string)$exhibitionAlertMain['level'], ENT_QUOTES, 'UTF-8') ?>：<?= htmlspecialchars((string)$exhibitionAlertMain['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                <strong style="font-size:16px;color:<?= htmlspecialchars($alertText, ENT_QUOTES, 'UTF-8') ?>;white-space:nowrap;">差 <?= number_format((float)$exhibitionAlertMain['gap'], 2) ?>秒</strong>
            </div>
            <div style="margin-top:7px;font-size:14px;color:#3f4d58;line-height:1.6;">
                <?= htmlspecialchars($exhibitionAlertBoat($exhibitionAlertMain['faster']), ENT_QUOTES, 'UTF-8') ?> が
                <?= htmlspecialchars($exhibitionAlertBoat($exhibitionAlertMain['slower']), ENT_QUOTES, 'UTF-8') ?> より展示タイム優位。<br>
                <?= htmlspecialchars((string)$exhibitionAlertMain['summary'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div style="margin-top:5px;font-size:11px;color:#76624d;"><?= htmlspecialchars((string)$exhibitionAlertMain['historical_effect'], ENT_QUOTES, 'UTF-8') ?></div>
        </div>

        <?php if (count($exhibitionAlerts) > 1): ?>
            <details style="margin:0 10px 10px;">
                <summary style="cursor:pointer;color:#8b5e34;font-size:12px;font-weight:bold;">ほか <?= count($exhibitionAlerts) - 1 ?>件の展示アラート</summary>
                <div style="margin-top:7px;display:grid;gap:5px;">
                    <?php foreach (array_slice($exhibitionAlerts, 1) as $alert): ?>
                        <div style="padding:7px 8px;border:1px solid #eadbc7;border-radius:6px;background:#fff;line-height:1.45;font-size:12px;color:#525f69;">
                            <strong><?= htmlspecialchars((string)$alert['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                            ：<?= htmlspecialchars($exhibitionAlertBoat($alert['faster']), ENT_QUOTES, 'UTF-8') ?> が <?= htmlspecialchars($exhibitionAlertBoat($alert['slower']), ENT_QUOTES, 'UTF-8') ?> より <?= number_format((float)$alert['gap'], 2) ?>秒速い
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>
    <?php endif; ?>
</section>
