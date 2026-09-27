<?php
// AI1着率 v5で検知するイン飛びと、検証済みの固定8点・大穴6点を比較表示する。
// 現行の穴目表示・最終予想・自動購入には接続しない。

$upsetV1PanelMode = (string)($upsetV1PanelMode ?? 'web');
$upsetV1Threshold = 31.772987;
$upsetV1Rows = $upsetV1PanelMode === 'app'
    ? (is_array($appTrifectaRows ?? null) ? $appTrifectaRows : [])
    : (is_array($trifectaDisplayRows ?? null) ? $trifectaDisplayRows : []);
$upsetV1Source = $upsetV1PanelMode === 'app'
    ? (string)($appTrifectaData['probability_source'] ?? '')
    : (string)($trifectaDisplaySource ?? '');

$upsetV1CourseByBoat = is_array($upsetCourseByBoat ?? null) ? $upsetCourseByBoat : [];
$upsetV1BoatByCourse = is_array($upsetBoatByCourse ?? null) ? $upsetBoatByCourse : [];
$upsetV1InBoat = (int)($upsetV1BoatByCourse[1] ?? 0);
$upsetV1InRate = null;
$upsetV1Ready = false;
$upsetV1Alert = false;
$upsetV1MainHead = 0;
$upsetV1SecondHead = 0;
$upsetV1FixedRows = [];
$upsetV1AllHeadRows = [];

$upsetV1WinBoats = is_array($aiWinBoats ?? null) ? $aiWinBoats : [];
if ($upsetV1InBoat >= 1 && $upsetV1InBoat <= 6) {
    $rate = $upsetV1WinBoats[(string)$upsetV1InBoat]['ai_rate']
        ?? $upsetV1WinBoats[$upsetV1InBoat]['ai_rate']
        ?? null;
    if (is_numeric($rate) && is_finite((float)$rate)) {
        $upsetV1InRate = (float)$rate;
    }
}

$upsetV1PlaceBoats = is_array($ai_place_rate_data['boats'] ?? null)
    ? $ai_place_rate_data['boats']
    : [];
$upsetV1Ready = (
    (string)($aiWinStatus ?? '') === 'ok'
    && count($upsetV1WinBoats) === 6
    && (string)($ai_place_rate_data['status'] ?? '') === 'ok'
    && count($upsetV1PlaceBoats) === 6
    && count($upsetV1CourseByBoat) === 6
    && $upsetV1Source === 'ai_place_v1_joint120'
    && count($upsetV1Rows) === 120
    && $upsetV1InRate !== null
);
$upsetV1Alert = $upsetV1Ready && $upsetV1InRate <= $upsetV1Threshold;

if ($upsetV1Alert) {
    $outer = array_values(array_filter(
        range(1, 6),
        static fn(int $boat): bool => $boat !== $upsetV1InBoat
    ));

    $top2Rank = $outer;
    usort($top2Rank, static function (int $a, int $b) use ($upsetV1PlaceBoats): int {
        $aRate = (float)($upsetV1PlaceBoats[(string)$a]['ai_top2_rate'] ?? $upsetV1PlaceBoats[$a]['ai_top2_rate'] ?? 0.0);
        $bRate = (float)($upsetV1PlaceBoats[(string)$b]['ai_top2_rate'] ?? $upsetV1PlaceBoats[$b]['ai_top2_rate'] ?? 0.0);
        $cmp = $bRate <=> $aRate;
        return $cmp !== 0 ? $cmp : ($a <=> $b);
    });

    $trioRank = $outer;
    usort($trioRank, static function (int $a, int $b) use ($upsetV1PlaceBoats): int {
        $aRate = (float)($upsetV1PlaceBoats[(string)$a]['ai_trio_rate'] ?? $upsetV1PlaceBoats[$a]['ai_trio_rate'] ?? 0.0);
        $bRate = (float)($upsetV1PlaceBoats[(string)$b]['ai_trio_rate'] ?? $upsetV1PlaceBoats[$b]['ai_trio_rate'] ?? 0.0);
        $cmp = $bRate <=> $aRate;
        return $cmp !== 0 ? $cmp : ($a <=> $b);
    });

    $upsetV1MainHead = (int)($top2Rank[0] ?? 0);
    $upsetV1SecondHead = (int)($trioRank[0] ?? 0);
    if ($upsetV1SecondHead === $upsetV1MainHead) {
        $upsetV1SecondHead = (int)($top2Rank[1] ?? 0);
    }

    foreach ([$upsetV1MainHead, $upsetV1SecondHead] as $head) {
        $headRows = [];
        foreach ($upsetV1Rows as $row) {
            $boats = is_array($row['boats'] ?? null) ? array_values(array_map('intval', $row['boats'])) : [];
            $probability = $row['probability'] ?? null;
            if (count($boats) !== 3 || $boats[0] !== $head || !is_numeric($probability)) {
                continue;
            }
            $headRows[] = [
                'key' => implode('-', $boats),
                'boats' => $boats,
                'probability' => (float)$probability,
            ];
        }
        usort($headRows, static function (array $a, array $b): int {
            $cmp = $b['probability'] <=> $a['probability'];
            return $cmp !== 0 ? $cmp : strcmp($a['key'], $b['key']);
        });
        $upsetV1AllHeadRows = array_merge($upsetV1AllHeadRows, $headRows);
        $upsetV1FixedRows = array_merge($upsetV1FixedRows, array_slice($headRows, 0, 4));
    }
}

if (!$upsetV1Alert || count($upsetV1FixedRows) !== 8) {
    return;
}

$upsetV1Payload = json_encode([
    'race_code' => (string)($race_code ?? ''),
    'heads' => [$upsetV1MainHead, $upsetV1SecondHead],
    // 大穴6点は固定8点の内側だけでなく、2頭から始まる全40通りを対象にする。
    'rows' => $upsetV1AllHeadRows,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if (!is_string($upsetV1Payload)) {
    return;
}

$upsetV1Chip = static function (array $row): string {
    return '<span style="display:inline-block;padding:4px 7px;border:1px solid #d8c7a5;border-radius:5px;background:#fffdf8;color:#4b5866;font-size:11px;font-weight:bold;">'
        . htmlspecialchars((string)$row['key'], ENT_QUOTES, 'UTF-8')
        . ' <small style="font-weight:normal;color:#75659b;">'
        . number_format((float)$row['probability'] * 100.0, 2) . '%</small></span>';
};
?>

<section id="upset-v1-comparison-panel" style="margin:10px 0 14px; border:1px solid #cf9c67; border-radius:8px; background:#fff8ee; color:#3f4b5a; overflow:hidden;">
    <div style="padding:11px 13px; background:#f4e3cc; border-bottom:1px solid #ddc09d;">
        <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;flex-wrap:wrap;">
            <strong style="font-size:15px;color:#8b5e2d;">🚨 イン飛び買い目 v1</strong>
            <span style="font-size:10px;padding:2px 7px;border:1px solid #cf9c67;border-radius:999px;background:#fffaf3;color:#8b5e2d;">比較表示・自動購入なし</span>
        </div>
        <div style="font-size:11px;color:#6b7785;margin-top:4px;">
            1CのAI1着率 v5 <?= number_format((float)$upsetV1InRate, 1) ?>%（警戒基準 <?= number_format($upsetV1Threshold, 1) ?>%以下）
            / 穴頭 <?= $upsetBoatBadge($upsetV1MainHead) ?>・<?= $upsetBoatBadge($upsetV1SecondHead) ?>
        </div>
    </div>

    <div style="padding:11px;display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:9px;">
        <div style="border:1px solid #ddc9ac;border-radius:7px;background:#fffdf8;padding:10px;">
            <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;">
                <strong style="font-size:13px;color:#7b6332;">イン飛び8点 v1</strong>
                <strong style="font-size:13px;color:#aa741f;">8点</strong>
            </div>
            <div style="font-size:10px;color:#7a8490;margin-top:2px;">穴頭2艇 × 各頭のAI確率上位4点</div>
            <div style="display:flex;gap:5px;flex-wrap:wrap;margin-top:8px;">
                <?php foreach ($upsetV1FixedRows as $row): ?><?= $upsetV1Chip($row) ?><?php endforeach; ?>
            </div>
        </div>

        <div style="border:1px solid #ddc9ac;border-radius:7px;background:#fffdf8;padding:10px;">
            <div style="display:flex;justify-content:space-between;gap:8px;align-items:center;">
                <strong style="font-size:13px;color:#7b6332;">大穴6点 v1</strong>
                <strong class="upset-v1-big-points" style="font-size:13px;color:#aa741f;">確認中</strong>
            </div>
            <div style="font-size:10px;color:#7a8490;margin-top:2px;">同じ穴頭2艇・100倍以上・AI確率0.2%以上から最大6点</div>
            <div class="upset-v1-big-status" style="font-size:11px;color:#6b7785;margin-top:7px;">公式3連単オッズを確認中です</div>
            <div class="upset-v1-big-list" style="display:flex;gap:5px;flex-wrap:wrap;margin-top:7px;"></div>
        </div>
    </div>
    <div style="padding:8px 12px;border-top:1px solid #e2d6c1;background:#f8f1e4;font-size:10px;color:#7a6d59;">
        現行の可変点数による穴目候補はそのまま残しています。固定8点は採用候補、大穴6点はオッズ標本が少ないため検証中です。
    </div>
</section>

<script id="upset-v1-comparison-data" type="application/json"><?= $upsetV1Payload ?></script>
<script>
(function () {
    'use strict';
    function start() {
        const panel = document.getElementById('upset-v1-comparison-panel');
        const dataNode = document.getElementById('upset-v1-comparison-data');
        if (!panel || !dataNode || panel.dataset.ready === '1') return;
        panel.dataset.ready = '1';

        const oldPanel = document.getElementById('upset-alert-panel');
        if (oldPanel) oldPanel.insertAdjacentElement('afterend', panel);

        let payload;
        try { payload = JSON.parse(dataNode.textContent || '{}'); } catch (e) { return; }
        const rows = Array.isArray(payload.rows) ? payload.rows.slice() : [];
        const heads = Array.isArray(payload.heads) ? payload.heads.map(Number) : [];
        const points = panel.querySelector('.upset-v1-big-points');
        const status = panel.querySelector('.upset-v1-big-status');
        const list = panel.querySelector('.upset-v1-big-list');
        const raceCode = String(payload.race_code || '');
        if (!/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/.test(raceCode)) {
            if (points) points.textContent = '見送り';
            if (status) status.textContent = '公式オッズを確認できません';
            return;
        }

        const body = new URLSearchParams();
        body.set('race_code', raceCode);
        body.set('refresh', '0');
        fetch('/web/official_odds_api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
            body: body.toString(),
            cache: 'no-store'
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            const odds = data && data.odds && typeof data.odds === 'object' ? data.odds : {};
            if (!data || data.status !== 'ok' || Object.keys(odds).length < 60) throw new Error('未取得');
            const selected = rows.filter(function (row) {
                const boatHead = Number(row && row.boats && row.boats[0]);
                const odd = Number(odds[String(row.key || '')]);
                return heads.indexOf(boatHead) >= 0
                    && Number(row.probability || 0) >= 0.002
                    && Number.isFinite(odd) && odd >= 100;
            }).sort(function (a, b) {
                return Number(b.probability || 0) - Number(a.probability || 0)
                    || String(a.key).localeCompare(String(b.key));
            }).slice(0, 6);

            if (points) points.textContent = selected.length ? selected.length + '点' : '見送り';
            if (status) status.textContent = selected.length
                ? '100倍以上からAI確率順（表示オッズは取得時点）'
                : '100倍以上かつAI確率0.2%以上の候補なし';
            if (list) {
                list.textContent = '';
                selected.forEach(function (row) {
                    const chip = document.createElement('span');
                    chip.textContent = String(row.key) + '  ' + Number(odds[row.key]).toFixed(1) + '倍';
                    chip.style.cssText = 'display:inline-block;padding:4px 7px;border:1px solid #d8c7a5;border-radius:5px;background:#fff7e8;color:#4b5866;font-size:11px;font-weight:bold;';
                    list.appendChild(chip);
                });
            }
        }).catch(function () {
            if (points) points.textContent = '確認不可';
            if (status) status.textContent = '公式オッズを取得できませんでした';
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
</script>
