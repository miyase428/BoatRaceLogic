<?php
require_once __DIR__ . '/../logic/AiTrioRateLogic.php';
require_once __DIR__ . '/../logic/RecentCourseTrioRateLogic.php';
require_once __DIR__ . '/../logic/CommonSecondRuntimeBridge.php';

$aiTrioCourseByBoat = [];
if (!empty($simulation_active) && is_array($prediction_course_by_boat ?? null)) {
    $aiTrioCourseByBoat = $prediction_course_by_boat;
} elseif (!empty($entry_map_ready) && is_array($entry_course_by_boat ?? null)) {
    $aiTrioCourseByBoat = $entry_course_by_boat;
}

$aiTrioLogic = new AiTrioRateLogic();
$aiTrioData = $aiTrioLogic->calculate(
    (string)($race_code ?? ''),
    is_array($results ?? null) ? $results : [],
    is_array($tenji_list ?? null) ? $tenji_list : [],
    $aiTrioCourseByBoat,
    !empty($simulation_active)
);

$aiTrioStatus = (string)($aiTrioData['status'] ?? 'error');
$aiTrioError = (string)($aiTrioData['error'] ?? '');
$aiTrioBoats = is_array($aiTrioData['boats'] ?? null) ? $aiTrioData['boats'] : [];
$aiTrioTotals = is_array($aiTrioData['totals'] ?? null) ? $aiTrioData['totals'] : [];
$aiTrioMethod = is_array($aiTrioData['method'] ?? null) ? $aiTrioData['method'] : [];

// 最終予想テーブルの6ヶ月/3ヶ月は表示専用。
// 「その選手 × 今回の進入コース」で対象レース日時点から集計する。
// 過去実コースはresult_detail優先、欠損時のみ展示で補完し、完了レースだけを分母にする。
// 展示進入変更・仮想進入時もAI3連対率と同じ今回コースへ追従する。
// 既存の切る艇判定用 three_in_rate_6m / 3m は変更しない。
$recentCourseTrioLogic = new RecentCourseTrioRateLogic();
$recentCourseTrioData = $recentCourseTrioLogic->calculate(
    (string)($race_code ?? ''),
    $aiTrioCourseByBoat
);
$recentCourseTrioBoats = is_array($recentCourseTrioData['boats'] ?? null)
    ? $recentCourseTrioData['boats']
    : [];

// 1着率・2着率と同じく、予想進入のコース順（1C→6C）で表示する。
$aiTrioCourseToBoat = [];
foreach ($aiTrioBoats as $boatKey => $row) {
    $boat = (int)($row['lane'] ?? $boatKey);
    $course = (int)($row['course'] ?? 0);
    if ($boat >= 1 && $boat <= 6 && $course >= 1 && $course <= 6) {
        $aiTrioCourseToBoat[$course] = $boat;
    }
}

if (count($aiTrioCourseToBoat) !== 6) {
    if (is_array($prediction_boat_by_course ?? null) && count($prediction_boat_by_course) === 6) {
        for ($course = 1; $course <= 6; $course++) {
            $boat = (int)($prediction_boat_by_course[$course] ?? 0);
            if ($boat >= 1 && $boat <= 6) {
                $aiTrioCourseToBoat[$course] = $boat;
            }
        }
    }
}

if (count($aiTrioCourseToBoat) !== 6) {
    $aiTrioCourseToBoat = array_combine(range(1, 6), range(1, 6));
}
ksort($aiTrioCourseToBoat);
?>

<div style="margin: 0 0 14px; background-color:#0f172a; border:1px solid #334155; border-radius:8px; padding:14px;">
    <div style="margin-bottom:10px;">
        <div style="font-size:16px; font-weight:bold; color:#a78bfa;">🤖 AI3連対率</div>
        <div style="font-size:12px; color:#94a3b8; margin-top:3px;">
            基礎3連対率：場×進入コース → 選手×進入コース → 選手×場×進入コース / BB_MEDIUM RAW（K=20・10）
        </div>
        <div style="font-size:12px; color:#94a3b8; margin-top:2px;">
            AI：基礎3連対率 + 一次評価Z + 二次評価Z / ENTRY_MODEをP1学習 → P2完全ホールドアウト検証済み
        </div>
        <div style="font-size:12px; color:#94a3b8; margin-top:2px;">
            ※6艇300%への強制正規化なし / SUM・スリットは追加効果が小さいため未採用
        </div>
        <?php if (!empty($simulation_active)): ?>
            <div style="font-size:12px; color:#aa741f; margin-top:3px;">
                ※仮想進入 <?= htmlspecialchars((string)($prediction_entry_order ?? '')) ?> をAI3連対率にも反映した試算値
            </div>
        <?php elseif (!empty($prediction_entry_changed)): ?>
            <div style="font-size:12px; color:#2f789f; margin-top:3px;">
                ※展示進入 <?= htmlspecialchars((string)($prediction_entry_order ?? '')) ?> をAI3連対率へ反映済み
            </div>
        <?php endif; ?>
    </div>

    <?php if ($aiTrioStatus === 'ok' && count($aiTrioBoats) === 6): ?>
        <div style="overflow-x:auto;">
            <table style="width:100%; min-width:760px; border-collapse:collapse;">
                <thead>
                    <tr style="background-color:#1e293b;">
                        <th style="padding:8px; text-align:left; min-width:130px;">項目 / 進入</th>
                        <?php for ($course = 1; $course <= 6; $course++): ?>
                            <?php
                                $boat = (int)($aiTrioCourseToBoat[$course] ?? $course);
                                $c = $lane_colors[$boat] ?? $lane_colors[1];
                            ?>
                            <th style="padding:8px; text-align:center; min-width:95px;">
                                <div style="font-weight:bold; color:#f8fafc; white-space:nowrap;">
                                    <?= $course ?>コース
                                </div>
                                <div style="margin-top:4px;">
                                    <span class="lane-badge"
                                          style="background-color:<?= htmlspecialchars((string)$c['bg']) ?>; color:<?= htmlspecialchars((string)$c['text']) ?>; border:1px solid <?= htmlspecialchars((string)$c['border']) ?>; display:inline-block; min-width:58px; width:auto; height:auto; line-height:1.4; padding:3px 8px; border-radius:5px; box-sizing:border-box; white-space:nowrap; text-align:center; font-weight:bold;">
                                        <?= $boat ?>号艇
                                    </span>
                                </div>
                            </th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="padding:10px 8px; font-weight:bold; color:#f8fafc;">基礎3連対率</td>
                        <?php for ($course = 1; $course <= 6; $course++): ?>
                            <?php
                                $boat = (int)($aiTrioCourseToBoat[$course] ?? $course);
                                $rate = $aiTrioBoats[$boat]['base_rate'] ?? null;
                            ?>
                            <td style="padding:10px 8px; text-align:center; font-size:16px; font-weight:bold; color:#2f789f;">
                                <?= $rate !== null ? number_format((float)$rate, 2) . '%' : '-' ?>
                            </td>
                        <?php endfor; ?>
                    </tr>
                    <tr style="border-top:1px solid #334155;">
                        <td style="padding:10px 8px; font-weight:bold; color:#f8fafc;">AI3連対率</td>
                        <?php for ($course = 1; $course <= 6; $course++): ?>
                            <?php
                                $boat = (int)($aiTrioCourseToBoat[$course] ?? $course);
                                $row = $aiTrioBoats[$boat] ?? [];
                                $rate = $row['ai_rate'] ?? null;
                                $rank = (int)($row['ai_rank'] ?? 0);
                                $tip = sprintf(
                                    '%d号艇 / %dC / 一次 %.3f (Z %+.3f) / 二次 %.3f (Z %+.3f)',
                                    $boat,
                                    (int)($row['course'] ?? $course),
                                    (float)($row['primary_score'] ?? 0),
                                    (float)($row['primary_z'] ?? 0),
                                    (float)($row['secondary_score'] ?? 0),
                                    (float)($row['secondary_z'] ?? 0)
                                );
                            ?>
                            <td style="padding:10px 8px; text-align:center;" title="<?= htmlspecialchars($tip, ENT_QUOTES, 'UTF-8') ?>">
                                <div style="font-size:19px; font-weight:bold; color:#75659b;">
                                    <?= $rate !== null ? number_format((float)$rate, 2) . '%' : '-' ?>
                                </div>
                                <?php if ($rank >= 1 && $rank <= 6): ?>
                                    <div style="margin-top:2px; font-size:11px; color:#6b7785;">AI <?= $rank ?>位</div>
                                <?php endif; ?>
                            </td>
                        <?php endfor; ?>
                    </tr>
                </tbody>
            </table>
        </div>

        <div style="margin-top:8px; font-size:12px; color:#94a3b8;">
            基礎6艇合計 <?= number_format((float)($aiTrioTotals['base'] ?? 0), 2) ?>%
            / AI6艇合計 <?= number_format((float)($aiTrioTotals['ai'] ?? 0), 2) ?>%
            / ENTRY_MODE本番係数を固定
            <?= (($aiTrioMethod['entry_source'] ?? '') === 'virtual') ? ' / 仮想進入試算' : '' ?>
        </div>
    <?php else: ?>
        <div style="padding:8px 10px; background-color:#1e293b; border-radius:5px; color:#fca5a5; font-size:13px;">
            AI3連対率：<?= htmlspecialchars($aiTrioError !== '' ? $aiTrioError : '計算待ち', ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const aiRates = <?= json_encode(array_map(
        static fn(array $row) => isset($row['ai_rate']) ? (float)$row['ai_rate'] : null,
        $aiTrioBoats
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const recentRates = <?= json_encode(array_map(
        static fn(array $row) => [
            'course' => (int)($row['course'] ?? 0),
            'rate6' => isset($row['rate6_dec']) && $row['rate6_dec'] !== null ? (float)$row['rate6_dec'] * 100.0 : null,
            'top3_6' => (int)($row['top3_6'] ?? 0),
            'n6' => (int)($row['n6'] ?? 0),
            'rate3' => isset($row['rate3_dec']) && $row['rate3_dec'] !== null ? (float)$row['rate3_dec'] * 100.0 : null,
            'top3_3' => (int)($row['top3_3'] ?? 0),
            'n3' => (int)($row['n3'] ?? 0),
        ],
        $recentCourseTrioBoats
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const headings = Array.from(document.querySelectorAll('h2'));
    const heading = headings.find(function (el) {
        return el.textContent.includes('最終予想');
    });
    if (!heading) return;

    const tableContainer = heading.nextElementSibling;
    const table = tableContainer ? tableContainer.querySelector('table') : null;
    if (!table) return;

    const headerCells = Array.from(table.querySelectorAll('thead th'));
    const labels = headerCells.map(function (th) { return th.textContent.trim(); });
    let rate6Index = labels.indexOf('直近6ヶ月枠別3連対率');
    if (rate6Index < 0) rate6Index = labels.indexOf('直近6ヶ月3連対率');
    let rate3Index = labels.indexOf('直近3ヶ月枠別3連対率');
    if (rate3Index < 0) rate3Index = labels.indexOf('直近3ヶ月3連対率');
    let aiIndex = labels.indexOf('↓3連対期待値');
    if (aiIndex < 0) aiIndex = labels.indexOf('AI3連対率');

    if (rate6Index >= 0) {
        headerCells[rate6Index].textContent = '直近6ヶ月3連対率';
    }
    if (rate3Index >= 0) {
        headerCells[rate3Index].textContent = '直近3ヶ月3連対率';
    }
    if (aiIndex >= 0) {
        headerCells[aiIndex].textContent = 'AI3連対率';
    }

    const rateCellHtml = function (rate, top3, n) {
        if (rate === null || n <= 0) return '-';
        return '<div>' + Number(rate).toFixed(1) + '%</div>'
            + '<div style="margin-top:1px;font-size:10px;color:#6b7785;">('
            + Number(top3) + '/' + Number(n) + ')</div>';
    };

    Array.from(table.querySelectorAll('tbody tr')).forEach(function (row) {
        // index.phpの表示加工後は2列目が「○号艇」。加工前でも枠番=艇番なので同じ値を拾える。
        const boatCell = row.cells[1] || row.cells[0];
        const match = String(boatCell?.textContent || '').match(/[1-6]/);
        if (!match) return;
        const boat = Number(match[0]);
        const recent = recentRates[String(boat)] || recentRates[boat] || null;
        const aiRate = aiRates[String(boat)] ?? aiRates[boat] ?? null;

        if (recent && rate6Index >= 0 && row.cells[rate6Index]) {
            row.cells[rate6Index].innerHTML = rateCellHtml(recent.rate6, recent.top3_6, recent.n6);
            row.cells[rate6Index].title = recent.course + 'コース / 直近6ヶ月 ' + recent.top3_6 + '/' + recent.n6;
        }

        if (recent && rate3Index >= 0 && row.cells[rate3Index]) {
            row.cells[rate3Index].innerHTML = rateCellHtml(recent.rate3, recent.top3_3, recent.n3);
            row.cells[rate3Index].title = recent.course + 'コース / 直近3ヶ月 ' + recent.top3_3 + '/' + recent.n3;
        }

        if (aiIndex >= 0 && row.cells[aiIndex]) {
            row.cells[aiIndex].textContent = aiRate !== null
                ? Number(aiRate).toFixed(1) + '%'
                : '-';
            row.cells[aiIndex].title = 'ENTRY_MODE AI3連対率';
            row.cells[aiIndex].style.fontWeight = '700';
            row.cells[aiIndex].style.color = '#75659b';
        }
    });
});
</script>

<?php
// AI3連対率まで計算済みの同一スコープを利用し、出目確率をその直後へ表示する。
// 2連単表示はラッパー側でSecondPlaceProbabilityLogicへ差し替え、
// 120通り表示は既存パネルをそのまま維持する。
include __DIR__ . '/trifecta_probability_panel_common.php';

// PC版もアプリと同じ共通2着確率ブリッジへ接続する。
// trifecta_probability_panel_common.php で作成した同じ120通りを再利用し、
// 現在の本命頭に対する2着候補だけを③ AI_FINAL順位へ置き換える。
// 頭・kiru・3着候補は既存summaryを維持する。
if (is_array($trifectaData ?? null) && is_array($viewData ?? null)) {
    $commonSecondBridge = new CommonSecondRuntimeBridge();
    $commonSecondBridgeResult = $commonSecondBridge->apply(
        $viewData,
        is_array($final_predictions ?? null) ? $final_predictions : [],
        $trifectaData
    );

    $viewData = is_array($commonSecondBridgeResult['view_data'] ?? null)
        ? $commonSecondBridgeResult['view_data']
        : $viewData;
    extract($viewData, EXTR_OVERWRITE);
}

// C2/C3で検証した穴警戒HIGH + TRIO_OUTERを表示専用で追加する。
// DOM読込後に最終予想の買い目直下へ移動し、買い目計算には接続しない。
include __DIR__ . '/upset_alert_panel.php';

// 最終前方検証で採用したTRIO1_OUTCOMEを、参考買い目候補としてのみ表示する。
// 既存穴目パネルの計算済み変数を使い、本番買い目には接続しない。
include __DIR__ . '/upset_reference_bet_panel.php';
?>