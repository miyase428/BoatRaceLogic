<?php
require_once __DIR__ . '/../logic/AiTenkaiTrialLogic.php';
require_once __DIR__ . '/../../common/db_connect.php';

$aiTenkaiAppCompact = (string)($stadiumCharacteristicsMode ?? '') === 'app';
$aiTenkaiTrial = [
    'status' => 'waiting',
    'message' => '展示情報が揃うとAI展開予想を表示します。',
    'top5' => [],
    'venue_races' => 0,
];

try {
    $aiTenkaiLogic = new AiTenkaiTrialLogic();
    $aiTenkaiTrial = $aiTenkaiLogic->calculate(
        getPDO(),
        (string)($selected_date ?? date('Y-m-d')),
        (string)($selected_place ?? ''),
        is_array($corrected_win_rate_data ?? null) ? $corrected_win_rate_data : [],
        is_array($kimarite_data ?? null) ? $kimarite_data : [],
        is_array($prediction_course_by_boat ?? null) ? $prediction_course_by_boat : []
    );
} catch (Throwable $e) {
    $aiTenkaiTrial = [
        'status' => 'error',
        'message' => 'AI展開予想の計算に失敗しました。',
        'top5' => [],
        'venue_races' => 0,
    ];
}

$aiTenkaiStatus = (string)($aiTenkaiTrial['status'] ?? 'waiting');
$aiTenkaiTop5 = is_array($aiTenkaiTrial['top5'] ?? null) ? $aiTenkaiTrial['top5'] : [];
$aiTenkaiVenueRaces = (int)($aiTenkaiTrial['venue_races'] ?? 0);
?>
<div id="ai-tenkai-trial-panel" style="margin:12px 0 14px;background:#fffaf2;border:1px solid #d8cdbc;border-radius:8px;padding:14px;color:#334155;">
    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
        <div style="font-size:16px;font-weight:800;color:#75659b;">🧭 AI展開予想</div>
        <span style="font-size:10px;font-weight:800;padding:2px 6px;border-radius:999px;background:#eee8f7;color:#75659b;border:1px solid #d7cbe8;">試験</span>
    </div>

    <?php if ($aiTenkaiStatus === 'ok' && $aiTenkaiTop5): ?>
        <div style="font-size:11px;color:#6b7785;margin-bottom:10px;line-height:1.6;">
            展示後の補正後1着率 × 選手の6ヶ月/1年決まり手を平滑化。場平均は対象日前日まで直近1年<?= $aiTenkaiVenueRaces > 0 ? '（' . number_format($aiTenkaiVenueRaces) . 'R）' : '' ?>。
        </div>
        <div style="overflow-x:auto;">
            <table style="width:100%;min-width:<?= $aiTenkaiAppCompact ? '0' : '620px' ?>;border-collapse:collapse;font-size:<?= $aiTenkaiAppCompact ? '11px' : '12px' ?>;<?= $aiTenkaiAppCompact ? 'table-layout:fixed;' : '' ?>">
                <thead>
                    <tr>
                        <th style="text-align:left;padding:7px <?= $aiTenkaiAppCompact ? '4px' : '8px' ?>;border-bottom:1px solid #ddd2c2;<?= $aiTenkaiAppCompact ? 'width:43%;' : '' ?>">展開</th>
                        <th style="text-align:right;padding:7px <?= $aiTenkaiAppCompact ? '3px' : '8px' ?>;border-bottom:1px solid #ddd2c2;<?= $aiTenkaiAppCompact ? 'width:19%;' : '' ?>">今回</th>
                        <th style="text-align:right;padding:7px <?= $aiTenkaiAppCompact ? '3px' : '8px' ?>;border-bottom:1px solid #ddd2c2;<?= $aiTenkaiAppCompact ? 'width:19%;' : '' ?>">場平均</th>
                        <th style="text-align:right;padding:7px <?= $aiTenkaiAppCompact ? '3px' : '8px' ?>;border-bottom:1px solid #ddd2c2;<?= $aiTenkaiAppCompact ? 'width:19%;' : '' ?>">差</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($aiTenkaiTop5 as $index => $event): ?>
                    <?php
                        $boat = (int)($event['boat'] ?? 0);
                        $course = (int)($event['course'] ?? 0);
                        $tech = (string)($event['tech'] ?? '-');
                        $prob = (float)($event['prob'] ?? 0.0);
                        $venueAverage = (float)($event['venue_average'] ?? 0.0);
                        $diff = (float)($event['diff'] ?? 0.0);
                        $diffColor = $diff >= 0.0 ? '#b45309' : '#64748b';
                    ?>
                    <tr>
                        <td style="padding:9px <?= $aiTenkaiAppCompact ? '4px' : '8px' ?>;border-bottom:1px solid #eee4d7;font-weight:700;white-space:nowrap;">
                            <span style="display:inline-block;min-width:<?= $aiTenkaiAppCompact ? '15px' : '22px' ?>;color:#8a779f;"><?= $index + 1 ?>.</span>
                            <?php if ($aiTenkaiAppCompact): ?>
                                <?= htmlspecialchars((string)$boat, ENT_QUOTES, 'UTF-8') ?>号/<?= htmlspecialchars((string)$course, ENT_QUOTES, 'UTF-8') ?>C <?= htmlspecialchars($tech, ENT_QUOTES, 'UTF-8') ?>
                            <?php else: ?>
                                <?= htmlspecialchars((string)$boat, ENT_QUOTES, 'UTF-8') ?>号艇 / <?= htmlspecialchars((string)$course, ENT_QUOTES, 'UTF-8') ?>C が「<?= htmlspecialchars($tech, ENT_QUOTES, 'UTF-8') ?>」
                            <?php endif; ?>
                        </td>
                        <td style="padding:9px <?= $aiTenkaiAppCompact ? '3px' : '8px' ?>;border-bottom:1px solid #eee4d7;text-align:right;font-weight:800;font-size:<?= $aiTenkaiAppCompact ? '12px' : '14px' ?>;white-space:nowrap;">
                            <?= number_format($prob, 1) ?>%
                        </td>
                        <td style="padding:9px <?= $aiTenkaiAppCompact ? '3px' : '8px' ?>;border-bottom:1px solid #eee4d7;text-align:right;white-space:nowrap;">
                            <?= number_format($venueAverage, 1) ?>%
                        </td>
                        <td style="padding:9px <?= $aiTenkaiAppCompact ? '3px' : '8px' ?>;border-bottom:1px solid #eee4d7;text-align:right;font-weight:800;color:<?= $diffColor ?>;white-space:nowrap;">
                            <?= $diff >= 0.0 ? '+' : '' ?><?= number_format($diff, 1) ?>pt
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="font-size:10px;color:#8a939f;margin-top:8px;line-height:1.5;">
            ※ 試験表示です。現在は主要決まり手（逃げ・差し・まくり・まくり差し）の上位5展開を表示しています。
        </div>
    <?php else: ?>
        <div style="font-size:12px;color:#6b7785;padding:8px 0 2px;">
            <?= htmlspecialchars((string)($aiTenkaiTrial['message'] ?? '展示情報待ち'), ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>
</div>