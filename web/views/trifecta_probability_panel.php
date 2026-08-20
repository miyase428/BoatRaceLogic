<?php
require_once __DIR__ . '/../logic/TrifectaProbabilityLogic.php';

$outcomeCourseByBoat = [];
if (is_array($aiTrioBoats ?? null) && count($aiTrioBoats) === 6) {
    foreach ($aiTrioBoats as $boatKey => $row) {
        $boat = (int)($row['lane'] ?? $boatKey);
        $course = (int)($row['course'] ?? 0);
        if ($boat >= 1 && $boat <= 6 && $course >= 1 && $course <= 6) {
            $outcomeCourseByBoat[$boat] = $course;
        }
    }
}

if (count($outcomeCourseByBoat) !== 6 && is_array($prediction_course_by_boat ?? null)) {
    for ($boat = 1; $boat <= 6; $boat++) {
        $course = (int)($prediction_course_by_boat[$boat] ?? 0);
        if ($course >= 1 && $course <= 6) {
            $outcomeCourseByBoat[$boat] = $course;
        }
    }
}

$trifectaLogic = new TrifectaProbabilityLogic();
$trifectaData = $trifectaLogic->calculate(
    (string)($race_code ?? ''),
    is_array($correctedWinBoats ?? null) ? $correctedWinBoats : [],
    is_array($aiTrioBoats ?? null) ? $aiTrioBoats : [],
    $outcomeCourseByBoat
);

$trifectaStatus = (string)($trifectaData['status'] ?? 'error');
$trifectaError = (string)($trifectaData['error'] ?? '');
$trifectaRows = is_array($trifectaData['rows'] ?? null) ? $trifectaData['rows'] : [];
$trifectaTop20 = is_array($trifectaData['top20'] ?? null) ? $trifectaData['top20'] : [];
$trifectaHistory = is_array($trifectaData['history'] ?? null) ? $trifectaData['history'] : [];
$trifectaMethod = is_array($trifectaData['method'] ?? null) ? $trifectaData['method'] : [];
$trifectaTotals = is_array($trifectaData['totals'] ?? null) ? $trifectaData['totals'] : [];

$trifectaCum = static function (array $rows, int $n): float {
    if ($n <= 0 || empty($rows)) {
        return 0.0;
    }
    $slice = array_slice($rows, 0, $n);
    return array_sum(array_map(static fn(array $r): float => (float)($r['probability'] ?? 0.0), $slice));
};

$trifectaBoatBadge = static function (int $boat) use ($lane_colors): string {
    $c = $lane_colors[$boat] ?? $lane_colors[1];
    return '<span class="lane-badge" style="background-color:'
        . htmlspecialchars((string)$c['bg'], ENT_QUOTES, 'UTF-8')
        . ';color:'
        . htmlspecialchars((string)$c['text'], ENT_QUOTES, 'UTF-8')
        . ';border:1px solid '
        . htmlspecialchars((string)$c['border'], ENT_QUOTES, 'UTF-8')
        . ';display:inline-block;min-width:42px;width:auto;height:auto;line-height:1.35;padding:2px 6px;border-radius:4px;box-sizing:border-box;white-space:nowrap;text-align:center;font-weight:bold;font-size:12px;">'
        . $boat . '号艇</span>';
};

$renderTrifectaTable = static function (array $rows, bool $full = false) use ($trifectaBoatBadge): void {
    ?>
    <div style="overflow-x:auto;">
        <table style="width:100%; min-width:720px; border-collapse:collapse;">
            <thead>
                <tr style="background-color:#1e293b;">
                    <th style="padding:7px 8px; text-align:center; width:54px;">順位</th>
                    <th style="padding:7px 8px; text-align:left; min-width:210px;">3連単</th>
                    <th style="padding:7px 8px; text-align:right; min-width:105px;">基礎出目</th>
                    <th style="padding:7px 8px; text-align:right; min-width:105px;">最終出目確率</th>
                    <th style="padding:7px 8px; text-align:right; min-width:105px;">基礎差</th>
                    <th style="padding:7px 8px; text-align:right; min-width:105px;">累計</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                    $boats = is_array($row['boats'] ?? null) ? $row['boats'] : [];
                    $base = (float)($row['base_probability'] ?? 0.0);
                    $final = (float)($row['probability'] ?? 0.0);
                    $delta = $final - $base;
                    $cum = (float)($row['cumulative_probability'] ?? 0.0);
                ?>
                <tr style="border-top:1px solid #334155;">
                    <td style="padding:7px 8px; text-align:center; color:#94a3b8; font-weight:bold;">
                        <?= (int)($row['rank'] ?? 0) ?>
                    </td>
                    <td style="padding:7px 8px; white-space:nowrap;">
                        <?php if (count($boats) === 3): ?>
                            <?= $trifectaBoatBadge((int)$boats[0]) ?>
                            <span style="color:#64748b; margin:0 4px;">-</span>
                            <?= $trifectaBoatBadge((int)$boats[1]) ?>
                            <span style="color:#64748b; margin:0 4px;">-</span>
                            <?= $trifectaBoatBadge((int)$boats[2]) ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td style="padding:7px 8px; text-align:right; color:#94a3b8;">
                        <?= number_format($base * 100.0, 3) ?>%
                    </td>
                    <td style="padding:7px 8px; text-align:right; font-size:16px; font-weight:bold; color:#aa741f;">
                        <?= number_format($final * 100.0, 3) ?>%
                    </td>
                    <td style="padding:7px 8px; text-align:right; color:<?= $delta >= 0 ? '#2f789f' : '#94a3b8' ?>;">
                        <?= sprintf('%+.3fpt', $delta * 100.0) ?>
                    </td>
                    <td style="padding:7px 8px; text-align:right; color:#cbd5e1;">
                        <?= number_format($cum * 100.0, 2) ?>%
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
};
?>

<div style="margin: 0 0 14px; background-color:#0f172a; border:1px solid #334155; border-radius:8px; padding:14px;">
    <div style="margin-bottom:10px;">
        <div style="font-size:16px; font-weight:bold; color:#aa741f;">🎲 出目確率</div>
        <div style="font-size:12px; color:#94a3b8; margin-top:3px;">
            基礎：場×1着C-2着C-3着C / VENUE_K3000 → 補正後1着率 α=1.00 → AI3連対率 β=1.25
        </div>
        <div style="font-size:12px; color:#94a3b8; margin-top:2px;">
            2着/3着順序：同一3艇のペア合計を維持し、trio δ=0.25 + win γ=0.25 で条件付き補正
        </div>
        <?php if (!empty($simulation_active)): ?>
            <div style="font-size:12px; color:#aa741f; margin-top:3px;">
                ※仮想進入 <?= htmlspecialchars((string)($prediction_entry_order ?? '')) ?> を1着率・AI3連対率・出目確率へ反映した試算値
            </div>
        <?php elseif (!empty($prediction_entry_changed)): ?>
            <div style="font-size:12px; color:#2f789f; margin-top:3px;">
                ※展示進入 <?= htmlspecialchars((string)($prediction_entry_order ?? '')) ?> を出目確率へ反映済み
            </div>
        <?php endif; ?>
    </div>

    <?php if ($trifectaStatus === 'ok' && count($trifectaRows) === 120): ?>
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin:0 0 10px;">
            <div style="background:#1e293b; border-radius:5px; padding:6px 9px; font-size:12px; color:#cbd5e1;">
                Top5累計 <strong style="color:#f8fafc;"><?= number_format($trifectaCum($trifectaRows, 5) * 100.0, 2) ?>%</strong>
            </div>
            <div style="background:#1e293b; border-radius:5px; padding:6px 9px; font-size:12px; color:#cbd5e1;">
                Top10累計 <strong style="color:#f8fafc;"><?= number_format($trifectaCum($trifectaRows, 10) * 100.0, 2) ?>%</strong>
            </div>
            <div style="background:#1e293b; border-radius:5px; padding:6px 9px; font-size:12px; color:#cbd5e1;">
                Top20累計 <strong style="color:#f8fafc;"><?= number_format($trifectaCum($trifectaRows, 20) * 100.0, 2) ?>%</strong>
            </div>
            <div style="background:#1e293b; border-radius:5px; padding:6px 9px; font-size:12px; color:#94a3b8;">
                場履歴 <?= number_format((int)($trifectaHistory['venue_n'] ?? 0)) ?>R
            </div>
        </div>

        <?= $renderTrifectaTable($trifectaTop20) ?>

        <details style="margin-top:10px;">
            <summary style="cursor:pointer; color:#cbd5e1; font-size:13px; font-weight:bold;">
                120通りすべて表示
            </summary>
            <div style="margin-top:8px;">
                <?= $renderTrifectaTable($trifectaRows, true) ?>
            </div>
        </details>

        <div style="margin-top:8px; font-size:12px; color:#94a3b8;">
            120通り合計 <?= number_format((float)($trifectaTotals['final'] ?? 0.0) * 100.0, 6) ?>%
            / P1選択 → P2完全ホールドアウト検証済み
            <?= !empty($simulation_active) ? ' / 仮想進入試算' : '' ?>
        </div>
    <?php else: ?>
        <div style="padding:8px 10px; background-color:#1e293b; border-radius:5px; color:#fca5a5; font-size:13px;">
            出目確率：<?= htmlspecialchars($trifectaError !== '' ? $trifectaError : '計算待ち', ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>
</div>
