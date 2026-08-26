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

        const tabs = document.createElement('nav');
        tabs.className = 'pc-main-tabs';
        tabs.setAttribute('aria-label', 'Web表示切替');
        tabs.innerHTML = ''
            + '<button type="button" class="pc-main-tab is-active" data-pc-main-tab="basic">基本情報</button>'
            + '<button type="button" class="pc-main-tab" data-pc-main-tab="main">メイン情報</button>'
            + '<button type="button" class="pc-main-tab" data-pc-main-tab="trifecta">120通り</button>'
            + '<button type="button" class="pc-main-tab" data-pc-main-tab="recent">直近60R</button>';

        const basicPanel = document.createElement('div');
        basicPanel.className = 'pc-main-tab-panel is-active';
        basicPanel.dataset.pcMainPanel = 'basic';

        const mainPanel = document.createElement('div');
        mainPanel.className = 'pc-main-tab-panel';
        mainPanel.dataset.pcMainPanel = 'main';
        mainPanel.hidden = true;

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
        mainPanel.insertAdjacentElement('afterend', trifectaPanel);
        trifectaPanel.insertAdjacentElement('afterend', recentPanel);

        const basicNodes = new Set();

        // 場特性5タブと前向き実戦検証はアプリ版と同じく「基本情報」側。
        sourceNodes.forEach(function (node) {
            if (!node || node.nodeType !== Node.ELEMENT_NODE) return;
            if (String(node.id || '').startsWith('stadium-characteristics-tabs-pc-')) {
                basicNodes.add(node);
            } else if (isForwardValidationNode(node)) {
                basicNodes.add(node);
            }
        });

        // 総合出走・展示マトリクスも「基本情報」側へまとめる。
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
            note.textContent = '3連単120通りは計算待ちです。';
            trifectaPanel.appendChild(note);
        }

        if (!recentHistory) {
            const note = document.createElement('div');
            note.style.cssText = 'margin:12px 0;padding:12px 14px;border:1px solid var(--border);border-radius:8px;background:var(--surface-soft);color:var(--text-muted);font-size:13px;';
            note.textContent = '直近60Rパネルを読み込めませんでした。';
            recentPanel.appendChild(note);
        }

        const buttons = Array.from(tabs.querySelectorAll('.pc-main-tab'));
        const panels = [basicPanel, mainPanel, trifectaPanel, recentPanel];
        const validTabs = ['basic', 'main', 'trifecta', 'recent'];

        function activate(name) {
            if (!validTabs.includes(name)) name = 'basic';

            buttons.forEach(function (button) {
                const active = button.dataset.pcMainTab === name;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-selected', active ? 'true' : 'false');
            });

            panels.forEach(function (panel) {
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
