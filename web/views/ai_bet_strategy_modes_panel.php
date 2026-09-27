<?php
// AI着順率v1の120通りから、目的別の参考買い目を表示する。
// 既存の本命・対抗・購入処理には接続しない。

$aiBetStrategyPanelMode = (string)($aiBetStrategyPanelMode ?? 'web');
$aiBetStrategyRows = $aiBetStrategyPanelMode === 'app'
    ? (is_array($appTrifectaRows ?? null) ? $appTrifectaRows : [])
    : (is_array($trifectaDisplayRows ?? null) ? $trifectaDisplayRows : []);
$aiBetStrategySource = $aiBetStrategyPanelMode === 'app'
    ? (string)($appTrifectaData['probability_source'] ?? '')
    : (string)($trifectaDisplaySource ?? '');

if ($aiBetStrategySource !== 'ai_place_v1_joint120' || count($aiBetStrategyRows) !== 120) {
    return;
}

$aiBetStrategyPayloadRows = [];
foreach ($aiBetStrategyRows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $boats = is_array($row['boats'] ?? null) ? array_values(array_map('intval', $row['boats'])) : [];
    $probability = $row['probability'] ?? null;
    if (
        count($boats) !== 3
        || count(array_unique($boats)) !== 3
        || min($boats) < 1
        || max($boats) > 6
        || !is_numeric($probability)
        || !is_finite((float)$probability)
        || (float)$probability < 0.0
    ) {
        continue;
    }
    $aiBetStrategyPayloadRows[] = [
        'key' => implode('-', $boats),
        'boats' => $boats,
        'probability' => (float)$probability,
    ];
}

if (count($aiBetStrategyPayloadRows) !== 120) {
    return;
}

$aiBetStrategyJson = json_encode([
    'race_code' => (string)($race_code ?? ''),
    'race_date' => (string)($selected_date ?? ''),
    'panel_mode' => $aiBetStrategyPanelMode,
    'honmei_kai' => (string)($honmei_kai ?? ''),
    'taikou_kai' => (string)($taikou_kai ?? ''),
    // API取得失敗時だけ使うフォールバック。通常はTOPと同じ日次固定判定を参照する。
    'hole_alert_fallback' => !empty($upsetAlertHigh),
    'rows' => $aiBetStrategyPayloadRows,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
if (!is_string($aiBetStrategyJson)) {
    return;
}
?>

<section id="ai-bet-strategy-modes" data-panel-mode="<?= htmlspecialchars($aiBetStrategyPanelMode, ENT_QUOTES, 'UTF-8') ?>"
         style="margin:10px 0 14px; border:1px solid #c9b891; border-radius:8px; background:#fffaf0; color:#3f4b5a; overflow:hidden;">
    <div style="padding:11px 13px; background:#eee2ca; border-bottom:1px solid #d8c7a5;">
        <div style="display:flex; justify-content:space-between; gap:8px; align-items:center; flex-wrap:wrap;">
            <strong style="font-size:15px; color:#7b6332;">🤖 AI買い方タイプ v1</strong>
            <span style="font-size:10px; padding:2px 7px; border:1px solid #c9b891; border-radius:999px; color:#7b6332; background:#fffaf0;">検証中・参考表示</span>
        </div>
        <div style="font-size:11px; color:#6b7785; margin-top:3px;">AI1着率 v5 × AI2・3着率 v1 の120通りから目的別に選択</div>
    </div>

    <div style="padding:11px; display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:8px;">
        <?php foreach ([
            'hit' => ['🎯', '的中重視', '確率上位20点'],
            'balance' => ['⚖️', 'バランス', '確率上位12点'],
            'one_shot' => ['💥', '一撃重視', '荒れ警戒＋万舟候補6点'],
            'selective' => ['🔎', '絞り込み', '上位6点の確率が35%以上'],
        ] as $modeKey => [$icon, $label, $rule]): ?>
            <div class="ai-bet-mode-card" data-mode="<?= $modeKey ?>"
                 style="border:1px solid #ddd0b8; border-radius:7px; background:#fffdf8; padding:9px; min-width:0;">
                <div style="display:flex; justify-content:space-between; gap:6px; align-items:center;">
                    <strong style="font-size:13px; color:#4b5866;"><?= $icon ?> <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></strong>
                    <span class="ai-bet-mode-points" style="font-size:12px; font-weight:bold; color:#aa741f;">計算中</span>
                </div>
                <div style="font-size:10px; color:#7a8490; margin-top:2px;"><?= htmlspecialchars($rule, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="ai-bet-mode-mass" style="font-size:11px; color:#6b7785; margin-top:6px;"></div>
                <details style="margin-top:5px;">
                    <summary style="cursor:pointer; font-size:11px; color:#1683bd;">買い目を見る</summary>
                    <div class="ai-bet-mode-list" style="display:flex; gap:4px; flex-wrap:wrap; margin-top:6px;"></div>
                </details>
            </div>
        <?php endforeach; ?>
    </div>

    <div style="padding:0 12px 11px; display:flex; gap:8px; align-items:center; flex-wrap:wrap; font-size:11px; color:#6b7785;">
        <span>AI万舟率 <strong class="ai-manshu-rate" style="color:#aa741f;">オッズ取得中</strong></span>
        <span class="ai-bet-odds-status">公式3連単オッズを確認中です</span>
    </div>
    <div style="padding:8px 12px; border-top:1px solid #e2d6c1; background:#f8f1e4; font-size:10px; color:#7a6d59;">
        100円均等の過去607R検証では全方式とも回収率100%未満です。自動購入には使わず、目的別の比較材料として表示します。
    </div>
</section>

<script id="ai-bet-strategy-modes-data" type="application/json"><?= $aiBetStrategyJson ?></script>
<script>
(function () {
    'use strict';

    function start() {
        const panel = document.getElementById('ai-bet-strategy-modes');
        const dataNode = document.getElementById('ai-bet-strategy-modes-data');
        if (!panel || !dataNode || panel.dataset.ready === '1') return;
        panel.dataset.ready = '1';

        let payload;
        try {
            payload = JSON.parse(dataNode.textContent || '{}');
        } catch (e) {
            return;
        }
        const rows = Array.isArray(payload.rows) ? payload.rows.slice() : [];
        if (rows.length !== 120) return;
        rows.sort(function (a, b) {
            return Number(b.probability || 0) - Number(a.probability || 0) || String(a.key).localeCompare(String(b.key));
        });

        function card(mode) {
            return panel.querySelector('.ai-bet-mode-card[data-mode="' + mode + '"]');
        }

        function render(mode, selected, note) {
            const node = card(mode);
            if (!node) return;
            const points = node.querySelector('.ai-bet-mode-points');
            const mass = node.querySelector('.ai-bet-mode-mass');
            const list = node.querySelector('.ai-bet-mode-list');
            const details = node.querySelector('details');
            const items = Array.isArray(selected) ? selected : [];
            const probabilityMass = items.reduce(function (sum, row) {
                return sum + Number(row.probability || 0);
            }, 0);
            if (points) points.textContent = items.length ? items.length + '点' : '見送り';
            if (mass) mass.textContent = note || (items.length ? '選択確率合計 ' + (probabilityMass * 100).toFixed(2) + '%' : '条件に該当しません');
            if (list) {
                list.textContent = '';
                items.forEach(function (row) {
                    const chip = document.createElement('span');
                    chip.textContent = String(row.key) + '  ' + (Number(row.probability || 0) * 100).toFixed(2) + '%';
                    chip.style.cssText = 'display:inline-block;padding:3px 6px;border:1px solid #d8c7a5;border-radius:4px;background:#f7efe0;color:#4b5866;font-size:10px;font-weight:bold;';
                    list.appendChild(chip);
                });
            }
            if (details) details.style.display = items.length ? '' : 'none';
        }

        const hit = rows.slice(0, 20);
        const balance = rows.slice(0, 12);
        const top6 = rows.slice(0, 6);
        const top6Mass = top6.reduce(function (sum, row) { return sum + Number(row.probability || 0); }, 0);
        render('hit', hit);
        render('balance', balance);
        render(
            'selective',
            top6Mass >= 0.35 ? top6 : [],
            top6Mass >= 0.35
                ? '上位6点の選択確率合計 ' + (top6Mass * 100).toFixed(2) + '%'
                : '見送り：上位6点合計 ' + (top6Mass * 100).toFixed(2) + '%（基準35%未満）'
        );

        render('one_shot', [], 'TOPと同じ荒れ警戒・公式オッズを確認中');

        // PC版では既存の最終予想・穴警報と同じ場所へまとめる。
        if (String(payload.panel_mode || '') === 'web') {
            const anchor = document.getElementById('upset-reference-bet-panel')
                || document.getElementById('upset-alert-panel')
                || document.querySelector('.summary-box');
            if (anchor) anchor.insertAdjacentElement('afterend', panel);
        }

        const status = panel.querySelector('.ai-bet-odds-status');
        const manshu = panel.querySelector('.ai-manshu-rate');
        const raceCode = String(payload.race_code || '');
        const raceDate = String(payload.race_date || '');
        if (!/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/.test(raceCode)) {
            if (status) status.textContent = '公式オッズを確認できません';
            if (manshu) manshu.textContent = '-';
            return;
        }

        let holeAlert = Boolean(payload.hole_alert_fallback);
        let signalReady = false;
        let oneShotPool = null;

        function updateOneShot() {
            if (!signalReady) {
                render('one_shot', [], 'TOPと同じ荒れ警戒を確認中');
                return;
            }
            if (!holeAlert) {
                render('one_shot', [], '見送り：TOPの荒れ判定は平常です');
                return;
            }
            if (!Array.isArray(oneShotPool)) {
                render('one_shot', [], '荒れ警戒あり・公式オッズ取得待ち');
                return;
            }
            const oneShot = oneShotPool.filter(function (row) {
                return Number(row.probability || 0) >= 0.002;
            }).slice(0, 6);
            render(
                'one_shot',
                oneShot,
                oneShot.length
                    ? 'TOP荒れ警戒あり・100倍以上からAI確率順'
                    : '見送り：100倍以上で確率0.2%以上の候補なし'
            );
        }

        let oddsStarted = false;
        function loadOddsAndCapture() {
            if (oddsStarted) return;
            oddsStarted = true;
            const body = new URLSearchParams();
            body.set('race_code', raceCode);
            body.set('refresh', '0');
            body.set('snapshot_source', 'ai_bet_modes_v1');
            body.set('hole_alert', holeAlert ? '1' : '0');
            body.set('honmei_kai', String(payload.honmei_kai || ''));
            body.set('taikou_kai', String(payload.taikou_kai || ''));
            body.set('trifecta_rows', JSON.stringify(rows.map(function (row) {
                return {boats: row.boats, probability: Number(row.probability || 0)};
            })));
            fetch('/web/official_odds_api.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                body: body.toString(),
                cache: 'no-store'
            }).then(function (response) {
                return response.json();
            }).then(function (data) {
                const odds = data && data.odds && typeof data.odds === 'object' ? data.odds : {};
                if (!data || data.status !== 'ok' || Object.keys(odds).length < 60) {
                    throw new Error(data && data.error ? String(data.error) : '未取得');
                }
                rows.forEach(function (row) {
                    const value = Number(odds[row.key]);
                    row.odds = Number.isFinite(value) && value > 0 ? value : null;
                });
                const longshotRows = rows.filter(function (row) {
                    return row.odds !== null && row.odds >= 100;
                });
                const manshuProbability = longshotRows.reduce(function (sum, row) {
                    return sum + Number(row.probability || 0);
                }, 0);
                if (manshu) manshu.textContent = (manshuProbability * 100).toFixed(2) + '%';
                if (status) status.textContent = '100倍以上の出目確率合計';
                oneShotPool = longshotRows;
                updateOneShot();
            }).catch(function (error) {
                if (status) status.textContent = '公式オッズ：' + String(error && error.message ? error.message : '取得エラー');
                if (manshu) manshu.textContent = '-';
            });
        }

        if (/^\d{4}-\d{2}-\d{2}$/.test(raceDate)) {
            fetch('/web/home_highlights_api.php?date=' + encodeURIComponent(raceDate), {
                cache: 'no-store'
            }).then(function (response) {
                return response.json();
            }).then(function (data) {
                const alerts = data && Array.isArray(data.rows) ? data.rows : [];
                const current = alerts.find(function (row) {
                    return String(row && row.race_code || '') === raceCode;
                });
                holeAlert = Boolean(current && String(current.primary || '平常') !== '平常');
                signalReady = true;
                updateOneShot();
                loadOddsAndCapture();
            }).catch(function () {
                signalReady = true;
                updateOneShot();
                loadOddsAndCapture();
            });
        } else {
            signalReady = true;
            updateOneShot();
            loadOddsAndCapture();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
</script>
