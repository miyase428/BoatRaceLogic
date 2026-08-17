<?php
$baseWinBoats = $base_win_rate_data['boats'] ?? [];
$baseWinError = $base_win_rate_data['error'] ?? '';
$baseWinRawTotal = (float)($base_win_rate_data['raw_total'] ?? 0.0);
?>

<div style="margin: 18px 0 14px; background-color: #0f172a; border: 1px solid #334155; border-radius: 8px; padding: 14px;">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:10px;">
        <div>
            <div style="font-size:16px; font-weight:bold; color:#38bdf8;">🎯 基本1着率（展示前）</div>
            <div style="font-size:12px; color:#94a3b8; margin-top:3px;">
                場×コース → 選手×コース → 選手×場×コース / BB_MEDIUM K=20・10 / 6艇合計100%正規化
            </div>
        </div>
        <?php if (!empty($baseWinBoats)): ?>
            <div style="font-size:12px; color:#94a3b8; white-space:nowrap;">
                正規化前 <?= number_format($baseWinRawTotal * 100, 2) ?>%
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($baseWinBoats)): ?>
        <div style="overflow-x:auto;">
            <table style="width:100%; min-width:760px; border-collapse:collapse;">
                <thead>
                    <tr style="background-color:#1e293b;">
                        <th style="padding:8px; text-align:left; min-width:130px;">項目 / 艇番</th>
                        <?php for ($i = 1; $i <= 6; $i++): ?>
                            <?php $c = $lane_colors[$i] ?? $lane_colors[1]; ?>
                            <th style="padding:8px; text-align:center; min-width:95px;">
                                <span class="lane-badge"
                                      style="background-color:<?= $c['bg'] ?>; color:<?= $c['text'] ?>; border:1px solid <?= $c['border'] ?>; padding:2px 8px; border-radius:4px;">
                                    <?= $i ?>号艇
                                </span>
                            </th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="padding:10px 8px; font-weight:bold; color:#f8fafc;">基本1着率</td>
                        <?php for ($i = 1; $i <= 6; $i++): ?>
                            <?php $rate = $baseWinBoats[$i]['normalized_rate'] ?? null; ?>
                            <td style="padding:10px 8px; text-align:center; font-size:18px; font-weight:bold; color:#38bdf8;">
                                <?= $rate !== null ? number_format((float)$rate, 2) . '%' : '-' ?>
                            </td>
                        <?php endfor; ?>
                    </tr>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div style="padding:8px 10px; background-color:#1e293b; border-radius:5px; color:#fca5a5; font-size:13px;">
            基本1着率を取得できませんでした<?= $baseWinError !== '' ? '：' . htmlspecialchars($baseWinError) : '' ?>
        </div>
    <?php endif; ?>
</div>
