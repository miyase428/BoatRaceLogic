<?php
// アプリの展示SUMはPC Webと同じ「レース適用値」表示へ揃える。
// スリット体系は現在Webで非表示のため、計算は残したままアプリ表示から外す。

$appSamRowsByCourse = [];
foreach (is_array($sam_applied_list ?? null) ? $sam_applied_list : [] as $row) {
    if (!is_array($row)) continue;
    $course = (int)($row['course'] ?? 0);
    if ($course >= 1 && $course <= 6) {
        $appSamRowsByCourse[$course] = $row;
    }
}
ksort($appSamRowsByCourse);

$appSamFeatureLabels = ['J列', 'K列', 'L列'];
$appSamFeatureDisplayNames = [
    'exhibition_time' => '展示タイム',
    'lap_time'        => '周回',
    'around_time'     => '周り足',
    'straight_time'   => '直線',
];
$appSamFeaturesPath = __DIR__ . '/../../theories/new_sam/features.json';
if (is_file($appSamFeaturesPath)) {
    $json = file_get_contents($appSamFeaturesPath);
    $all = is_string($json) ? json_decode($json, true) : null;
    $keys = is_array($all) ? ($all[(string)($selected_place ?? '')] ?? []) : [];
    if (is_array($keys) && count($keys) >= 3) {
        for ($i = 0; $i < 3; $i++) {
            $key = (string)($keys[$i] ?? '');
            if (isset($appSamFeatureDisplayNames[$key])) {
                $appSamFeatureLabels[$i] = $appSamFeatureDisplayNames[$key];
            }
        }
    }
}

$appSamBadge = static function (int $boat) use ($lane_colors): string {
    $c = $lane_colors[$boat] ?? $lane_colors[1];
    return '<span class="app-sam-boat-badge" data-boat="' . $boat . '" style="display:inline-block;min-width:46px;padding:3px 7px;border-radius:5px;box-sizing:border-box;text-align:center;font-weight:bold;font-size:11px;background:'
        . htmlspecialchars((string)$c['bg'], ENT_QUOTES, 'UTF-8')
        . ';color:' . htmlspecialchars((string)$c['text'], ENT_QUOTES, 'UTF-8')
        . ';border:1px solid ' . htmlspecialchars((string)$c['border'], ENT_QUOTES, 'UTF-8')
        . ';">' . $boat . '号艇</span>';
};

$appSamPct = static function ($value): string {
    return is_numeric($value) ? number_format((float)$value * 100.0, 0) . '%' : '-';
};
$appSamColor = static function ($value): string {
    if (!is_numeric($value)) return '#8a8176';
    if ((float)$value > 0) return '#2f789f';
    if ((float)$value < 0) return '#b65b4a';
    return '#6b7785';
};
?>

<section class="app-card app-sam-applied-card" style="overflow:hidden;">
    <div class="app-card-body" style="padding-bottom:8px;">
        <h2 class="app-section-title">📐 展示サム理論（レース適用値）</h2>
        <div class="app-note" style="margin-top:-3px;">艇番タップで選手SUM特性。艇番下に場SUM×選手SUMサイン・着順偏りを表示します。</div>
    </div>

    <?php if (count($appSamRowsByCourse) === 6): ?>
        <div style="overflow-x:auto;padding:0 10px 10px;-webkit-overflow-scrolling:touch;">
            <table style="width:100%;min-width:820px;border-collapse:collapse;font-size:11px;">
                <thead>
                    <tr style="background:#e8dfd2;color:#4b5866;">
                        <th style="padding:7px;text-align:left;">コース</th>
                        <th style="padding:7px;text-align:center;">艇番</th>
                        <th style="padding:7px;text-align:right;"><?= htmlspecialchars($appSamFeatureLabels[0], ENT_QUOTES, 'UTF-8') ?></th>
                        <th style="padding:7px;text-align:right;"><?= htmlspecialchars($appSamFeatureLabels[1], ENT_QUOTES, 'UTF-8') ?></th>
                        <th style="padding:7px;text-align:right;"><?= htmlspecialchars($appSamFeatureLabels[2], ENT_QUOTES, 'UTF-8') ?></th>
                        <th style="padding:7px;text-align:right;">合計</th>
                        <th style="padding:7px;text-align:right;">平均差</th>
                        <th style="padding:7px;text-align:right;">1着率</th>
                        <th style="padding:7px;text-align:right;">2着率</th>
                        <th style="padding:7px;text-align:right;">3着率</th>
                        <th style="padding:7px;text-align:right;">3連対率</th>
                    </tr>
                </thead>
                <tbody>
                <?php for ($course = 1; $course <= 6; $course++): ?>
                    <?php
                        $row = $appSamRowsByCourse[$course] ?? [];
                        $boat = (int)($row['teiban'] ?? $course);
                        if ($boat < 1 || $boat > 6) $boat = $course;
                        $avgDiff = $row['avg_diff'] ?? null;
                    ?>
                    <tr style="border-top:1px solid #ddd2c3;">
                        <td style="padding:7px;white-space:nowrap;font-weight:bold;"><?= $course ?>コース</td>
                        <td style="padding:7px;text-align:center;white-space:nowrap;"><?= $appSamBadge($boat) ?></td>
                        <td style="padding:7px;text-align:right;"><?= is_numeric($row['val_j'] ?? null) ? number_format((float)$row['val_j'], 2) : '-' ?></td>
                        <td style="padding:7px;text-align:right;"><?= is_numeric($row['val_k'] ?? null) ? number_format((float)$row['val_k'], 2) : '-' ?></td>
                        <td style="padding:7px;text-align:right;"><?= is_numeric($row['val_l'] ?? null) ? number_format((float)$row['val_l'], 2) : '-' ?></td>
                        <td style="padding:7px;text-align:right;font-weight:bold;"><?= is_numeric($row['sum'] ?? null) ? number_format((float)$row['sum'], 2) : '-' ?></td>
                        <td style="padding:7px;text-align:right;font-weight:bold;color:<?= is_numeric($avgDiff) ? ((float)$avgDiff < 0 ? '#2f789f' : ((float)$avgDiff > 0 ? '#b65b4a' : '#6b7785')) : '#8a8176' ?>;">
                            <?= is_numeric($avgDiff) ? sprintf('%+.3f', (float)$avgDiff) : '-' ?>
                        </td>
                        <?php foreach (['win', 'place2', 'place3', 'trio'] as $metric): ?>
                            <?php $v = $row[$metric] ?? null; ?>
                            <td style="padding:7px;text-align:right;font-weight:<?= $metric === 'trio' ? 'bold' : 'normal' ?>;color:<?= $appSamColor($v) ?>;">
                                <?= $appSamPct($v) ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endfor; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f2ece2;font-weight:bold;">
                        <td colspan="5" style="padding:7px;text-align:right;color:#6b7785;">全体平均:</td>
                        <td style="padding:7px;text-align:right;color:#2f789f;"><?= is_numeric($overall_avg ?? null) ? number_format((float)$overall_avg, 3) : '-' ?></td>
                        <td colspan="5"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    <?php else: ?>
        <div class="app-card-body app-note">展示サム理論：計算待ち、または場マスタ未取得です。</div>
    <?php endif; ?>
</section>
