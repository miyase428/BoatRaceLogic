<?php
require_once __DIR__ . '/../logic/AiTrioRateLogic.php';

$aiTrioLogic = new AiTrioRateLogic();
$aiTrioData = $aiTrioLogic->calculate(
    (string)($race_code ?? ''),
    is_array($results ?? null) ? $results : [],
    is_array($tenji_list ?? null) ? $tenji_list : []
);

$aiTrioStatus = (string)($aiTrioData['status'] ?? 'error');
$aiTrioError = (string)($aiTrioData['error'] ?? '');
$aiTrioBoats = is_array($aiTrioData['boats'] ?? null) ? $aiTrioData['boats'] : [];
$aiTrioTotals = is_array($aiTrioData['totals'] ?? null) ? $aiTrioData['totals'] : [];

// 1着率・2着率と同じく、画面は予想進入のコース順（1C→6C）で並べる。
// AI3連対率の計算自体は検証条件どおり艇番（枠番=今回コース）基準のまま変更しない。
$aiTrioCourseToBoat = [];
if (is_array($prediction_boat_by_course ?? null) && count($prediction_boat_by_course) === 6) {
    for ($course = 1; $course <= 6; $course++) {
        $boat = (int)($prediction_boat_by_course[$course] ?? 0);
        if ($boat >= 1 && $boat <= 6) {
            $aiTrioCourseToBoat[$course] = $boat;
        }
    }
}

if (count($aiTrioCourseToBoat) !== 6) {
    $aiTrioCourseToBoat = array_combine(range(1, 6), range(1, 6));
}
?>

<div style="margin: 0 0 14px; background-color:#0f172a; border:1px solid #334155; border-radius:8px; padding:14px;">
    <div style="margin-bottom:10px;">
        <div style="font-size:16px; font-weight:bold; color:#a78bfa;">🤖 AI3連対率</div>
        <div style="font-size:12px; color:#94a3b8; margin-top:3px;">
            基礎3連対率：場×コース → 選手×コース → 選手×場×コース / BB_MEDIUM RAW（K=20・10）
        </div>
        <div style="font-size:12px; color:#94a3b8; margin-top:2px;">
            AI：基礎3連対率 + 一次評価Z + 二次評価Z / P1学習 → P2完全ホールドアウト検証済み
        </div>
        <div style="font-size:12px; color:#94a3b8; margin-top:2px;">
            ※6艇300%への強制正規化なし / SUM・スリットは追加効果が小さいため未採用
        </div>
        <?php if (!empty($prediction_entry_changed)): ?>
            <div style="font-size:12px; color:#aa741f; margin-top:3px;">
                ※表示は予想進入のコース順。AI3連対率の計算は検証条件に合わせ「枠番=今回コース」のまま（進入補正は未適用）
            </div>
        <?php endif; ?>
    </div>

    <?php if ($aiTrioStatus === 'ok' && count($aiTrioBoats) === 6): ?>
        <div style="overflow-x:auto;">
            <table style="width:100%; min-width:760px; border-collapse:collapse;">
                <thead>
                    <tr style="background-color:#1e293b;">
                        <th style="padding:8px; text-align:left; min-width:130px;">項目 / 進入</th>
                        <?php for ($course = 1; $course <= 6; $course++): ?>
                            <?php
                                $boat = (int)($aiTrioCourseToBoat[$course] ?? $course);
                                $c = $lane_colors[$boat] ?? $lane_colors[1];
                            ?>
                            <th style="padding:8px; text-align:center; min-width:95px;">
                                <div style="font-weight:bold; color:#f8fafc; white-space:nowrap;">
                                    <?= $course ?>コース
                                </div>
                                <div style="margin-top:4px;">
                                    <span class="lane-badge"
                                          style="background-color:<?= htmlspecialchars((string)$c['bg']) ?>; color:<?= htmlspecialchars((string)$c['text']) ?>; border:1px solid <?= htmlspecialchars((string)$c['border']) ?>; display:inline-block; min-width:58px; width:auto; height:auto; line-height:1.4; padding:3px 8px; border-radius:5px; box-sizing:border-box; white-space:nowrap; text-align:center; font-weight:bold;">
                                        <?= $boat ?>号艇
                                    </span>
                                </div>
                            </th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="padding:10px 8px; font-weight:bold; color:#f8fafc;">基礎3連対率</td>
                        <?php for ($course = 1; $course <= 6; $course++): ?>
                            <?php
                                $boat = (int)($aiTrioCourseToBoat[$course] ?? $course);
                                $rate = $aiTrioBoats[$boat]['base_rate'] ?? null;
                            ?>
                            <td style="padding:10px 8px; text-align:center; font-size:16px; font-weight:bold; color:#2f789f;">
                                <?= $rate !== null ? number_format((float)$rate, 2) . '%' : '-' ?>
                            </td>
                        <?php endfor; ?>
                    </tr>
                    <tr style="border-top:1px solid #334155;">
                        <td style="padding:10px 8px; font-weight:bold; color:#f8fafc;">AI3連対率</td>
                        <?php for ($course = 1; $course <= 6; $course++): ?>
                            <?php
                                $boat = (int)($aiTrioCourseToBoat[$course] ?? $course);
                                $row = $aiTrioBoats[$boat] ?? [];
                                $rate = $row['ai_rate'] ?? null;
                                $rank = (int)($row['ai_rank'] ?? 0);
                                $tip = sprintf(
                                    '%d号艇 / 一次 %.3f (Z %+.3f) / 二次 %.3f (Z %+.3f)',
                                    $boat,
                                    (float)($row['primary_score'] ?? 0),
                                    (float)($row['primary_z'] ?? 0),
                                    (float)($row['secondary_score'] ?? 0),
                                    (float)($row['secondary_z'] ?? 0)
                                );
                            ?>
                            <td style="padding:10px 8px; text-align:center;" title="<?= htmlspecialchars($tip, ENT_QUOTES, 'UTF-8') ?>">
                                <div style="font-size:19px; font-weight:bold; color:#75659b;">
                                    <?= $rate !== null ? number_format((float)$rate, 2) . '%' : '-' ?>
                                </div>
                                <?php if ($rank >= 1 && $rank <= 6): ?>
                                    <div style="margin-top:2px; font-size:11px; color:#6b7785;">AI <?= $rank ?>位</div>
                                <?php endif; ?>
                            </td>
                        <?php endfor; ?>
                    </tr>
                </tbody>
            </table>
        </div>

        <div style="margin-top:8px; font-size:12px; color:#94a3b8;">
            基礎6艇合計 <?= number_format((float)($aiTrioTotals['base'] ?? 0), 2) ?>%
            / AI6艇合計 <?= number_format((float)($aiTrioTotals['ai'] ?? 0), 2) ?>%
            / 本番係数はP1学習値を固定
        </div>
    <?php else: ?>
        <div style="padding:8px 10px; background-color:#1e293b; border-radius:5px; color:#fca5a5; font-size:13px;">
            AI3連対率：<?= htmlspecialchars($aiTrioError !== '' ? $aiTrioError : '計算待ち', ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>
</div>
