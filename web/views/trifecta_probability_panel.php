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
$trifectaTotals = is_array($trifectaData['totals'] ?? null) ? $trifectaData['totals'] : [];
$trifectaBoatByCourse = is_array($trifectaData['boat_by_course'] ?? null)
    ? $trifectaData['boat_by_course']
    : [];

$trifectaCum = static function (array $rows, int $n): float {
    if ($n <= 0 || empty($rows)) {
        return 0.0;
    }
    return array_sum(array_map(
        static fn(array $r): float => (float)($r['probability'] ?? 0.0),
        array_slice($rows, 0, $n)
    ));
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

$renderTrifectaTable = static function (array $rows) use ($trifectaBoatBadge): void {
    ?>
    <div style="overflow-x:auto;">
        <table style="width:100%; min-width:720px; border-collapse:collapse;">
            <thead>
                <tr style="background-color:#e8dfd2; color:#2b3440;">
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
                <tr style="border-top:1px solid #d8cdbc;">
                    <td style="padding:7px 8px; text-align:center; color:#6b7785; font-weight:bold;">
                        <?= (int)($row['rank'] ?? 0) ?>
                    </td>
                    <td style="padding:7px 8px; white-space:nowrap;">
                        <?php if (count($boats) === 3): ?>
                            <?= $trifectaBoatBadge((int)$boats[0]) ?>
                            <span style="color:#8a8176; margin:0 4px;">-</span>
                            <?= $trifectaBoatBadge((int)$boats[1]) ?>
                            <span style="color:#8a8176; margin:0 4px;">-</span>
                            <?= $trifectaBoatBadge((int)$boats[2]) ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td style="padding:7px 8px; text-align:right; color:#6b7785;">
                        <?= number_format($base * 100.0, 3) ?>%
                    </td>
                    <td style="padding:7px 8px; text-align:right; font-size:16px; font-weight:bold; color:#aa741f;">
                        <?= number_format($final * 100.0, 3) ?>%
                    </td>
                    <td style="padding:7px 8px; text-align:right; color:<?= $delta >= 0 ? '#2f789f' : '#6b7785' ?>;">
                        <?= sprintf('%+.3fpt', $delta * 100.0) ?>
                    </td>
                    <td style="padding:7px 8px; text-align:right; color:#3f4b5a;">
                        <?= number_format($cum * 100.0, 2) ?>%
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
};

// イン1着時（1C頭）の2連単5通り。
$head1ExactaRows = [];
$head1BaseMass = 0.0;
$head1AiMass = 0.0;
$head1BaseBySecondCourse = array_fill(2, 5, 0.0);
$head1AiBySecondCourse = array_fill(2, 5, 0.0);

if ($trifectaStatus === 'ok' && count($trifectaRows) === 120) {
    foreach ($trifectaRows as $row) {
        $courses = is_array($row['courses'] ?? null) ? $row['courses'] : [];
        if (count($courses) !== 3 || (int)$courses[0] !== 1) {
            continue;
        }

        $secondCourse = (int)$courses[1];
        if ($secondCourse < 2 || $secondCourse > 6) {
            continue;
        }

        $baseP = (float)($row['base_probability'] ?? 0.0);
        $aiP = (float)($row['probability'] ?? 0.0);
        $head1BaseBySecondCourse[$secondCourse] += $baseP;
        $head1AiBySecondCourse[$secondCourse] += $aiP;
        $head1BaseMass += $baseP;
        $head1AiMass += $aiP;
    }

    $headBoat = (int)($trifectaBoatByCourse[1] ?? 1);
    for ($secondCourse = 2; $secondCourse <= 6; $secondCourse++) {
        $secondBoat = (int)($trifectaBoatByCourse[$secondCourse] ?? $secondCourse);
        $baseCond = $head1BaseMass > 0.0
            ? $head1BaseBySecondCourse[$secondCourse] / $head1BaseMass
            : 0.0;
        $aiCond = $head1AiMass > 0.0
            ? $head1AiBySecondCourse[$secondCourse] / $head1AiMass
            : 0.0;

        $head1ExactaRows[] = [
            'second_course' => $secondCourse,
            'head_boat' => $headBoat,
            'second_boat' => $secondBoat,
            'base' => $baseCond,
            'ai' => $aiCond,
            'delta' => $aiCond - $baseCond,
        ];
    }

    $ranked = $head1ExactaRows;
    usort($ranked, static function (array $a, array $b): int {
        $cmp = ($b['ai'] <=> $a['ai']);
        return $cmp !== 0 ? $cmp : ($a['second_course'] <=> $b['second_course']);
    });
    $rankByCourse = [];
    foreach ($ranked as $idx => $row) {
        $rankByCourse[(int)$row['second_course']] = $idx + 1;
    }
    foreach ($head1ExactaRows as &$row) {
        $row['ai_rank'] = (int)($rankByCourse[(int)$row['second_course']] ?? 0);
    }
    unset($row);
}
?>

<!-- メイン表示：イン1着時の2連単。DOM読込後に最終予想の買い目直下へ移動する。 -->
<div id="head1-exacta-panel" style="margin:0 0 10px; background-color:#f8f4ec; border:1px solid #d8cdbc; border-radius:8px; padding:14px; color:#3f4b5a;">
    <div style="margin-bottom:10px;">
        <div style="font-size:16px; font-weight:bold; color:#aa741f;">🎯 イン1着時 2連単</div>
        <div style="font-size:12px; color:#6b7785; margin-top:3px;">
            1コースが1着になった場合の2着分布 / 2C～6Cの5通りを100%化
        </div>
        <div style="font-size:12px; color:#6b7785; margin-top:2px;">
            場平均：VENUE_K3000 / AI予想：補正後1着率＋AI3連対率＋2着3着順序補正を反映
        </div>
        <div style="font-size:11px; color:#6b7785; margin-top:3px;">
            ※検証条件は「1Cが1着」。公式決まり手の「逃げ」だけに限定した値ではありません。
        </div>
        <?php if (!empty($simulation_active)): ?>
            <div style="font-size:12px; color:#aa741f; margin-top:3px;">
                ※仮想進入 <?= htmlspecialchars((string)($prediction_entry_order ?? '')) ?> を反映した試算値
            </div>
        <?php elseif (!empty($prediction_entry_changed)): ?>
            <div style="font-size:12px; color:#2f789f; margin-top:3px;">
                ※展示進入 <?= htmlspecialchars((string)($prediction_entry_order ?? '')) ?> を反映済み
            </div>
        <?php endif; ?>
    </div>

    <?php if (count($head1ExactaRows) === 5): ?>
        <div style="overflow-x:auto;">
            <table style="width:100%; min-width:620px; border-collapse:collapse;">
                <thead>
                    <tr style="background-color:#e8dfd2; color:#2b3440;">
                        <th style="padding:8px; text-align:left; min-width:190px;">2連単</th>
                        <th style="padding:8px; text-align:center; min-width:90px;">2着コース</th>
                        <th style="padding:8px; text-align:right; min-width:105px;">場平均</th>
                        <th style="padding:8px; text-align:right; min-width:105px;">AI予想</th>
                        <th style="padding:8px; text-align:right; min-width:100px;">差</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($head1ExactaRows as $row): ?>
                        <?php
                            $headBoat = (int)$row['head_boat'];
                            $secondBoat = (int)$row['second_boat'];
                            $base = (float)$row['base'];
                            $ai = (float)$row['ai'];
                            $delta = (float)$row['delta'];
                            $rank = (int)$row['ai_rank'];
                        ?>
                        <tr style="border-top:1px solid #d8cdbc;">
                            <td style="padding:8px; white-space:nowrap;">
                                <?= $trifectaBoatBadge($headBoat) ?>
                                <span style="color:#8a8176; margin:0 5px;">-</span>
                                <?= $trifectaBoatBadge($secondBoat) ?>
                                <?php if ($rank === 1): ?>
                                    <span style="margin-left:7px; font-size:11px; font-weight:bold; color:#aa741f;">AI 1位</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding:8px; text-align:center; color:#3f4b5a;">
                                1C→<?= (int)$row['second_course'] ?>C
                            </td>
                            <td style="padding:8px; text-align:right; color:#6b7785; font-weight:bold;">
                                <?= number_format($base * 100.0, 1) ?>%
                            </td>
                            <td style="padding:8px; text-align:right; font-size:18px; font-weight:bold; color:#aa741f;">
                                <?= number_format($ai * 100.0, 1) ?>%
                            </td>
                            <td style="padding:8px; text-align:right; font-weight:bold; color:<?= $delta >= 0 ? '#2f789f' : '#6b7785' ?>;">
                                <?= sprintf('%+.1fpt', $delta * 100.0) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="margin-top:8px; font-size:11px; color:#6b7785;">
            場平均5通り=100% / AI予想5通り=100% / P2ホールドアウトで場平均よりLogLoss・Brier・Top1/Top2/Top3改善を確認済み
        </div>
    <?php else: ?>
        <div style="padding:8px 10px; background-color:#f2ece2; border-radius:5px; color:#a33f32; font-size:13px;">
            イン1着時2連単：<?= htmlspecialchars($trifectaError !== '' ? $trifectaError : '計算待ち', ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>
</div>

<!-- 参考情報：完成済み3連単120通りは削除せず折りたたんで保持 -->
<details id="trifecta-reference-panel" style="margin:0 0 14px; background-color:#f8f4ec; border:1px solid #d8cdbc; border-radius:8px; overflow:hidden; color:#3f4b5a;">
    <summary style="cursor:pointer; padding:12px 14px; color:#3f4b5a; font-size:14px; font-weight:bold; background:#e8dfd2;">
        📚 参考情報：3連単120通り 出目確率
    </summary>
    <div style="padding:14px;">
        <div style="margin-bottom:10px;">
            <div style="font-size:16px; font-weight:bold; color:#aa741f;">🎲 出目確率</div>
            <div style="font-size:12px; color:#6b7785; margin-top:3px;">
                基礎：場×1着C-2着C-3着C / VENUE_K3000 → 補正後1着率 α=1.00 → AI3連対率 β=1.25
            </div>
            <div style="font-size:12px; color:#6b7785; margin-top:2px;">
                2着/3着順序：同一3艇のペア合計を維持し、trio δ=0.25 + win γ=0.25 で条件付き補正
            </div>
        </div>

        <?php if ($trifectaStatus === 'ok' && count($trifectaRows) === 120): ?>
            <div style="display:flex; gap:8px; flex-wrap:wrap; margin:0 0 10px;">
                <div style="background:#f2ece2; border:1px solid #d8cdbc; border-radius:5px; padding:6px 9px; font-size:12px; color:#3f4b5a;">
                    Top5累計 <strong><?= number_format($trifectaCum($trifectaRows, 5) * 100.0, 2) ?>%</strong>
                </div>
                <div style="background:#f2ece2; border:1px solid #d8cdbc; border-radius:5px; padding:6px 9px; font-size:12px; color:#3f4b5a;">
                    Top10累計 <strong><?= number_format($trifectaCum($trifectaRows, 10) * 100.0, 2) ?>%</strong>
                </div>
                <div style="background:#f2ece2; border:1px solid #d8cdbc; border-radius:5px; padding:6px 9px; font-size:12px; color:#3f4b5a;">
                    Top20累計 <strong><?= number_format($trifectaCum($trifectaRows, 20) * 100.0, 2) ?>%</strong>
                </div>
                <div style="background:#f2ece2; border:1px solid #d8cdbc; border-radius:5px; padding:6px 9px; font-size:12px; color:#6b7785;">
                    場履歴 <?= number_format((int)($trifectaHistory['venue_n'] ?? 0)) ?>R
                </div>
            </div>

            <?= $renderTrifectaTable($trifectaTop20) ?>

            <details style="margin-top:10px;">
                <summary style="cursor:pointer; color:#3f4b5a; font-size:13px; font-weight:bold;">
                    120通りすべて表示
                </summary>
                <div style="margin-top:8px;">
                    <?= $renderTrifectaTable($trifectaRows) ?>
                </div>
            </details>

            <div style="margin-top:8px; font-size:12px; color:#6b7785;">
                120通り合計 <?= number_format((float)($trifectaTotals['final'] ?? 0.0) * 100.0, 6) ?>%
                / P1選択 → P2完全ホールドアウト検証済み
                <?= !empty($simulation_active) ? ' / 仮想進入試算' : '' ?>
            </div>
        <?php else: ?>
            <div style="padding:8px 10px; background-color:#f2ece2; border-radius:5px; color:#a33f32; font-size:13px;">
                出目確率：<?= htmlspecialchars($trifectaError !== '' ? $trifectaError : '計算待ち', ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>
    </div>
</details>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const exactaPanel = document.getElementById('head1-exacta-panel');
    const referencePanel = document.getElementById('trifecta-reference-panel');
    const summaryBox = document.querySelector('.summary-box');
    if (!exactaPanel || !summaryBox) return;

    // 最終予想の買い目表 → 2連単 → 3連単参考情報、の順に表示だけ移動する。
    // 出目確率・最終予想・買い目の計算値には一切影響しない。
    summaryBox.insertAdjacentElement('afterend', exactaPanel);
    exactaPanel.style.marginTop = '14px';

    if (referencePanel) {
        exactaPanel.insertAdjacentElement('afterend', referencePanel);
        referencePanel.style.marginTop = '0';
    }
});
</script>