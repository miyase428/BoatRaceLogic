<?php
require_once __DIR__ . '/../logic/SecondPlaceProbabilityLogic.php';

// 120通りの計算・表示ヘルパーだけを共通ランタイムで準備する。
// 旧 trifecta_probability_panel.php の2着集計は実行しない。
include __DIR__ . '/trifecta_probability_runtime.php';

$head1SecondLogic = new SecondPlaceProbabilityLogic();
$head1SecondData = $head1SecondLogic->calculate(
    is_array($trifectaData ?? null) ? $trifectaData : [],
    1
);

$head1ExactaRows = (
    (string)($head1SecondData['status'] ?? '') === 'ok'
    && is_array($head1SecondData['rows'] ?? null)
)
    ? $head1SecondData['rows']
    : [];

$head1SecondError = (string)($head1SecondData['error'] ?? '');
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
                            $headBoat = (int)($row['head_boat'] ?? 0);
                            $secondBoat = (int)($row['second_boat'] ?? 0);
                            $base = (float)($row['base'] ?? 0.0);
                            $ai = (float)($row['ai'] ?? 0.0);
                            $delta = (float)($row['delta'] ?? 0.0);
                            $rank = (int)($row['ai_rank'] ?? 0);
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
                                1C→<?= (int)($row['second_course'] ?? 0) ?>C
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
            場平均5通り=100% / AI予想5通り=100% / 共通2着確率エンジン③ AI_FINALを使用
        </div>
    <?php else: ?>
        <div style="padding:8px 10px; background-color:#f2ece2; border-radius:5px; color:#a33f32; font-size:13px;">
            イン1着時2連単：<?= htmlspecialchars(
                $head1SecondError !== ''
                    ? $head1SecondError
                    : (($trifectaError ?? '') !== '' ? (string)$trifectaError : '計算待ち'),
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </div>
    <?php endif; ?>
</div>

<?php
// 120通り参考表示は同じ $trifectaData をそのまま使う。
include __DIR__ . '/trifecta_probability_reference.php';
