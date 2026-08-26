<?php
// 選手SUM特性の表示補助。
// 計算ロジックには触れず、実率の併記・場SUMとの逆行警告・名称統一だけを行う。
$psUiAlerts = [];
$psUiBands = [];

$psUiVenueByBoat = [];
foreach (is_array($sam_applied_list ?? null) ? $sam_applied_list : [] as $samRow) {
    $boat = (int)($samRow['teiban'] ?? 0);
    if ($boat < 1 || $boat > 6) {
        continue;
    }
    $psUiVenueByBoat[$boat] = is_numeric($samRow['trio'] ?? null)
        ? (float)$samRow['trio']
        : null;
}

foreach (is_array($playerSamBoats ?? null) ? $playerSamBoats : [] as $boat => $row) {
    $boat = (int)$boat;
    $currentBand = is_array($row['current_band_stats'] ?? null) ? $row['current_band_stats'] : [];
    $playerN = (int)($currentBand['n'] ?? 0);
    $playerDiff = $currentBand['diff']['trio'] ?? null;
    $venueDiff = $psUiVenueByBoat[$boat] ?? null;

    $alert = '';
    if ($playerN >= 5 && is_numeric($playerDiff) && is_numeric($venueDiff)) {
        $v = (float)$venueDiff;
        $p = (float)$playerDiff;
        if (($v > 0 && $p < 0) || ($v < 0 && $p > 0)) {
            $alert = $p > 0 ? '⚠ 逆行（選手↑）' : '⚠ 逆行（選手↓）';
        }
    }
    $psUiAlerts[] = $alert;

    $bandRates = [];
    foreach (['plus_04', 'zero_plus_04', 'minus_04_zero', 'under_minus_04'] as $bandKey) {
        $band = is_array($row['bands'][$bandKey] ?? null) ? $row['bands'][$bandKey] : [];
        $n = (int)($band['n'] ?? 0);
        $rate = $band['rates']['trio'] ?? null;
        $bandRates[] = [
            'n' => $n,
            'rate' => is_numeric($rate) ? (float)$rate : null,
        ];
    }
    $psUiBands[] = $bandRates;
}

$psUiPayload = json_encode(
    [
        'alerts' => $psUiAlerts,
        'bands' => $psUiBands,
    ],
    JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
);
if (!is_string($psUiPayload)) {
    $psUiPayload = '{"alerts":[],"bands":[]}';
}
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const panel = document.getElementById('player-sam-panel');
    if (!panel) return;

    const payload = <?= $psUiPayload ?>;

    // JSで後付け変更する場合でも確実に名称を統一する。
    const walker = document.createTreeWalker(panel, NodeFilter.SHOW_TEXT);
    let textNode;
    while ((textNode = walker.nextNode())) {
        if (textNode.nodeValue && textNode.nodeValue.includes('選手SUMチェッカー')) {
            textNode.nodeValue = textNode.nodeValue.replace('選手SUMチェッカー', '選手SUM特性');
            break;
        }
    }

    const details = Array.from(panel.querySelectorAll('details'));
    details.forEach(function (detail, index) {
        const summary = detail.querySelector('summary');
        if (!summary) return;
        const summaryRow = summary.querySelector('div');

        const alert = Array.isArray(payload.alerts) ? (payload.alerts[index] || '') : '';
        if (alert && summaryRow && !summary.querySelector('.player-sam-reverse-badge')) {
            const badge = document.createElement('span');
            badge.className = 'player-sam-reverse-badge';
            badge.textContent = alert;
            badge.style.cssText = 'display:inline-block;padding:2px 6px;border-radius:999px;background:#fff1cf;border:1px solid #e2b95f;color:#8a5a12;font-size:10px;font-weight:bold;white-space:nowrap;';
            summaryRow.appendChild(badge);
        }

        const table = detail.querySelector('table');
        if (!table || table.dataset.playerSamActualRateAdded === '1') return;
        table.dataset.playerSamActualRateAdded = '1';

        const headRow = table.querySelector('thead tr');
        const headerCells = headRow ? headRow.querySelectorAll('th') : [];
        if (headRow && headerCells.length >= 6) {
            const th = document.createElement('th');
            th.textContent = '実3連対率';
            th.style.cssText = 'padding:6px;text-align:right;white-space:nowrap;';
            headRow.insertBefore(th, headerCells[headerCells.length - 1]);
        }

        const bandRows = Array.from(table.querySelectorAll('tbody tr'));
        const dataRows = Array.isArray(payload.bands) && Array.isArray(payload.bands[index])
            ? payload.bands[index]
            : [];

        bandRows.forEach(function (tr, bandIndex) {
            const cells = tr.querySelectorAll('td');
            if (cells.length < 6) return;
            const info = dataRows[bandIndex] || {};
            const td = document.createElement('td');
            td.style.cssText = 'padding:6px;text-align:right;font-weight:bold;color:#4b5866;white-space:nowrap;';
            if (typeof info.rate === 'number' && Number.isFinite(info.rate) && Number(info.n || 0) > 0) {
                td.textContent = (info.rate * 100).toFixed(1) + '%';
            } else {
                td.textContent = '—';
            }
            tr.insertBefore(td, cells[cells.length - 1]);
        });
    });
});
</script>
