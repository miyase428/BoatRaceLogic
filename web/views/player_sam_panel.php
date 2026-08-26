<?php
require_once __DIR__ . '/../logic/PlayerSamLogic.php';

$playerSamMode = (string)($playerSamMode ?? 'pc');
$playerSamLogic = new PlayerSamLogic();
$playerSamData = $playerSamLogic->calculate(
    (string)($race_code ?? ''),
    is_array($entries ?? null) ? $entries : [],
    is_array($prediction_course_by_boat ?? null) ? $prediction_course_by_boat : [],
    is_array($sam_applied_list ?? null) ? $sam_applied_list : []
);

$playerSamStatus = (string)($playerSamData['status'] ?? 'error');
$playerSamError = (string)($playerSamData['error'] ?? '');
$playerSamBoats = is_array($playerSamData['boats'] ?? null) ? $playerSamData['boats'] : [];
$playerSamBands = is_array($playerSamData['bands'] ?? null) ? $playerSamData['bands'] : [];

$psPct = static function ($value, int $digits = 1): string {
    return is_numeric($value) ? number_format((float)$value * 100.0, $digits) . '%' : '-';
};
$psDiff = static function ($value): string {
    if (!is_numeric($value)) return '—';
    return sprintf('%+.1fpt', (float)$value * 100.0);
};
$psDiffColor = static function ($value): string {
    if (!is_numeric($value)) return '#94a3b8';
    $v = (float)$value;
    if ($v > 0) return '#2f789f';
    if ($v < 0) return '#b65b4a';
    return '#6b7785';
};
$psBoatBadge = static function (int $boat) use ($lane_colors): string {
    $c = $lane_colors[$boat] ?? $lane_colors[1];
    return '<span style="display:inline-block;min-width:48px;padding:3px 7px;border-radius:5px;box-sizing:border-box;white-space:nowrap;text-align:center;font-weight:bold;font-size:12px;background:'
        . htmlspecialchars((string)$c['bg'], ENT_QUOTES, 'UTF-8')
        . ';color:' . htmlspecialchars((string)$c['text'], ENT_QUOTES, 'UTF-8')
        . ';border:1px solid ' . htmlspecialchars((string)$c['border'], ENT_QUOTES, 'UTF-8')
        . ';">' . $boat . '号艇</span>';
};
$isApp = $playerSamMode === 'app';
?>

<div id="player-sam-panel"
     style="margin:<?= $isApp ? '0 0 10px' : '18px 0 16px' ?>;background:#f8f4ec;border:1px solid #d8cdbc;border-radius:8px;padding:<?= $isApp ? '10px' : '14px' ?>;color:#3f4b5a;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;margin-bottom:9px;">
        <div>
            <div style="font-size:<?= $isApp ? '14px' : '16px' ?>;font-weight:bold;color:#75659b;">👤 選手SUMチェッカー</div>
            <div style="margin-top:3px;font-size:11px;color:#6b7785;">
                選手×今回進入コース / DB内過去SUM履歴 / 基準＝その選手自身の同コース全体
            </div>
            <div style="margin-top:2px;font-size:11px;color:#6b7785;">
                ※SUMはタイム合計の平均との差。マイナス側ほど6艇平均より速い。表示専用で予想補正には未接続。
            </div>
        </div>
        <div style="font-size:10px;color:#8a8176;white-space:nowrap;">4区分 / N&lt;5は参考外</div>
    </div>

    <?php if ($playerSamStatus !== 'ok' || count($playerSamBoats) !== 6): ?>
        <div style="padding:9px 10px;background:#f2ece2;border-radius:6px;color:#a74932;font-size:12px;">
            選手SUM：<?= htmlspecialchars($playerSamError !== '' ? $playerSamError : '計算待ち', ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php else: ?>
        <div style="display:grid;gap:7px;">
            <?php foreach ($playerSamBoats as $boat => $row): ?>
                <?php
                    $boat = (int)$boat;
                    $course = (int)($row['course'] ?? $boat);
                    $base = is_array($row['base'] ?? null) ? $row['base'] : [];
                    $baseRates = is_array($base['rates'] ?? null) ? $base['rates'] : [];
                    $currentDiff = $row['current_diff'] ?? null;
                    $currentBandKey = (string)($row['current_band_key'] ?? '');
                    $currentBand = is_array($row['current_band_stats'] ?? null) ? $row['current_band_stats'] : null;
                    $currentTrioDiff = $currentBand['diff']['trio'] ?? null;
                    $currentN = (int)($currentBand['n'] ?? 0);
                    $currentDiffColor = is_numeric($currentDiff)
                        ? ((float)$currentDiff < 0 ? '#2f789f' : ((float)$currentDiff > 0 ? '#b65b4a' : '#6b7785'))
                        : '#94a3b8';
                ?>
                <details style="border:1px solid #d8cdbc;border-radius:7px;background:#fffaf2;overflow:hidden;">
                    <summary style="cursor:pointer;list-style:auto;padding:8px 10px;">
                        <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap;">
                            <?= $psBoatBadge($boat) ?>
                            <strong style="font-size:12px;color:#334155;"><?= $course ?>C</strong>
                            <strong style="font-size:12px;color:#334155;"><?= htmlspecialchars((string)($row['player_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                            <span style="font-size:10px;color:#8a8176;">#<?= htmlspecialchars((string)($row['player_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                            <span style="font-size:11px;color:<?= $currentDiffColor ?>;font-weight:bold;">
                                現在SUM <?= is_numeric($currentDiff) ? sprintf('%+.3f', (float)$currentDiff) : '-' ?>
                            </span>
                            <span style="font-size:11px;color:#6b7785;">
                                <?= htmlspecialchars((string)($row['current_band_label'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <span style="font-size:11px;color:#6b7785;">N=<?= (int)($base['n'] ?? 0) ?></span>
                            <span style="font-size:11px;color:#6b7785;">基準3連 <?= $psPct($baseRates['trio'] ?? null) ?></span>
                            <?php if ($currentBand !== null): ?>
                                <span style="font-size:11px;font-weight:bold;color:<?= $psDiffColor($currentTrioDiff) ?>;">
                                    現帯3連 <?= $currentN < 5 ? '参考外' : $psDiff($currentTrioDiff) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </summary>

                    <div style="padding:0 9px 10px;">
                        <div style="display:flex;gap:10px;flex-wrap:wrap;margin:5px 0 8px;padding:7px 8px;background:#f2ece2;border-radius:5px;font-size:11px;">
                            <span>通算 N=<strong><?= (int)($base['n'] ?? 0) ?></strong></span>
                            <span>1着 <strong><?= $psPct($baseRates['win'] ?? null) ?></strong></span>
                            <span>2着 <strong><?= $psPct($baseRates['place2'] ?? null) ?></strong></span>
                            <span>3着 <strong><?= $psPct($baseRates['place3'] ?? null) ?></strong></span>
                            <span>3連対 <strong><?= $psPct($baseRates['trio'] ?? null) ?></strong></span>
                            <?php if (!empty($base['min_date']) || !empty($base['max_date'])): ?>
                                <span style="color:#8a8176;">
                                    <?= htmlspecialchars((string)($base['min_date'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>〜<?= htmlspecialchars((string)($base['max_date'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div style="overflow-x:auto;">
                            <table style="width:100%;min-width:590px;border-collapse:collapse;font-size:11px;">
                                <thead>
                                    <tr style="background:#e8dfd2;color:#4b5866;">
                                        <th style="padding:6px;text-align:left;">SUM平均との差</th>
                                        <th style="padding:6px;text-align:right;">件数</th>
                                        <th style="padding:6px;text-align:right;">1着差</th>
                                        <th style="padding:6px;text-align:right;">2着差</th>
                                        <th style="padding:6px;text-align:right;">3着差</th>
                                        <th style="padding:6px;text-align:right;">3連対差</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($playerSamBands as $bandKey => $bandLabel): ?>
                                        <?php
                                            $band = is_array($row['bands'][$bandKey] ?? null) ? $row['bands'][$bandKey] : [];
                                            $n = (int)($band['n'] ?? 0);
                                            $isCurrent = $currentBandKey === (string)$bandKey;
                                            $reliability = (string)($band['reliability'] ?? '');
                                        ?>
                                        <tr style="border-top:1px solid #ddd2c3;<?= $isCurrent ? 'background:#eef7fb;' : '' ?>">
                                            <td style="padding:6px;font-weight:<?= $isCurrent ? 'bold' : 'normal' ?>;white-space:nowrap;">
                                                <?= htmlspecialchars((string)$bandLabel, ENT_QUOTES, 'UTF-8') ?>
                                                <?= $isCurrent ? '<span style="margin-left:4px;color:#2f789f;font-size:10px;">←現在</span>' : '' ?>
                                            </td>
                                            <td style="padding:6px;text-align:right;white-space:nowrap;">
                                                <?= $n ?><?= $reliability !== '' ? '<span style="margin-left:4px;color:#a06f30;font-size:9px;">' . htmlspecialchars($reliability, ENT_QUOTES, 'UTF-8') . '</span>' : '' ?>
                                            </td>
                                            <?php foreach (['win', 'place2', 'place3', 'trio'] as $metric): ?>
                                                <?php $diff = $band['diff'][$metric] ?? null; ?>
                                                <td style="padding:6px;text-align:right;font-weight:<?= $metric === 'trio' ? 'bold' : 'normal' ?>;color:<?= $psDiffColor($diff) ?>;">
                                                    <?= $psDiff($diff) ?>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php if (!$isApp): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const panel = document.getElementById('player-sam-panel');
    if (!panel) return;
    const heading = Array.from(document.querySelectorAll('h2')).find(function (el) {
        return el.textContent.includes('展示サム理論（レース適用値）');
    });
    if (!heading) return;
    const block = heading.nextElementSibling;
    if (block) {
        block.insertAdjacentElement('afterend', panel);
    } else {
        heading.insertAdjacentElement('afterend', panel);
    }
});
</script>
<?php endif; ?>
