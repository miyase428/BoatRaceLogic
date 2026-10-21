<?php
/** Shared PC/PWA status-and-values panel. The values remain hidden until both
 * independent LIVE sources pass the client-side fail-closed contract. */
?>
<section
    class="tenkai-ai-status-card"
    data-tenkai-ai-panel
    data-race-code="<?= htmlspecialchars((string)$race_code, ENT_QUOTES, 'UTF-8') ?>"
    aria-live="polite"
    aria-busy="true"
>
    <h2 class="tenkai-ai-status-title">展開AI</h2>
    <div class="tenkai-ai-status-body" data-tenkai-ai-status>展開AIを確認中…</div>
    <div class="tenkai-ai-status-meta" data-tenkai-ai-meta></div>
    <div class="tenkai-ai-status-notice" data-tenkai-ai-numeric-notice hidden></div>
    <div class="tenkai-ai-metric-grid" data-tenkai-ai-grid hidden aria-label="4コース展開予測">
        <div class="tenkai-ai-metric tenkai-ai-metric--positive">
            <div class="tenkai-ai-metric-label">3コース壁率 <span class="tenkai-ai-help" title="3コースが4コースの攻めを止める見込み" aria-label="3コースが4コースの攻めを止める見込み">?</span></div>
            <strong data-tenkai-ai-value="wall">—</strong>
        </div>
        <div class="tenkai-ai-metric tenkai-ai-metric--positive">
            <div class="tenkai-ai-metric-label">4コースまくり<br>成功見込み <span class="tenkai-ai-help" title="4コースがまくりで攻め切る見込み" aria-label="4コースがまくりで攻め切る見込み">?</span></div>
            <strong data-tenkai-ai-value="makuri">—</strong>
        </div>
        <div class="tenkai-ai-metric tenkai-ai-metric--positive">
            <div class="tenkai-ai-metric-label">4コースまくり差し<br>成功見込み <span class="tenkai-ai-help" title="4コースがまくり差しで攻め切る見込み" aria-label="4コースがまくり差しで攻め切る見込み">?</span></div>
            <strong data-tenkai-ai-value="makurizashi">—</strong>
        </div>
        <div class="tenkai-ai-metric tenkai-ai-metric--no-attack">
            <div class="tenkai-ai-metric-label">攻撃成功なし <span class="tenkai-ai-help" title="4コースがまくり・まくり差しで攻め切らない見込み" aria-label="4コースがまくり・まくり差しで攻め切らない見込み">?</span></div>
            <strong data-tenkai-ai-value="no-attack">—</strong>
        </div>
    </div>
    <p class="tenkai-ai-status-note" data-tenkai-ai-footer hidden>展示後の情報をもとに、4コースの攻撃パターン（まくり・まくり差し等）の成功見込みを予測します。レース展開により「攻撃成功なし」が高くなる場合があります。</p>
</section>
