<?php
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

// 決まり手表はコース基準のまま、見出しに展示進入の実艇番を併記する。
// 表示専用。決まり手集計・最終予想ロジックには影響させない。
$kimariteHeaderMap = [];
for ($course = 1; $course <= 6; $course++) {
    $boat = !empty($entry_map_ready)
        ? (int)($boat_by_entry_course[$course] ?? $course)
        : $course;

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
                        <th style="padding:8px; text-align:left; min-width:130px;">項目 / 艇番</th>
                        <?php for ($i = 1; $i <= 6; $i++): ?>
                            <?php $c = $lane_colors[$i] ?? $lane_colors[1]; ?>
                            <th style="padding:8px; text-align:center; min-width:95px;">
                                <span class="lane-badge"
                                      style="background-color:<?= $c['bg'] ?>; color:<?= $c['text'] ?>; border:1px solid <?= $c['border'] ?>; padding:2px 8px; border-radius:4px;">
                                    <?= $i ?>号艇
                                </span>
                            </th>
                        <?php endfor; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="padding:10px 8px; font-weight:bold; color:#f8fafc;">基本1着率</td>
                        <?php for ($i = 1; $i <= 6; $i++): ?>
                            <?php $rate = $baseWinBoats[$i]['normalized_rate'] ?? null; ?>
                            <td style="padding:10px 8px; text-align:center; font-size:18px; font-weight:bold; color:#38bdf8;">
                                <?= $rate !== null ? number_format((float)$rate, 2) . '%' : '-' ?>
                            </td>
                        <?php endfor; ?>
                    </tr>
                    <tr style="border-top:1px solid #334155;">
                        <td style="padding:10px 8px; font-weight:bold; color:#f8fafc;">補正後1着率</td>
                        <?php for ($i = 1; $i <= 6; $i++): ?>
                            <?php $rate = $correctedWinBoats[(string)$i]['corrected_rate'] ?? $correctedWinBoats[$i]['corrected_rate'] ?? null; ?>
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

    document.querySelectorAll('tr').forEach(function (row) {
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
});
</script>
