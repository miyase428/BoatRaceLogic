<?php
// アプリのメイン情報向け：展示SUM理論とスリット体系を表示する。
// 計算はIndexControllerで作成済みの値をそのまま利用し、予想ロジックには触れない。

$appSamSlitBoatByCourse = [];
for ($course = 1; $course <= 6; $course++) {
    $boat = (int)($prediction_boat_by_course[$course] ?? $course);
    $appSamSlitBoatByCourse[$course] = ($boat >= 1 && $boat <= 6) ? $boat : $course;
}

$appSamSlitBadge = static function (int $boat) use ($lane_colors): string {
    $c = $lane_colors[$boat] ?? $lane_colors[1];
    return '<span style="display:inline-block;min-width:42px;padding:3px 6px;border-radius:5px;box-sizing:border-box;text-align:center;font-weight:bold;font-size:11px;background:'
        . htmlspecialchars((string)$c['bg'], ENT_QUOTES, 'UTF-8')
        . ';color:' . htmlspecialchars((string)$c['text'], ENT_QUOTES, 'UTF-8')
        . ';border:1px solid ' . htmlspecialchars((string)$c['border'], ENT_QUOTES, 'UTF-8')
        . ';">' . $boat . '号</span>';
};

$appSamSlitDiff = static function ($value, int $digits = 1): string {
    return is_numeric($value) ? sprintf('%+.' . $digits . 'fpt', (float)$value * 100.0) : '-';
};

$appSamSlitDiffColor = static function ($value): string {
    if (!is_numeric($value)) return '#8a8176';
    if ((float)$value > 0) return '#2f789f';
    if ((float)$value < 0) return '#b65b4a';
    return '#6b7785';
};

$appSamRowsByCourse = [];
foreach (is_array($sam_applied_list ?? null) ? $sam_applied_list : [] as $row) {
    if (!is_array($row)) continue;
    $course = (int)($row['course'] ?? 0);
    if ($course >= 1 && $course <= 6) {
        $appSamRowsByCourse[$course] = $row;
    }
}
ksort($appSamRowsByCourse);

$appSlitFeatures = [];
foreach (is_array($slit_pattern['features'] ?? null) ? $slit_pattern['features'] : [] as $key => $enabled) {
    if ($enabled === true) {
        $appSlitFeatures[] = (string)($feature_name[$key] ?? $key);
    }
}
?>

<section class="app-card" style="overflow:hidden;">
    <div class="app-card-body" style="padding-bottom:8px;">
        <h2 class="app-section-title">📐 展示SUM理論（レース適用値）</h2>
        <div class="app-note" style="margin-top:-3px;">今回の展示値を場×コースのSUM帯へ当てたバフ・デバフ。平均差はマイナスほど6艇平均より速い。</div>
    </div>

    <?php if (count($appSamRowsByCourse) === 6): ?>
        <div style="overflow-x:auto;padding:0 10px 10px;">
            <table style="width:100%;min-width:700px;border-collapse:collapse;font-size:11px;">
                <thead>
                    <tr style="background:#e8dfd2;color:#4b5866;">
                        <th style="padding:7px;text-align:left;">進入 / 艇</th>
                        <th style="padding:7px;text-align:right;">SUM</th>
                        <th style="padding:7px;text-align:right;">平均差</th>
                        <th style="padding:7px;text-align:center;">区間</th>
                        <th style="padding:7px;text-align:right;">1着差</th>
                        <th style="padding:7px;text-align:right;">2着差</th>
                        <th style="padding:7px;text-align:right;">3着差</th>
                        <th style="padding:7px;text-align:right;">3連対差</th>
                    </tr>
                </thead>
                <tbody>
                <?php for ($course = 1; $course <= 6; $course++): ?>
                    <?php
                        $row = $appSamRowsByCourse[$course] ?? [];
                        $boat = (int)($row['teiban'] ?? $appSamSlitBoatByCourse[$course] ?? $course);
                        $avgDiff = $row['avg_diff'] ?? null;
                    ?>
                    <tr style="border-top:1px solid #ddd2c3;">
                        <td style="padding:7px;white-space:nowrap;"><strong><?= $course ?>C</strong> <?= $appSamSlitBadge($boat) ?></td>
                        <td style="padding:7px;text-align:right;font-weight:bold;"><?= is_numeric($row['sum'] ?? null) ? number_format((float)$row['sum'], 2) : '-' ?></td>
                        <td style="padding:7px;text-align:right;font-weight:bold;color:<?= is_numeric($avgDiff) ? ((float)$avgDiff < 0 ? '#2f789f' : ((float)$avgDiff > 0 ? '#b65b4a' : '#6b7785')) : '#8a8176' ?>;">
                            <?= is_numeric($avgDiff) ? sprintf('%+.3f', (float)$avgDiff) : '-' ?>
                        </td>
                        <td style="padding:7px;text-align:center;white-space:nowrap;"><?= htmlspecialchars((string)($row['interval'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        <?php foreach (['win', 'place2', 'place3', 'trio'] as $metric): ?>
                            <?php $v = $row[$metric] ?? null; ?>
                            <td style="padding:7px;text-align:right;font-weight:<?= $metric === 'trio' ? 'bold' : 'normal' ?>;color:<?= $appSamSlitDiffColor($v) ?>;">
                                <?= $appSamSlitDiff($v) ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
        </div>
        <div class="app-note" style="padding:0 12px 11px;">6艇SUM平均：<?= is_numeric($overall_avg ?? null) ? number_format((float)$overall_avg, 3) : '-' ?></div>
    <?php else: ?>
        <div class="app-card-body app-note">展示SUM理論：計算待ち、または場マスタ未取得です。</div>
    <?php endif; ?>
</section>

<section class="app-card" style="overflow:hidden;">
    <div class="app-card-body" style="padding-bottom:8px;">
        <h2 class="app-section-title">📊 スリット体系</h2>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:4px;">
            <span style="font-size:11px;color:#6b7785;">PID <?= htmlspecialchars((string)($slit_pattern['id'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></span>
            <strong style="display:inline-block;padding:3px 8px;border-radius:999px;background:#e7f3fa;color:#2f789f;font-size:12px;">
                <?= htmlspecialchars((string)($slit_pattern['name'] ?? '不明'), ENT_QUOTES, 'UTF-8') ?>
            </strong>
        </div>
        <?php if (!empty($slit_pattern['desc'])): ?>
            <div class="app-note" style="margin-top:5px;"><?= htmlspecialchars((string)$slit_pattern['desc'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($appSlitFeatures): ?>
            <div class="app-note" style="margin-top:4px;">特徴：<?= htmlspecialchars(implode(' / ', $appSlitFeatures), ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
    </div>

    <?php if (!empty($slit_data)): ?>
        <div style="overflow-x:auto;padding:0 10px 10px;">
            <table style="width:100%;min-width:570px;border-collapse:collapse;font-size:11px;">
                <thead>
                    <tr style="background:#e8dfd2;color:#4b5866;">
                        <th style="padding:7px;text-align:left;">進入 / 艇</th>
                        <th style="padding:7px;text-align:right;">1着差</th>
                        <th style="padding:7px;text-align:right;">2着差</th>
                        <th style="padding:7px;text-align:right;">3着差</th>
                        <th style="padding:7px;text-align:right;">3連対差</th>
                    </tr>
                </thead>
                <tbody>
                <?php for ($course = 1; $course <= 6; $course++): ?>
                    <?php
                        $metrics = $slit_data[$course] ?? $slit_data[(string)$course] ?? [];
                        $boat = (int)($appSamSlitBoatByCourse[$course] ?? $course);
                    ?>
                    <tr style="border-top:1px solid #ddd2c3;">
                        <td style="padding:7px;white-space:nowrap;"><strong><?= $course ?>C</strong> <?= $appSamSlitBadge($boat) ?></td>
                        <?php foreach (['win', 'place2', 'place3', 'trio'] as $metric): ?>
                            <?php $v = $metrics[$metric] ?? null; ?>
                            <td style="padding:7px;text-align:right;font-weight:<?= $metric === 'trio' ? 'bold' : 'normal' ?>;color:<?= $appSamSlitDiffColor($v) ?>;">
                                <?= $appSamSlitDiff($v) ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endfor; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="app-card-body app-note">スリット体系：データを取得できませんでした。</div>
    <?php endif; ?>
</section>
