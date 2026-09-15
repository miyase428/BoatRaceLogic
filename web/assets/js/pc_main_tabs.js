(function () {
    'use strict';

    const STORAGE_KEY = 'boatracePcMainTab';

    function isAssetNode(node) {
        if (!node || node.nodeType !== Node.ELEMENT_NODE) return false;
        return ['SCRIPT', 'STYLE', 'LINK'].includes(node.tagName);
    }

    function isForwardValidationNode(node) {
        return !!(
            node
            && node.nodeType === Node.ELEMENT_NODE
            && node.querySelector
            && node.querySelector('input[name="fv_race_code"]')
            && String(node.textContent || '').includes('前向き実戦検証')
        );
    }

    function detectTrifectaCount() {
        const tableBox = document.getElementById('web-trifecta-all-table');
        const tbody = tableBox ? tableBox.querySelector('tbody') : null;
        if (tbody && tbody.rows.length > 0) return tbody.rows.length;

        const reference = document.getElementById('trifecta-reference-panel');
        const text = String(reference ? reference.textContent : '');
        const match = text.match(/3連単\s*(\d+)通り/);
        return match ? Number(match[1]) : 120;
    }

    function updateTabColumns(tabs) {
        if (!tabs) return;
        const count = tabs.querySelectorAll('.pc-main-tab').length;
        if (count > 0) {
            tabs.style.gridTemplateColumns = 'repeat(' + count + ', minmax(0, 1fr))';
        }
    }

    function setupPcMainTabs() {
        const container = document.querySelector('.container');
        const codeBox = container ? container.querySelector('.code-box') : null;
        if (!container || !codeBox || container.querySelector('.pc-main-tabs')) return;

        // 既存パネル側のDOMContentLoadedによる表示移動がすべて終わった後の
        // DOM順を基準に大タブへ振り分ける。
        const children = Array.from(container.children);
        const codeIndex = children.indexOf(codeBox);
        if (codeIndex < 0) return;
        const sourceNodes = children.slice(codeIndex + 1);
        const trifectaCount = detectTrifectaCount();

        const tabs = document.createElement('nav');
        tabs.className = 'pc-main-tabs';
        tabs.setAttribute('aria-label', 'Web表示切替');
        tabs.innerHTML = ''
            + '<button type="button" class="pc-main-tab is-active" data-pc-main-tab="basic">基本情報</button>'
            + '<button type="button" class="pc-main-tab" data-pc-main-tab="main">メイン情報</button>'
            + '<button type="button" class="pc-main-tab" data-pc-main-tab="other">その他</button>'
            + '<button type="button" class="pc-main-tab" data-pc-main-tab="trifecta">' + trifectaCount + '通り</button>'
            + '<button type="button" class="pc-main-tab" data-pc-main-tab="recent">直近60R</button>';

        const basicPanel = document.createElement('div');
        basicPanel.className = 'pc-main-tab-panel is-active';
        basicPanel.dataset.pcMainPanel = 'basic';

        const mainPanel = document.createElement('div');
        mainPanel.className = 'pc-main-tab-panel';
        mainPanel.dataset.pcMainPanel = 'main';
        mainPanel.hidden = true;

        const otherPanel = document.createElement('div');
        otherPanel.className = 'pc-main-tab-panel';
        otherPanel.dataset.pcMainPanel = 'other';
        otherPanel.hidden = true;

        const trifectaPanel = document.createElement('div');
        trifectaPanel.className = 'pc-main-tab-panel';
        trifectaPanel.dataset.pcMainPanel = 'trifecta';
        trifectaPanel.hidden = true;

        const recentPanel = document.createElement('div');
        recentPanel.className = 'pc-main-tab-panel';
        recentPanel.dataset.pcMainPanel = 'recent';
        recentPanel.hidden = true;

        codeBox.insertAdjacentElement('afterend', tabs);
        tabs.insertAdjacentElement('afterend', basicPanel);
        basicPanel.insertAdjacentElement('afterend', mainPanel);
        mainPanel.insertAdjacentElement('afterend', otherPanel);
        otherPanel.insertAdjacentElement('afterend', trifectaPanel);
        trifectaPanel.insertAdjacentElement('afterend', recentPanel);
        updateTabColumns(tabs);

        // 後から2連単・買い目タブが追加されても列数を自動追従する。
        const tabsObserver = new MutationObserver(function () { updateTabColumns(tabs); });
        tabsObserver.observe(tabs, {childList: true});

        const basicNodes = new Set();
        const otherNodes = new Set();

        // 場特性は「基本情報」。前向き実戦検証と旧SUMマスタは「その他」へ。
        sourceNodes.forEach(function (node) {
            if (!node || node.nodeType !== Node.ELEMENT_NODE) return;
            if (String(node.id || '').startsWith('stadium-characteristics-tabs-pc-')) {
                basicNodes.add(node);
            } else if (isForwardValidationNode(node)) {
                otherNodes.add(node);
            } else if (String(node.id || '') === 'sam-block') {
                otherNodes.add(node);
            }
        });

        // 総合出走・展示マトリクスは「基本情報」側へまとめる。
        // 行の間引きや複製は行わず、従来どおり全項目をそのまま表示する。
        const matrixIndex = sourceNodes.findIndex(function (node) {
            return node
                && node.nodeType === Node.ELEMENT_NODE
                && node.classList
                && node.classList.contains('matrix-header-area');
        });
        const finalIndex = sourceNodes.findIndex(function (node, index) {
            return index > matrixIndex
                && node
                && node.nodeType === Node.ELEMENT_NODE
                && node.tagName === 'H2'
                && String(node.textContent || '').includes('最終予想');
        });

        if (matrixIndex >= 0) {
            const end = finalIndex >= 0 ? finalIndex : sourceNodes.length;
            for (let i = matrixIndex; i < end; i++) {
                const node = sourceNodes[i];
                if (node && !isAssetNode(node)) basicNodes.add(node);
            }
        }

        const trifectaReference = document.getElementById('trifecta-reference-panel');
        const recentHistory = document.getElementById('recent-prediction-history-panel');

        if (trifectaReference) {
            // 専用大タブで表示するため、内側detailsは常時開いた状態にする。
            trifectaReference.open = true;
            trifectaReference.classList.add('pc-main-trifecta-card');
        }

        // 既存DOMを再生成せず、そのまま各大タブへ移す。
        // SCRIPT / STYLE / LINKは元位置に残し、既存イベント登録を壊さない。
        sourceNodes.forEach(function (node) {
            if (!node || isAssetNode(node)) return;

            if (node === recentHistory) {
                recentPanel.appendChild(node);
                return;
            }

            if (node === trifectaReference) {
                trifectaPanel.appendChild(node);
                return;
            }

            if (otherNodes.has(node)) {
                otherPanel.appendChild(node);
                return;
            }

            if (basicNodes.has(node)) {
                basicPanel.appendChild(node);
                return;
            }

            mainPanel.appendChild(node);
        });

        // 既存の各表示移動処理と競合した場合でも最後に専用タブへ回収する。
        if (trifectaReference && trifectaReference.parentElement !== trifectaPanel) {
            trifectaPanel.appendChild(trifectaReference);
        }
        if (recentHistory && recentHistory.parentElement !== recentPanel) {
            recentPanel.appendChild(recentHistory);
        }

        if (!trifectaReference) {
            const note = document.createElement('div');
            note.style.cssText = 'margin:12px 0;padding:12px 14px;border:1px solid var(--border);border-radius:8px;background:var(--surface-soft);color:var(--text-muted);font-size:13px;';
            note.textContent = '3連単' + trifectaCount + '通りは計算待ちです。';
            trifectaPanel.appendChild(note);
        }

        if (!recentHistory) {
            const note = document.createElement('div');
            note.style.cssText = 'margin:12px 0;padding:12px 14px;border:1px solid var(--border);border-radius:8px;background:var(--surface-soft);color:var(--text-muted);font-size:13px;';
            note.textContent = '直近60Rパネルを読み込めませんでした。';
            recentPanel.appendChild(note);
        }

        if (!otherPanel.children.length) {
            const note = document.createElement('div');
            note.style.cssText = 'margin:12px 0;padding:12px 14px;border:1px solid var(--border);border-radius:8px;background:var(--surface-soft);color:var(--text-muted);font-size:13px;';
            note.textContent = 'その他の詳細情報はありません。';
            otherPanel.appendChild(note);
        }

        const buttons = Array.from(tabs.querySelectorAll('.pc-main-tab'));
        const validTabs = ['basic', 'main', 'other', 'trifecta', 'recent'];

        function activate(name) {
            if (!validTabs.includes(name)) name = 'basic';

            // 2連単・買い目など後から追加される大タブも含め、
            // 現在存在する全タブのactive状態を毎回整理する。
            Array.from(tabs.querySelectorAll('.pc-main-tab')).forEach(function (button) {
                const active = button.dataset.pcMainTab === name;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-selected', active ? 'true' : 'false');
            });

            // 後から追加されたタブパネルも含めて切り替えることで、
            // 2つのタブが同時に選択状態になる表示崩れを防ぐ。
            Array.from(container.querySelectorAll('.pc-main-tab-panel')).forEach(function (panel) {
                const active = panel.dataset.pcMainPanel === name;
                panel.classList.toggle('is-active', active);
                panel.hidden = !active;
            });

            try {
                sessionStorage.setItem(STORAGE_KEY, name);
            } catch (e) {}
        }

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                activate(button.dataset.pcMainTab || 'basic');
            });
        });

        let initial = 'basic';
        try {
            const saved = sessionStorage.getItem(STORAGE_KEY);
            if (validTabs.includes(saved)) {
                initial = saved;
            }
        } catch (e) {}

        activate(initial);
    }

    function scheduleSetup() {
        // DOMContentLoaded内で直接実行すると、後から登録された既存パネルの
        // 移動処理より先に走るため、0ms後へ送り最後に振り分ける。
        window.setTimeout(setupPcMainTabs, 0);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scheduleSetup);
    } else {
        scheduleSetup();
    }
})();

(function () {
    'use strict';

    function currentRaceCode() {
        const node = document.querySelector('.code-box .code-value');
        const code = String(node ? node.textContent : '').trim().toUpperCase();
        return /^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/.test(code) ? code : '';
    }

    function raceDateFromCode(code) {
        return code.slice(0, 4) + '-' + code.slice(4, 6) + '-' + code.slice(6, 8);
    }

    function showSignal() {
        const code = currentRaceCode();
        if (!code) return;

        const date = raceDateFromCode(code);
        const place = code.slice(8, 11);
        fetch('/web/tamagawa_lane4_star_api.php?date=' + encodeURIComponent(date) + '&place=' + encodeURIComponent(place), {cache: 'no-store'})
            .then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok || !data || data.status !== 'ok') {
                        throw new Error(String((data && data.error) || ('HTTP ' + response.status)));
                    }
                    return data;
                });
            })
            .then(function (data) {
                const lane1Detail = data.lane1_matches && data.lane1_matches[code] ? data.lane1_matches[code] : null;
                const lane2SashiDetail = data.lane2_sashi_matches && data.lane2_sashi_matches[code] ? data.lane2_sashi_matches[code] : null;
                const lane2MakuriDetail = data.lane2_makuri_matches && data.lane2_makuri_matches[code] ? data.lane2_makuri_matches[code] : null;
                const lane3Detail = data.lane3_matches && data.lane3_matches[code] ? data.lane3_matches[code] : null;
                const lane4Detail = data.matches && data.matches[code] ? data.matches[code] : null;
                const lane5Detail = data.lane5_matches && data.lane5_matches[code] ? data.lane5_matches[code] : null;
                const lane6Detail = data.lane6_matches && data.lane6_matches[code] ? data.lane6_matches[code] : null;
                if (!lane1Detail && !lane2SashiDetail && !lane2MakuriDetail && !lane3Detail && !lane4Detail && !lane5Detail && !lane6Detail) return;

                let retries = 40;
                function place() {
                    const panel = document.querySelector('.pc-main-tab-panel[data-pc-main-panel="basic"]');
                    if (!panel) {
                        if (retries-- > 0) window.setTimeout(place, 50);
                        return;
                    }
                    if (panel.querySelector('.tmg-course-detail-signals')) return;

                    function makeBox(detail, course) {
                        const isLane2 = course === 2;
                        const isLane1 = course === 1;
                        const isLane2Makuri = isLane2 && detail.technique === 'makuri';
                        const isLane3 = course === 3;
                        const isLane4 = course === 4;
                        const isLane6 = course === 6;
                        const level = Math.max(1, Math.min(3, Number(detail.star_level || 1)));
                        const stars = '★'.repeat(level);
                        const signal = String(detail.signal || (course + (level >= 2 ? '軸' : '攻め')));
                        const note = (course === 1 || course === 2 || course === 5 || course === 6) ? '' : (level >= 3 ? '（検証中）' : '');
                        const accent = isLane1 ? '#9a3f4b' : (isLane2Makuri ? '#7042a8' : (isLane2 ? '#287a67' : (isLane3 ? '#176c9f' : (isLane4 ? '#b87500' : (isLane6 ? '#4f5964' : '#7042a8')))));

                        const box = document.createElement('div');
                        box.className = 'tmg-lane' + course + '-detail-signal';
                        box.style.cssText = isLane2Makuri
                            ? 'padding:10px 13px;border:1px solid #c4a7e7;border-radius:8px;background:#f7efff;color:#563b72;box-shadow:0 1px 5px rgba(112,66,168,.08);'
                            : (isLane1
                                ? 'padding:10px 13px;border:1px solid #e0a8b1;border-radius:8px;background:#fff2f4;color:#6e3340;box-shadow:0 1px 5px rgba(154,63,75,.08);'
                                : (isLane2
                                ? 'padding:10px 13px;border:1px solid #9bd2bf;border-radius:8px;background:#eefaf4;color:#285b4d;box-shadow:0 1px 5px rgba(40,122,103,.08);'
                                : (isLane3
                                    ? 'padding:10px 13px;border:1px solid #8fc5e3;border-radius:8px;background:#edf8ff;color:#274f67;box-shadow:0 1px 5px rgba(38,112,151,.08);'
                                    : (isLane4
                                ? 'padding:10px 13px;border:1px solid #d8a94c;border-radius:8px;background:#fff7df;color:#5d4a21;box-shadow:0 1px 5px rgba(140,104,35,.08);'
                                : (isLane6
                                    ? 'padding:10px 13px;border:1px solid #aeb7bf;border-radius:8px;background:#f0f2f4;color:#43505b;box-shadow:0 1px 5px rgba(79,89,100,.08);'
                                    : 'padding:10px 13px;border:1px solid #c4a7e7;border-radius:8px;background:#f7efff;color:#563b72;box-shadow:0 1px 5px rgba(112,66,168,.08);')))));

                        const title = document.createElement('div');
                        title.style.cssText = 'font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px;flex-wrap:wrap;';
                        const badge = document.createElement('span');
                        badge.style.cssText = 'color:' + accent + ';font-size:17px;';
                        badge.textContent = course + 'C' + stars + ' ' + signal;
                        const name = document.createElement('span');
                        name.textContent = String(data.place_name || place) + course + 'コースサイン' + note;
                        title.appendChild(badge);
                        title.appendChild(name);
                        box.appendChild(title);

                        const parts = [];
                        if (isLane1 && Number.isFinite(Number(detail.nige_rate))) {
                            parts.push('1C逃げ率 ' + Number(detail.nige_rate).toFixed(1) + '%');
                        } else if (isLane2Makuri && Number.isFinite(Number(detail.makuri_rate))) {
                            parts.push('2まくり率 ' + Number(detail.makuri_rate).toFixed(1) + '%');
                            parts.push('ST順位 2=' + Number(detail.lane2_avg_rank).toFixed(2) + ' < 1=' + Number(detail.lane1_avg_rank).toFixed(2));
                        } else if (isLane2 && Number.isFinite(Number(detail.sashi_rate))) {
                            parts.push('2差し率 ' + Number(detail.sashi_rate).toFixed(1) + '%');
                        } else if (isLane3 && Number.isFinite(Number(detail.attack_rate))) {
                            parts.push('3攻め率 ' + Number(detail.attack_rate).toFixed(1) + '%');
                        } else if (isLane4 && Number.isFinite(Number(detail.makuri_rate))) {
                            if (detail.primary_metric === 'attack_rate' && Number.isFinite(Number(detail.attack_rate))) {
                                parts.push('4攻め率 ' + Number(detail.attack_rate).toFixed(1) + '%');
                            } else {
                                parts.push('4まくり率 ' + Number(detail.makuri_rate).toFixed(1) + '%');
                            }
                            if (Number.isFinite(Number(detail.lane3_avg_rank)) && Number.isFinite(Number(detail.lane4_avg_rank))) {
                                parts.push('ST順位 4=' + Number(detail.lane4_avg_rank).toFixed(2) + ' < 3=' + Number(detail.lane3_avg_rank).toFixed(2));
                            }
                            if (Number.isFinite(Number(detail.lane1_vulnerability_rate))) {
                                parts.push('1C脆弱性 ' + Number(detail.lane1_vulnerability_rate).toFixed(1) + '%');
                            }
                        } else if (course === 5 && Number.isFinite(Number(detail.attack_rate))) {
                            parts.push('5攻め率 ' + Number(detail.attack_rate).toFixed(1) + '%');
                        } else if (isLane6 && Number.isFinite(Number(detail.attack_rate))) {
                            parts.push('6攻め率 ' + Number(detail.attack_rate).toFixed(1) + '%');
                            parts.push('ST順位 6=' + Number(detail.lane6_avg_rank).toFixed(2) + ' < 5=' + Number(detail.lane5_avg_rank).toFixed(2));
                        }
                        if (detail.secondary_ready) {
                            parts.push('二次 ' + Number(detail.second_score).toFixed(0));
                            parts.push('TOP差 ' + Number(detail.gap_to_top).toFixed(0));
                            parts.push('直線 ' + Number(detail.straight_score).toFixed(0));
                            if (isLane1 || isLane2) {
                                parts.push('二次順位 ' + Number(detail.second_rank).toFixed(0));
                                parts.push('周回 ' + Number(detail.lap_score).toFixed(0));
                            } else if (isLane3 && Number.isFinite(Number(detail.mawari_score))) {
                                parts.push('周り足 ' + Number(detail.mawari_score).toFixed(0));
                            } else if (course === 5) {
                                parts.push('二次順位 ' + Number(detail.second_rank).toFixed(0));
                                parts.push('周回 ' + Number(detail.lap_score).toFixed(0));
                            } else if (isLane6) {
                                parts.push('二次順位 ' + Number(detail.second_rank).toFixed(0));
                                parts.push('周回 ' + Number(detail.lap_score).toFixed(0));
                            }
                        } else {
                            parts.push('展示前');
                        }

                        const sub = document.createElement('div');
                        sub.style.cssText = 'margin-top:4px;font-size:12px;color:' + (isLane1 ? '#8a5660' : (isLane2Makuri ? '#735a8d' : (isLane2 ? '#527c6d' : (isLane3 ? '#52758a' : (isLane4 ? '#7a6948' : (isLane6 ? '#66737e' : '#735a8d')))))) + ';';
                        sub.textContent = parts.join(' / ');
                        box.appendChild(sub);

                        const performance = detail.historical_stats;
                        if (performance && Number.isFinite(Number(performance.first_rate))) {
                            const signed = function (value) {
                                const n = Number(value);
                                return (n >= 0 ? '+' : '') + n.toFixed(1) + 'pt';
                            };
                            const history = document.createElement('div');
                            history.style.cssText = 'margin-top:3px;font-size:11px;color:#6f767b;';
                            const period = performance.period ? String(performance.period) : '過去24か月';
                            history.textContent = period + '実績 N=' + Number(performance.n).toLocaleString()
                                + ' / 1着率 ' + Number(performance.first_rate).toFixed(1) + '%（基準比' + signed(performance.first_delta) + '）'
                                + ' / 2連対率 ' + Number(performance.top2_rate).toFixed(1) + '%（基準比' + signed(performance.top2_delta) + '）'
                                + ' / 3連対率 ' + Number(performance.top3_rate).toFixed(1) + '%（基準比' + signed(performance.top3_delta) + '）';
                            box.appendChild(history);
                        }
                        return box;
                    }

                    const signals = document.createElement('div');
                    signals.className = 'tmg-course-detail-signals';
                    signals.style.cssText = 'display:grid;gap:8px;margin:10px 0 12px;';
                    if (lane1Detail) signals.appendChild(makeBox(lane1Detail, 1));
                    if (lane2SashiDetail) signals.appendChild(makeBox(lane2SashiDetail, 2));
                    if (lane2MakuriDetail) signals.appendChild(makeBox(lane2MakuriDetail, 2));
                    if (lane3Detail) signals.appendChild(makeBox(lane3Detail, 3));
                    if (lane4Detail) signals.appendChild(makeBox(lane4Detail, 4));
                    if (lane5Detail) signals.appendChild(makeBox(lane5Detail, 5));
                    if (lane6Detail) signals.appendChild(makeBox(lane6Detail, 6));
                    panel.insertBefore(signals, panel.firstChild);
                }
                place();
            })
            .catch(function () {
                // 詳細表示本体を壊さないため、サインAPI失敗時は表示しない。
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { window.setTimeout(showSignal, 120); });
    } else {
        window.setTimeout(showSignal, 120);
    }
})();
