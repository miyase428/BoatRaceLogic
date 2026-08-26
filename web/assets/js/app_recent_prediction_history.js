(function () {
    'use strict';

    const STORAGE_KEY = 'boatraceAppTabEnhanced';

    function parseConfig() {
        const node = document.getElementById('app-recent-history-config');
        if (!node) return null;
        try {
            return JSON.parse(node.textContent || '{}');
        } catch (e) {
            return null;
        }
    }

    function waitForTabs(callback, retry) {
        const tabs = document.querySelector('.app-tabs');
        const trifectaPanel = document.querySelector('.app-tab-panel[data-panel="trifecta"]');
        if (tabs && trifectaPanel) {
            callback(tabs, trifectaPanel);
            return;
        }
        if (retry <= 0) return;
        window.setTimeout(function () {
            waitForTabs(callback, retry - 1);
        }, 40);
    }

    function setup() {
        const config = parseConfig();
        if (!config) return;

        waitForTabs(function (tabs, trifectaPanel) {
            if (tabs.querySelector('[data-tab="recent"]')) return;

            tabs.style.gridTemplateColumns = 'repeat(4, minmax(0, 1fr))';

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'app-tab';
            button.dataset.tab = 'recent';
            button.textContent = '直近60R';
            tabs.appendChild(button);

            const panel = document.createElement('div');
            panel.className = 'app-tab-panel app-recent-history-panel';
            panel.dataset.panel = 'recent';
            panel.hidden = true;
            trifectaPanel.insertAdjacentElement('afterend', panel);

            panel.innerHTML = ''
                + '<section class="app-card app-recent-card">'
                + '  <div class="app-card-body app-recent-head">'
                + '    <div>'
                + '      <h2 class="app-section-title">📅 直近5開催日 予想×結果</h2>'
                + '      <div class="app-note">現在のWeb予想ロジックを、結果が揃った直近5開催日へ再適用。</div>'
                + '    </div>'
                + '    <button type="button" class="app-recent-reload">再計算</button>'
                + '  </div>'
                + '  <div class="app-card-body app-recent-status">直近60Rタブを開くと集計します。</div>'
                + '  <div class="app-recent-content" hidden>'
                + '    <div class="app-card-body app-recent-meta"></div>'
                + '    <div class="app-recent-summary"></div>'
                + '    <div class="app-card-body app-recent-subtitle">開催日別（本命＋対抗）</div>'
                + '    <div class="app-recent-days"></div>'
                + '    <div class="app-card-body app-recent-subtitle">レース一覧</div>'
                + '    <div class="app-recent-table-wrap">'
                + '      <table class="app-recent-table">'
                + '        <thead><tr>'
                + '          <th>日付</th><th>R</th><th>本命買い目</th><th>対抗買い目</th><th>実結果</th><th>本</th><th>対</th><th>払戻</th>'
                + '        </tr></thead>'
                + '        <tbody></tbody>'
                + '      </table>'
                + '    </div>'
                + '    <div class="app-card-body app-recent-errors"></div>'
                + '  </div>'
                + '</section>';

            const status = panel.querySelector('.app-recent-status');
            const content = panel.querySelector('.app-recent-content');
            const meta = panel.querySelector('.app-recent-meta');
            const summaryBox = panel.querySelector('.app-recent-summary');
            const daysBox = panel.querySelector('.app-recent-days');
            const tbody = panel.querySelector('tbody');
            const errorsBox = panel.querySelector('.app-recent-errors');
            const reload = panel.querySelector('.app-recent-reload');

            let loading = false;
            let loaded = false;

            function pct(value, digits) {
                const n = Number(value);
                return Number.isFinite(n) ? n.toFixed(digits == null ? 1 : digits) + '%' : '-';
            }

            function num(value, digits) {
                const n = Number(value);
                return Number.isFinite(n) ? n.toFixed(digits == null ? 1 : digits) : '-';
            }

            function yen(value) {
                const n = Number(value);
                return Number.isFinite(n) ? Math.round(n).toLocaleString('ja-JP') + '円' : '-';
            }

            function dateLabel(value, full) {
                const text = String(value || '');
                const m = text.match(/^(\d{4})-(\d{2})-(\d{2})$/);
                if (!m) return text;
                return full ? m[1] + '/' + m[2] + '/' + m[3] : m[2] + '/' + m[3];
            }

            function clear(node) {
                while (node && node.firstChild) node.removeChild(node.firstChild);
            }

            function makeSummaryCard(title, bucket, races) {
                const card = document.createElement('div');
                card.className = 'app-recent-summary-card';
                card.innerHTML = '<strong class="app-recent-summary-title"></strong>'
                    + '<div class="app-recent-summary-grid">'
                    + '<div><span>的中率</span><strong class="app-recent-hit-rate"></strong></div>'
                    + '<div><span>平均点</span><strong class="app-recent-avg"></strong></div>'
                    + '<div><span>回収率</span><strong class="app-recent-roi"></strong></div>'
                    + '</div>'
                    + '<div class="app-recent-money"></div>';
                card.querySelector('.app-recent-summary-title').textContent = title;
                card.querySelector('.app-recent-hit-rate').textContent = Number(bucket.hits || 0) + '/' + races + ' ' + pct(bucket.hit_rate, 1);
                card.querySelector('.app-recent-avg').textContent = num(bucket.avg_points, 1) + '点';
                card.querySelector('.app-recent-roi').textContent = pct(bucket.roi, 1);
                card.querySelector('.app-recent-money').textContent = '購入 ' + yen(bucket.investment) + ' / 払戻 ' + yen(bucket.payout);
                return card;
            }

            function renderSummary(data) {
                clear(summaryBox);
                const s = data.summary || {};
                const races = Number(s.evaluated_races || 0);
                summaryBox.appendChild(makeSummaryCard('本命買い目', s.honmei || {}, races));
                summaryBox.appendChild(makeSummaryCard('対抗買い目', s.taikou || {}, races));
                summaryBox.appendChild(makeSummaryCard('本命＋対抗', s.combined || {}, races));
            }

            function renderDays(data) {
                clear(daysBox);
                const days = Array.isArray(data.daily) ? data.daily : [];
                days.forEach(function (day) {
                    const combined = day.combined || {};
                    const races = Number(day.races || 0);
                    const card = document.createElement('div');
                    card.className = 'app-recent-day';
                    card.innerHTML = '<strong></strong><span></span><span></span>';
                    card.querySelector('strong').textContent = dateLabel(day.race_date, true);
                    const spans = card.querySelectorAll('span');
                    spans[0].textContent = '的中 ' + Number(combined.hits || 0) + '/' + races + ' ' + pct(combined.hit_rate, 1);
                    spans[1].textContent = '回収 ' + pct(combined.roi, 1);
                    daysBox.appendChild(card);
                });
            }

            function mark(hit) {
                const span = document.createElement('span');
                span.className = hit ? 'app-recent-hit' : 'app-recent-miss';
                span.textContent = hit ? '○' : '×';
                return span;
            }

            function renderRows(data) {
                clear(tbody);
                const rows = Array.isArray(data.rows) ? data.rows : [];
                rows.forEach(function (row) {
                    const tr = document.createElement('tr');
                    if (row.combined_hit) tr.classList.add('is-hit');

                    const values = [
                        dateLabel(row.race_date, false),
                        null,
                        String(row.honmei_kai || '-'),
                        String(row.taikou_kai || '-'),
                        String(row.actual_trifecta || '-')
                    ];

                    const dateCell = document.createElement('td');
                    dateCell.textContent = values[0];
                    tr.appendChild(dateCell);

                    const raceCell = document.createElement('td');
                    const link = document.createElement('a');
                    link.className = 'app-recent-race-link';
                    link.textContent = String(row.race_number || '-') + 'R';
                    link.href = '/web/app.php?date=' + encodeURIComponent(String(row.race_date || ''))
                        + '&place=' + encodeURIComponent(String(config.place || ''))
                        + '&race=' + encodeURIComponent(String(row.race_number || ''));
                    raceCell.appendChild(link);
                    tr.appendChild(raceCell);

                    [values[2], values[3], values[4]].forEach(function (value, index) {
                        const td = document.createElement('td');
                        td.textContent = value;
                        if (index === 2) td.className = 'app-recent-actual';
                        tr.appendChild(td);
                    });

                    const honmei = document.createElement('td');
                    honmei.appendChild(mark(Boolean(row.honmei_hit)));
                    tr.appendChild(honmei);

                    const taikou = document.createElement('td');
                    taikou.appendChild(mark(Boolean(row.taikou_hit)));
                    tr.appendChild(taikou);

                    const payout = document.createElement('td');
                    payout.textContent = row.payout == null ? '-' : yen(row.payout);
                    tr.appendChild(payout);

                    tbody.appendChild(tr);
                });
            }

            function renderErrors(data) {
                clear(errorsBox);
                const errors = Array.isArray(data.errors) ? data.errors : [];
                if (!errors.length) return;
                errorsBox.textContent = '再計算できなかったレース: ' + errors.map(function (row) {
                    return dateLabel(row.race_date, false) + ' ' + String(row.race_number || '-') + 'R';
                }).join(' / ');
            }

            function render(data) {
                if (!data || data.status !== 'ok') {
                    throw new Error(String((data && data.error) || '直近60Rを取得できませんでした。'));
                }

                renderSummary(data);
                renderDays(data);
                renderRows(data);
                renderErrors(data);

                const s = data.summary || {};
                const dates = Array.isArray(data.dates) ? data.dates : [];
                const cache = data.cache || {};
                meta.textContent = String(config.venue || config.place || '')
                    + ' / ' + dates.map(function (d) { return dateLabel(d, false); }).join('・')
                    + ' / 評価 ' + Number(s.evaluated_races || 0) + 'R'
                    + (cache.used ? ' / キャッシュ' : ' / 今回再計算');

                status.hidden = true;
                content.hidden = false;
                loaded = true;
            }

            async function load(force) {
                if (loading) return;
                if (loaded && !force) return;
                loading = true;
                reload.disabled = true;
                content.hidden = true;
                status.hidden = false;
                status.classList.remove('is-error');
                status.textContent = force
                    ? '直近60Rを再計算中…'
                    : '直近60Rを集計中… 初回は少し時間がかかります。';

                try {
                    const url = '/web/recent_prediction_history_api.php?place=' + encodeURIComponent(String(config.place || ''))
                        + '&date=' + encodeURIComponent(String(config.date || ''))
                        + '&force=' + (force ? '1' : '0');
                    const response = await fetch(url, {cache: 'no-store'});
                    const data = await response.json();
                    if (!response.ok && (!data || data.status !== 'ok')) {
                        throw new Error(String((data && data.error) || ('HTTP ' + response.status)));
                    }
                    render(data);
                } catch (error) {
                    status.hidden = false;
                    status.classList.add('is-error');
                    status.textContent = '直近60Rの集計に失敗しました：' + String(error && error.message ? error.message : error);
                } finally {
                    loading = false;
                    reload.disabled = false;
                }
            }

            function activateRecent() {
                document.querySelectorAll('.app-tab').forEach(function (tab) {
                    tab.classList.toggle('is-active', tab.dataset.tab === 'recent');
                });
                document.querySelectorAll('.app-tab-panel').forEach(function (tabPanel) {
                    const active = tabPanel.dataset.panel === 'recent';
                    tabPanel.classList.toggle('is-active', active);
                    tabPanel.hidden = !active;
                });
                try { sessionStorage.setItem(STORAGE_KEY, 'recent'); } catch (e) {}
                load(false);
            }

            button.addEventListener('click', activateRecent);
            reload.addEventListener('click', function () {
                load(true);
            });

            let saved = '';
            try { saved = sessionStorage.getItem(STORAGE_KEY) || ''; } catch (e) {}
            if (saved === 'recent') {
                activateRecent();
            }
        }, 50);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else {
        setup();
    }
})();
