(function () {
    'use strict';

    function setup() {
        const panel = document.getElementById('recent-prediction-history-panel');
        if (!panel || panel.dataset.recentHistoryReady === '1') return;
        panel.dataset.recentHistoryReady = '1';

        const place = String(panel.dataset.place || '').trim();
        const date = String(panel.dataset.date || '').trim();
        const venue = String(panel.dataset.venue || place).trim();
        const status = document.getElementById('recent-history-status');
        const content = document.getElementById('recent-history-content');
        const meta = document.getElementById('recent-history-meta');
        const summaryBox = document.getElementById('recent-history-summary');
        const dailyBox = document.getElementById('recent-history-daily');
        const tbody = document.getElementById('recent-history-body');
        const errorsBox = document.getElementById('recent-history-errors');
        const reload = document.getElementById('recent-history-reload');

        if (!status || !content || !meta || !summaryBox || !dailyBox || !tbody || !reload) return;

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

        function dateLabel(value) {
            const text = String(value || '');
            const m = text.match(/^(\d{4})-(\d{2})-(\d{2})$/);
            return m ? m[2] + '/' + m[3] : text;
        }

        function fullDateLabel(value) {
            const text = String(value || '');
            const m = text.match(/^(\d{4})-(\d{2})-(\d{2})$/);
            return m ? m[1] + '/' + m[2] + '/' + m[3] : text;
        }

        function clear(node) {
            while (node.firstChild) node.removeChild(node.firstChild);
        }

        function summaryCard(title, bucket, raceCount) {
            const card = document.createElement('div');
            card.className = 'recent-history-summary-card';

            const h3 = document.createElement('h3');
            h3.textContent = title;
            card.appendChild(h3);

            const grid = document.createElement('div');
            grid.className = 'recent-history-summary-grid';

            const values = [
                ['的中率', String(Number(bucket.hits || 0)) + '/' + String(raceCount) + '　' + pct(bucket.hit_rate, 1)],
                ['平均点数', num(bucket.avg_points, 2) + '点'],
                ['回収率', pct(bucket.roi, 1)]
            ];

            values.forEach(function (item) {
                const cell = document.createElement('div');
                const label = document.createElement('span');
                const strong = document.createElement('strong');
                label.textContent = item[0];
                strong.textContent = item[1];
                cell.appendChild(label);
                cell.appendChild(strong);
                grid.appendChild(cell);
            });

            card.appendChild(grid);

            const money = document.createElement('div');
            money.style.cssText = 'margin-top:6px;color:var(--text-muted);font-size:9px;text-align:right;';
            money.textContent = '購入 ' + yen(bucket.investment) + ' / 払戻 ' + yen(bucket.payout);
            card.appendChild(money);
            return card;
        }

        function renderSummary(data) {
            clear(summaryBox);
            const s = data.summary || {};
            const n = Number(s.evaluated_races || 0);
            summaryBox.appendChild(summaryCard('本命買い目', s.honmei || {}, n));
            summaryBox.appendChild(summaryCard('対抗買い目', s.taikou || {}, n));
            summaryBox.appendChild(summaryCard('本命＋対抗（重複除外）', s.combined || {}, n));
        }

        function renderDaily(data) {
            clear(dailyBox);
            const days = Array.isArray(data.daily) ? data.daily : [];
            days.forEach(function (day) {
                const card = document.createElement('div');
                card.className = 'recent-history-day';
                const strong = document.createElement('strong');
                strong.textContent = fullDateLabel(day.race_date);
                card.appendChild(strong);

                const combined = day.combined || {};
                const races = Number(day.races || 0);
                const line1 = document.createElement('div');
                line1.textContent = '本命＋対抗 ' + Number(combined.hits || 0) + '/' + races + '　' + pct(combined.hit_rate, 1);
                const line2 = document.createElement('div');
                line2.textContent = '回収 ' + pct(combined.roi, 1);
                card.appendChild(line1);
                card.appendChild(line2);
                dailyBox.appendChild(card);
            });
        }

        function resultMark(hit) {
            const span = document.createElement('span');
            span.className = hit ? 'recent-history-hit' : 'recent-history-miss';
            span.textContent = hit ? '○' : '×';
            return span;
        }

        function renderRows(data) {
            clear(tbody);
            const rows = Array.isArray(data.rows) ? data.rows : [];

            rows.forEach(function (row) {
                const tr = document.createElement('tr');
                if (row.combined_hit) tr.classList.add('is-hit');

                const dateCell = document.createElement('td');
                dateCell.textContent = dateLabel(row.race_date);
                tr.appendChild(dateCell);

                const raceCell = document.createElement('td');
                const raceLink = document.createElement('a');
                raceLink.className = 'recent-history-race-link';
                raceLink.textContent = String(row.race_number || '-') + 'R';
                raceLink.href = '/web/index.php?date=' + encodeURIComponent(String(row.race_date || ''))
                    + '&place=' + encodeURIComponent(place)
                    + '&race=' + encodeURIComponent(String(row.race_number || ''));
                raceCell.appendChild(raceLink);
                tr.appendChild(raceCell);

                const honmei = document.createElement('td');
                honmei.textContent = String(row.honmei_kai || '-');
                honmei.title = '本命 ' + String(row.honmei_points || 0) + '点';
                tr.appendChild(honmei);

                const taikou = document.createElement('td');
                taikou.textContent = String(row.taikou_kai || '-');
                taikou.title = '対抗 ' + String(row.taikou_points || 0) + '点';
                tr.appendChild(taikou);

                const actual = document.createElement('td');
                const actualStrong = document.createElement('strong');
                actualStrong.textContent = String(row.actual_trifecta || '-');
                actual.appendChild(actualStrong);
                tr.appendChild(actual);

                const honmeiHit = document.createElement('td');
                honmeiHit.appendChild(resultMark(Boolean(row.honmei_hit)));
                tr.appendChild(honmeiHit);

                const taikouHit = document.createElement('td');
                taikouHit.appendChild(resultMark(Boolean(row.taikou_hit)));
                tr.appendChild(taikouHit);

                const payout = document.createElement('td');
                payout.className = 'recent-history-payout';
                payout.textContent = row.payout == null ? '-' : yen(row.payout);
                tr.appendChild(payout);

                tbody.appendChild(tr);
            });
        }

        function renderErrors(data) {
            if (!errorsBox) return;
            clear(errorsBox);
            const errors = Array.isArray(data.errors) ? data.errors : [];
            if (!errors.length) return;
            errorsBox.textContent = '再計算できなかったレース: ' + errors.map(function (e) {
                return fullDateLabel(e.race_date) + ' ' + String(e.race_number || '-') + 'R';
            }).join(' / ');
        }

        function render(data) {
            if (!data || data.status !== 'ok') {
                throw new Error(String((data && data.error) || '直近60Rを取得できませんでした。'));
            }

            renderSummary(data);
            renderDaily(data);
            renderRows(data);
            renderErrors(data);

            const s = data.summary || {};
            const dates = Array.isArray(data.dates) ? data.dates : [];
            const cache = data.cache || {};
            const cacheLabel = cache.auto_invalidated
                ? ' / 開催日更新で自動再計算'
                : (cache.used ? ' / キャッシュ表示' : ' / 今回再計算');
            meta.textContent = venue + ' / 対象 ' + dates.map(fullDateLabel).join('・')
                + ' / 評価 ' + Number(s.evaluated_races || 0) + 'R'
                + (Number(s.error_races || 0) > 0 ? ' / 再計算エラー ' + Number(s.error_races || 0) + 'R' : '')
                + cacheLabel;

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
            status.classList.add('is-loading');
            status.textContent = force
                ? '直近60Rを再計算中…'
                : '直近60Rを集計中… 初回は60R分の本番予想を再現するため少し時間がかかります。';

            try {
                const url = '/web/recent_prediction_history_api.php?place=' + encodeURIComponent(place)
                    + '&date=' + encodeURIComponent(date)
                    + '&force=' + (force ? '1' : '0');
                const response = await fetch(url, {cache: 'no-store'});
                const data = await response.json();
                if (!response.ok && (!data || data.status !== 'ok')) {
                    throw new Error(String(data.error || ('HTTP ' + response.status)));
                }
                render(data);
            } catch (error) {
                status.hidden = false;
                status.classList.remove('is-loading');
                status.classList.add('is-error');
                status.textContent = '直近60Rの集計に失敗しました：' + String(error && error.message ? error.message : error);
            } finally {
                loading = false;
                reload.disabled = false;
                status.classList.remove('is-loading');
            }
        }

        reload.addEventListener('click', function () {
            load(true);
        });

        document.addEventListener('click', function (event) {
            const button = event.target && event.target.closest
                ? event.target.closest('.pc-main-tab[data-pc-main-tab="recent"]')
                : null;
            if (button) load(false);
        });

        setTimeout(function () {
            const active = document.querySelector('.pc-main-tab[data-pc-main-tab="recent"].is-active');
            if (active) load(false);
        }, 120);

        // レース表示時にもバックグラウンドで最新開催日だけ確認する。
        // 対象日が同じならキャッシュ即返却、新しい結果確定日が増えた時だけ再計算する。
        setTimeout(function () {
            load(false);
        }, 350);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else {
        setup();
    }
})();
