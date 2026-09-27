<?php $airRaceCode = (string)($race_code ?? ''); ?>
<section id="air-prediction-panel" class="air-prediction-panel"
    data-race-code="<?= htmlspecialchars($airRaceCode, ENT_QUOTES, 'UTF-8') ?>"
    data-api-url="/web/air_prediction_api.php">
    <div class="air-prediction-heading">
        <h2>☁️ エア予想</h2>
        <p>買わずに予想を残して、レース後に答え合わせできます。登録内容はWeb・アプリで共有されます。</p>
    </div>
    <div class="air-prediction-body">
        <div class="air-prediction-inputs">
            <label>3連単の予想（フォーメーション可）
                <input class="air-prediction-tickets" type="text" inputmode="numeric" placeholder="例: 2-134-1346、1-234-2345" autocomplete="off">
            </label>
            <label>1点あたり
                <select class="air-prediction-stake">
                    <option value="100">100円</option><option value="200">200円</option>
                    <option value="500">500円</option><option value="1000">1,000円</option>
                </select>
            </label>
            <label class="air-prediction-memo-label">メモ（任意）
                <input class="air-prediction-memo" type="text" maxlength="120" placeholder="根拠など" autocomplete="off">
            </label>
        </div>
        <div class="air-prediction-actions">
            <button type="button" class="air-prediction-save">エア予想を登録</button>
            <button type="button" class="air-prediction-clear">入力を消去</button>
            <span class="air-prediction-save-message" aria-live="polite"></span>
        </div>
        <div class="air-prediction-result" aria-live="polite">
            <div class="air-prediction-result-status">結果を確認中…</div>
            <div class="air-prediction-result-details" hidden></div>
            <div class="air-prediction-result-actions">
                <button type="button" class="air-prediction-fetch" disabled>結果を取得</button>
                <a class="air-prediction-official-link" href="#" target="_blank" rel="noopener noreferrer" hidden>公式結果を開く</a>
            </div>
        </div>
    </div>
</section>
<link rel="stylesheet" href="/web/assets/css/air_prediction.css?v=20260919c">
<script src="/web/assets/js/air_prediction.js?v=20260919d" defer></script>
