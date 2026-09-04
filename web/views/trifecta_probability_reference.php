<?php
$trifectaActiveBoats = is_array($trifectaActiveBoats ?? null)
    ? array_values(array_unique(array_filter(array_map('intval', $trifectaActiveBoats), static fn(int $b): bool => $b >= 1 && $b <= 6)))
    : range(1, 6);
sort($trifectaActiveBoats, SORT_NUMERIC);
$trifectaOutcomeCount = (int)($trifectaOutcomeCount ?? count($trifectaRows ?? []));
$trifectaExactaCount = (int)($trifectaExactaCount ?? (count($trifectaActiveBoats) * max(0, count($trifectaActiveBoats) - 1)));
$trifectaExcludedBoats = is_array($trifectaExcludedBoats ?? null)
    ? array_values(array_unique(array_filter(array_map('intval', $trifectaExcludedBoats), static fn(int $b): bool => $b >= 1 && $b <= 6)))
    : [];
?>
<!-- 参考情報：3連単出目確率。通常6艇=120通り、実質5艇立て=60通り。 -->
<details id="trifecta-reference-panel" style="margin:0 0 14px; background-color:#f8f4ec; border:1px solid #d8cdbc; border-radius:8px; overflow:hidden; color:#3f4b5a;">
    <summary style="cursor:pointer; padding:12px 14px; color:#3f4b5a; font-size:14px; font-weight:bold; background:#e8dfd2;">
        📚 参考情報：3連単<?= $trifectaOutcomeCount ?>通り 出目確率
    </summary>
    <div style="padding:14px;">
        <div style="margin-bottom:10px;">
            <div style="font-size:16px; font-weight:bold; color:#aa741f;">🎲 出目確率</div>
            <div style="font-size:12px; color:#6b7785; margin-top:3px;">
                基礎：場×1着C-2着C-3着C / VENUE_K3000 → 補正後1着率 α=1.00 → AI3連対率 β=1.25
            </div>
            <div style="font-size:12px; color:#6b7785; margin-top:2px;">
                2着/3着順序：同一3艇のペア合計を維持し、trio δ=0.25 + win γ=0.25 で条件付き補正
            </div>
            <?php if (!empty($trifectaExcludedBoats)): ?>
                <div style="font-size:12px; color:#a36a18; margin-top:3px; font-weight:bold;">
                    欠場扱い <?= htmlspecialchars(implode('・', array_map(static fn(int $b): string => $b . '号艇', $trifectaExcludedBoats)), ENT_QUOTES, 'UTF-8') ?> を除外し、残り<?= count($trifectaActiveBoats) ?>艇で100%へ再正規化
                </div>
            <?php endif; ?>
        </div>

        <?php if ($trifectaStatus === 'ok' && $trifectaOutcomeCount > 0 && count($trifectaRows) === $trifectaOutcomeCount): ?>
            <div style="display:flex; gap:8px; flex-wrap:wrap; margin:0 0 10px;">
                <div style="background:#f2ece2; border:1px solid #d8cdbc; border-radius:5px; padding:6px 9px; font-size:12px; color:#3f4b5a;">
                    Top5累計 <strong><?= number_format($trifectaCum($trifectaRows, 5) * 100.0, 2) ?>%</strong>
                </div>
                <div style="background:#f2ece2; border:1px solid #d8cdbc; border-radius:5px; padding:6px 9px; font-size:12px; color:#3f4b5a;">
                    Top10累計 <strong><?= number_format($trifectaCum($trifectaRows, 10) * 100.0, 2) ?>%</strong>
                </div>
                <div style="background:#f2ece2; border:1px solid #d8cdbc; border-radius:5px; padding:6px 9px; font-size:12px; color:#3f4b5a;">
                    Top20累計 <strong><?= number_format($trifectaCum($trifectaRows, 20) * 100.0, 2) ?>%</strong>
                </div>
                <div style="background:#f2ece2; border:1px solid #d8cdbc; border-radius:5px; padding:6px 9px; font-size:12px; color:#6b7785;">
                    場履歴 <?= number_format((int)($trifectaHistory['venue_n'] ?? 0)) ?>R
                </div>
            </div>

            <?= $renderTrifectaTable($trifectaTop20) ?>

            <details id="trifecta-all-details" style="margin-top:10px;">
                <summary style="cursor:pointer; color:#3f4b5a; font-size:13px; font-weight:bold;">
                    <?= $trifectaOutcomeCount ?>通りすべて表示
                </summary>
                <div style="margin-top:10px; padding:10px; background:#f2ece2; border:1px solid #d8cdbc; border-radius:6px;">
                    <label style="display:block; color:#6b7785; font-size:12px; font-weight:bold;">
                        買い目検索
                        <input id="web-trifecta-search" type="text" inputmode="numeric" autocomplete="off"
                               placeholder="例 1 / 1-2 / 1-2-3"
                               style="display:block; width:100%; box-sizing:border-box; margin-top:5px; padding:8px 10px; border:1px solid #cbbda9; border-radius:5px; background:#fffdf9; color:#2b3440; font-size:14px;">
                    </label>

                    <div id="web-trifecta-filters" style="display:grid; gap:6px; margin-top:10px;">
                        <?php foreach (['1着', '2着', '3着'] as $position => $label): ?>
                            <div class="web-trifecta-filter-group" data-position="<?= $position ?>" style="display:flex; align-items:center; gap:5px; flex-wrap:wrap;">
                                <span style="width:34px; color:#6b7785; font-size:12px; font-weight:bold; text-align:center;">
                                    <?= $label ?>
                                </span>
                                <button type="button" class="web-trifecta-filter" data-boat="0"
                                        style="min-width:42px; padding:6px 9px; border:1px solid #1683bd; border-radius:5px; background:#fffaf2; color:#1683bd; box-shadow:inset 0 0 0 1px #1683bd; font-weight:bold; cursor:pointer;">全</button>
                                <?php foreach ($trifectaActiveBoats as $boat): ?>
                                    <button type="button" class="web-trifecta-filter" data-boat="<?= $boat ?>"
                                            style="min-width:42px; padding:6px 9px; border:1px solid #cbbda9; border-radius:5px; background:#eee6da; color:#4b5866; font-weight:bold; cursor:pointer;"><?= $boat ?></button>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; margin-top:10px;">
                        <span id="web-trifecta-count" style="color:#6b7785; font-size:12px; font-weight:bold;"><?= $trifectaOutcomeCount ?> / <?= $trifectaOutcomeCount ?>件</span>
                        <button id="web-trifecta-clear" type="button"
                                style="padding:6px 12px; border:1px solid #cbbda9; border-radius:5px; background:#eee6da; color:#4b5866; font-weight:bold; cursor:pointer;">クリア</button>
                    </div>
                </div>
                <div id="web-trifecta-all-table" style="margin-top:8px;">
                    <?= $renderTrifectaTable($trifectaRows) ?>
                </div>
            </details>

            <div style="margin-top:8px; font-size:12px; color:#6b7785;">
                <?= $trifectaOutcomeCount ?>通り合計 <?= number_format((float)($trifectaTotals['final'] ?? 0.0) * 100.0, 6) ?>%
                / P1選択 → P2完全ホールドアウト検証済み
                <?= !empty($simulation_active) ? ' / 仮想進入試算' : '' ?>
            </div>
        <?php else: ?>
            <div style="padding:8px 10px; background-color:#f2ece2; border-radius:5px; color:#a33f32; font-size:13px;">
                出目確率：<?= htmlspecialchars($trifectaError !== '' ? $trifectaError : '計算待ち', ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>
    </div>
</details>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const totalOutcomes = <?= (int)$trifectaOutcomeCount ?>;
    const exactaPanel = document.getElementById('head1-exacta-panel');
    const referencePanel = document.getElementById('trifecta-reference-panel');
    const summaryBox = document.querySelector('.summary-box');
    if (!exactaPanel || !summaryBox) return;

    // 最終予想の買い目表 → 2連単 → 3連単参考情報、の順に表示だけ移動する。
    summaryBox.insertAdjacentElement('afterend', exactaPanel);
    exactaPanel.style.marginTop = '14px';

    if (referencePanel) {
        exactaPanel.insertAdjacentElement('afterend', referencePanel);
        referencePanel.style.marginTop = '0';
    }

    const allDetails = document.getElementById('trifecta-all-details');
    const allTableBox = document.getElementById('web-trifecta-all-table');
    const table = allTableBox ? allTableBox.querySelector('table') : null;
    const tbody = table ? table.querySelector('tbody') : null;
    const search = document.getElementById('web-trifecta-search');
    const count = document.getElementById('web-trifecta-count');
    const clear = document.getElementById('web-trifecta-clear');
    const filters = document.getElementById('web-trifecta-filters');
    if (!allDetails || !table || !tbody || !search || !filters) return;

    function numberFromCell(cell) {
        const value = parseFloat(String(cell ? cell.textContent : '').replace(/,/g, ''));
        return Number.isFinite(value) ? value : 0;
    }

    function normalizeSearch(value) {
        return String(value || '')
            .trim()
            .replace(/[１-６]/g, function (char) {
                return String(char.charCodeAt(0) - 0xFEE0);
            })
            .replace(/[－–—→>\s]+/g, '-')
            .replace(/[^1-6-]/g, '')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '');
    }

    const rowMeta = Array.from(tbody.querySelectorAll('tr')).map(function (row) {
        const boats = (String(row.cells[1]?.textContent || '').match(/[1-6]/g) || []).slice(0, 3).map(Number);
        return {
            row: row,
            boats: boats,
            key: boats.join('-'),
            rank: numberFromCell(row.cells[0]),
            combination: boats.length === 3 ? boats[0] * 100 + boats[1] * 10 + boats[2] : 0,
            base: numberFromCell(row.cells[2]),
            final: numberFromCell(row.cells[3]),
            delta: numberFromCell(row.cells[4]),
            cumulative: numberFromCell(row.cells[5])
        };
    });

    const selected = [new Set(), new Set(), new Set()];
    const sortKeys = ['rank', 'combination', 'base', 'final', 'delta', 'cumulative'];
    const headerCells = Array.from(table.querySelectorAll('thead th')).slice(0, sortKeys.length);
    const headerLabels = headerCells.map(function (th) { return th.textContent.trim(); });
    let sortKey = 'rank';
    let sortDirection = 1;

    function paintGroup(group, position) {
        const active = selected[position];
        group.querySelectorAll('.web-trifecta-filter').forEach(function (button) {
            const boat = Number(button.dataset.boat || 0);
            const isActive = boat === 0 ? active.size === 0 : active.has(boat);
            button.style.borderColor = isActive ? '#1683bd' : '#cbbda9';
            button.style.background = isActive ? '#fffaf2' : '#eee6da';
            button.style.color = isActive ? '#1683bd' : '#4b5866';
            button.style.boxShadow = isActive ? 'inset 0 0 0 1px #1683bd' : 'none';
        });
    }

    function updateHeaders() {
        headerCells.forEach(function (th, index) {
            th.textContent = headerLabels[index];
            th.style.cursor = 'pointer';
            th.style.userSelect = 'none';
            th.title = 'クリックで並べ替え';
            if (sortKeys[index] === sortKey) {
                th.textContent += sortDirection > 0 ? ' ▲' : ' ▼';
                th.style.color = '#1683bd';
            } else {
                th.style.color = '#2b3440';
            }
        });
    }

    function compare(a, b) {
        const av = Number(a[sortKey] || 0);
        const bv = Number(b[sortKey] || 0);
        if (av === bv) return a.rank - b.rank;
        return (av < bv ? -1 : 1) * sortDirection;
    }

    function matches(meta) {
        if (meta.boats.length !== 3) return false;
        for (let position = 0; position < 3; position++) {
            if (selected[position].size > 0 && !selected[position].has(meta.boats[position])) {
                return false;
            }
        }

        const query = normalizeSearch(search.value);
        if (!query) return true;
        return meta.key === query || meta.key.indexOf(query + '-') === 0;
    }

    function render() {
        let visible = 0;
        rowMeta.slice().sort(compare).forEach(function (meta) {
            const show = matches(meta);
            meta.row.style.display = show ? '' : 'none';
            tbody.appendChild(meta.row);
            if (show) visible++;
        });
        if (count) count.textContent = visible + ' / ' + totalOutcomes + '件';
        updateHeaders();
    }

    filters.addEventListener('click', function (event) {
        const button = event.target.closest('.web-trifecta-filter');
        if (!button) return;
        const group = button.closest('.web-trifecta-filter-group');
        if (!group) return;

        const position = Number(group.dataset.position || 0);
        const boat = Number(button.dataset.boat || 0);
        if (boat === 0) {
            selected[position].clear();
        } else if (selected[position].has(boat)) {
            selected[position].delete(boat);
        } else {
            selected[position].add(boat);
        }
        paintGroup(group, position);
        render();
    });

    search.addEventListener('input', render);

    if (clear) {
        clear.addEventListener('click', function () {
            search.value = '';
            selected.forEach(function (set) { set.clear(); });
            filters.querySelectorAll('.web-trifecta-filter-group').forEach(function (group) {
                paintGroup(group, Number(group.dataset.position || 0));
            });
            sortKey = 'rank';
            sortDirection = 1;
            render();
        });
    }

    headerCells.forEach(function (th, index) {
        th.addEventListener('click', function () {
            const nextKey = sortKeys[index];
            if (sortKey === nextKey) {
                sortDirection *= -1;
            } else {
                sortKey = nextKey;
                sortDirection = (nextKey === 'rank' || nextKey === 'combination') ? 1 : -1;
            }
            render();
        });
    });

    filters.querySelectorAll('.web-trifecta-filter-group').forEach(function (group) {
        paintGroup(group, Number(group.dataset.position || 0));
    });
    render();
});
</script>
