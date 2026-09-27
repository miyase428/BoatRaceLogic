<?php
$eight = is_array($eight_point_formation ?? null) ? $eight_point_formation : [];
if (empty($eight['visible']) || !is_array($eight['variants'] ?? null)) {
    return;
}
$eightMode = (string)($eight_point_panel_mode ?? 'web');
$eightOdds = is_array($eight['odds'] ?? null) ? $eight['odds'] : [];
?>
<section id="eight-point-formation-panel" class="eight-point-formation <?= $eightMode === 'app' ? 'eight-point-formation-app app-card' : '' ?>">
    <div class="eight-point-header">
        <div>
            <strong>🧪 8点フォーメーション</strong>
            <span>試験運用</span>
        </div>
        <small>既存買い目とは別の参考表示</small>
    </div>
    <div class="eight-point-grid">
        <?php foreach ($eight['variants'] as $variant): ?>
            <article class="eight-point-variant <?= !empty($variant['recommended']) ? 'is-recommended' : '' ?>">
                <div class="eight-point-variant-title">
                    <strong><?= htmlspecialchars((string)($variant['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                    <?php if (!empty($variant['recommended'])): ?><b>推奨</b><?php endif; ?>
                </div>
                <div class="eight-point-bet"><?= htmlspecialchars((string)($variant['formation'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                <small><?= (int)($variant['points'] ?? 0) ?>点</small>
            </article>
        <?php endforeach; ?>
    </div>
    <div class="eight-point-reason">推奨理由：<?= htmlspecialchars((string)($eight['recommendation_reason'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
    <?php if (is_array($eight['reference_variants'] ?? null) && $eight['reference_variants'] !== []): ?>
        <details class="eight-point-reference">
            <summary>参考：8点条件外のフォーメーション</summary>
            <?php foreach ($eight['reference_variants'] as $variant): ?>
                <div class="eight-point-reference-row">
                    <strong><?= htmlspecialchars((string)($variant['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                    <span><?= htmlspecialchars((string)($variant['formation'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                    <b><?= (int)($variant['points'] ?? 0) ?>点</b>
                    <small><?= htmlspecialchars((string)($variant['reference_reason'] ?? ''), ENT_QUOTES, 'UTF-8') ?></small>
                </div>
            <?php endforeach; ?>
        </details>
    <?php endif; ?>
    <?php if (($eightOdds['status'] ?? '') === 'ok'): ?>
        <div class="eight-point-odds <?= !empty($eightOdds['skip_candidate']) ? 'is-skip' : '' ?>">
            <?= !empty($eightOdds['skip_candidate']) ? '見送り候補' : '合成オッズ目安' ?>：<?= number_format((float)$eightOdds['combined_odds'], 2) ?>倍
            <small>（基準 <?= number_format((float)$eightOdds['threshold'], 1) ?>倍）</small>
        </div>
    <?php else: ?>
        <div class="eight-point-odds is-waiting">合成オッズ：公式オッズ待ち</div>
    <?php endif; ?>
</section>
<?php if ($eightMode !== 'app'): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    window.setTimeout(function () {
        const panel = document.getElementById('eight-point-formation-panel');
        const summary = document.querySelector('.summary-box');
        const upset = document.getElementById('upset-alert-panel');
        if (!panel || !summary) return;
        summary.insertAdjacentElement('afterend', panel);
        if (upset) panel.insertAdjacentElement('afterend', upset);
    }, 0);
});
</script>
<?php endif; ?>
