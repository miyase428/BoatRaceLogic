<?php
$recentHistoryPlace = strtoupper((string)($selected_place ?? ''));
$recentHistoryDate = (string)($selected_date ?? date('Y-m-d'));
$recentHistoryVenue = (string)($place_names[$recentHistoryPlace] ?? $recentHistoryPlace);
?>

<section id="recent-prediction-history-panel"
         data-place="<?= htmlspecialchars($recentHistoryPlace, ENT_QUOTES, 'UTF-8') ?>"
         data-date="<?= htmlspecialchars($recentHistoryDate, ENT_QUOTES, 'UTF-8') ?>"
         data-venue="<?= htmlspecialchars($recentHistoryVenue, ENT_QUOTES, 'UTF-8') ?>"
         class="recent-history-panel">
    <div class="recent-history-heading">
        <div>
            <h2>📅 直近5開催日 予想 × 結果</h2>
            <div class="recent-history-note">
                <?= htmlspecialchars($recentHistoryVenue, ENT_QUOTES, 'UTF-8') ?> / 選択日以前の結果が12R揃う直近5開催日（最大60R）
            </div>
            <div class="recent-history-note">
                ※保存当時の予想ログではなく、現在の本番ロジックを各過去レースへ再適用。回収率は1点100円均等購入。
            </div>
        </div>
        <button type="button" id="recent-history-reload" class="recent-history-reload">再計算</button>
    </div>

    <div id="recent-history-status" class="recent-history-status">
        「直近60R」タブを開くと集計します。初回は60R分の再計算で少し時間がかかる場合があります。
    </div>

    <div id="recent-history-content" hidden>
        <div id="recent-history-meta" class="recent-history-meta"></div>
        <div id="recent-history-summary" class="recent-history-summary"></div>
        <div id="recent-history-daily" class="recent-history-daily"></div>
        <div class="recent-history-table-wrap">
            <table class="recent-history-table">
                <thead>
                    <tr>
                        <th>日付</th>
                        <th>R</th>
                        <th>本命買い目</th>
                        <th>対抗買い目</th>
                        <th>実結果</th>
                        <th>本命</th>
                        <th>対抗</th>
                        <th>3連単払戻</th>
                    </tr>
                </thead>
                <tbody id="recent-history-body"></tbody>
            </table>
        </div>
        <div id="recent-history-errors" class="recent-history-errors"></div>
    </div>
</section>

<link rel="stylesheet" href="/web/assets/css/recent_prediction_history.css?v=20260826a">
<script src="/web/assets/js/recent_prediction_history.js?v=20260826a"></script>
