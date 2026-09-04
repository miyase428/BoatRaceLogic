<?php
require_once __DIR__ . '/../logic/TrifectaProbabilityLogic.php';
require_once __DIR__ . '/../logic/AiTrioRateLogic.php';
require_once __DIR__ . '/../logic/BaseWinRateLogic.php';

$outcomeCourseByBoat = [];
if (is_array($aiTrioBoats ?? null) && count($aiTrioBoats) === 6) {
    foreach ($aiTrioBoats as $boatKey => $row) {
        $boat = (int)($row['lane'] ?? $boatKey);
        $course = (int)($row['course'] ?? 0);
        if ($boat >= 1 && $boat <= 6 && $course >= 1 && $course <= 6) {
            $outcomeCourseByBoat[$boat] = $course;
        }
    }
}

if (count($outcomeCourseByBoat) !== 6 && is_array($prediction_course_by_boat ?? null)) {
    for ($boat = 1; $boat <= 6; $boat++) {
        $course = (int)($prediction_course_by_boat[$boat] ?? 0);
        if ($course >= 1 && $course <= 6) {
            $outcomeCourseByBoat[$boat] = $course;
        }
    }
}

$trifectaLogic = new TrifectaProbabilityLogic();
$trifectaData = $trifectaLogic->calculate(
    (string)($race_code ?? ''),
    is_array($correctedWinBoats ?? null) ? $correctedWinBoats : [],
    is_array($aiTrioBoats ?? null) ? $aiTrioBoats : [],
    $outcomeCourseByBoat
);

$trifectaStatus = (string)($trifectaData['status'] ?? 'error');
$trifectaError = (string)($trifectaData['error'] ?? '');
$trifectaRows = is_array($trifectaData['rows'] ?? null) ? $trifectaData['rows'] : [];
$trifectaTop20 = is_array($trifectaData['top20'] ?? null) ? $trifectaData['top20'] : [];
$trifectaHistory = is_array($trifectaData['history'] ?? null) ? $trifectaData['history'] : [];
$trifectaTotals = is_array($trifectaData['totals'] ?? null) ? $trifectaData['totals'] : [];
$trifectaBoatByCourse = is_array($trifectaData['boat_by_course'] ?? null)
    ? $trifectaData['boat_by_course']
    : [];

// Webの2連単30通り・3連単120通り専用表示データ。
// 正式な$trifectaDataは既存の共通2着ロジック等でそのまま使い、展示前の暫定値は流さない。
$trifectaDisplayMode = 'exhibition';
$trifectaDisplayData = $trifectaData;

if ($trifectaStatus !== 'ok' || count($trifectaRows) !== 120) {
    $trifectaDisplayMode = 'provisional';

    // 展示前は枠なり進入。仮想進入中のみ指定進入を使う。
    $provisionalCourseByBoat = [];
    if (
        !empty($simulation_active)
        && is_array($prediction_course_by_boat ?? null)
        && count($prediction_course_by_boat) === 6
    ) {
        $provisionalCourseByBoat = $prediction_course_by_boat;
    } else {
        for ($boat = 1; $boat <= 6; $boat++) {
            $provisionalCourseByBoat[$boat] = $boat;
        }
    }

    // 1着側は展示補正前の基本1着率を使う。
    $provisionalBaseWinBoats = is_array($baseWinBoats ?? null) ? $baseWinBoats : [];
    if (!empty($simulation_active)) {
        $provisionalBaseWinLogic = new BaseWinRateLogic();
        $provisionalBaseWinData = $provisionalBaseWinLogic->calculate(
            (string)($race_code ?? ''),
            $provisionalCourseByBoat
        );
        if (is_array($provisionalBaseWinData['boats'] ?? null) && count($provisionalBaseWinData['boats']) === 6) {
            $provisionalBaseWinBoats = $provisionalBaseWinData['boats'];
        }
    }

    $provisionalWinBoats = [];
    for ($boat = 1; $boat <= 6; $boat++) {
        $rate = $provisionalBaseWinBoats[$boat]['normalized_rate']
            ?? $provisionalBaseWinBoats[(string)$boat]['normalized_rate']
            ?? null;
        if (is_numeric($rate)) {
            $provisionalWinBoats[$boat] = ['corrected_rate' => (float)$rate];
        }
    }

    // AI3連対率は展示由来の二次評価だけ中立（Z=0）として暫定計算する。
    $neutralTenjiList = [];
    for ($boat = 1; $boat <= 6; $boat++) {
        $neutralTenjiList[] = [
            'teiban' => $boat,
            'tenji_course' => (int)$provisionalCourseByBoat[$boat],
            'final_2nd_score' => 0.0,
        ];
    }

    $provisionalAiTrioLogic = new AiTrioRateLogic();
    $provisionalAiTrioData = $provisionalAiTrioLogic->calculate(
        (string)($race_code ?? ''),
        is_array($results ?? null) ? $results : [],
        $neutralTenjiList,
        $provisionalCourseByBoat,
        true
    );
    $provisionalAiTrioBoats = is_array($provisionalAiTrioData['boats'] ?? null)
        ? $provisionalAiTrioData['boats']
        : [];

    $trifectaDisplayData = $trifectaLogic->calculate(
        (string)($race_code ?? ''),
        $provisionalWinBoats,
        $provisionalAiTrioBoats,
        $provisionalCourseByBoat
    );
}

$trifectaDisplayStatus = (string)($trifectaDisplayData['status'] ?? 'error');
$trifectaDisplayError = (string)($trifectaDisplayData['error'] ?? '');
$trifectaDisplayRows = is_array($trifectaDisplayData['rows'] ?? null) ? $trifectaDisplayData['rows'] : [];
$trifectaDisplayTop20 = is_array($trifectaDisplayData['top20'] ?? null) ? $trifectaDisplayData['top20'] : [];
$trifectaDisplayHistory = is_array($trifectaDisplayData['history'] ?? null) ? $trifectaDisplayData['history'] : [];
$trifectaDisplayTotals = is_array($trifectaDisplayData['totals'] ?? null) ? $trifectaDisplayData['totals'] : [];

$trifectaCum = static function (array $rows, int $n): float {
    if ($n <= 0 || empty($rows)) {
        return 0.0;
    }
    return array_sum(array_map(
        static fn(array $r): float => (float)($r['probability'] ?? 0.0),
        array_slice($rows, 0, $n)
    ));
};

$trifectaBoatBadge = static function (int $boat) use ($lane_colors): string {
    $c = $lane_colors[$boat] ?? $lane_colors[1];
    return '<span class="lane-badge" style="background-color:'
        . htmlspecialchars((string)$c['bg'], ENT_QUOTES, 'UTF-8')
        . ';color:'
        . htmlspecialchars((string)$c['text'], ENT_QUOTES, 'UTF-8')
        . ';border:1px solid '
        . htmlspecialchars((string)$c['border'], ENT_QUOTES, 'UTF-8')
        . ';display:inline-block;min-width:42px;width:auto;height:auto;line-height:1.35;padding:2px 6px;border-radius:4px;box-sizing:border-box;white-space:nowrap;text-align:center;font-weight:bold;font-size:12px;">'
        . $boat . '号艇</span>';
};

$renderTrifectaTable = static function (array $rows) use ($trifectaBoatBadge): void {
    ?>
    <div style="overflow-x:auto;">
        <table style="width:100%; min-width:720px; border-collapse:collapse;">
            <thead>
                <tr style="background-color:#e8dfd2; color:#2b3440;">
                    <th style="padding:7px 8px; text-align:center; width:54px;">順位</th>
                    <th style="padding:7px 8px; text-align:left; min-width:210px;">3連単</th>
                    <th style="padding:7px 8px; text-align:right; min-width:105px;">基礎出目</th>
                    <th style="padding:7px 8px; text-align:right; min-width:105px;">最終出目確率</th>
                    <th style="padding:7px 8px; text-align:right; min-width:105px;">基礎差</th>
                    <th style="padding:7px 8px; text-align:right; min-width:105px;">累計</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php
                    $boats = is_array($row['boats'] ?? null) ? $row['boats'] : [];
                    $base = (float)($row['base_probability'] ?? 0.0);
                    $final = (float)($row['probability'] ?? 0.0);
                    $delta = $final - $base;
                    $cum = (float)($row['cumulative_probability'] ?? 0.0);
                ?>
                <tr style="border-top:1px solid #d8cdbc;">
                    <td style="padding:7px 8px; text-align:center; color:#6b7785; font-weight:bold;">
                        <?= (int)($row['rank'] ?? 0) ?>
                    </td>
                    <td style="padding:7px 8px; white-space:nowrap;">
                        <?php if (count($boats) === 3): ?>
                            <?= $trifectaBoatBadge((int)$boats[0]) ?>
                            <span style="color:#8a8176; margin:0 4px;">-</span>
                            <?= $trifectaBoatBadge((int)$boats[1]) ?>
                            <span style="color:#8a8176; margin:0 4px;">-</span>
                            <?= $trifectaBoatBadge((int)$boats[2]) ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td style="padding:7px 8px; text-align:right; color:#6b7785;">
                        <?= number_format($base * 100.0, 3) ?>%
                    </td>
                    <td style="padding:7px 8px; text-align:right; font-size:16px; font-weight:bold; color:#aa741f;">
                        <?= number_format($final * 100.0, 3) ?>%
                    </td>
                    <td style="padding:7px 8px; text-align:right; color:<?= $delta >= 0 ? '#2f789f' : '#6b7785' ?>;">
                        <?= sprintf('%+.3fpt', $delta * 100.0) ?>
                    </td>
                    <td style="padding:7px 8px; text-align:right; color:#3f4b5a;">
                        <?= number_format($cum * 100.0, 2) ?>%
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
};
