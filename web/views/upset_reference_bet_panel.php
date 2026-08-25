<?php
// 穴目レイヤー最終検証で固定した TRIO1_OUTCOME を表示専用で再現する。
// 穴頭本命を1着固定、穴ヒモ候補Top3を2着、現行cutを維持した非cut艇を3着候補にする。
// 本番PredictionLogic・既存買い目・購入処理には接続しない。

$upsetReferenceBetHead = (int)($upsetHoleHead ?? 0);
$upsetReferenceBetSeconds = [];
$upsetReferenceBetThirds = [];
$upsetReferenceBetPoints = 0;
$upsetReferenceBetReady = false;

if (
    !empty($upsetAlertHigh)
    && $upsetReferenceBetHead >= 1
    && $upsetReferenceBetHead <= 6
    && is_array($upsetHimoCandidates ?? null)
    && !empty($upsetHimoCandidates)
) {
    foreach ($upsetHimoCandidates as $candidate) {
        $boat = (int)($candidate['boat'] ?? 0);
        if (
            $boat >= 1
            && $boat <= 6
            && $boat !== $upsetReferenceBetHead
            && !isset(($upsetCutBoats ?? [])[$boat])
        ) {
            $upsetReferenceBetSeconds[] = $boat;
        }
    }
    $upsetReferenceBetSeconds = array_values(array_unique($upsetReferenceBetSeconds));

    foreach (range(1, 6) as $boat) {
        if ($boat === $upsetReferenceBetHead || isset(($upsetCutBoats ?? [])[$boat])) {
            continue;
        }
        $upsetReferenceBetThirds[] = $boat;
    }

    $betKeys = [];
    foreach ($upsetReferenceBetSeconds as $second) {
        foreach ($upsetReferenceBetThirds as $third) {
            if ($second === $third) {
                continue;
            }
            $betKeys[$upsetReferenceBetHead . '-' . $second . '-' . $third] = true;
        }
    }
    $upsetReferenceBetPoints = count($betKeys);
    $upsetReferenceBetReady = $upsetReferenceBetPoints > 0;
}

if (!$upsetReferenceBetReady) {
    return;
}

$upsetReferenceBetBadges = static function (array $boats) use ($upsetBoatBadge): string {
    $html = [];
    foreach ($boats as $boat) {
        $html[] = $upsetBoatBadge((int)$boat);
    }
    return implode(' ', $html);
};
?>

<div id="upset-reference-bet-panel" style="margin-top:10px; background:#f4ead7; border:1px solid #d5bd91; border-radius:7px; padding:10px 11px; color:#3f4b5a;">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
        <div style="font-size:13px; font-weight:bold; color:#7b6332;">🎯 参考の穴目買い目候補</div>
        <div style="font-size:11px; color:#7b6332; white-space:nowrap;">参考表示 / 自動購入なし</div>
    </div>

    <div style="margin-top:8px; display:grid; grid-template-columns:auto 1fr; gap:7px 9px; align-items:center;">
        <div style="font-size:11px; color:#6b7785;">1着</div>
        <div><?= $upsetBoatBadge($upsetReferenceBetHead) ?></div>

        <div style="font-size:11px; color:#6b7785;">2着</div>
        <div style="display:flex; gap:5px; flex-wrap:wrap;"><?= $upsetReferenceBetBadges($upsetReferenceBetSeconds) ?></div>

        <div style="font-size:11px; color:#6b7785;">3着</div>
        <div style="display:flex; gap:5px; flex-wrap:wrap;"><?= $upsetReferenceBetBadges($upsetReferenceBetThirds) ?></div>
    </div>

    <div style="margin-top:8px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:11px; color:#6b7785;">
        <strong style="font-size:13px; color:#aa741f;"><?= $upsetReferenceBetPoints ?>点</strong>
        <span>穴頭TRIO_TOP1固定 / 2着=120通り P(2着|頭) Top3 / 3着=非cut艇</span>
    </div>
    <div style="margin-top:4px; font-size:11px; color:#6b7785;">
        P1/P2/P3で平均点数を増やさず的中率改善を再現。ROIは期間差があるため参考候補として表示します。
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const upsetPanel = document.getElementById('upset-alert-panel');
    const refPanel = document.getElementById('upset-reference-bet-panel');
    if (!upsetPanel || !refPanel) return;

    // upset_alert_panel.php が買い目直下へ移動した後、同じ穴目パネル内の最下段へまとめる。
    upsetPanel.appendChild(refPanel);
});
</script>
