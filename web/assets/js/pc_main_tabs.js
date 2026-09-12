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

    function makeMatrixSubsetCard(sourceTable, rows, titleText, noteText, className) {
        if (!sourceTable || !rows.length) return null;

        const card = document.createElement('div');
        card.className = className || 'pc-review-subset-card';
        card.style.cssText = 'margin:12px 0 16px;padding:12px;border:1px solid var(--border);border-radius:10px;background:var(--surface-soft);';

        const title = document.createElement('div');
        title.style.cssText = 'font-size:16px;font-weight:800;color:var(--text-strong);margin-bottom:4px;';
        title.textContent = titleText;
        card.appendChild(title);

        if (noteText) {
            const note = document.createElement('div');
            note.style.cssText = 'font-size:12px;color:var(--text-muted);margin-bottom:10px;';
            note.textContent = noteText;
            card.appendChild(note);
        }

        const wrap = document.createElement('div');
        wrap.className = 'matrix-wrapper';
        wrap.style.cssText = 'overflow-x:auto;';

        const table = sourceTable.cloneNode(false);
        table.removeAttribute('id');
        table.classList.add('pc-review-subset-table');

        if (sourceTable.tHead) table.appendChild(sourceTable.tHead.cloneNode(true));
        const tbody = document.createElement('tbody');
        rows.forEach(function (row) {
            tbody.appendChild(row.cloneNode(true));
        });
        table.appendChild(tbody);
        wrap.appendChild(table);
        card.appendChild(wrap);
        return card;
    }

    function reorganizeMatrix(basicPanel, mainPanel, otherPanel) {
        const matrix = basicPanel ? basicPanel.querySelector('.matrix-table') : null;
        const tbody = matrix && matrix.tBodies ? matrix.tBodies[0] : null;
        if (!matrix || !tbody || matrix.dataset.pcReviewReorganized === '1') return;
        matrix.dataset.pcReviewReorganized = '1';

        const rows = Array.from(tbody.rows);
        const otherRows = [];
        const kimariteRows = [];
        const hideOther = [];
        const hideKimarite = [];
        let section = '';

        rows.forEach(function (row) {
            if (!row.cells || !row.cells.length) return;
            const firstCell = row.cells[0];
            const label = String(firstCell.textContent || '').replace(/\s+/g, ' ').trim();
            const isSection = Number(firstCell.colSpan || 1) >= 7;

            if (isSection) {
                if (label.includes('出走表・基本情報')) {
                    section = 'basic';
                } else if (label.includes('展示・評価情報')) {
                    section = 'exhibition';
                } else if (label.includes('決まり手（直近1年）') || label.includes('決まり手（直近6ヶ月）')) {
                    section = 'kimarite';
                } else if (section === 'kimarite') {
                    section = 'after';
                }
            }

            if (section === 'basic') {
                if (isSection) {
                    otherRows.push(row);
                    return;
                }
                if (label === '選手名' || label === '級別 / 支部') return;
                otherRows.push(row);
                hideOther.push(row);
                return;
            }

            if (section === 'exhibition') {
                if (isSection) {
                    otherRows.push(row);
                    return;
                }
                // 基本情報側には、今回の進入だけ残す。
                if (label === '展示進入コース') return;
                otherRows.push(row);
                hideOther.push(row);
                return;
            }

            if (section === 'kimarite') {
                kimariteRows.push(row);
                hideKimarite.push(row);
            }
        });

        // クローンを作ってから元行を隠す。元DOMは各既存スクリプトの参照用に残す。
        const otherCard = makeMatrixSubsetCard(
            matrix,
            otherRows,
            '📦 一次・展示・二次 詳細',
            '使用頻度の低い詳細値は「その他」にまとめています。計算ロジックは変更していません。',
            'pc-review-detail-card'
        );
        if (otherCard && otherPanel) otherPanel.insertBefore(otherCard, otherPanel.firstChild);

        const kimariteCard = makeMatrixSubsetCard(
            matrix,
            kimariteRows,
            '🎯 決まり手',
            '直近1年 / 6ヶ月のコース別決まり手を、よく見る情報としてメインへ移動しました。',
            'pc-review-kimarite-card'
        );
        if (kimariteCard && mainPanel) mainPanel.insertBefore(kimariteCard, mainPanel.firstChild);

        hideOther.forEach(function (row) { row.style.display = 'none'; });
        hideKimarite.forEach(function (row) { row.style.display = 'none'; });
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

        // 場特性は「基本情報」。前向き実戦検証は棚卸し結果に合わせ「その他」へ。
        sourceNodes.forEach(function (node) {
            if (!node || node.nodeType !== Node.ELEMENT_NODE) return;
            if (String(node.id || '').startsWith('stadium-characteristics-tabs-pc-')) {
                basicNodes.add(node);
            } else if (isForwardValidationNode(node)) {
                otherNodes.add(node);
            } else if (String(node.id || '') === 'sam-block') {
                // 旧マスタ表示は残すが、普段使いからは外す。
                otherNodes.add(node);
            }
        });

        // 総合出走・展示マトリクスは「基本情報」側へまとめる。
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

        // 決まり手はメインへ、一次/展示/二次の詳細はその他へ。
        // 元行はDOMに残したまま非表示にして、既存計算・抽出処理への影響を避ける。
        window.setTimeout(function () {
            reorganizeMatrix(basicPanel, mainPanel, otherPanel);
        }, 120);
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
        if (!code || code.slice(8, 11) !== 'TMG') return;

        const date = raceDateFromCode(code);
        fetch('/web/tamagawa_lane4_star_api.php?date=' + encodeURIComponent(date), {cache: 'no-store'})
            .then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok || !data || data.status !== 'ok') {
                        throw new Error(String((data && data.error) || ('HTTP ' + response.status)));
                    }
                    return data;
                });
            })
            .then(function (data) {
                const detail = data.matches && data.matches[code] ? data.matches[code] : null;
                if (!detail) return;

                let retries = 40;
                function place() {
                    const panel = document.querySelector('.pc-main-tab-panel[data-pc-main-panel="basic"]');
                    if (!panel) {
                        if (retries-- > 0) window.setTimeout(place, 50);
                        return;
                    }
                    if (panel.querySelector('.tmg-lane4-detail-signal')) return;

                    const level = Number(detail.star_level || 1);
                    const label = level >= 3 ? '★★★ 4頭強' : (level === 2 ? '★★ 4軸' : '★ 4攻め');
                    const note = level >= 3 ? '（検証中）' : '';

                    const box = document.createElement('div');
                    box.className = 'tmg-lane4-detail-signal';
                    box.style.cssText = 'margin:10px 0 12px;padding:10px 13px;border:1px solid #d8a94c;border-radius:8px;background:#fff7df;color:#5d4a21;box-shadow:0 1px 5px rgba(140,104,35,.08);';

                    const title = document.createElement('div');
                    title.style.cssText = 'font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px;flex-wrap:wrap;';
                    title.innerHTML = '<span style="color:#b87500;font-size:17px;">' + label + '</span><span>多摩川4コースサイン' + note + '</span>';
                    box.appendChild(title);

                    const parts = [];
                    if (Number.isFinite(Number(detail.makuri_rate))) {
                        parts.push('4まくり率 ' + Number(detail.makuri_rate).toFixed(1) + '%');
                    }
                    if (detail.secondary_ready) {
                        parts.push('二次 ' + Number(detail.second_score).toFixed(0));
                        parts.push('TOP差 ' + Number(detail.gap_to_top).toFixed(0));
                        parts.push('直線 ' + Number(detail.straight_score).toFixed(0));
                    } else {
                        parts.push('展示前');
                    }

                    const sub = document.createElement('div');
                    sub.style.cssText = 'margin-top:4px;font-size:12px;color:#7a6948;';
                    sub.textContent = parts.join(' / ');
                    box.appendChild(sub);

                    panel.insertBefore(box, panel.firstChild);
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
