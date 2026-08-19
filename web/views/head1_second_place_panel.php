<?php
require_once __DIR__ . '/../logic/Head1SecondPlaceLogic.php';

$head1SecondLogic = new Head1SecondPlaceLogic();
$head1SecondData = $head1SecondLogic->calculate(
    (string)($race_code ?? ''),
    is_array($prediction_course_by_boat ?? null) ? $prediction_course_by_boat : []
);

$head1SecondBoats = $head1SecondData['boats'] ?? [];
$head1SecondStatus = $head1SecondData['status'] ?? 'error';
$head1SecondError = $head1SecondData['error'] ?? '';
$head1SecondWarning = $head1SecondData['warning'] ?? '';
$head1SecondVenueN = (int)($head1SecondData['venue_n'] ?? 0);
$head1SecondGlobalN = (int)($head1SecondData['global_n'] ?? 0);
$head1SecondK = (float)($head1SecondData['k_pc'] ?? 10.0);

// 2着率の計算結果そのものから course -> boat を作り、
// 見出しと数値を同じ進入基準でコース順に表示する。
$head1SecondCourseToBoat = [];
foreach ($head1SecondBoats as $boatKey => $row) {
    $boat = (int)($row['lane'] ?? $boatKey);
    $course = (int)($row['course'] ?? $boat);
    if ($boat >= 1 && $boat <= 6 && $course >= 1 && $course <= 6) {
        $head1SecondCourseToBoat[$course] = $boat;
    }
}
for ($course = 1; $course <= 6; $course++) {
    if (!isset($head1SecondCourseToBoat[$course])) {
        $head1SecondCourseToBoat[$course] = $course;
    }
}
ksort($head1SecondCourseToBoat);
?>

<div style="margin: 0 0 14px; background-color:#0f172a; border:1px solid #334155; border-radius:8px; padding:14px;">
    <div style="margin-bottom:10px;">
        <div style="font-size:16px; font-weight:bold; color:#a78bfa;">🎯 1号艇1着時の2着率</div>
        <div style="font-size:12px; color:#94a3b8; margin-top:3px;">
            場2着率：<?= htmlspecialchars((string)($place_names[$selected_place] ?? $selected_place ?? '')) ?>で1号艇が1着したときの実コース別2着率（比較表示のみ）
        </div>
        <div style="font-size:12px; color:#94a3b8; margin-top:2px;">
            基本2着率：全場コース別p0 → 選手×実コース（直前100走） / K=<?= number_format($head1SecondK, 0) ?> → 2～6号艇100%正規化
        </div>
        <?php if (!empty($simulation_active)): ?>
            <div style="font-size:12px; color:#93c5fd; margin-top:3px;">
                ※現在は仮想進入 <?= htmlspecialchars((string)($prediction_entry_order ?? '123456')) ?> 基準で計算
            </div>
        <?php endif; ?>
    </div>

    <?php if ($head1SecondStatus === 'ok' && !empty($head1SecondBoats)): ?>
        <div style="overflow-x:auto;">
            <table style="width:100%; min-width:760px; border-collapse:collapse;">
                <thead>
                    <tr style="background-color:#1e293b;">
                        <th style="padding:8px; text-align:left; min-width:130px;">項目 / 進入</th>
                        <?php for ($course = 1; $course <= 6; $course++): ?>
                            <?php
                                $boat = (int)($head1SecondCourseToBoat[$course] ?? $course);
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
                        <td style="padding:10px 8px; font-weight:bold; color:#f8fafc;">場2着率</td>
                        <?php for ($course = 1; $course <= 6; $course++): ?>
                            <?php
                                $boat = (int)($head1SecondCourseToBoat[$course] ?? $course);
                                $rate = $head1SecondBoats[$boat]['venue_rate'] ?? null;
                            ?>
                            <td style="padding:10px 8px; text-align:center; font-size:16px; font-weight:bold; color:#cbd5e1;">
                                <?= $boat === 1 ? '-' : ($rate !== null ? number_format((float)$rate, 2) . '%' : '-') ?>
                            </td>
                        <?php endfor; ?>
                    </tr>
                    <tr style="border-top:1px solid #334155;">
                        <td style="padding:10px 8px; font-weight:bold; color:#f8fafc;">基本2着率</td>
                        <?php for ($course = 1; $course <= 6; $course++): ?>
                            <?php
                                $boat = (int)($head1SecondCourseToBoat[$course] ?? $course);
                                $rate = $head1SecondBoats[$boat]['basic_rate'] ?? null;
                            ?>
                            <td style="padding:10px 8px; text-align:center; font-size:18px; font-weight:bold; color:#a78bfa;">
                                <?= $boat === 1 ? '-' : ($rate !== null ? number_format((float)$rate, 2) . '%' : '-') ?>
                            </td>
                        <?php endfor; ?>
                    </tr>
                </tbody>
            </table>
        </div>

        <div style="margin-top:8px; font-size:12px; color:#94a3b8;">
            場母数 <?= number_format($head1SecondVenueN) ?>R / 全場p0母数 <?= number_format($head1SecondGlobalN) ?>R
            / 基本2着率は2～6号艇合計100%
        </div>

        <?php if ($head1SecondVenueN <= 0): ?>
            <div style="margin-top:5px; font-size:12px; color:#fca5a5;">
                場2着率：対象場の履歴がないため表示できません
            </div>
        <?php endif; ?>

        <?php if ($head1SecondWarning !== ''): ?>
            <div style="margin-top:5px; font-size:12px; color:#fbbf24;">
                <?= htmlspecialchars($head1SecondWarning) ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div style="padding:8px 10px; background-color:#1e293b; border-radius:5px; color:#fca5a5; font-size:13px;">
            2着率を取得できませんでした<?= $head1SecondError !== '' ? '：' . htmlspecialchars($head1SecondError) : '' ?>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const table = document.querySelector('.summary-box table');
    if (!table) return;

    const laneColors = <?= json_encode($lane_colors ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    // 買い目生成用の加工列は内部値として残し、画面だけ非表示にする。
    let headers = Array.from(table.querySelectorAll('thead th'));
    const removeIndexes = [];

    headers.forEach(function (th, index) {
        const label = th.textContent.trim();
        if (label === '相手候補(加工)' || label === '切る艇(加工)') {
            removeIndexes.push(index);
        }
    });

    removeIndexes.sort(function (a, b) { return b - a; });
    removeIndexes.forEach(function (index) {
        Array.from(table.rows).forEach(function (row) {
            if (row.cells[index]) {
                row.deleteCell(index);
            }
        });
    });

    headers = Array.from(table.querySelectorAll('thead th')).map(function (th) {
        return th.textContent.trim();
    });

    const headIndex = headers.indexOf('頭');
    const aiteIndex = headers.indexOf('相手候補');
    const kiruIndex = headers.indexOf('切る艇');

    function makeBoatBadge(boat) {
        const color = laneColors[String(boat)] || laneColors[boat] || laneColors['1'] || laneColors[1] || {};
        const badge = document.createElement('span');
        badge.className = 'lane-badge';
        badge.textContent = boat + '号艇';
        badge.style.backgroundColor = color.bg || '#334155';
        badge.style.color = color.text || '#ffffff';
        badge.style.border = '1px solid ' + (color.border || '#64748b');
        badge.style.display = 'inline-block';
        badge.style.minWidth = '54px';
        badge.style.padding = '2px 7px';
        badge.style.borderRadius = '4px';
        badge.style.boxSizing = 'border-box';
        badge.style.whiteSpace = 'nowrap';
        badge.style.textAlign = 'center';
        badge.style.fontWeight = 'bold';
        return badge;
    }

    function parseBoats(text) {
        const matches = String(text || '').match(/[1-6]/g) || [];
        const boats = [];
        matches.forEach(function (value) {
            const boat = Number(value);
            if (!boats.includes(boat)) {
                boats.push(boat);
            }
        });
        return boats;
    }

    function renderBoatBadges(cell) {
        if (!cell) return;
        const boats = parseBoats(cell.textContent);
        if (!boats.length) return;

        cell.textContent = '';
        const wrap = document.createElement('div');
        wrap.style.display = 'flex';
        wrap.style.justifyContent = 'center';
        wrap.style.alignItems = 'center';
        wrap.style.flexWrap = 'wrap';
        wrap.style.gap = '5px';

        boats.forEach(function (boat) {
            wrap.appendChild(makeBoatBadge(boat));
        });

        cell.appendChild(wrap);
    }

    Array.from(table.querySelectorAll('tbody tr')).forEach(function (row) {
        if (headIndex >= 0) renderBoatBadges(row.cells[headIndex]);
        if (aiteIndex >= 0) renderBoatBadges(row.cells[aiteIndex]);
        if (kiruIndex >= 0) renderBoatBadges(row.cells[kiruIndex]);
    });
});

// 決まり手表は直近1年 / 直近6ヶ月をタブで切り替える。
// 集計値や決まり手ロジックは変更せず、既存行の表示だけを整理する。
document.addEventListener('DOMContentLoaded', function () {
    const matrix = document.querySelector('.matrix-table');
    if (!matrix || !matrix.tBodies.length) return;

    const rows = Array.from(matrix.tBodies[0].rows);
    const yearTitle = rows.find(function (row) {
        return row.textContent.indexOf('決まり手（直近1年）') !== -1;
    });
    const halfTitle = rows.find(function (row) {
        return row.textContent.indexOf('決まり手（直近6ヶ月）') !== -1;
    });

    if (!yearTitle || !halfTitle) return;

    const yearTitleIndex = rows.indexOf(yearTitle);
    const halfTitleIndex = rows.indexOf(halfTitle);
    if (yearTitleIndex < 0 || halfTitleIndex <= yearTitleIndex) return;

    // タイトル行そのものはタブに置き換えるため常時非表示。
    yearTitle.style.display = 'none';
    halfTitle.style.display = 'none';

    const yearRows = rows.slice(yearTitleIndex + 1, halfTitleIndex);
    const halfRows = rows.slice(halfTitleIndex + 1);

    // 次セクションが同じtbodyへ追加された場合に巻き込まないため、
    // 「決まり手」表として想定する先頭5行（列見出し + 4決まり手）だけを対象にする。
    const yearGroup = yearRows.slice(0, 5);
    const halfGroup = halfRows.slice(0, 5);

    if (!yearGroup.length || !halfGroup.length) return;

    const tabRow = document.createElement('tr');
    tabRow.className = 'kimarite-period-tabs';
    const tabCell = document.createElement('td');
    tabCell.colSpan = 7;
    tabCell.style.padding = '8px 10px';
    tabCell.style.backgroundColor = '#e8dfd2';
    tabCell.style.borderTop = '2px solid #cbbda9';
    tabCell.style.borderBottom = '1px solid #cbbda9';

    const wrap = document.createElement('div');
    wrap.style.display = 'flex';
    wrap.style.alignItems = 'center';
    wrap.style.gap = '8px';

    const label = document.createElement('span');
    label.textContent = '🎯 決まり手';
    label.style.fontWeight = 'bold';
    label.style.color = '#2b3440';
    label.style.marginRight = '5px';

    function makeTab(text, period) {
        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = text;
        button.dataset.period = period;
        button.style.width = 'auto';
        button.style.padding = '5px 14px';
        button.style.borderRadius = '999px';
        button.style.border = '1px solid #bdaF9d';
        button.style.fontSize = '12px';
        button.style.fontWeight = 'bold';
        button.style.cursor = 'pointer';
        button.style.boxShadow = 'none';
        return button;
    }

    const yearButton = makeTab('直近1年', 'year');
    const halfButton = makeTab('直近6ヶ月', 'half');

    wrap.appendChild(label);
    wrap.appendChild(yearButton);
    wrap.appendChild(halfButton);
    tabCell.appendChild(wrap);
    tabRow.appendChild(tabCell);
    yearTitle.parentNode.insertBefore(tabRow, yearTitle);

    const heatmapColors = {
        '#f87171': '#e7a0a0',
        '#fb923c': '#e5ad7b',
        '#facc15': '#ddc66b',
        '#60a5fa': '#91b5d3',
        '#475569': '#c7ccd1'
    };

    yearGroup.concat(halfGroup).forEach(function (row) {
        Array.from(row.cells).forEach(function (cell) {
            const rawStyle = (cell.getAttribute('style') || '').toLowerCase();
            Object.keys(heatmapColors).forEach(function (from) {
                if (rawStyle.indexOf('background:' + from) !== -1 || rawStyle.indexOf('background-color:' + from) !== -1) {
                    cell.style.setProperty('background-color', heatmapColors[from], 'important');
                    cell.style.setProperty('color', '#2b3440', 'important');
                }
            });
        });
    });

    function setActive(period) {
        const showYear = period === 'year';

        yearGroup.forEach(function (row) {
            row.style.display = showYear ? '' : 'none';
        });
        halfGroup.forEach(function (row) {
            row.style.display = showYear ? 'none' : '';
        });

        [yearButton, halfButton].forEach(function (button) {
            const active = button.dataset.period === period;
            button.style.backgroundColor = active ? '#0f7ab8' : '#f8f4ec';
            button.style.color = active ? '#ffffff' : '#52606d';
            button.style.borderColor = active ? '#0b679b' : '#cbbda9';
        });
    }

    yearButton.addEventListener('click', function () { setActive('year'); });
    halfButton.addEventListener('click', function () { setActive('half'); });

    // 最初は従来どおり直近1年を表示。
    setActive('year');
});
</script>
