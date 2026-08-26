<?php
// 場SUM（場×コース）と選手SUM特性を混同しないための比較専用表示。
// 予想ロジックには一切接続せず、3連対差の向きだけを比較する。
$pscMode = (string)($playerSamMode ?? 'pc');
$pscIsApp = $pscMode === 'app';

$pscPct = static function ($value): string {
    return is_numeric($value) ? number_format((float)$value * 100.0, 1) . '%' : '—';
};
$pscDiff = static function ($value): string {
    return is_numeric($value) ? sprintf('%+.1fpt', (float)$value * 100.0) : '—';
};
$pscColor = static function ($value): string {
    if (!is_numeric($value)) return '#8a8176';
    if ((float)$value > 0) return '#2f789f';
    if ((float)$value < 0) return '#b65b4a';
    return '#6b7785';
};

$pscVenueByBoat = [];
foreach (is_array($sam_applied_list ?? null) ? $sam_applied_list : [] as $samRow) {
    $boat = (int)($samRow['teiban'] ?? 0);
    if ($boat < 1 || $boat > 6) continue;
    $pscVenueByBoat[$boat] = is_numeric($samRow['trio'] ?? null)
        ? (float)$samRow['trio']
        : null;
}
?>

<?php if (($playerSamStatus ?? 'error') === 'ok' && count($playerSamBoats ?? []) === 6): ?>
<div id="player-sam-cross-panel"
     style="margin:<?= $pscIsApp ? '0 0 10px' : '0 0 16px' ?>;background:#fffaf2;border:1px solid #d8cdbc;border-radius:8px;padding:<?= $pscIsApp ? '9px' : '11px 12px' ?>;color:#3f4b5a;">
    <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:7px;">
        <strong style="font-size:<?= $pscIsApp ? '12px' : '13px' ?>;color:#75659b;">🔀 場SUM × 選手SUM 比較</strong>
        <span style="font-size:9px;color:#8a8176;">3連対差の方向比較 / 最終予想未反映</span>
    </div>
    <div style="font-size:10px;color:#6b7785;margin-bottom:7px;">
        場SUM＝その場・そのコースの傾向 / 選手SUM＝その選手自身の今回コースでの傾向。向きが逆なら要注意。
    </div>

    <div style="overflow-x:auto;">
        <table style="width:100%;min-width:580px;border-collapse:collapse;font-size:10px;">
            <thead>
                <tr style="background:#e8dfd2;color:#4b5866;">
                    <th style="padding:5px;text-align:left;">艇 / コース</th>
                    <th style="padding:5px;text-align:right;">場SUM 3連差</th>
                    <th style="padding:5px;text-align:right;">選手現帯 実3連</th>
                    <th style="padding:5px;text-align:right;">選手基準差</th>
                    <th style="padding:5px;text-align:center;">判定</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($playerSamBoats as $boat => $row): ?>
                <?php
                    $boat = (int)$boat;
                    $course = (int)($row['course'] ?? $boat);
                    $currentBand = is_array($row['current_band_stats'] ?? null) ? $row['current_band_stats'] : [];
                    $playerN = (int)($currentBand['n'] ?? 0);
                    $playerRate = $currentBand['rates']['trio'] ?? null;
                    $playerDiff = $currentBand['diff']['trio'] ?? null;
                    $venueDiff = $pscVenueByBoat[$boat] ?? null;

                    $label = '—';
                    $labelStyle = 'color:#8a8176;';
                    if ($playerN < 5 || !is_numeric($playerDiff)) {
                        $label = '選手参考外';
                        $labelStyle = 'color:#a06f30;';
                    } elseif (!is_numeric($venueDiff)) {
                        $label = '場SUMなし';
                    } else {
                        $v = (float)$venueDiff;
                        $p = (float)$playerDiff;
                        if (($v > 0 && $p < 0) || ($v < 0 && $p > 0)) {
                            $label = $p > 0 ? '⚠ 逆行（選手↑）' : '⚠ 逆行（選手↓）';
                            $labelStyle = 'display:inline-block;padding:2px 6px;border-radius:999px;background:#fff1cf;border:1px solid #e2b95f;color:#8a5a12;font-weight:bold;white-space:nowrap;';
                        } elseif ($v > 0 && $p > 0) {
                            $label = '一致 ↑';
                            $labelStyle = 'color:#2f789f;font-weight:bold;';
                        } elseif ($v < 0 && $p < 0) {
                            $label = '一致 ↓';
                            $labelStyle = 'color:#b65b4a;font-weight:bold;';
                        } else {
                            $label = '中立';
                        }
                    }
                ?>
                <tr style="border-top:1px solid #ddd2c3;">
                    <td style="padding:5px;white-space:nowrap;font-weight:bold;"><?= $boat ?>号艇 / <?= $course ?>C</td>
                    <td style="padding:5px;text-align:right;color:<?= $pscColor($venueDiff) ?>;font-weight:bold;"><?= $pscDiff($venueDiff) ?></td>
                    <td style="padding:5px;text-align:right;white-space:nowrap;">
                        <?= $playerN > 0 ? $pscPct($playerRate) . ' <span style="color:#8a8176;">N=' . $playerN . '</span>' : '—' ?>
                    </td>
                    <td style="padding:5px;text-align:right;color:<?= $pscColor($playerDiff) ?>;font-weight:bold;"><?= $pscDiff($playerDiff) ?></td>
                    <td style="padding:5px;text-align:center;"><span style="<?= $labelStyle ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const playerPanel = document.getElementById('player-sam-panel');
    const crossPanel = document.getElementById('player-sam-cross-panel');
    if (!playerPanel || !crossPanel) return;

    // 名称を「選手SUM特性」に統一する。
    Array.from(playerPanel.querySelectorAll('div')).some(function (el) {
        if (el.textContent.trim() === '👤 選手SUMチェッカー') {
            el.textContent = '👤 選手SUM特性';
            return true;
        }
        return false;
    });

    // PCでは元パネルがSUM表直下へ移動した後、そのさらに直下へ比較表を置く。
    playerPanel.insertAdjacentElement('afterend', crossPanel);
});
</script>
<?php endif; ?>
