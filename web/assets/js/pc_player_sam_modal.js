(function () {
    'use strict';

    function findPlayerSamPanel() {
        return document.getElementById('player-sam-panel');
    }

    function findCrossPanel() {
        return document.getElementById('player-sam-cross-panel');
    }

    function findAppliedSamHeading() {
        return Array.from(document.querySelectorAll('h2')).find(function (node) {
            return String(node.textContent || '').includes('展示サム理論（レース適用値）');
        }) || null;
    }

    function findAppliedSamTable(heading) {
        if (!heading) return null;
        let node = heading.nextElementSibling;
        while (node) {
            if (node.tagName === 'H2') break;
            if (node.tagName === 'TABLE') return node;
            const table = node.querySelector ? node.querySelector('table') : null;
            if (table) return table;
            node = node.nextElementSibling;
        }
        return null;
    }

    function detailsByBoat(panel) {
        const map = {};
        if (!panel) return map;

        Array.from(panel.querySelectorAll('details')).forEach(function (details) {
            const summary = details.querySelector('summary');
            const text = String(summary ? summary.textContent : details.textContent || '');
            const match = text.match(/([1-6])号艇/);
            if (!match) return;
            map[Number(match[1])] = details;
        });

        return map;
    }

    function crossPatternByBoat(panel) {
        const map = {};
        if (!panel) return map;

        Array.from(panel.querySelectorAll('tbody tr')).forEach(function (row) {
            if (!row.cells || row.cells.length < 5) return;
            const boatMatch = String(row.cells[0].textContent || '').match(/([1-6])号艇/);
            if (!boatMatch) return;

            const boat = Number(boatMatch[1]);
            const label = String(row.cells[row.cells.length - 1].textContent || '').replace(/\s+/g, ' ').trim();
            let pattern = {
                text: '—',
                title: '場SUM × 選手SUM：判定なし',
                css: 'background:#f1ede6;border-color:#d8cdbc;color:#8a8176;'
            };

            if (label.includes('一致') && label.includes('↑')) {
                pattern = {
                    text: '◎ 場↑ 選↑',
                    title: '場SUMも選手SUMもプラス方向（一致↑）',
                    css: 'background:#e8f4f7;border-color:#9ec7d3;color:#2f789f;'
                };
            } else if (label.includes('一致') && label.includes('↓')) {
                pattern = {
                    text: '▼ 場↓ 選↓',
                    title: '場SUMも選手SUMもマイナス方向（一致↓）',
                    css: 'background:#f8ece8;border-color:#dfb2a7;color:#a74932;'
                };
            } else if (label.includes('逆行') && label.includes('選手↑')) {
                pattern = {
                    text: '⚠ 場↓ 選↑',
                    title: '場SUMはマイナス方向、選手SUMはプラス方向（逆行・選手↑）',
                    css: 'background:#fff3d6;border-color:#e2bf73;color:#8a5a12;'
                };
            } else if (label.includes('逆行') && label.includes('選手↓')) {
                pattern = {
                    text: '⚠ 場↑ 選↓',
                    title: '場SUMはプラス方向、選手SUMはマイナス方向（逆行・選手↓）',
                    css: 'background:#fff3d6;border-color:#e2bf73;color:#8a5a12;'
                };
            } else if (label.includes('中立')) {
                pattern = {
                    text: '－ 中立',
                    title: '場SUM × 選手SUM：中立',
                    css: 'background:#f1ede6;border-color:#d8cdbc;color:#6b7785;'
                };
            } else if (label.includes('選手参考外')) {
                pattern = {
                    text: '? 参考外',
                    title: '選手SUMのサンプル不足で参考外',
                    css: 'background:#f7efe0;border-color:#dbc79f;color:#9a6b26;'
                };
            } else if (label.includes('場SUMなし')) {
                pattern = {
                    text: '? 場なし',
                    title: '場SUMデータなし',
                    css: 'background:#f1ede6;border-color:#d8cdbc;color:#8a8176;'
                };
            }

            map[boat] = pattern;
        });

        return map;
    }

    function ensureModal() {
        let backdrop = document.getElementById('pc-player-sam-modal-backdrop');
        if (backdrop) {
            return {
                backdrop: backdrop,
                title: backdrop.querySelector('.pc-player-sam-modal-title'),
                body: backdrop.querySelector('.pc-player-sam-modal-body')
            };
        }

        backdrop = document.createElement('div');
        backdrop.id = 'pc-player-sam-modal-backdrop';
        backdrop.style.cssText = [
            'position:fixed',
            'inset:0',
            'z-index:12000',
            'display:none',
            'align-items:center',
            'justify-content:center',
            'padding:18px',
            'background:rgba(15,23,42,.62)',
            'box-sizing:border-box'
        ].join(';');

        const modal = document.createElement('div');
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.style.cssText = [
            'width:min(920px,calc(100vw - 36px))',
            'max-height:86vh',
            'display:flex',
            'flex-direction:column',
            'overflow:hidden',
            'background:#f8f4ec',
            'border:1px solid #d8cdbc',
            'border-radius:12px',
            'box-shadow:0 24px 60px rgba(15,23,42,.35)',
            'color:#3f4b5a'
        ].join(';');

        const header = document.createElement('div');
        header.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;border-bottom:1px solid #d8cdbc;background:#fffaf2;';

        const title = document.createElement('div');
        title.className = 'pc-player-sam-modal-title';
        title.style.cssText = 'font-size:16px;font-weight:800;color:#75659b;';
        title.textContent = '👤 選手SUM特性';

        const close = document.createElement('button');
        close.type = 'button';
        close.setAttribute('aria-label', '閉じる');
        close.textContent = '×';
        close.style.cssText = 'border:0;background:transparent;color:#64748b;font-size:28px;line-height:1;cursor:pointer;padding:0 4px;';

        const body = document.createElement('div');
        body.className = 'pc-player-sam-modal-body';
        body.style.cssText = 'padding:12px 14px 14px;overflow:auto;';

        header.appendChild(title);
        header.appendChild(close);
        modal.appendChild(header);
        modal.appendChild(body);
        backdrop.appendChild(modal);
        document.body.appendChild(backdrop);

        function hide() {
            backdrop.style.display = 'none';
            body.innerHTML = '';
            document.body.style.overflow = backdrop.dataset.prevBodyOverflow || '';
        }

        close.addEventListener('click', hide);
        backdrop.addEventListener('click', function (event) {
            if (event.target === backdrop) hide();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && backdrop.style.display !== 'none') hide();
        });

        backdrop._hidePlayerSamModal = hide;

        return {backdrop: backdrop, title: title, body: body};
    }

    function openModal(boat, sourceDetails) {
        const modal = ensureModal();
        modal.title.textContent = '👤 選手SUM特性 — ' + boat + '号艇';
        modal.body.innerHTML = '';

        if (!sourceDetails) {
            const empty = document.createElement('div');
            empty.style.cssText = 'padding:14px;background:#fffaf2;border:1px solid #d8cdbc;border-radius:8px;color:#a74932;font-size:13px;';
            empty.textContent = 'この艇の選手SUM特性を取得できませんでした。';
            modal.body.appendChild(empty);
        } else {
            const clone = sourceDetails.cloneNode(true);
            clone.open = true;
            clone.style.margin = '0';
            clone.style.background = '#fffaf2';
            modal.body.appendChild(clone);
        }

        modal.backdrop.dataset.prevBodyOverflow = document.body.style.overflow || '';
        document.body.style.overflow = 'hidden';
        modal.backdrop.style.display = 'flex';
    }

    function decorateTrigger(badge, boat, sourceDetails) {
        if (!badge || badge.dataset.playerSamModalReady === '1') return;
        badge.dataset.playerSamModalReady = '1';
        badge.dataset.playerSamBoat = String(boat);
        badge.setAttribute('role', 'button');
        badge.setAttribute('tabindex', '0');
        badge.setAttribute('title', boat + '号艇の選手SUM特性を表示');
        badge.style.cursor = 'pointer';
        badge.style.boxShadow = '0 0 0 2px rgba(117,101,155,.16)';

        function open() {
            openModal(boat, sourceDetails);
        }

        badge.addEventListener('click', open);
        badge.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                open();
            }
        });
    }

    function decoratePattern(cell, pattern) {
        if (!cell || !pattern) return;
        let marker = cell.querySelector('.pc-player-sam-cross-marker');
        if (!marker) {
            marker = document.createElement('div');
            marker.className = 'pc-player-sam-cross-marker';
            marker.style.cssText = 'display:table;margin:5px auto 0;padding:2px 5px;border:1px solid;border-radius:999px;font-size:9px;font-weight:800;line-height:1.25;white-space:nowrap;';
            cell.appendChild(marker);
        }
        marker.textContent = pattern.text;
        marker.setAttribute('title', pattern.title);
        marker.style.cssText = 'display:table;margin:5px auto 0;padding:2px 5px;border:1px solid;border-radius:999px;font-size:9px;font-weight:800;line-height:1.25;white-space:nowrap;' + pattern.css;
    }

    function setup() {
        const panel = findPlayerSamPanel();
        const crossPanel = findCrossPanel();
        const heading = findAppliedSamHeading();
        const table = findAppliedSamTable(heading);
        if (!panel || !heading || !table) return false;

        // PCでは常設の選手SUMチェッカーを隠し、展示SUMの艇番から必要な艇だけ開く。
        panel.style.display = 'none';

        if (!heading.querySelector('.pc-player-sam-modal-hint')) {
            const hint = document.createElement('span');
            hint.className = 'pc-player-sam-modal-hint';
            hint.style.cssText = 'margin-left:10px;font-size:11px;font-weight:500;color:#8a8176;';
            hint.textContent = '艇番クリックで選手SUM特性 / 艇番下＝場SUM×選手SUM';
            heading.appendChild(hint);
        }

        const sources = detailsByBoat(panel);
        const patterns = crossPatternByBoat(crossPanel);
        Array.from(table.querySelectorAll('tbody tr')).forEach(function (row) {
            if (!row.cells || row.cells.length < 2) return;
            const badge = row.cells[1].querySelector('.lane-badge');
            if (!badge) return;
            const match = String(badge.textContent || '').match(/([1-6])/);
            if (!match) return;
            const boat = Number(match[1]);
            decorateTrigger(badge, boat, sources[boat] || null);
            if (patterns[boat]) decoratePattern(row.cells[1], patterns[boat]);
        });

        return true;
    }

    function schedule() {
        let tries = 100;
        function run() {
            setup();
            if (tries-- > 0) window.setTimeout(run, 80);
        }
        run();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            window.setTimeout(schedule, 60);
        });
    } else {
        window.setTimeout(schedule, 60);
    }
})();
