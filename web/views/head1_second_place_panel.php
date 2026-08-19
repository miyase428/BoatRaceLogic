<?php
require_once __DIR__ . '/../logic/Head1SecondPlaceLogic.php';

$head1SecondLogic = new Head1SecondPlaceLogic();
$head1SecondData = $head1SecondLogic->calculate(
    (string)($race_code ?? ''),
    is_array($prediction_course_by_boat ?? null) ? $prediction_course_by_boat : []
);

$head1SecondBoats = $head1SecondData['boats'] ?? [];
$head1SecondStatus = $head1SecondData['status'] ?? 'error';
$head1SecondError = $head1SecondData['error'] ?? '';
$head1SecondWarning = $head1SecondData['warning'] ?? '';
$head1SecondVenueN = (int)($head1SecondData['venue_n'] ?? 0);
$head1SecondGlobalN = (int)($head1SecondData['global_n'] ?? 0);
$head1SecondK = (float)($head1SecondData['k_pc'] ?? 10.0);

// 2着率の計算結果そのものから course -> boat を作り、
// 見出しと数値を同じ進入基準でコース順に表示する。
$head1SecondCourseToBoat = [];
foreach ($head1SecondBoats as $boatKey => $row) {
    $boat = (int)($row['lane'] ?? $boatKey);
    $course = (int)($row['course'] ?? $boat);
    if ($boat >= 1 && $boat <= 6 && $course >= 1 && $course <= 6) {
        $head1SecondCourseToBoat[$course] = $boat;
    }
}
for ($course = 1; $course <= 6; $course++) {
    if (!isset($head1SecondCourseToBoat[$course])) {
        $head1SecondCourseToBoat[$course] = $course;
    }
}
ksort($head1SecondCourseToBoat);
?>

<div style="margin: 0 0 14px; background-color:#0f172a; border:1px solid #334155; border-radius:8px; padding:14px;">
    <div style="margin-bottom:10px;">
        <div style="font-size:16px; font-weight:bold; color:#a78bfa;">🎯 1号艇1着時の2着率</div>
        <div style="font-size:12px; color:#94a3b8; margin-top:3px;">
            場2着率：<?= htmlspecialchars((string)($place_names[$selected_place] ?? $selected_place ?? '')) ?>で1号艇が1着したときの実コース別2着率（比較表示のみ）
        </div>
        <div style="font-size:12px; color:#94a3b8; margin-top:2px;">
            基本2着率：全場コース別p0 → 選手×実コース（直前100走） / K=<?= number_format($head1SecondK, 0) ?> → 2～6号艇100%正規化
        </div>
        <?php if (!empty($simulation_active)): ?>
            <div style="font-size:12px; color:#93c5fd; margin-top:3px;">
                ※現在は仮想進入 <?= htmlspecialchars((string)($prediction_entry_order ?? '123456')) ?> 基準で計算
            </div>
        <?php endif; ?>
    </div>

    <?php if ($head1SecondStatus === 'ok' && !empty($head1SecondBoats)): ?>
        <div style="overflow-x:auto;">
            <table style="width:100%; min-width:760px; border-collapse:collapse;">
                <thead>
                    <tr style="background-color:#1e293b;">
                        <th style="padding:8px; text-align:left; min-width:130px;">項目 / 進入</th>
                        <?php for ($course = 1; $course <= 6; $course++): ?>
                            <?php
                                $boat = (int)($head1SecondCourseToBoat[$course] ?? $course);
                                $c = $lane_colors[$boat] ?? $lane_colors[1];
                            ?>
                            <th style="padding:8px; text-align:center; min-width:95px;">
                                <div style="font-weight:bold; color:#f8fafc; white-space:nowrap;">
                                    <?= $course ?>コース
                                </div>
                                <div style="margin-top:4px;">
                                    <span class="lane-badge"
                                          style="background-color:<?= $c['bg'] ?>; color:<?= $c['text'] ?>; border:1px solid <?= $c['border'] ?>; display:inline-block; min-width:54px; padding:2px 7px; border-radius:4px; box-sizing:border-box; white-space:nowrap; text-align:center; font-weight:bold;">
                                        <?= $boat ?>号艇
                                    </span>
                                </div>
                            </th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="padding:10px 8px; font-weight:bold; color:#f8fafc;">場2着率</td>
                        <?php for ($course = 1; $course <= 6; $course++): ?>
                            <?php
                                $boat = (int)($head1SecondCourseToBoat[$course] ?? $course);
                                $rate = $head1SecondBoats[$boat]['venue_rate'] ?? null;
                            ?>
                            <td style="padding:10px 8px; text-align:center; font-size:16px; font-weight:bold; color:#cbd5e1;">
                                <?= $boat === 1 ? '-' : ($rate !== null ? number_format((float)$rate, 2) . '%' : '-') ?>
                            </td>
                        <?php endfor; ?>
                    </tr>
                    <tr style="border-top:1px solid #334155;">
                        <td style="padding:10px 8px; font-weight:bold; color:#f8fafc;">基本2着率</td>
                        <?php for ($course = 1; $course <= 6; $course++): ?>
                            <?php
                                $boat = (int)($head1SecondCourseToBoat[$course] ?? $course);
                                $rate = $head1SecondBoats[$boat]['basic_rate'] ?? null;
                            ?>
                            <td style="padding:10px 8px; text-align:center; font-size:18px; font-weight:bold; color:#a78bfa;">
                                <?= $boat === 1 ? '-' : ($rate !== null ? number_format((float)$rate, 2) . '%' : '-') ?>
                            </td>
                        <?php endfor; ?>
                    </tr>
                </tbody>
            </table>
        </div>

        <div style="margin-top:8px; font-size:12px; color:#94a3b8;">
            場母数 <?= number_format($head1SecondVenueN) ?>R / 全場p0母数 <?= number_format($head1SecondGlobalN) ?>R
            / 基本2着率は2～6号艇合計100%
        </div>

        <?php if ($head1SecondVenueN <= 0): ?>
            <div style="margin-top:5px; font-size:12px; color:#fca5a5;">
                場2着率：対象場の履歴がないため表示できません
            </div>
        <?php endif; ?>

        <?php if ($head1SecondWarning !== ''): ?>
            <div style="margin-top:5px; font-size:12px; color:#fbbf24;">
                <?= htmlspecialchars($head1SecondWarning) ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div style="padding:8px 10px; background-color:#1e293b; border-radius:5px; color:#fca5a5; font-size:13px;">
            2着率を取得できませんでした<?= $head1SecondError !== '' ? '：' . htmlspecialchars($head1SecondError) : '' ?>
        </div>
    <?php endif; ?>
</div>
