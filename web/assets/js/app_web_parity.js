(function () {
    'use strict';

    const PARITY_STORAGE_KEY = 'boatraceAppTabParity';
    const DESIRED_TABS = ['basic', 'player', 'main', 'exacta', 'trifecta', 'bet', 'recent', 'other'];

    function normalizeText(value) {
        return String(value || '').replace(/\s+/g, '').trim();
    }

    function ensureStyles() {
        if (document.getElementById('app-web-parity-style')) return;
        const style = document.createElement('style');
        style.id = 'app-web-parity-style';
        style.textContent = `
            .app-tabs.app-web-parity-tabs {
                display:flex !important;
                grid-template-columns:none !important;
                gap:0;
                overflow-x:auto;
                -webkit-overflow-scrolling:touch;
                scrollbar-width:thin;
                padding-bottom:2px;
            }
            .app-tabs.app-web-parity-tabs .app-tab {
                flex:0 0 auto;
                min-width:86px;
                padding:7px 9px;
                white-space:normal;
                line-height:1.25;
                font-size:12px;
            }
            .app-tabs.app-web-parity-tabs .app-tab[data-tab="basic"] { min-width:108px; }
            .app-tabs.app-web-parity-tabs .app-tab[data-tab="player"] { min-width:104px; }
            .app-tabs.app-web-parity-tabs .app-tab[data-tab="recent"] { min-width:88px; }
            .app-parity-card-title {
                padding:11px 12px 8px;
                border-bottom:1px solid var(--app-border);
                background:#fffaf2;
            }
            .app-parity-card-title .app-section-title { margin:0; }
            .app-parity-ai-second-section {
                background:#eee8f7 !important;
                color:#75659b !important;
            }
            .app-parity-ai-rate {
                color:#75659b !important;
                font-weight:900 !important;
            }
            .app-parity-delta-plus { color:#b45309 !important; font-weight:900 !important; }
            .app-parity-delta-minus { color:#64748b !important; font-weight:900 !important; }
            .app-parity-note {
                padding:7px 10px 10px;
                color:#6b7785;
                font-size:10px;
                line-height:1.5;
            }
            .app-sam-pattern-marker,
            .app-sam-position-hint {
                display:table;
                margin:3px auto 0;
                padding:2px 4px;
                border:1px solid;
                border-radius:999px;
                font-size:8px;
                font-weight:900;
                line-height:1.25;
                white-space:nowrap;
            }
            .app-sam-position-hint { border-radius:4px; }
            .app-sam-boat-badge { cursor:pointer; }
            #app-player-sam-modal-backdrop {
                position:fixed;
                inset:0;
                z-index:13000;
                display:none;
                align-items:center;
                justify-content:center;
                padding:12px;
                background:rgba(15,23,42,.62);
                box-sizing:border-box;
            }
            #app-player-sam-modal {
                width:min(760px,calc(100vw - 24px));
                max-height:88vh;
                display:flex;
                flex-direction:column;
                overflow:hidden;
                background:#f8f4ec;
                border:1px solid #d8cdbc;
                border-radius:12px;
                box-shadow:0 24px 60px rgba(15,23,42,.35);
                color:#3f4b5a;
            }
            .app-player-sam-modal-header {
                display:flex;
                align-items:center;
                justify-content:space-between;
                gap:10px;
                padding:11px 12px;
                border-bottom:1px solid #d8cdbc;
                background:#fffaf2;
            }
            .app-player-sam-modal-title { font-size:15px; font-weight:900; color:#75659b; }
            .app-player-sam-modal-close {
                border:0;
                background:transparent;
                color:#64748b;
                font-size:28px;
                line-height:1;
                padding:0 3px;
            }
            .app-player-sam-modal-body { padding:10px; overflow:auto; -webkit-overflow-scrolling:touch; }
            #ai-tenkai-trial-panel { margin:0 0 10px !important; }
            #ai-tenkai-trial-panel table { min-width:560px !important; }
            .app-parity-other-title { margin-bottom:0; }
            @media (max-width:390px) {
                .app-tabs.app-web-parity-tabs .app-tab { min-width:80px; font-size:11px; padding-inline:7px; }
                .app-tabs.app-web-parity-tabs .app-tab[data-tab="basic"] { min-width:102px; }
                .app-tabs.app-web-parity-tabs .app-tab[data-tab="player"] { min-width:98px; }
            }
        `;
        document.head.appendChild(style);
    }

    function directChildUnder(parent, node) {
        let current = node;
        while (current && current.parentElement && current.parentElement !== parent) {
            current = current.parentElement;
        }
        return current && current.parentElement === parent ? current : null;
    }

    function ensureButton(tabs, name, label) {
        let button = tabs.querySelector('.app-tab[data-tab="' + name + '"]');
        if (!button) {
            button = document.createElement('button');
            button.type = 'button';
            button.className = 'app-tab';
            button.dataset.tab = name;
            tabs.appendChild(button);
        }
        if (label) button.textContent = label;
        return button;
    }

    function ensurePanel(shell, name) {
        let panel = shell.querySelector('.app-tab-panel[data-panel="' + name + '"]');
        if (!panel) {
            panel = document.createElement('div');
            panel.className = 'app-tab-panel';
            panel.dataset.panel = name;
            panel.hidden = true;
            const existing = Array.from(shell.querySelectorAll(':scope > .app-tab-panel'));
            const last = existing.length ? existing[existing.length - 1] : null;
            if (last) last.insertAdjacentElement('afterend', panel);
            else shell.appendChild(panel);
        }
        return panel;
    }

    function reorderTabs(tabs) {
        const current = Array.from(tabs.querySelectorAll('.app-tab')).map(function (button) {
            return button.dataset.tab || '';
        });
        const target = DESIRED_TABS.filter(function (name) {
            return !!tabs.querySelector('.app-tab[data-tab="' + name + '"]');
        });
        const extras = current.filter(function (name) { return name && !DESIRED_TABS.includes(name); });
        const desired = target.concat(extras);
        if (current.join('|') === desired.join('|')) return;
        desired.forEach(function (name) {
            const button = tabs.querySelector('.app-tab[data-tab="' + name + '"]');
            if (button) tabs.appendChild(button);
        });
    }

    function activateTab(name) {
        const tabs = document.querySelector('.app-tabs');
        const shell = document.querySelector('.app-shell');
        if (!tabs || !shell) return;
        const panel = shell.querySelector('.app-tab-panel[data-panel="' + name + '"]');
        const button = tabs.querySelector('.app-tab[data-tab="' + name + '"]');
        if (!panel || !button) return;

        Array.from(tabs.querySelectorAll('.app-tab')).forEach(function (tab) {
            const active = tab.dataset.tab === name;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        Array.from(shell.querySelectorAll('.app-tab-panel')).forEach(function (tabPanel) {
            const active = tabPanel.dataset.panel === name;
            tabPanel.classList.toggle('is-active', active);
            tabPanel.hidden = !active;
        });
        try {
            sessionStorage.setItem(PARITY_STORAGE_KEY, name);
            sessionStorage.setItem('boatraceAppTab', name);
            sessionStorage.setItem('boatraceAppTabEnhanced', name);
        } catch (e) {}
    }

    function setupUnifiedTabClicks(tabs) {
        if (tabs.dataset.appWebParityEvents === '1') return;
        tabs.dataset.appWebParityEvents = '1';
        tabs.addEventListener('click', function (event) {
            const button = event.target.closest('.app-tab');
            if (!button || !tabs.contains(button)) return;
            const name = String(button.dataset.tab || '');
            if (!name) return;
            window.setTimeout(function () { activateTab(name); }, 0);
        });
    }

    function directGridChildren(grid) {
        return grid ? Array.from(grid.children) : [];
    }

    function rowByLabel(grid, label) {
        const children = directGridChildren(grid);
        const wanted = normalizeText(label);
        const labelIndex = children.findIndex(function (node) {
            return node.classList && node.classList.contains('app-basic-label')
                && normalizeText(node.textContent) === wanted;
        });
        if (labelIndex < 0) return [];
        return children.slice(labelIndex, labelIndex + 7);
    }

    function headerRow(grid) {
        return rowByLabel(grid, '進入');
    }

    function appendClones(target, nodes) {
        nodes.forEach(function (node) { target.appendChild(node.cloneNode(true)); });
    }

    function createCard(title) {
        const card = document.createElement('section');
        card.className = 'app-card app-basic-card app-parity-role-card';
        const head = document.createElement('div');
        head.className = 'app-parity-card-title';
        head.innerHTML = '<h2 class="app-section-title">' + title + '</h2>';
        card.appendChild(head);
        return card;
    }

    function createGridFromRows(sourceGrid, title, labels) {
        const card = createCard(title);
        const grid = document.createElement('div');
        grid.className = 'app-basic-grid';
        appendClones(grid, headerRow(sourceGrid));
        labels.forEach(function (label) { appendClones(grid, rowByLabel(sourceGrid, label)); });
        card.appendChild(grid);
        return {card: card, grid: grid};
    }

    function parseLegacyExacta() {
        const grid = document.querySelector('.app-main-exacta .app-exacta-grid');
        if (!grid) return {headBoat: 1, bySecondBoat: {}};
        const cells = Array.from(grid.children);
        if (cells.length < 24) return {headBoat: 1, bySecondBoat: {}};
        const bySecondBoat = {};
        let headBoat = 1;
        for (let i = 1; i <= 5; i++) {
            const combo = String(cells[i].textContent || '').trim();
            const match = combo.match(/([1-6])\s*-\s*([1-6])/);
            if (!match) continue;
            headBoat = Number(match[1]);
            const second = Number(match[2]);
            bySecondBoat[second] = {
                base: String(cells[6 + i].textContent || '').trim(),
                ai: String(cells[12 + i].textContent || '').trim(),
                delta: String(cells[18 + i].textContent || '').trim()
            };
        }
        return {headBoat: headBoat, bySecondBoat: bySecondBoat};
    }

    function headerBoatOrder(grid) {
        const row = headerRow(grid);
        if (row.length !== 7) return [1, 2, 3, 4, 5, 6];
        return row.slice(1).map(function (node, index) {
            const match = String(node.textContent || '').match(/([1-6])号/);
            return match ? Number(match[1]) : index + 1;
        });
    }

    function appendCustomRow(grid, label, values, classNameFn) {
        const labelNode = document.createElement('div');
        labelNode.className = 'app-basic-label';
        labelNode.textContent = label;
        grid.appendChild(labelNode);
        values.forEach(function (value, index) {
            const node = document.createElement('div');
            node.className = 'app-basic-value';
            if (classNameFn) {
                const extra = classNameFn(value, index);
                if (extra) node.classList.add(extra);
            }
            node.textContent = value;
            grid.appendChild(node);
        });
    }

    function buildHead1Card(sourceGrid) {
        const built = createGridFromRows(sourceGrid, '🎯 1号艇1着時の2着率', ['場2着率', '基本2着率']);
        const exacta = parseLegacyExacta();
        const boatOrder = headerBoatOrder(sourceGrid);
        if (Object.keys(exacta.bySecondBoat).length) {
            const band = document.createElement('div');
            band.className = 'app-basic-section app-parity-ai-second-section';
            band.textContent = '今回AI（イン1Cが1着の場合）';
            built.grid.appendChild(band);

            function valuesFor(key) {
                return boatOrder.map(function (boat) {
                    const row = exacta.bySecondBoat[boat];
                    return row ? row[key] : '-';
                });
            }
            appendCustomRow(built.grid, 'AI場平均', valuesFor('base'));
            appendCustomRow(built.grid, '今回AI2着率', valuesFor('ai'), function () { return 'app-parity-ai-rate'; });
            appendCustomRow(built.grid, '場平均との差', valuesFor('delta'), function (value) {
                return String(value).trim().startsWith('+') ? 'app-parity-delta-plus' : 'app-parity-delta-minus';
            });

            const note = document.createElement('div');
            note.className = 'app-parity-note';
            note.textContent = '今回AI2着率：最終3連単出目確率から P(2着 | 1C頭) を集約。AI場平均・差は従来の「イン1着時 2連単」と同じ値。';
            built.card.appendChild(note);
        }
        return built.card;
    }

    function sectionRange(grid, title) {
        const children = directGridChildren(grid);
        const wanted = normalizeText(title);
        const start = children.findIndex(function (node) {
            return node.classList && node.classList.contains('app-basic-section')
                && normalizeText(node.textContent) === wanted;
        });
        if (start < 0) return [];
        let end = children.length;
        for (let i = start + 1; i < children.length; i++) {
            if (children[i].classList && children[i].classList.contains('app-basic-section')) {
                end = i;
                break;
            }
        }
        return children.slice(start, end);
    }

    function buildKimariteCard(sourceGrid) {
        const card = createCard('🎯 決まり手傾向');
        const grid = document.createElement('div');
        grid.className = 'app-basic-grid';
        appendClones(grid, headerRow(sourceGrid));
        const block = sectionRange(sourceGrid, '決まり手');
        appendClones(grid, block.slice(1));
        card.appendChild(grid);
        return card;
    }

    function removeSection(sourceGrid, title) {
        sectionRange(sourceGrid, title).forEach(function (node) { node.remove(); });
    }

    function addOtherTitle(card) {
        if (!card || card.querySelector('.app-parity-other-title')) return;
        const head = document.createElement('div');
        head.className = 'app-card-body app-parity-other-title';
        head.innerHTML = '<h2 class="app-section-title">🧪 評価詳細</h2><div class="app-note">一次評価・展示加工評価などの詳細確認用。</div>';
        card.insertBefore(head, card.firstChild);
    }

    function buildRoleContent(shell, basicPanel, mainPanel, playerPanel, otherPanel) {
        if (shell.dataset.appWebParityContent === '1') return true;
        const sourceCard = mainPanel.querySelector('.app-analysis-card');
        const sourceGrid = sourceCard ? sourceCard.querySelector('.app-basic-grid') : null;
        const legacyExacta = mainPanel.querySelector('.app-main-exacta');
        if (!sourceCard || !sourceGrid || !legacyExacta) return false;

        const rate = createGridFromRows(
            sourceGrid,
            '📊 連対率・勝率',
            ['基本1着率', '補正後1着率', '基礎3連対率', 'AI3連対率']
        ).card;
        playerPanel.appendChild(rate);
        playerPanel.appendChild(buildHead1Card(sourceGrid));
        playerPanel.appendChild(buildKimariteCard(sourceGrid));

        const aiTenkai = document.getElementById('ai-tenkai-trial-panel');
        if (aiTenkai) playerPanel.appendChild(aiTenkai);

        ['🎯 1着率', '🎯 1号艇1着時の2着率', '🤖 AI3連対率', '決まり手'].forEach(function (title) {
            removeSection(sourceGrid, title);
        });
        const status = sourceCard.querySelector('.app-basic-status');
        if (status) status.remove();
        addOtherTitle(sourceCard);
        otherPanel.appendChild(sourceCard);

        const forwardInput = basicPanel.querySelector('input[name="fv_race_code"]');
        const forwardRoot = forwardInput ? directChildUnder(basicPanel, forwardInput) : null;
        if (forwardRoot) otherPanel.insertBefore(forwardRoot, otherPanel.firstChild);

        const playerSam = document.getElementById('player-sam-panel');
        const cross = document.getElementById('player-sam-cross-panel');
        if (playerSam) playerSam.style.display = 'none';
        if (cross) cross.style.display = 'none';

        legacyExacta.style.display = 'none';

        const finalCard = Array.from(mainPanel.children).find(function (node) {
            return node.matches && node.matches('section.app-card') && String(node.textContent || '').includes('最終予想');
        });
        if (finalCard) mainPanel.insertBefore(finalCard, mainPanel.firstChild);

        shell.dataset.appWebParityContent = '1';
        return true;
    }

    function detailsByBoat(panel) {
        const map = {};
        if (!panel) return map;
        Array.from(panel.querySelectorAll('details')).forEach(function (details) {
            const text = String(details.textContent || '');
            const match = text.match(/([1-6])号艇/);
            if (match) map[Number(match[1])] = details;
        });
        return map;
    }

    function crossPatterns(panel) {
        const map = {};
        if (!panel) return map;
        Array.from(panel.querySelectorAll('tbody tr')).forEach(function (row) {
            if (!row.cells || row.cells.length < 5) return;
            const boatMatch = String(row.cells[0].textContent || '').match(/([1-6])号艇/);
            if (!boatMatch) return;
            const boat = Number(boatMatch[1]);
            const label = String(row.cells[row.cells.length - 1].textContent || '').replace(/\s+/g, ' ').trim();
            if (label.includes('一致') && label.includes('↑')) {
                map[boat] = {text:'◎ 場↑ 選↑', title:'場SUMも選手SUMもプラス方向', css:'background:#e8f4f7;border-color:#9ec7d3;color:#2f789f;'};
            } else if (label.includes('一致') && label.includes('↓')) {
                map[boat] = {text:'▼ 場↓ 選↓', title:'場SUMも選手SUMもマイナス方向', css:'background:#f8ece8;border-color:#dfb2a7;color:#a74932;'};
            } else if (label.includes('逆行') && label.includes('選手↑')) {
                map[boat] = {text:'⚠ 場↓ 選↑', title:'場SUMはマイナス、選手SUMはプラス', css:'background:#fff3d6;border-color:#e2bf73;color:#8a5a12;'};
            } else if (label.includes('逆行') && label.includes('選手↓')) {
                map[boat] = {text:'⚠ 場↑ 選↓', title:'場SUMはプラス、選手SUMはマイナス', css:'background:#fff3d6;border-color:#e2bf73;color:#8a5a12;'};
            } else if (label.includes('中立')) {
                map[boat] = {text:'－ 中立', title:'場SUM × 選手SUM：中立', css:'background:#f1ede6;border-color:#d8cdbc;color:#6b7785;'};
            } else if (label.includes('選手参考外')) {
                map[boat] = {text:'? 参考外', title:'選手SUMのサンプル不足', css:'background:#f7efe0;border-color:#dbc79f;color:#9a6b26;'};
            } else {
                map[boat] = {text:'—', title:'判定なし', css:'background:#f1ede6;border-color:#d8cdbc;color:#8a8176;'};
            }
        });
        return map;
    }

    function parseDiffPt(text) {
        const match = String(text || '').replace(/\s+/g, '').match(/([+-]?\d+(?:\.\d+)?)pt/);
        return match ? Number(match[1]) : null;
    }

    function currentBandHint(details) {
        if (!details) return null;
        const current = Array.from(details.querySelectorAll('tbody tr')).find(function (row) {
            return String(row.textContent || '').includes('←現在');
        });
        if (!current || !current.cells || current.cells.length < 6) return null;
        const nMatch = String(current.cells[1].textContent || '').match(/\d+/);
        const n = nMatch ? Number(nMatch[0]) : 0;
        if (n < 5) return null;
        const metrics = [
            {label:'1着', value:parseDiffPt(current.cells[2].textContent)},
            {label:'2着', value:parseDiffPt(current.cells[3].textContent)},
            {label:'3着', value:parseDiffPt(current.cells[4].textContent)}
        ].filter(function (item) { return Number.isFinite(item.value) && Math.abs(item.value) >= 10; })
         .sort(function (a, b) { return Math.abs(b.value) - Math.abs(a.value); });
        let selected = metrics[0] || null;
        const trio = parseDiffPt(current.cells[5].textContent);
        if (!selected && Number.isFinite(trio) && Math.abs(trio) >= 10) selected = {label:'3連', value:trio};
        if (!selected) return null;
        const signed = (selected.value > 0 ? '+' : '') + selected.value.toFixed(1) + 'pt';
        return {
            text:selected.label + (selected.value > 0 ? '↑ ' : '↓ ') + signed + (n < 10 ? ' ※' : ''),
            title:'選手SUM現在帯：' + selected.label + '差 ' + signed + ' / N=' + n + (n < 10 ? '（母数少）' : ''),
            css:selected.value > 0
                ? 'background:#eef7fb;border-color:#b7d6df;color:#2f789f;'
                : 'background:#f9efec;border-color:#e3c0b8;color:#a74932;',
            lowN:n < 10
        };
    }

    function ensurePlayerModal() {
        let backdrop = document.getElementById('app-player-sam-modal-backdrop');
        if (backdrop) return backdrop;
        backdrop = document.createElement('div');
        backdrop.id = 'app-player-sam-modal-backdrop';
        backdrop.innerHTML = '<div id="app-player-sam-modal" role="dialog" aria-modal="true">'
            + '<div class="app-player-sam-modal-header"><div class="app-player-sam-modal-title">👤 選手SUM特性</div><button type="button" class="app-player-sam-modal-close" aria-label="閉じる">×</button></div>'
            + '<div class="app-player-sam-modal-body"></div></div>';
        document.body.appendChild(backdrop);
        function close() {
            backdrop.style.display = 'none';
            const body = backdrop.querySelector('.app-player-sam-modal-body');
            if (body) body.innerHTML = '';
            document.body.style.overflow = backdrop.dataset.prevBodyOverflow || '';
        }
        backdrop.querySelector('.app-player-sam-modal-close').addEventListener('click', close);
        backdrop.addEventListener('click', function (event) { if (event.target === backdrop) close(); });
        document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && backdrop.style.display !== 'none') close(); });
        backdrop._closePlayerSam = close;
        return backdrop;
    }

    function openPlayerModal(boat, details) {
        const backdrop = ensurePlayerModal();
        const title = backdrop.querySelector('.app-player-sam-modal-title');
        const body = backdrop.querySelector('.app-player-sam-modal-body');
        if (title) title.textContent = '👤 選手SUM特性 — ' + boat + '号艇';
        if (body) {
            body.innerHTML = '';
            if (details) {
                const clone = details.cloneNode(true);
                clone.open = true;
                clone.style.margin = '0';
                body.appendChild(clone);
            } else {
                body.textContent = 'この艇の選手SUM特性を取得できませんでした。';
            }
        }
        backdrop.dataset.prevBodyOverflow = document.body.style.overflow || '';
        document.body.style.overflow = 'hidden';
        backdrop.style.display = 'flex';
    }

    function decorateSam() {
        const samCard = document.querySelector('.app-sam-applied-card');
        const playerPanel = document.getElementById('player-sam-panel');
        const crossPanel = document.getElementById('player-sam-cross-panel');
        if (!samCard || !playerPanel) return false;
        playerPanel.style.display = 'none';
        if (crossPanel) crossPanel.style.display = 'none';

        const details = detailsByBoat(playerPanel);
        const patterns = crossPatterns(crossPanel);
        Array.from(samCard.querySelectorAll('tbody tr')).forEach(function (row) {
            const badge = row.querySelector('.app-sam-boat-badge');
            if (!badge) return;
            const boat = Number(badge.dataset.boat || (String(badge.textContent || '').match(/[1-6]/) || [0])[0]);
            if (boat < 1 || boat > 6) return;
            if (badge.dataset.appSamReady !== '1') {
                badge.dataset.appSamReady = '1';
                badge.setAttribute('role', 'button');
                badge.setAttribute('tabindex', '0');
                badge.setAttribute('title', boat + '号艇の選手SUM特性を表示');
                const open = function () { openPlayerModal(boat, details[boat] || null); };
                badge.addEventListener('click', open);
                badge.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); open(); }
                });
            }
            const cell = badge.parentElement;
            if (!cell) return;
            if (!cell.querySelector('.app-sam-pattern-marker') && patterns[boat]) {
                const marker = document.createElement('div');
                marker.className = 'app-sam-pattern-marker';
                marker.textContent = patterns[boat].text;
                marker.title = patterns[boat].title;
                marker.style.cssText += patterns[boat].css;
                cell.appendChild(marker);
            }
            if (!cell.querySelector('.app-sam-position-hint')) {
                const hint = currentBandHint(details[boat] || null);
                if (hint) {
                    const marker = document.createElement('div');
                    marker.className = 'app-sam-position-hint';
                    marker.textContent = hint.text;
                    marker.title = hint.title;
                    marker.style.cssText += (hint.lowN ? 'border-style:dashed;' : '') + hint.css;
                    cell.appendChild(marker);
                }
            }
        });
        return true;
    }

    function setupOnce() {
        const shell = document.querySelector('.app-shell');
        const tabs = document.querySelector('.app-tabs');
        if (!shell || !tabs) return false;
        ensureStyles();
        tabs.classList.add('app-web-parity-tabs');

        const basicButton = ensureButton(tabs, 'basic', '場・出走・展示');
        ensureButton(tabs, 'player', '連対率・展開');
        const mainButton = ensureButton(tabs, 'main', 'AI予想');
        ensureButton(tabs, 'other', 'その他');
        if (basicButton) basicButton.textContent = '場・出走・展示';
        if (mainButton) mainButton.textContent = 'AI予想';

        const basicPanel = ensurePanel(shell, 'basic');
        const playerPanel = ensurePanel(shell, 'player');
        const mainPanel = ensurePanel(shell, 'main');
        const otherPanel = ensurePanel(shell, 'other');

        reorderTabs(tabs);
        setupUnifiedTabClicks(tabs);
        buildRoleContent(shell, basicPanel, mainPanel, playerPanel, otherPanel);
        decorateSam();

        if (tabs.dataset.appWebParityObserver !== '1') {
            tabs.dataset.appWebParityObserver = '1';
            const observer = new MutationObserver(function () {
                reorderTabs(tabs);
                tabs.classList.add('app-web-parity-tabs');
            });
            observer.observe(tabs, {childList:true});
        }
        return true;
    }

    function start() {
        let tries = 160;
        function run() {
            const ready = setupOnce();
            if ((!ready || !document.querySelector('.app-sam-applied-card')) && tries-- > 0) {
                window.setTimeout(run, 50);
            } else {
                // exacta / 120通り / 買い目 / 直近60R は後から追加されるため、少し後にも順序を確定する。
                [250, 600, 1200, 2000].forEach(function (delay) {
                    window.setTimeout(function () {
                        setupOnce();
                        const tabs = document.querySelector('.app-tabs');
                        if (tabs) reorderTabs(tabs);
                    }, delay);
                });
                window.setTimeout(function () {
                    let saved = '';
                    try { saved = sessionStorage.getItem(PARITY_STORAGE_KEY) || ''; } catch (e) {}
                    if (saved && document.querySelector('.app-tab[data-tab="' + saved + '"]')) activateTab(saved);
                }, 1300);
            }
        }
        run();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { window.setTimeout(start, 0); });
    } else {
        window.setTimeout(start, 0);
    }
})();
