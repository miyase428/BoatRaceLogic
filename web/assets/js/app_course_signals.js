(function () {
    'use strict';

    const panel = document.getElementById('app-course-signals');
    if (!panel) return;

    const code = String(panel.dataset.raceCode || '').trim().toUpperCase();
    const date = String(panel.dataset.date || '').trim();
    const place = String(panel.dataset.place || '').trim().toUpperCase();
    let readyResolved = false;
    let resolveReady;
    window.appCourseSignalsReady = new Promise(function (resolve) {
        resolveReady = resolve;
    });

    function markReady(displayed) {
        if (readyResolved) return;
        readyResolved = true;
        window.appCourseSignalsReadyState = displayed ? 'displayed' : 'finished';
        resolveReady(!!displayed);
    }

    if (!/^\d{8}[A-Z0-9]{3}(0[1-9]|1[0-2])$/.test(code) || !date || !place) {
        markReady(false);
        return;
    }

    const colors = {
        lane1: {accent: '#9a3f4b', border: '#e0a8b1', bg: '#fff2f4', text: '#6e3340', meta: '#8a5660'},
        lane2sashi: {accent: '#287a67', border: '#9bd2bf', bg: '#eefaf4', text: '#285b4d', meta: '#527c6d'},
        lane2makuri: {accent: '#7042a8', border: '#c4a7e7', bg: '#f7efff', text: '#563b72', meta: '#735a8d'},
        lane3: {accent: '#176c9f', border: '#8fc5e3', bg: '#edf8ff', text: '#274f67', meta: '#52758a'},
        lane4: {accent: '#b87500', border: '#d8a94c', bg: '#fff7df', text: '#5d4a21', meta: '#7a6948'},
        lane5: {accent: '#7042a8', border: '#c4a7e7', bg: '#f7efff', text: '#563b72', meta: '#735a8d'},
        lane6: {accent: '#4f5964', border: '#aeb7bf', bg: '#f0f2f4', text: '#43505b', meta: '#66737e'}
    };

    function finite(value) {
        return Number.isFinite(Number(value));
    }

    function number(value, digits) {
        return Number(value).toFixed(digits);
    }

    function signed(value) {
        const n = Number(value);
        return (n >= 0 ? '+' : '') + n.toFixed(1) + 'pt';
    }

    function appendText(parent, className, text) {
        const node = document.createElement('div');
        node.className = className;
        node.textContent = text;
        parent.appendChild(node);
        return node;
    }

    function makeBox(detail, course, placeName) {
        const isLane1 = course === 1;
        const isLane2 = course === 2;
        const isLane2Makuri = isLane2 && detail.technique === 'makuri';
        const isLane3 = course === 3;
        const isLane4 = course === 4;
        const isLane6 = course === 6;
        const palette = isLane1 ? colors.lane1
            : (isLane2Makuri ? colors.lane2makuri
                : (isLane2 ? colors.lane2sashi
                    : (isLane3 ? colors.lane3
                        : (isLane4 ? colors.lane4
                            : (isLane6 ? colors.lane6 : colors.lane5)))));
        const level = Math.max(1, Math.min(3, Number(detail.star_level || 1)));
        const stars = '★'.repeat(level);
        const signal = String(detail.signal || (course + (level >= 2 ? '軸' : '攻め')));
        const note = (course === 1 || course === 2 || course === 5 || course === 6)
            ? ''
            : (level >= 3 ? '（検証中）' : '');

        const box = document.createElement('div');
        box.className = 'app-course-signal';
        box.style.border = '1px solid ' + palette.border;
        box.style.background = palette.bg;
        box.style.color = palette.text;
        box.style.boxShadow = '0 1px 5px rgba(50,70,80,.07)';

        const title = document.createElement('div');
        title.className = 'app-course-signal-title';
        const badge = document.createElement('span');
        badge.className = 'app-course-signal-badge';
        badge.style.color = palette.accent;
        badge.textContent = course + 'C' + stars + ' ' + signal;
        title.appendChild(badge);
        const name = document.createElement('span');
        name.textContent = placeName + course + 'コースサイン' + note;
        title.appendChild(name);
        box.appendChild(title);

        const parts = [];
        if (isLane1 && finite(detail.nige_rate)) {
            parts.push('1C逃げ率 ' + number(detail.nige_rate, 1) + '%');
        } else if (isLane2Makuri && finite(detail.makuri_rate)) {
            parts.push('2まくり率 ' + number(detail.makuri_rate, 1) + '%');
            if (finite(detail.lane2_avg_rank) && finite(detail.lane1_avg_rank)) {
                const stOperator = detail.st_relation === 'same_or_better' ? ' ≤ ' : ' < ';
                parts.push('ST順位 2=' + number(detail.lane2_avg_rank, 2) + stOperator + '1=' + number(detail.lane1_avg_rank, 2));
            }
            if (finite(detail.lane1_vulnerability_rate)) {
                parts.push('1C脆弱性 ' + number(detail.lane1_vulnerability_rate, 1) + '%');
            }
        } else if (isLane2 && finite(detail.sashi_rate)) {
            parts.push('2差し率 ' + number(detail.sashi_rate, 1) + '%');
        } else if (isLane3 && finite(detail.attack_rate)) {
            parts.push('3攻め率 ' + number(detail.attack_rate, 1) + '%');
        } else if (isLane4) {
            if (detail.primary_metric === 'attack_rate' && finite(detail.attack_rate)) {
                parts.push('4攻め率 ' + number(detail.attack_rate, 1) + '%');
            } else if (finite(detail.makuri_rate)) {
                parts.push('4まくり率 ' + number(detail.makuri_rate, 1) + '%');
            }
            if (finite(detail.lane3_avg_rank) && finite(detail.lane4_avg_rank)) {
                parts.push('ST順位 4=' + number(detail.lane4_avg_rank, 2) + ' < 3=' + number(detail.lane3_avg_rank, 2));
            }
            if (finite(detail.lane1_vulnerability_rate)) {
                parts.push('1C脆弱性 ' + number(detail.lane1_vulnerability_rate, 1) + '%');
            }
        } else if (course === 5 && finite(detail.attack_rate)) {
            parts.push('5攻め率 ' + number(detail.attack_rate, 1) + '%');
        } else if (isLane6 && finite(detail.attack_rate)) {
            parts.push('6攻め率 ' + number(detail.attack_rate, 1) + '%');
            if (finite(detail.lane6_avg_rank) && finite(detail.lane5_avg_rank)) {
                parts.push('ST順位 6=' + number(detail.lane6_avg_rank, 2) + ' < 5=' + number(detail.lane5_avg_rank, 2));
            }
        }

        if (detail.secondary_ready) {
            parts.push('二次 ' + number(detail.second_score, 0));
            parts.push('TOP差 ' + number(detail.gap_to_top, 0));
            parts.push('直線 ' + number(detail.straight_score, 0));
            if (isLane1 || isLane2 || course === 5 || isLane6) {
                if (finite(detail.second_rank)) parts.push('二次順位 ' + number(detail.second_rank, 0));
                if (finite(detail.lap_score)) parts.push('周回 ' + number(detail.lap_score, 0));
            } else if (isLane3 && finite(detail.mawari_score)) {
                parts.push('周り足 ' + number(detail.mawari_score, 0));
            }
        } else {
            parts.push('展示前');
        }
        appendText(box, 'app-course-signal-meta', parts.join(' / ')).style.color = palette.meta;

        const performance = detail.historical_stats;
        if (performance && finite(performance.first_rate)) {
            const period = performance.period ? String(performance.period) : '過去24か月';
            appendText(
                box,
                'app-course-signal-history',
                period + '実績 N=' + Number(performance.n).toLocaleString()
                    + ' / 1着率 ' + number(performance.first_rate, 1) + '%（基準比' + signed(performance.first_delta) + '）'
                    + ' / 2連対率 ' + number(performance.top2_rate, 1) + '%（基準比' + signed(performance.top2_delta) + '）'
                    + ' / 3連対率 ' + number(performance.top3_rate, 1) + '%（基準比' + signed(performance.top3_delta) + '）'
            );
        }
        return box;
    }

    function show(data) {
        const name = String(data.place_name || place);
        const maps = [
            [1, data.lane1_matches],
            [2, data.lane2_sashi_matches],
            [2, data.lane2_makuri_matches],
            [3, data.lane3_matches],
            [4, data.matches],
            [5, data.lane5_matches],
            [6, data.lane6_matches]
        ];
        const details = maps
            .map(function (item) { return [item[0], item[1] && item[1][code] ? item[1][code] : null]; })
            .filter(function (item) { return item[1]; });
        if (!details.length) {
            markReady(false);
            return;
        }

        panel.innerHTML = '';
        appendText(panel, 'app-course-signals-title', '💡 コースサイン');
        details.forEach(function (item) { panel.appendChild(makeBox(item[1], item[0], name)); });
        panel.hidden = false;
        markReady(true);
    }

    function load(attempt) {
        fetch('/web/tamagawa_lane4_star_api.php?date=' + encodeURIComponent(date) + '&place=' + encodeURIComponent(place), {cache: 'no-store'})
            .then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok || !data || data.status !== 'ok') {
                        throw new Error(String((data && data.error) || ('HTTP ' + response.status)));
                    }
                    return data;
                });
            })
            .then(show)
            .catch(function () {
                if (attempt < 2) window.setTimeout(function () { load(attempt + 1); }, 900 * (attempt + 1));
                else markReady(false);
            });
    }

    // このスクリプトは画面本体の末尾で読み込まれるため、DOMContentLoadedを待たず
    // 取得を開始する。app.phpの読み込み表示はこのPromiseの完了を待つ。
    load(0);
})();
