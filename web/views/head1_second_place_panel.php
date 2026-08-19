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
    const courseByBoat = <?= json_encode($prediction_course_by_boat ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const laneColors = <?= json_encode($lane_colors ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const summary = document.querySelector('.summary-box table');
    if (!summary) return;

    const headers = Array.from(summary.querySelectorAll('thead th')).map(function (th) {
        return th.textContent.trim();
    });

    const headIndex = headers.indexOf('頭');
    const aiteIndex = headers.indexOf('相手候補');
    const kiruIndex = headers.indexOf('切る艇');
    const betIndex = headers.findIndex(function (text) {
        return text.includes('買い目候補');
    });

    function courseOf(boat) {
        const course = Number(courseByBoat[String(boat)] ?? courseByBoat[boat] ?? boat);
        return course >= 1 && course <= 6 ? course : boat;
    }

    function makeBoatCourse(boat, compact) {
        const course = courseOf(boat);
        const color = laneColors[String(boat)] || laneColors[boat] || laneColors['1'] || laneColors[1] || {};
        const wrap = document.createElement('span');
        wrap.style.display = 'inline-flex';
        wrap.style.flexDirection = 'column';
        wrap.style.alignItems = 'center';
        wrap.style.justifyContent = 'center';
        wrap.style.margin = compact ? '1px 2px' : '2px 4px';
        wrap.style.verticalAlign = 'middle';

        const courseLabel = document.createElement('span');
        courseLabel.textContent = course + 'C';
        courseLabel.style.fontSize = compact ? '10px' : '11px';
        courseLabel.style.color = '#cbd5e1';
        courseLabel.style.lineHeight = '1.2';
        courseLabel.style.whiteSpace = 'nowrap';

        const badge = document.createElement('span');
        badge.className = 'lane-badge';
        badge.textContent = boat + '号艇';
        badge.style.backgroundColor = color.bg || '#334155';
        badge.style.color = color.text || '#ffffff';
        badge.style.border = '1px solid ' + (color.border || '#64748b');
        badge.style.display = 'inline-block';
        badge.style.minWidth = compact ? '46px' : '54px';
        badge.style.padding = compact ? '1px 5px' : '2px 7px';
        badge.style.borderRadius = '4px';
        badge.style.boxSizing = 'border-box';
        badge.style.whiteSpace = 'nowrap';
        badge.style.textAlign = 'center';
        badge.style.fontWeight = 'bold';
        badge.style.fontSize = compact ? '11px' : '12px';

        wrap.appendChild(courseLabel);
        wrap.appendChild(badge);
        return wrap;
    }

    function parseBoats(text) {
        const matches = String(text || '').match(/[1-6]/g) || [];
        const out = [];
        matches.forEach(function (v) {
            const boat = Number(v);
            if (!out.includes(boat)) out.push(boat);
        });
        return out;
    }

    function renderBoatList(cell, originalText) {
        const boats = parseBoats(originalText);
        if (!boats.length) return;
        cell.textContent = '';
        const wrap = document.createElement('div');
        wrap.style.display = 'flex';
        wrap.style.justifyContent = 'center';
        wrap.style.alignItems = 'flex-end';
        wrap.style.flexWrap = 'wrap';
        wrap.style.gap = '2px';
        boats.forEach(function (boat) {
            wrap.appendChild(makeBoatCourse(boat, false));
        });
        cell.appendChild(wrap);
    }

    function renderBet(cell, originalText) {
        const bet = String(originalText || '').trim();
        if (!bet) return;

        cell.textContent = '';

        const raw = document.createElement('div');
        raw.textContent = bet;
        raw.style.fontWeight = 'bold';
        raw.style.color = '#38bdf8';
        raw.style.whiteSpace = 'nowrap';
        cell.appendChild(raw);

        const label = document.createElement('div');
        label.textContent = '進入対応';
        label.style.fontSize = '10px';
        label.style.color = '#94a3b8';
        label.style.marginTop = '5px';
        cell.appendChild(label);

        const visual = document.createElement('div');
        visual.style.display = 'flex';
        visual.style.alignItems = 'flex-end';
        visual.style.justifyContent = 'center';
        visual.style.gap = '4px';
        visual.style.marginTop = '2px';
        visual.style.whiteSpace = 'nowrap';

        const groups = bet.split('-');
        groups.forEach(function (group, groupIndex) {
            if (groupIndex > 0) {
                const hyphen = document.createElement('span');
                hyphen.textContent = '-';
                hyphen.style.color = '#94a3b8';
                hyphen.style.paddingBottom = '5px';
                visual.appendChild(hyphen);
            }

            const groupWrap = document.createElement('span');
            groupWrap.style.display = 'inline-flex';
            groupWrap.style.alignItems = 'flex-end';
            groupWrap.style.gap = '1px';

            (group.match(/[1-6]/g) || []).forEach(function (v) {
                groupWrap.appendChild(makeBoatCourse(Number(v), true));
            });
            visual.appendChild(groupWrap);
        });

        cell.appendChild(visual);
    }

    Array.from(summary.querySelectorAll('tbody tr')).forEach(function (row) {
        const cells = row.cells;
        if (!cells || !cells.length) return;

        if (headIndex >= 0 && cells[headIndex]) {
            renderBoatList(cells[headIndex], cells[headIndex].textContent);
        }
        if (aiteIndex >= 0 && cells[aiteIndex]) {
            renderBoatList(cells[aiteIndex], cells[aiteIndex].textContent);
        }
        if (kiruIndex >= 0 && cells[kiruIndex]) {
            renderBoatList(cells[kiruIndex], cells[kiruIndex].textContent);
        }
        if (betIndex >= 0 && cells[betIndex]) {
            renderBet(cells[betIndex], cells[betIndex].textContent);
        }
    });
});
</script>
