// レース詳細画面（Web / アプリ共通）の公式締切予定時刻表示。
// 発走時刻ではなく、投票判断に直接使える公式の「締切予定時刻」を明示する。
(function () {
    'use strict';

    const nodes = Array.from(document.querySelectorAll('[data-race-deadline]'));
    if (nodes.length === 0) return;

    const groups = new Map();
    nodes.forEach(function (node) {
        const date = String(node.dataset.date || '').trim();
        const place = String(node.dataset.place || '').trim().toUpperCase();
        const race = Number(node.dataset.race || 0);
        if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || !/^[A-Z]{3}$/.test(place) || race < 1 || race > 12) {
            node.textContent = '締切予定 —';
            return;
        }
        const key = date + ':' + place;
        if (!groups.has(key)) groups.set(key, {date: date, place: place, nodes: []});
        groups.get(key).nodes.push({node: node, race: race});
    });

    groups.forEach(function (group) {
        fetch(
            '/web/home_deadlines_api.php?date=' + encodeURIComponent(group.date)
                + '&place=' + encodeURIComponent(group.place),
            {cache: 'no-store'}
        ).then(function (response) {
            if (!response.ok) throw new Error('deadline request failed');
            return response.json();
        }).then(function (data) {
            const deadlines = data && data.deadlines && typeof data.deadlines === 'object' ? data.deadlines : {};
            const prefix = group.date.replace(/\D/g, '') + group.place;
            group.nodes.forEach(function (item) {
                const time = String(deadlines[prefix + String(item.race).padStart(2, '0')] || '');
                if (!/^\d{2}:\d{2}$/.test(time)) {
                    item.node.textContent = '締切予定 —';
                    return;
                }
                item.node.textContent = '締切予定 ' + time;
                item.node.setAttribute('datetime', group.date + 'T' + time + ':00+09:00');
            });
        }).catch(function () {
            group.nodes.forEach(function (item) {
                item.node.textContent = '締切予定 —';
            });
        });
    });
})();
