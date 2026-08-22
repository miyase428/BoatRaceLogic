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

$stadiumAffinityMeta = is_array($stadiumAffinityAll['meta'] ?? null)
    ? $stadiumAffinityAll['meta']
    : [];
$stadiumAffinityRows = is_array($stadiumAffinityAll['stadiums'] ?? null)
    ? $stadiumAffinityAll['stadiums']
    : [];
$stadiumAffinity = is_array($stadiumAffinityRows[$selected_place ?? ''] ?? null)
    ? $stadiumAffinityRows[$selected_place]
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
<?php endif; ?>
