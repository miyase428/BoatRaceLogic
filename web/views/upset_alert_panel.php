<?php
// STEP C2/C3で検証した「穴警戒HIGH + TRIO_OUTER」を表示専用で再現する。
// 最終予想・本命/対抗・cut・買い目ロジックには接続しない。
$upsetAlertStatus = 'waiting';
$upsetAlertHigh = false;
$upsetAlertError = '';
$upsetInBoat = 0;
$upsetAiHead = 0;
$upsetCurrentHead = (int)($honmei_head ?? 0);
$upsetHoleHead = 0;
$upsetInWinRate = null;
$upsetHoleTrioRate = null;
$upsetHoleCourse = 0;

$upsetCourseByBoat = [];
foreach (is_array($aiTrioBoats ?? null) ? $aiTrioBoats : [] as $boatKey => $row) {
    $boat = (int)($row['lane'] ?? $boatKey);
    $course = (int)($row['course'] ?? 0);
    if ($boat >= 1 && $boat <= 6 && $course >= 1 && $course <= 6) {
        $upsetCourseByBoat[$boat] = $course;
        if ($course === 1) {
            $upsetInBoat = $boat;
        }
    }
}

$upsetCorrectedRates = [];
foreach (range(1, 6) as $boat) {
    $row = $correctedWinBoats[(string)$boat] ?? $correctedWinBoats[$boat] ?? null;
    if (is_array($row) && isset($row['corrected_rate']) && is_numeric($row['corrected_rate'])) {
        $upsetCorrectedRates[$boat] = (float)$row['corrected_rate'];
    }
}

if (
    ($correctedWinStatus ?? '') === 'ok'
    && ($aiTrioStatus ?? '') === 'ok'
    && count($upsetCorrectedRates) === 6
    && count($upsetCourseByBoat) === 6
    && $upsetInBoat >= 1
    && $upsetInBoat <= 6
    && $upsetCurrentHead >= 1
    && $upsetCurrentHead <= 6
) {
    // C2/C3のPythonと同じく、同値時は艇番の小さい方を優先する。
    $winRankedBoats = range(1, 6);
    usort($winRankedBoats, static function (int $a, int $b) use ($upsetCorrectedRates): int {
        $cmp = ($upsetCorrectedRates[$b] <=> $upsetCorrectedRates[$a]);
        return $cmp !== 0 ? $cmp : ($a <=> $b);
    });
    $upsetAiHead = (int)($winRankedBoats[0] ?? 0);
    $upsetInWinRate = $upsetCorrectedRates[$upsetInBoat] ?? null;

    $outerBoats = array_values(array_filter(
        range(1, 6),
        static fn(int $boat): bool => $boat !== $upsetInBoat
    ));
    usort($outerBoats, static function (int $a, int $b) use ($aiTrioBoats): int {
        $aRate = (float)($aiTrioBoats[$a]['ai_rate'] ?? 0.0);
        $bRate = (float)($aiTrioBoats[$b]['ai_rate'] ?? 0.0);
        $cmp = ($bRate <=> $aRate);
        return $cmp !== 0 ? $cmp : ($a <=> $b);
    });

    $upsetHoleHead = (int)($outerBoats[0] ?? 0);
    if ($upsetHoleHead >= 1 && $upsetHoleHead <= 6) {
        $upsetHoleTrioRate = isset($aiTrioBoats[$upsetHoleHead]['ai_rate'])
            ? (float)$aiTrioBoats[$upsetHoleHead]['ai_rate']
            : null;
        $upsetHoleCourse = (int)($upsetCourseByBoat[$upsetHoleHead] ?? 0);
    }

    $upsetAlertHigh = (
        $upsetAiHead === $upsetInBoat
        && $upsetCurrentHead !== $upsetInBoat
        && $upsetInWinRate !== null
        && $upsetInWinRate < 50.0
    );
    $upsetAlertStatus = 'ok';
} else {
    $upsetAlertError = '補正後1着率・AI3連対率・進入・最終予想がそろうと判定します';
}

$upsetBoatBadge = static function (int $boat) use ($lane_colors): string {
    $c = $lane_colors[$boat] ?? $lane_colors[1];
    return '<span class="lane-badge" style="background-color:'
        . htmlspecialchars((string)$c['bg'], ENT_QUOTES, 'UTF-8')
        . ';color:'
        . htmlspecialchars((string)$c['text'], ENT_QUOTES, 'UTF-8')
        . ';border:1px solid '
        . htmlspecialchars((string)$c['border'], ENT_QUOTES, 'UTF-8')
        . ';display:inline-block;min-width:58px;width:auto;height:auto;line-height:1.4;padding:3px 8px;border-radius:5px;box-sizing:border-box;white-space:nowrap;text-align:center;font-weight:bold;">'
        . $boat . '号艇</span>';
};
?>

<div id="upset-alert-panel" style="margin:0 0 10px; background:#f8f4ec; border:1px solid <?= $upsetAlertHigh ? '#d9a74f' : '#d8cdbc' ?>; border-radius:8px; padding:<?= $upsetAlertHigh ? '14px' : '10px 14px' ?>; color:#3f4b5a;">
    <?php if ($upsetAlertStatus === 'ok' && $upsetAlertHigh): ?>
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
            <div>
                <div style="font-size:17px; font-weight:bold; color:#aa741f;">⚠ 穴警戒：高</div>
                <div style="font-size:12px; color:#6b7785; margin-top:3px;">
                    C2固定条件：AI本命=1C / 現行本命≠1C / イン補正後1着率&lt;50%
                </div>
            </div>
            <div style="font-size:11px; color:#6b7785; white-space:nowrap;">表示専用 / 買い目へ未接続</div>
        </div>

        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:11px;">
            <div style="flex:1 1 180px; background:#f2ece2; border:1px solid #d8cdbc; border-radius:6px; padding:9px 10px;">
                <div style="font-size:11px; color:#6b7785;">AI本命（補正後1着率）</div>
                <div style="margin-top:5px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <?= $upsetBoatBadge($upsetAiHead) ?>
                    <strong><?= (int)($upsetCourseByBoat[$upsetAiHead] ?? 0) ?>C</strong>
                    <strong style="color:#aa741f;"><?= number_format((float)$upsetInWinRate, 1) ?>%</strong>
                </div>
            </div>

            <div style="flex:1 1 180px; background:#f2ece2; border:1px solid #d8cdbc; border-radius:6px; padding:9px 10px;">
                <div style="font-size:11px; color:#6b7785;">現行本命</div>
                <div style="margin-top:5px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <?= $upsetBoatBadge($upsetCurrentHead) ?>
                    <strong><?= (int)($upsetCourseByBoat[$upsetCurrentHead] ?? 0) ?>C</strong>
                </div>
            </div>

            <div style="flex:1 1 210px; background:#f4ead7; border:1px solid #d9a74f; border-radius:6px; padding:9px 10px;">
                <div style="font-size:11px; color:#7b6640;">穴頭候補（TRIO_OUTER）</div>
                <div style="margin-top:5px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <?= $upsetBoatBadge($upsetHoleHead) ?>
                    <strong><?= $upsetHoleCourse ?>C</strong>
                    <?php if ($upsetHoleTrioRate !== null): ?>
                        <strong style="color:#75659b;">AI3連対 <?= number_format($upsetHoleTrioRate, 1) ?>%</strong>
                    <?php endif; ?>
                </div>
                <?php if ($upsetHoleHead === $upsetCurrentHead): ?>
                    <div style="font-size:11px; color:#7b6640; margin-top:4px;">現行本命と穴候補が一致</div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($simulation_active)): ?>
            <div style="font-size:11px; color:#aa741f; margin-top:8px;">
                ※仮想進入での試算表示。C2/C3の検証は実展示進入基準です。
            </div>
        <?php endif; ?>
    <?php elseif ($upsetAlertStatus === 'ok'): ?>
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
            <div>
                <span style="font-size:14px; font-weight:bold; color:#5f6f5d;">🛡 穴警戒：通常</span>
                <span style="font-size:11px; color:#6b7785; margin-left:8px;">C2のHIGH条件には該当しません</span>
            </div>
            <div style="font-size:11px; color:#6b7785;">表示専用</div>
        </div>
    <?php else: ?>
        <div style="font-size:13px; color:#6b7785;">
            🛡 穴警戒：判定待ち
            <span style="font-size:11px; margin-left:6px;"><?= htmlspecialchars($upsetAlertError, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const panel = document.getElementById('upset-alert-panel');
    const summaryBox = document.querySelector('.summary-box');
    if (!panel || !summaryBox) return;

    // 最終予想の買い目表の直後へ表示だけ移動する。
    // trifecta_probability_panel.php の移動処理より後に登録されるため、
    // 最終表示順は「買い目 → 穴警戒 → 2連単 → 3連単参考情報」となる。
    summaryBox.insertAdjacentElement('afterend', panel);
    panel.style.marginTop = '14px';
});
</script>
