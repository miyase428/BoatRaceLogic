<?php
$stadiumAffinityPath = __DIR__ . '/../../config/stadium_affinity.json';
$stadiumAffinityAll = [];

if (is_file($stadiumAffinityPath)) {
    $stadiumAffinityJson = file_get_contents($stadiumAffinityPath);
    $decoded = is_string($stadiumAffinityJson)
        ? json_decode($stadiumAffinityJson, true)
        : null;
    if (is_array($decoded)) {
        $stadiumAffinityAll = $decoded;
    }
}

$raceCompatibilityPath = __DIR__ . '/../../config/stadium_race_number_compatibility.json';
$raceCompatibilityAll = [];

if (is_file($raceCompatibilityPath)) {
    $raceCompatibilityJson = file_get_contents($raceCompatibilityPath);
    $raceDecoded = is_string($raceCompatibilityJson)
        ? json_decode($raceCompatibilityJson, true)
        : null;
    if (is_array($raceDecoded)) {
        $raceCompatibilityAll = $raceDecoded;
    }
}

$stadiumAffinityMeta = is_array($stadiumAffinityAll['meta'] ?? null)
    ? $stadiumAffinityAll['meta']
    : [];
$stadiumAffinityRows = is_array($stadiumAffinityAll['stadiums'] ?? null)
    ? $stadiumAffinityAll['stadiums']
    : [];
$stadiumAffinity = is_array($stadiumAffinityRows[$selected_place ?? ''] ?? null)
    ? $stadiumAffinityRows[$selected_place]
    : [];

$raceCompatibilityMeta = is_array($raceCompatibilityAll['meta'] ?? null)
    ? $raceCompatibilityAll['meta']
    : [];
$raceCompatibilityStadiums = is_array($raceCompatibilityAll['stadiums'] ?? null)
    ? $raceCompatibilityAll['stadiums']
    : [];
$raceCompatibilityVenue = is_array($raceCompatibilityStadiums[$selected_place ?? ''] ?? null)
    ? $raceCompatibilityStadiums[$selected_place]
    : [];

if (!empty($stadiumAffinity)):
    $affinityEsc = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    $affinityMark = (string)($stadiumAffinity['affinity'] ?? '-');
    $stabilityMark = (string)($stadiumAffinity['stability'] ?? '-');
    $affinityColors = [
        '◎' => ['bg' => '#dcfce7', 'text' => '#166534', 'border' => '#86efac'],
        '○' => ['bg' => '#dbeafe', 'text' => '#1d4ed8', 'border' => '#93c5fd'],
        '△' => ['bg' => '#fef3c7', 'text' => '#92400e', 'border' => '#fcd34d'],
        '×' => ['bg' => '#fee2e2', 'text' => '#b91c1c', 'border' => '#fca5a5'],
    ];
    $affinityColor = $affinityColors[$affinityMark] ?? ['bg' => '#e2e8f0', 'text' => '#334155', 'border' => '#cbd5e1'];
    $stabilityColor = $affinityColors[$stabilityMark] ?? ['bg' => '#e2e8f0', 'text' => '#334155', 'border' => '#cbd5e1'];
    $mode = (string)($stadiumAffinityMode ?? 'pc');
    $periodLabel = (string)($stadiumAffinityMeta['label'] ?? '暫定');
    $startDate = (string)($stadiumAffinityMeta['start_date'] ?? '');
    $endDate = (string)($stadiumAffinityMeta['end_date'] ?? '');
    $venueName = (string)($stadiumAffinity['name'] ?? ($place_names[$selected_place] ?? $selected_place ?? ''));
    $rate = (float)($stadiumAffinity['honmei_first_rate'] ?? 0.0);
    $rank = (int)($stadiumAffinity['rank'] ?? 0);
    $races = (int)($stadiumAffinity['races'] ?? 0);
    $period1 = (float)($stadiumAffinity['period1_rate'] ?? 0.0);
    $period2 = (float)($stadiumAffinity['period2_rate'] ?? 0.0);
    $gap = (float)($stadiumAffinity['stability_gap'] ?? 0.0);

    $raceGradeColors = [
        'A' => ['bg' => '#dcfce7', 'text' => '#166534', 'border' => '#86efac'],
        'B' => ['bg' => '#dbeafe', 'text' => '#1d4ed8', 'border' => '#93c5fd'],
        'C' => ['bg' => '#fef3c7', 'text' => '#92400e', 'border' => '#fcd34d'],
        'D' => ['bg' => '#fee2e2', 'text' => '#b91c1c', 'border' => '#fca5a5'],
        '参考' => ['bg' => '#e2e8f0', 'text' => '#475569', 'border' => '#cbd5e1'],
    ];
    $raceCompatibilityRows = is_array($raceCompatibilityVenue['races'] ?? null)
        ? $raceCompatibilityVenue['races']
        : [];
    $selectedRaceNo = (int)($selected_race ?? 0);
    $currentRaceCompatibility = is_array($raceCompatibilityRows[(string)$selectedRaceNo] ?? null)
        ? $raceCompatibilityRows[(string)$selectedRaceNo]
        : [];
    $raceCompatibilityLabel = (string)($raceCompatibilityMeta['label'] ?? '過去データ');
    $raceCompatibilityStart = (string)($raceCompatibilityMeta['start_date'] ?? '');
    $raceCompatibilityEnd = (string)($raceCompatibilityMeta['end_date'] ?? '');

    $goodRaceLabels = [];
    foreach ($raceCompatibilityRows as $raceNoKey => $raceRow) {
        if (!is_array($raceRow)) {
            continue;
        }
        $grade = (string)($raceRow['grade'] ?? '参考');
        if ($grade === 'A' || $grade === 'B') {
            $goodRaceLabels[] = ((int)$raceNoKey) . 'R';
        }
    }
?>
<?php if ($mode === 'app'): ?>
    <div style="margin:0 0 10px; padding:10px 11px; border:1px solid #d8cdbc; border-radius:10px; background:#fffaf2; color:#334155;">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap;">
            <div style="font-size:13px; font-weight:800;">📊 <?= $affinityEsc($venueName) ?>との予想相性</div>
            <div style="font-size:10px; color:#6b7785;"><?= $affinityEsc($periodLabel) ?></div>
        </div>
        <div style="display:flex; gap:7px; align-items:center; flex-wrap:wrap; margin-top:7px;">
            <span style="display:inline-block; padding:3px 8px; border-radius:999px; font-weight:800; font-size:12px; background:<?= $affinityEsc($affinityColor['bg']) ?>; color:<?= $affinityEsc($affinityColor['text']) ?>; border:1px solid <?= $affinityEsc($affinityColor['border']) ?>;">相性 <?= $affinityEsc($affinityMark) ?></span>
            <span style="display:inline-block; padding:3px 8px; border-radius:999px; font-weight:800; font-size:12px; background:<?= $affinityEsc($stabilityColor['bg']) ?>; color:<?= $affinityEsc($stabilityColor['text']) ?>; border:1px solid <?= $affinityEsc($stabilityColor['border']) ?>;">安定 <?= $affinityEsc($stabilityMark) ?></span>
            <strong style="font-size:13px;">本命1着 <?= number_format($rate, 1) ?>%</strong>
            <span style="font-size:11px; color:#6b7785;">24場中 <?= $rank ?>位</span>
        </div>
        <div style="margin-top:5px; font-size:10px; color:#6b7785;">
            <?= $affinityEsc($startDate) ?>〜<?= $affinityEsc($endDate) ?> / <?= number_format($races) ?>R / 月差 <?= number_format($gap, 1) ?>pt
        </div>
    </div>
<?php else: ?>
    <div style="margin:14px 0; padding:12px 14px; background:var(--surface-soft); border:1px solid var(--border); border-radius:8px; color:var(--text);">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
            <div>
                <div style="font-size:16px; font-weight:bold; color:var(--accent);">📊 現行Webとの場相性</div>
                <div style="margin-top:3px; font-size:12px; color:var(--text-muted);">
                    <?= $affinityEsc($venueName) ?> / <?= $affinityEsc($periodLabel) ?>（<?= $affinityEsc($startDate) ?>〜<?= $affinityEsc($endDate) ?>）
                </div>
            </div>
            <div style="display:flex; gap:7px; align-items:center; flex-wrap:wrap;">
                <span style="display:inline-block; padding:4px 10px; border-radius:999px; font-weight:bold; background:<?= $affinityEsc($affinityColor['bg']) ?>; color:<?= $affinityEsc($affinityColor['text']) ?>; border:1px solid <?= $affinityEsc($affinityColor['border']) ?>;">相性 <?= $affinityEsc($affinityMark) ?></span>
                <span style="display:inline-block; padding:4px 10px; border-radius:999px; font-weight:bold; background:<?= $affinityEsc($stabilityColor['bg']) ?>; color:<?= $affinityEsc($stabilityColor['text']) ?>; border:1px solid <?= $affinityEsc($stabilityColor['border']) ?>;">安定度 <?= $affinityEsc($stabilityMark) ?></span>
            </div>
        </div>
        <div style="display:flex; gap:18px; flex-wrap:wrap; margin-top:10px; font-size:13px;">
            <div>本命1着率 <strong style="color:var(--text-strong); font-size:17px;"><?= number_format($rate, 2) ?>%</strong></div>
            <div>24場順位 <strong style="color:var(--text-strong);"><?= $rank ?>位</strong></div>
            <div>対象 <strong style="color:var(--text-strong);"><?= number_format($races) ?>R</strong></div>
            <div>期間別 <strong style="color:var(--text-strong);"><?= number_format($period1, 2) ?>% → <?= number_format($period2, 2) ?>%</strong></div>
            <div>差 <strong style="color:var(--text-strong);"><?= number_format($gap, 2) ?>pt</strong></div>
        </div>
        <details style="margin-top:8px; font-size:11px; color:var(--text-muted);">
            <summary style="cursor:pointer;">相性度・安定度の暫定基準</summary>
            <div style="margin-top:5px; line-height:1.6;">
                <?= $affinityEsc($stadiumAffinityMeta['affinity_rule'] ?? '') ?><br>
                <?= $affinityEsc($stadiumAffinityMeta['stability_rule'] ?? '') ?>
            </div>
        </details>
    </div>
<?php endif; ?>

<?php if (!empty($raceCompatibilityVenue) && !empty($raceCompatibilityRows)): ?>
    <?php
        $currentGrade = (string)($currentRaceCompatibility['grade'] ?? '参考');
        $currentGradeColor = $raceGradeColors[$currentGrade] ?? $raceGradeColors['参考'];
    ?>
    <?php if ($mode === 'app'): ?>
        <div style="margin:-2px 0 10px; padding:10px 11px; border:1px solid #d8cdbc; border-radius:10px; background:#fffdf8; color:#334155;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap;">
                <div style="font-size:13px; font-weight:800;">🎯 R別予想相性</div>
                <div style="font-size:10px; color:#6b7785;"><?= $affinityEsc($raceCompatibilityLabel) ?></div>
            </div>

            <?php if (!empty($currentRaceCompatibility)): ?>
                <div style="display:flex; gap:7px; align-items:center; flex-wrap:wrap; margin-top:7px;">
                    <span style="display:inline-block; padding:3px 8px; border-radius:999px; font-weight:800; font-size:12px; background:<?= $affinityEsc($currentGradeColor['bg']) ?>; color:<?= $affinityEsc($currentGradeColor['text']) ?>; border:1px solid <?= $affinityEsc($currentGradeColor['border']) ?>;"><?= $selectedRaceNo ?>R 相性<?= $affinityEsc($currentGrade) ?></span>
                    <strong style="font-size:12px;">本命1着 <?= number_format((float)($currentRaceCompatibility['honmei_first_rate'] ?? 0), 1) ?>%</strong>
                    <span style="font-size:11px;">的中 <?= number_format((float)($currentRaceCompatibility['bet_hit_rate'] ?? 0), 1) ?>%</span>
                    <span style="font-size:11px;">直近 <?= number_format((float)($currentRaceCompatibility['recent_bet_hit_rate'] ?? 0), 1) ?>%</span>
                </div>
                <div style="margin-top:5px; font-size:10px; color:#6b7785;">
                    TOP3≧2 <?= number_format((float)($currentRaceCompatibility['top3_2plus_rate'] ?? 0), 1) ?>% / 回収 <?= number_format((float)($currentRaceCompatibility['roi'] ?? 0), 1) ?>% / N=<?= (int)($currentRaceCompatibility['n'] ?? 0) ?>
                </div>
            <?php endif; ?>

            <div style="display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:5px; margin-top:8px;">
                <?php for ($raceNo = 1; $raceNo <= 12; $raceNo++): ?>
                    <?php
                        $raceRow = is_array($raceCompatibilityRows[(string)$raceNo] ?? null)
                            ? $raceCompatibilityRows[(string)$raceNo]
                            : [];
                        $grade = (string)($raceRow['grade'] ?? '参考');
                        $gradeColor = $raceGradeColors[$grade] ?? $raceGradeColors['参考'];
                        $isCurrent = $raceNo === $selectedRaceNo;
                    ?>
                    <div style="text-align:center; padding:5px 2px; border-radius:7px; background:<?= $affinityEsc($gradeColor['bg']) ?>; color:<?= $affinityEsc($gradeColor['text']) ?>; border:<?= $isCurrent ? '2px' : '1px' ?> solid <?= $affinityEsc($gradeColor['border']) ?>; font-size:11px; font-weight:800;">
                        <?= $raceNo ?>R <?= $affinityEsc($grade) ?>
                    </div>
                <?php endfor; ?>
            </div>

            <?php if ($goodRaceLabels): ?>
                <div style="margin-top:6px; font-size:10px; color:#475569;">A/B候補: <?= $affinityEsc(implode('・', $goodRaceLabels)) ?></div>
            <?php endif; ?>
            <div style="margin-top:4px; font-size:9px; line-height:1.4; color:#7c8795;">A/Bは現行Webとの噛み合いやすさ。回収率とは別の参考指標。</div>
        </div>
    <?php else: ?>
        <div style="margin:-4px 0 14px; padding:12px 14px; background:var(--surface-soft); border:1px solid var(--border); border-radius:8px; color:var(--text);">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
                <div>
                    <div style="font-size:16px; font-weight:bold; color:var(--accent);">🎯 R別予想相性</div>
                    <div style="margin-top:3px; font-size:12px; color:var(--text-muted);">
                        <?= $affinityEsc($venueName) ?> / <?= $affinityEsc($raceCompatibilityLabel) ?>（<?= $affinityEsc($raceCompatibilityStart) ?>〜<?= $affinityEsc($raceCompatibilityEnd) ?>）
                    </div>
                </div>
                <?php if ($goodRaceLabels): ?>
                    <div style="font-size:12px; color:var(--text-muted);">A/B候補: <strong style="color:var(--text-strong);"><?= $affinityEsc(implode('・', $goodRaceLabels)) ?></strong></div>
                <?php endif; ?>
            </div>

            <?php if (!empty($currentRaceCompatibility)): ?>
                <div style="display:flex; gap:16px; align-items:center; flex-wrap:wrap; margin-top:10px; font-size:13px;">
                    <span style="display:inline-block; padding:4px 10px; border-radius:999px; font-weight:bold; background:<?= $affinityEsc($currentGradeColor['bg']) ?>; color:<?= $affinityEsc($currentGradeColor['text']) ?>; border:1px solid <?= $affinityEsc($currentGradeColor['border']) ?>;"><?= $selectedRaceNo ?>R 相性 <?= $affinityEsc($currentGrade) ?></span>
                    <div>本命1着 <strong style="color:var(--text-strong); font-size:16px;"><?= number_format((float)($currentRaceCompatibility['honmei_first_rate'] ?? 0), 1) ?>%</strong></div>
                    <div>TOP3≧2 <strong style="color:var(--text-strong);"><?= number_format((float)($currentRaceCompatibility['top3_2plus_rate'] ?? 0), 1) ?>%</strong></div>
                    <div>買い目的中 <strong style="color:var(--text-strong);"><?= number_format((float)($currentRaceCompatibility['bet_hit_rate'] ?? 0), 1) ?>%</strong></div>
                    <div>直近的中 <strong style="color:var(--text-strong);"><?= number_format((float)($currentRaceCompatibility['recent_bet_hit_rate'] ?? 0), 1) ?>%</strong></div>
                    <div>回収率 <strong style="color:var(--text-strong);"><?= number_format((float)($currentRaceCompatibility['roi'] ?? 0), 1) ?>%</strong></div>
                    <div>N=<strong style="color:var(--text-strong);"><?= (int)($currentRaceCompatibility['n'] ?? 0) ?></strong></div>
                </div>
            <?php endif; ?>

            <div style="display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:6px; margin-top:10px;">
                <?php for ($raceNo = 1; $raceNo <= 12; $raceNo++): ?>
                    <?php
                        $raceRow = is_array($raceCompatibilityRows[(string)$raceNo] ?? null)
                            ? $raceCompatibilityRows[(string)$raceNo]
                            : [];
                        $grade = (string)($raceRow['grade'] ?? '参考');
                        $gradeColor = $raceGradeColors[$grade] ?? $raceGradeColors['参考'];
                        $isCurrent = $raceNo === $selectedRaceNo;
                    ?>
                    <div style="text-align:center; padding:7px 4px; border-radius:7px; background:<?= $affinityEsc($gradeColor['bg']) ?>; color:<?= $affinityEsc($gradeColor['text']) ?>; border:<?= $isCurrent ? '2px' : '1px' ?> solid <?= $affinityEsc($gradeColor['border']) ?>; font-size:12px;">
                        <strong><?= $raceNo ?>R</strong> <?= $affinityEsc($grade) ?>
                    </div>
                <?php endfor; ?>
            </div>

            <div style="margin-top:7px; font-size:11px; color:var(--text-muted);">
                A/Bは現行Webとの噛み合いやすさの参考。回収率100%以上を意味しません。
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php endif; ?>
