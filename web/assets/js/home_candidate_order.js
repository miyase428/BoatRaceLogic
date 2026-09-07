(function () {
    'use strict';

    const root = document.getElementById('home-highlights');
    if (!root) return;

    const date = String(root.dataset.date || '').trim();

    function deadlineMs(time) {
        const value = String(time || '').trim();
        if (!/^\d{2}:\d{2}$/.test(value) || !/^\d{4}-\d{2}-\d{2}$/.test(date)) {
            return NaN;
        }
        return Date.parse(date + 'T' + value + ':00+09:00');
    }

    function install() {
        const helper = window.BoatRaceHomeDeadlines;
        if (!helper || typeof helper.timeForRow !== 'function') {
            window.setTimeout(install, 100);
            return;
        }

        // カチカチ候補・荒れ警戒は、
        // 1) これからのレースを締切の近い順
        // 2) 締切済みをその下へ（直近終了順）
        // 3) 時刻不明を最後
        // の順で並べる。
        helper.sortRows = function (rows) {
            const now = Date.now();

            return (Array.isArray(rows) ? rows : []).map(function (row, index) {
                const time = helper.timeForRow(row);
                const ms = deadlineMs(time);
                let group = 2;

                if (Number.isFinite(ms)) {
                    group = ms > now ? 0 : 1;
                }

                return {
                    row: row,
                    index: index,
                    time: time,
                    ms: ms,
                    group: group
                };
            }).sort(function (a, b) {
                if (a.group !== b.group) {
                    return a.group - b.group;
                }

                // 未来は近い順、終了済みは直近終了順。
                if (a.group === 0 && a.ms !== b.ms) {
                    return a.ms - b.ms;
                }
                if (a.group === 1 && a.ms !== b.ms) {
                    return b.ms - a.ms;
                }

                // 時刻不明、または同時刻は元の並びを維持。
                return a.index - b.index;
            }).map(function (item) {
                return item.row;
            });
        };

        // すでに候補一覧が描画済みでも、差し替え直後に並べ直す。
        document.dispatchEvent(new CustomEvent('boatrace:deadlines', {
            detail: helper.getData ? helper.getData() : null
        }));
    }

    install();

    // 時間が進んで締切を跨いだら、カチカチ候補・荒れ警戒も自動で並べ直す。
    document.addEventListener('boatrace:clock', function () {
        const helper = window.BoatRaceHomeDeadlines;
        if (!helper || typeof helper.sortRows !== 'function') return;

        document.dispatchEvent(new CustomEvent('boatrace:deadlines', {
            detail: helper.getData ? helper.getData() : null
        }));
    });
})();