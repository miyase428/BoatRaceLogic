<?php
// STEP C2/C2-2/C3/E1 + 穴目追加検証で採用した表示専用レイヤー。
// 最終予想・本命/対抗・cut・買い目ロジックには接続しない。
//
// イン飛び警報（AI本命=1C かつ 現行本命!=1C のとき）
//   非常に高: イン補正後1着率 < 40%
//   高      : 40% <= イン補正後1着率 < 50%
//   注意    : 50% <= イン補正後1着率 < 55%
//   通常    : 上記以外
//
// 穴頭本命/対抗（<50%のみ）
//   穴頭本命: イン以外のAI3連対率1位（TRIO1 / TRIO_OUTER）
//   穴頭対抗: イン以外のAI3連対率2位（TRIO2）
//
// 穴頭信頼度（C5/C6）
//   強: TRIO1=CURRENT かつ TRIO1-TRIO2差>=10pt
//   弱: TRIO1-TRIO2差<2pt
//   中: 上記以外
//
// ①残り（TRAIN + 2026-08-15～08-22完全未来で再現）
//   低: インAI3連対率 < 60%
//   中: 60% <= インAI3連対率 < 70%
//   高: 70%以上
// ※「インが1着を逃した時に2・3着へ残るか」の表示用分類。
//
// 展開候補（TRAIN + 完全未来で再現）
//   3～5Cの6month point-in-time「まくり+まくり差し」が最大のコース。
//   sample_n>=10のみ。技は同コース内で率の高い方を表示する。

$upsetAlertStatus = 'waiting';
$upsetAlertLevel = 'normal';
$upsetAlertHigh = false;
$upsetAlertError = '';
$upsetInBoat = 0;
$upsetAiHead = 0;
$upsetCurrentHead = (int)($honmei_head ?? 0);
$upsetHoleHead = 0;
$upsetHoleSecondHead = 0;
$upsetInWinRate = null;
$upsetInTrioRate = null;
$upsetInRemainLevel = '';
$upsetHoleTrioRate = null;
$upsetHoleSecondTrioRate = null;
$upsetHoleTrioGap = null;
$upsetHoleCourse = 0;
$upsetHoleSecondCourse = 0;
$upsetHeadConfidence = '';
$upsetBaseDisagree = false;

$upsetAttackBoat = 0;
$upsetAttackCourse = 0;
$upsetAttackRate = null;
$upsetAttackTechnique = '';
$upsetAttackTechniqueRate = null;
$upsetAttackSampleN = 0;

$upsetCourseByBoat = [];
$upsetBoatByCourse = [];
foreach (is_array($aiTrioBoats ?? null) ? $aiTrioBoats : [] as $boatKey => $row) {
    $boat = (int)($row['lane'] ?? $boatKey);
    $course = (int)($row['course'] ?? 0);
    if ($boat >= 1 && $boat <= 6 && $course >= 1 && $course <= 6) {
        $upsetCourseByBoat[$boat] = $course;
        $upsetBoatByCourse[$course] = $boat;
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
    // Python検証と同じく、同値時は艇番の小さい方を優先する。
    $winRankedBoats = range(1, 6);
    usort($winRankedBoats, static function (int $a, int $b) use ($upsetCorrectedRates): int {
        $cmp = ($upsetCorrectedRates[$b] <=> $upsetCorrectedRates[$a]);
        return $cmp !== 0 ? $cmp : ($a <=> $b);
    });
    $upsetAiHead = (int)($winRankedBoats[0] ?? 0);
    $upsetInWinRate = $upsetCorrectedRates[$upsetInBoat] ?? null;
    $upsetInTrioRate = isset($aiTrioBoats[$upsetInBoat]['ai_rate'])
        ? (float)$aiTrioBoats[$upsetInBoat]['ai_rate']
        : null;

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
    $upsetHoleSecondHead = (int)($outerBoats[1] ?? 0);

    if ($upsetHoleHead >= 1 && $upsetHoleHead <= 6) {
        $upsetHoleTrioRate = isset($aiTrioBoats[$upsetHoleHead]['ai_rate'])
            ? (float)$aiTrioBoats[$upsetHoleHead]['ai_rate']
            : null;
        $upsetHoleCourse = (int)($upsetCourseByBoat[$upsetHoleHead] ?? 0);
    }

    if ($upsetHoleSecondHead >= 1 && $upsetHoleSecondHead <= 6) {
        $upsetHoleSecondTrioRate = isset($aiTrioBoats[$upsetHoleSecondHead]['ai_rate'])
            ? (float)$aiTrioBoats[$upsetHoleSecondHead]['ai_rate']
            : null;
        $upsetHoleSecondCourse = (int)($upsetCourseByBoat[$upsetHoleSecondHead] ?? 0);
    }

    if ($upsetHoleTrioRate !== null && $upsetHoleSecondTrioRate !== null) {
        $upsetHoleTrioGap = $upsetHoleTrioRate - $upsetHoleSecondTrioRate;
    }

    $upsetBaseDisagree = (
        $upsetAiHead === $upsetInBoat
        && $upsetCurrentHead !== $upsetInBoat
        && $upsetInWinRate !== null
    );

    if ($upsetBaseDisagree) {
        if ($upsetInWinRate < 40.0) {
            $upsetAlertLevel = 'very_high';
        } elseif ($upsetInWinRate < 50.0) {
            $upsetAlertLevel = 'high';
        } elseif ($upsetInWinRate < 55.0) {
            $upsetAlertLevel = 'attention';
        }
    }

    // E1/C5/C6および今回の追加検証は従来C2 HIGH（<50%）を母集団にしている。
    $upsetAlertHigh = in_array($upsetAlertLevel, ['very_high', 'high'], true);

    if ($upsetAlertHigh && $upsetHoleTrioGap !== null) {
        if ($upsetHoleHead === $upsetCurrentHead && $upsetHoleTrioGap >= 10.0) {
            $upsetHeadConfidence = 'strong';
        } elseif ($upsetHoleTrioGap < 2.0) {
            $upsetHeadConfidence = 'weak';
        } else {
            $upsetHeadConfidence = 'middle';
        }
    }

    // ①残り：イン敗戦時の2・3着残りやすさ。AI3連対率だけで固定分類する。
    if ($upsetAlertHigh && $upsetInTrioRate !== null) {
        if ($upsetInTrioRate < 60.0) {
            $upsetInRemainLevel = 'low';
        } elseif ($upsetInTrioRate < 70.0) {
            $upsetInRemainLevel = 'middle';
        } else {
            $upsetInRemainLevel = 'high';
        }
    }

    // 展開候補：Python検証と同じ定義。
    // 3～5C、6month sample_n>=10、攻め率=まくり+まくり差し最大。
    if ($upsetAlertHigh && is_array($kimarite_data ?? null)) {
        $attackCandidates = [];
        foreach ([3, 4, 5] as $course) {
            $sixMonth = $kimarite_data[$course]['6month']
                ?? $kimarite_data[(string)$course]['6month']
                ?? null;
            if (!is_array($sixMonth)) {
                continue;
            }

            $sampleN = (int)($sixMonth['_sample_n'] ?? 0);
            if ($sampleN < 10) {
                continue;
            }

            $makuri = is_numeric($sixMonth['makuri'] ?? null) ? (float)$sixMonth['makuri'] : 0.0;
            $makurizashi = is_numeric($sixMonth['makurizashi'] ?? null) ? (float)$sixMonth['makurizashi'] : 0.0;
            $attackRate = $makuri + $makurizashi;
            $technique = $makuri >= $makurizashi ? 'まくり' : 'まくり差し';
            $techniqueRate = max($makuri, $makurizashi);

            $attackCandidates[] = [
                'course' => $course,
                'attack_rate' => $attackRate,
                'technique' => $technique,
                'technique_rate' => $techniqueRate,
                'sample_n' => $sampleN,
            ];
        }

        if ($attackCandidates) {
            usort($attackCandidates, static function (array $a, array $b): int {
                $cmp = ($b['attack_rate'] <=> $a['attack_rate']);
                if ($cmp !== 0) {
                    return $cmp;
                }
                return $a['course'] <=> $b['course'];
            });

            $bestAttack = $attackCandidates[0];
            $upsetAttackCourse = (int)$bestAttack['course'];
            $upsetAttackBoat = (int)($upsetBoatByCourse[$upsetAttackCourse] ?? 0);
            $upsetAttackRate = (float)$bestAttack['attack_rate'];
            $upsetAttackTechnique = (string)$bestAttack['technique'];
            $upsetAttackTechniqueRate = (float)$bestAttack['technique_rate'];
            $upsetAttackSampleN = (int)$bestAttack['sample_n'];
        }
    }

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

$upsetConfidenceBadge = static function (string $level): string {
    $styles = [
        'strong' => ['label' => '強', 'bg' => '#e4efe0', 'border' => '#9bb795', 'color' => '#466545'],
        'middle' => ['label' => '中', 'bg' => '#f3ead8', 'border' => '#cfb477', 'color' => '#7b6332'],
        'weak' => ['label' => '弱', 'bg' => '#eeeeeb', 'border' => '#bebdb6', 'color' => '#66655f'],
    ];
    if (!isset($styles[$level])) {
        return '';
    }
    $s = $styles[$level];
    return '<span style="display:inline-block;padding:2px 7px;border-radius:999px;background:'
        . $s['bg'] . ';border:1px solid ' . $s['border'] . ';color:' . $s['color']
        . ';font-size:11px;font-weight:bold;white-space:nowrap;">穴頭信頼度：'
        . $s['label'] . '</span>';
};

$upsetRemainMeta = [
    'high' => ['label' => '高', 'bg' => '#e4efe0', 'border' => '#9bb795', 'color' => '#466545'],
    'middle' => ['label' => '中', 'bg' => '#f3ead8', 'border' => '#cfb477', 'color' => '#7b6332'],
    'low' => ['label' => '低', 'bg' => '#f4e5e1', 'border' => '#cfa49b', 'color' => '#8b5147'],
];
$upsetRemain = $upsetRemainMeta[$upsetInRemainLevel] ?? null;

$upsetAlertMeta = [
    'very_high' => ['label' => '🚨 イン飛び警報：非常に高', 'color' => '#a74932', 'border' => '#c98670', 'bg' => '#f7e8e1'],
    'high' => ['label' => '⚠ イン飛び警報：高', 'color' => '#aa741f', 'border' => '#d9a74f', 'bg' => '#f8f0df'],
    'attention' => ['label' => '⚠ イン飛び警報：注意', 'color' => '#8a6d32', 'border' => '#cfb477', 'bg' => '#f6f0e3'],
    'normal' => ['label' => '🛡 イン飛び警報：通常', 'color' => '#5f6f5d', 'border' => '#d8cdbc', 'bg' => '#f8f4ec'],
];
$upsetMeta = $upsetAlertMeta[$upsetAlertLevel] ?? $upsetAlertMeta['normal'];
?>

<div id="upset-alert-panel" style="margin:0 0 10px; background:<?= htmlspecialchars($upsetMeta['bg'], ENT_QUOTES, 'UTF-8') ?>; border:1px solid <?= htmlspecialchars($upsetMeta['border'], ENT_QUOTES, 'UTF-8') ?>; border-radius:8px; padding:<?= $upsetAlertHigh ? '14px' : '10px 14px' ?>; color:#3f4b5a;">
    <?php if ($upsetAlertStatus === 'ok' && $upsetAlertHigh): ?>
        <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
            <div>
                <div style="font-size:17px; font-weight:bold; color:<?= htmlspecialchars($upsetMeta['color'], ENT_QUOTES, 'UTF-8') ?>;">
                    <?= htmlspecialchars($upsetMeta['label'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div style="font-size:12px; color:#6b7785; margin-top:3px;">
                    AI本命=1C / 現行本命≠1C / イン補正後1着率 <?= number_format((float)$upsetInWinRate, 1) ?>%
                    （<?= $upsetAlertLevel === 'very_high' ? '40%未満' : '40〜50%未満' ?>）
                </div>
            </div>
            <div style="font-size:11px; color:#6b7785; white-space:nowrap;">表示専用 / 買い目へ未接続</div>
        </div>

        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:11px;">
            <div style="flex:1 1 170px; background:#f2ece2; border:1px solid #d8cdbc; border-radius:6px; padding:9px 10px;">
                <div style="font-size:11px; color:#6b7785;">AI本命（補正後1着率）</div>
                <div style="margin-top:5px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <?= $upsetBoatBadge($upsetAiHead) ?>
                    <strong><?= (int)($upsetCourseByBoat[$upsetAiHead] ?? 0) ?>C</strong>
                    <strong style="color:#aa741f;"><?= number_format((float)$upsetInWinRate, 1) ?>%</strong>
                </div>
            </div>

            <div style="flex:1 1 150px; background:#f2ece2; border:1px solid #d8cdbc; border-radius:6px; padding:9px 10px;">
                <div style="font-size:11px; color:#6b7785;">現行本命</div>
                <div style="margin-top:5px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <?= $upsetBoatBadge($upsetCurrentHead) ?>
                    <strong><?= (int)($upsetCourseByBoat[$upsetCurrentHead] ?? 0) ?>C</strong>
                </div>
            </div>

            <div style="flex:1 1 210px; background:#f4ead7; border:1px solid <?= htmlspecialchars($upsetMeta['border'], ENT_QUOTES, 'UTF-8') ?>; border-radius:6px; padding:9px 10px;">
                <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap;">
                    <div style="font-size:11px; color:#7b6640;">穴頭本命</div>
                    <?= $upsetConfidenceBadge($upsetHeadConfidence) ?>
                </div>
                <div style="margin-top:5px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <?= $upsetBoatBadge($upsetHoleHead) ?>
                    <strong><?= $upsetHoleCourse ?>C</strong>
                    <?php if ($upsetHoleTrioRate !== null): ?>
                        <strong style="color:#75659b;">AI3連対 <?= number_format($upsetHoleTrioRate, 1) ?>%</strong>
                    <?php endif; ?>
                </div>
                <?php if ($upsetHoleHead === $upsetCurrentHead): ?>
                    <div style="font-size:11px; color:#7b6640; margin-top:4px;">現行本命と一致</div>
                <?php endif; ?>
                <?php if ($upsetHoleTrioGap !== null): ?>
                    <div style="font-size:11px; color:#6b7785; margin-top:4px;">
                        AI3連対率 対抗差 <?= number_format($upsetHoleTrioGap, 1) ?>pt
                    </div>
                <?php endif; ?>
            </div>

            <div style="flex:1 1 185px; background:#f7f2e9; border:1px solid #d8cdbc; border-radius:6px; padding:9px 10px;">
                <div style="font-size:11px; color:#6b7785;">穴頭対抗</div>
                <div style="margin-top:5px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <?= $upsetBoatBadge($upsetHoleSecondHead) ?>
                    <strong><?= $upsetHoleSecondCourse ?>C</strong>
                    <?php if ($upsetHoleSecondTrioRate !== null): ?>
                        <strong style="color:#75659b;">AI3連対 <?= number_format($upsetHoleSecondTrioRate, 1) ?>%</strong>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:10px;">
            <div style="flex:1 1 240px; background:#f7f2e9; border:1px solid #d8cdbc; border-radius:6px; padding:9px 10px;">
                <div style="font-size:11px; color:#6b7785;">展開候補</div>
                <?php if ($upsetAttackCourse >= 3 && $upsetAttackCourse <= 5 && $upsetAttackBoat >= 1): ?>
                    <div style="margin-top:5px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <?= $upsetBoatBadge($upsetAttackBoat) ?>
                        <strong><?= $upsetAttackCourse ?>C <?= htmlspecialchars($upsetAttackTechnique, ENT_QUOTES, 'UTF-8') ?></strong>
                        <?php if ($upsetAttackRate !== null): ?>
                            <strong style="color:#aa741f;">攻め率 <?= number_format($upsetAttackRate, 1) ?>%</strong>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:11px; color:#6b7785; margin-top:4px;">
                        3〜5Cの6ヶ月攻め率最大
                        <?php if ($upsetAttackSampleN > 0): ?> / sample <?= $upsetAttackSampleN ?>走<?php endif; ?>
                    </div>
                <?php else: ?>
                    <div style="font-size:12px; color:#6b7785; margin-top:5px;">決まり手サンプル不足のため候補なし</div>
                <?php endif; ?>
            </div>

            <div style="flex:1 1 220px; background:#f7f2e9; border:1px solid #d8cdbc; border-radius:6px; padding:9px 10px;">
                <div style="font-size:11px; color:#6b7785;">①残り（イン敗戦時）</div>
                <?php if ($upsetRemain !== null && $upsetInTrioRate !== null): ?>
                    <div style="margin-top:5px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <span style="display:inline-block;padding:3px 9px;border-radius:999px;background:<?= htmlspecialchars($upsetRemain['bg'], ENT_QUOTES, 'UTF-8') ?>;border:1px solid <?= htmlspecialchars($upsetRemain['border'], ENT_QUOTES, 'UTF-8') ?>;color:<?= htmlspecialchars($upsetRemain['color'], ENT_QUOTES, 'UTF-8') ?>;font-weight:bold;">①残り：<?= htmlspecialchars($upsetRemain['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        <strong style="color:#75659b;">AI3連対 <?= number_format($upsetInTrioRate, 1) ?>%</strong>
                    </div>
                    <div style="font-size:11px; color:#6b7785; margin-top:4px;">1着を逃した場合の2・3着残りやすさ</div>
                <?php else: ?>
                    <div style="font-size:12px; color:#6b7785; margin-top:5px;">判定データ不足</div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($simulation_active)): ?>
            <div style="font-size:11px; color:#aa741f; margin-top:8px;">
                ※仮想進入での試算表示。穴目各指標の検証は実展示進入基準です。
            </div>
        <?php endif; ?>
    <?php elseif ($upsetAlertStatus === 'ok' && $upsetAlertLevel === 'attention'): ?>
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
            <div>
                <span style="font-size:14px; font-weight:bold; color:<?= htmlspecialchars($upsetMeta['color'], ENT_QUOTES, 'UTF-8') ?>;">
                    <?= htmlspecialchars($upsetMeta['label'], ENT_QUOTES, 'UTF-8') ?>
                </span>
                <span style="font-size:11px; color:#6b7785; margin-left:8px;">
                    イン補正後1着率 <?= number_format((float)$upsetInWinRate, 1) ?>%（50〜55%未満）
                </span>
            </div>
            <div style="font-size:11px; color:#6b7785;">穴頭・展開候補・①残りは&lt;50%のみ表示</div>
        </div>
    <?php elseif ($upsetAlertStatus === 'ok'): ?>
        <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
            <div>
                <span style="font-size:14px; font-weight:bold; color:<?= htmlspecialchars($upsetMeta['color'], ENT_QUOTES, 'UTF-8') ?>;">
                    <?= htmlspecialchars($upsetMeta['label'], ENT_QUOTES, 'UTF-8') ?>
                </span>
                <span style="font-size:11px; color:#6b7785; margin-left:8px;">
                    <?= $upsetBaseDisagree && $upsetInWinRate !== null
                        ? 'イン補正後1着率 ' . number_format((float)$upsetInWinRate, 1) . '%（55%以上）'
                        : '警報条件には該当しません' ?>
                </span>
            </div>
            <div style="font-size:11px; color:#6b7785;">表示専用</div>
        </div>
    <?php else: ?>
        <div style="font-size:13px; color:#6b7785;">
            🛡 イン飛び警報：判定待ち
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
    // 最終表示順は「買い目 → イン飛び警報 → 2連単 → 3連単参考情報」となる。
    summaryBox.insertAdjacentElement('afterend', panel);
    panel.style.marginTop = '14px';
});
</script>
