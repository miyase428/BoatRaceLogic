<?php
// 通常時の進入変更だけ、基本1着率を展示進入基準で再計算する。
// 仮想進入時はController側ですでに試算進入を渡しているため再計算しない。
if (
    empty($simulation_active)
    && !empty($prediction_entry_changed)
    && !empty($prediction_course_by_boat)
    && is_array($prediction_course_by_boat)
    && !empty($race_code)
) {
    $baseWinRateLogicForPanel = new BaseWinRateLogic();
    $base_win_rate_data = $baseWinRateLogicForPanel->calculate(
        (string)$race_code,
        $prediction_course_by_boat
    );
}

$baseWinBoats = $base_win_rate_data['boats'] ?? [];
$baseWinError = $base_win_rate_data['error'] ?? '';
$baseWinRawTotal = (float)($base_win_rate_data['raw_total'] ?? 0.0);

$correctedWinBoats = $corrected_win_rate_data['boats'] ?? [];
$correctedWinStatus = $corrected_win_rate_data['status'] ?? 'error';
$correctedWinError = $corrected_win_rate_data['error'] ?? '';
$correctedMethod = $corrected_win_rate_data['method'] ?? [];
$correctedExLabel = in_array($selected_place ?? '', ['AMG', 'TKY'], true)
    ? 'EX_TOTAL3（展示＋周回＋周り足）'
    : 'EX_TOTAL';

// 決まり手表・1着率表は「予想に使うコース基準」で見出しを作る。
// 通常時は展示進入、仮想進入モード時は試算進入。
$kimariteCourseToBoat = !empty($prediction_boat_by_course)
    ? $prediction_boat_by_course
    : ($boat_by_entry_course ?? []);

$kimariteHeaderMap = [];
for ($course = 1; $course <= 6; $course++) {
    $boat = (int)($kimariteCourseToBoat[$course] ?? $course);

    if ($boat < 1 || $boat > 6) {
        $boat = $course;
    }

    $boatColor = $lane_colors[$boat] ?? $lane_colors[1];
    $kimariteHeaderMap[(string)$course] = [
        'boat'   => $boat,
        'bg'     => (string)($boatColor['bg'] ?? '#334155'),
        'text'   => (string)($boatColor['text'] ?? '#ffffff'),
        'border' => (string)($boatColor['border'] ?? '#64748b'),
    ];
}
?>

<?php if (!empty($simulation_active)): ?>
    <div style="margin: 14px 0; background:#172554; border:1px solid #3b82f6; border-radius:8px; padding:11px 14px; color:#dbeafe;">
        <strong>🧪 仮想進入モード</strong>
        <span style="margin-left:12px;">展示進入 <?= htmlspecialchars((string)($exhibition_entry_order ?? '123456')) ?></span>
        <span style="margin:0 8px; color:#93c5fd;">→</span>
        <span>試算進入 <strong><?= htmlspecialchars((string)($prediction_entry_order ?? '123456')) ?></strong></span>
        <div style="margin-top:4px; font-size:12px; color:#bfdbfe;">
            決まり手・基本/補正後1着率・本命/対抗・最終順位は試算進入で再計算。展示値そのものは実測値を使用。
        </div>
    </div>
<?php elseif (!empty($virtual_entry_error)): ?>
    <div style="margin:14px 0; background:#450a0a; border:1px solid #ef4444; border-radius:8px; padding:10px 14px; color:#fecaca;">
        <?= htmlspecialchars((string)$virtual_entry_error) ?>
    </div>
<?php endif; ?>

<div style="margin: 18px 0 14px; background-color: #0f172a; border: 1px solid #334155; border-radius: 8px; padding: 14px;">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:10px;">
        <div>
            <div style="font-size:16px; font-weight:bold; color:#38bdf8;">🎯 1着率</div>
            <div style="font-size:12px; color:#94a3b8; margin-top:3px;">
                基本：場×コース → 選手×コース → 選手×場×コース / BB_MEDIUM K=20・10
            </div>
            <div style="font-size:12px; color:#94a3b8; margin-top:2px;">
                補正後：展示進入 → <?= htmlspecialchars($correctedExLabel) ?> β=0.10 → SUM_RAW γ=2.0 → スリット α=0.25 / 各段階6艇100%正規化
            </div>
            <?php if (!empty($simulation_active)): ?>
                <div style="font-size:12px; color:#93c5fd; margin-top:3px;">
                    ※現在は仮想進入 <?= htmlspecialchars((string)$prediction_entry_order) ?> 基準で計算
                </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($baseWinBoats)): ?>
            <div style="font-size:12px; color:#94a3b8; white-space:nowrap;">
                基本正規化前 <?= number_format($baseWinRawTotal * 100, 2) ?>%
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($baseWinBoats)): ?>
        <div style="overflow-x:auto;">
            <table style="width:100%; min-width:760px; border-collapse:collapse;">
                <thead>
                    <tr style="background-color:#1e293b;">
                        <th style="padding:8px; text-align:left; min-width:130px;">項目 / 進入</th>
                        <?php for ($course = 1; $course <= 6; $course++): ?>
                            <?php
                                $header = $kimariteHeaderMap[(string)$course] ?? [];
                                $boat = (int)($header['boat'] ?? $course);
                                $c = $lane_colors[$boat] ?? $lane_colors[1];
                            ?>
                            <th style="padding:8px; text-align:center; min-width:95px;">
                                <div style="font-weight:bold; color:#f8fafc; white-space:nowrap;">
                                    <?= $course ?>コース
                                </div>
                                <div style="margin-top:4px;">
                                    <span class="lane-badge"
                                          style="background-color:<?= $c['bg'] ?>; color:<?= $c['text'] ?>; border:1px solid <?= $c['border'] ?>; display:inline-block; min-width:54px; padding:2px 7px; border-radius:4px; box-sizing:border-box; white-space:nowrap; text-align:center; font-weight:bold;">
                                        <?= $boat ?>号艇
                                    </span>
                                </div>
                            </th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="padding:10px 8px; font-weight:bold; color:#f8fafc;">場1着率（コース別）</td>
                        <?php for ($course = 1; $course <= 6; $course++): ?>
                            <?php
                                $boat = (int)($kimariteHeaderMap[(string)$course]['boat'] ?? $course);
                                $rate = isset($baseWinBoats[$boat]['p0'])
                                    ? ((float)$baseWinBoats[$boat]['p0'] * 100.0)
                                    : null;
                            ?>
                            <td style="padding:10px 8px; text-align:center; font-size:16px; font-weight:bold; color:#cbd5e1;">
                                <?= $rate !== null ? number_format($rate, 2) . '%' : '-' ?>
                            </td>
                        <?php endfor; ?>
                    </tr>
                    <tr style="border-top:1px solid #334155;">
                        <td style="padding:10px 8px; font-weight:bold; color:#f8fafc;">基本1着率</td>
                        <?php for ($course = 1; $course <= 6; $course++): ?>
                            <?php
                                $boat = (int)($kimariteHeaderMap[(string)$course]['boat'] ?? $course);
                                $rate = $baseWinBoats[$boat]['normalized_rate'] ?? null;
                            ?>
                            <td style="padding:10px 8px; text-align:center; font-size:18px; font-weight:bold; color:#38bdf8;">
                                <?= $rate !== null ? number_format((float)$rate, 2) . '%' : '-' ?>
                            </td>
                        <?php endfor; ?>
                    </tr>
                    <tr style="border-top:1px solid #334155;">
                        <td style="padding:10px 8px; font-weight:bold; color:#f8fafc;">補正後1着率</td>
                        <?php for ($course = 1; $course <= 6; $course++): ?>
                            <?php
                                $boat = (int)($kimariteHeaderMap[(string)$course]['boat'] ?? $course);
                                $rate = $correctedWinBoats[(string)$boat]['corrected_rate']
                                    ?? $correctedWinBoats[$boat]['corrected_rate']
                                    ?? null;
                            ?>
                            <td style="padding:10px 8px; text-align:center; font-size:18px; font-weight:bold; color:#fbbf24;">
                                <?= $rate !== null ? number_format((float)$rate, 2) . '%' : '-' ?>
                            </td>
                        <?php endfor; ?>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php if ($correctedWinStatus === 'ok'): ?>
            <div style="margin-top:8px; font-size:12px; color:#94a3b8;">
                補正後6艇合計 <?= number_format((float)($corrected_win_rate_data['totals']['corrected'] ?? 0), 2) ?>%
                <?php if (isset($correctedMethod['slit_pattern_id'])): ?>
                    / スリット PID=<?= htmlspecialchars((string)$correctedMethod['slit_pattern_id']) ?>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div style="margin-top:8px; font-size:12px; color:#fca5a5;">
                補正後1着率：<?= htmlspecialchars($correctedWinError !== '' ? $correctedWinError : '展示情報待ち') ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div style="padding:8px 10px; background-color:#1e293b; border-radius:5px; color:#fca5a5; font-size:13px;">
            基本1着率を取得できませんでした<?= $baseWinError !== '' ? '：' . htmlspecialchars($baseWinError) : '' ?>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const entryMap = <?= json_encode($kimariteHeaderMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const kimariteData = <?= json_encode($kimarite_data ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const exhibitionEntryOrder = <?= json_encode((string)($exhibition_entry_order ?? '123456'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const virtualEntry = <?= json_encode((string)($virtual_entry ?? '123456'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const simulateEntry = <?= !empty($simulate_entry) ? 'true' : 'false' ?>;
    const entryMapReady = <?= !empty($entry_map_ready) ? 'true' : 'false' ?>;

    // サム理論の区間別マスタは普段は閉じ、必要なときだけ表示する。
    const samBlock = document.getElementById('sam-block');
    const samToggleButton = document.getElementById('toggle-sam');
    if (samBlock && samToggleButton) {
        samBlock.style.display = 'none';
        samToggleButton.textContent = 'サム理論を表示する';
    }

    // 旧「進入コース(6桁)」欄を、展示進入表示＋仮想進入試算UIへ置き換える。
    const oldInput = document.querySelector('input[name="in_course"]');
    const formGroup = oldInput ? oldInput.closest('.form-group') : null;
    if (formGroup) {
        formGroup.innerHTML = '';

        const label = document.createElement('label');
        label.textContent = '進入シミュレーション';
        formGroup.appendChild(label);

        const actual = document.createElement('div');
        actual.style.marginBottom = '7px';
        actual.style.fontSize = '13px';
        actual.style.color = '#cbd5e1';
        actual.innerHTML = '展示進入：<strong style="color:#f8fafc;">' +
            (entryMapReady ? exhibitionEntryOrder : '展示情報待ち') + '</strong>';
        formGroup.appendChild(actual);

        const row = document.createElement('div');
        row.style.display = 'flex';
        row.style.alignItems = 'center';
        row.style.gap = '7px';
        row.style.flexWrap = 'wrap';

        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.name = 'simulate_entry';
        checkbox.value = '1';
        checkbox.checked = simulateEntry;
        checkbox.id = 'simulate-entry-checkbox';
        checkbox.style.width = 'auto';
        checkbox.style.margin = '0';

        const checkLabel = document.createElement('label');
        checkLabel.htmlFor = 'simulate-entry-checkbox';
        checkLabel.textContent = '仮想進入で試算';
        checkLabel.style.margin = '0';
        checkLabel.style.whiteSpace = 'nowrap';
        checkLabel.style.cursor = 'pointer';

        const input = document.createElement('input');
        input.type = 'text';
        input.name = 'virtual_entry';
        input.maxLength = 6;
        input.inputMode = 'numeric';
        input.value = virtualEntry;
        input.placeholder = '例: 126345';
        input.style.width = '92px';
        input.style.margin = '0';
        input.style.opacity = checkbox.checked ? '1' : '0.55';

        checkbox.addEventListener('change', function () {
            input.style.opacity = checkbox.checked ? '1' : '0.55';
        });

        row.appendChild(checkbox);
        row.appendChild(checkLabel);
        row.appendChild(input);
        formGroup.appendChild(row);

        const help = document.createElement('div');
        help.style.marginTop = '5px';
        help.style.fontSize = '11px';
        help.style.color = '#94a3b8';
        help.textContent = 'コース順の艇番を入力（例: 126345 = 3Cに6号艇）';
        formGroup.appendChild(help);
    }

    const matrixTable = document.querySelector('.matrix-table');
    if (!matrixTable) return;

    const rows = Array.from(matrixTable.querySelectorAll('tbody tr'));

    // コース見出しに「予想で使う艇番」を併記する。
    rows.forEach(function (row) {
        const cells = Array.from(row.cells || []);
        if (cells.length < 7 || cells[0].textContent.trim() !== '決まり手') {
            return;
        }

        for (let course = 1; course <= 6; course++) {
            const cell = cells[course];
            const info = entryMap[String(course)];
            if (!cell || !info) continue;

            cell.innerHTML = '';

            const courseLabel = document.createElement('div');
            courseLabel.textContent = course + 'コース';
            courseLabel.style.whiteSpace = 'nowrap';
            cell.appendChild(courseLabel);

            const badgeWrap = document.createElement('div');
            badgeWrap.style.marginTop = '4px';

            const badge = document.createElement('span');
            badge.className = 'lane-badge';
            badge.textContent = info.boat + '号艇';
            badge.style.backgroundColor = info.bg;
            badge.style.color = info.text;
            badge.style.border = '1px solid ' + info.border;
            badge.style.display = 'inline-block';
            badge.style.minWidth = '54px';
            badge.style.padding = '2px 7px';
            badge.style.borderRadius = '4px';
            badge.style.boxSizing = 'border-box';
            badge.style.whiteSpace = 'nowrap';
            badge.style.textAlign = 'center';
            badge.style.fontWeight = 'bold';

            badgeWrap.appendChild(badge);
            cell.appendChild(badgeWrap);
        }
    });

    // 決まり手の率に「発生回数 / 選手×コースの集計母数」を併記する。
    const metricKeys = {
        '逃げ / 逃がし':      {1: 'nige',            2: 'nogashi'},
        '差され / 差し':      {1: 'sasare',          2: 'sashi', 3: 'sashi', 4: 'sashi', 5: 'sashi', 6: 'sashi'},
        '捲られ / 捲り':      {1: 'makurare',        2: 'makuri', 3: 'makuri', 4: 'makuri', 5: 'makuri', 6: 'makuri'},
        '捲られ差 / 捲り差し': {1: 'makurarezashi',   2: 'makurizashi', 3: 'makurizashi', 4: 'makurizashi', 5: 'makurizashi', 6: 'makurizashi'}
    };

    let period = null;

    rows.forEach(function (row) {
        const cells = Array.from(row.cells || []);
        if (!cells.length) return;

        const label = cells[0].textContent.trim();

        if (label.includes('決まり手（直近1年）')) {
            period = '1year';
            return;
        }
        if (label.includes('決まり手（直近6ヶ月）')) {
            period = '6month';
            return;
        }

        const rowKeys = metricKeys[label];
        if (!period || !rowKeys || cells.length < 7) {
            return;
        }

        for (let course = 1; course <= 6; course++) {
            const key = rowKeys[course];
            if (!key) continue;

            const courseData = kimariteData[String(course)] || kimariteData[course] || {};
            const periodData = courseData[period] || {};
            const sampleN = Number(periodData._sample_n || 0);
            const counts = periodData._counts || {};
            const count = Number(counts[key] || 0);
            const rate = Number(periodData[key] || 0);
            const cell = cells[course];
            if (!cell) continue;

            if (sampleN > 0) {
                cell.textContent = rate.toFixed(1) + '%（' + count + '/' + sampleN + '）';
                cell.title = '発生 ' + count + '回 / 選手がこのコースを走った集計対象 ' + sampleN + '走';
            } else {
                cell.textContent = '-';
                cell.title = '集計対象なし';
            }
        }
    });
});
</script>

<?php include __DIR__ . '/head1_second_place_panel.php'; ?>