(function () {
    'use strict';

    const compat = document.createElement('link');
    compat.rel = 'stylesheet';
    compat.href = '/web/assets/css/home_markup_compat.css?v=20260910a';
    document.head.appendChild(compat);
})();

(function () {
    'use strict';

    const root = document.getElementById('home-highlights');
    if (!root) return;

    const date = String(root.dataset.date || '').trim();
    const datePrefix = date.replace(/\D/g, '');

    const style = document.createElement('style');
    style.textContent = [
        /* 締切済み：薄くしつつ、読める程度には残す */
        '.race-button.is-deadline-past{opacity:.62!important;filter:grayscale(.30)!important;background:#f2eee8!important;border-color:#d7d0c7!important}',
        '.pick-item.is-deadline-past{opacity:.64!important;filter:grayscale(.25)!important;background:#f3efe9!important}',
        '.race-button.is-deadline-past .deadline-time,.pick-item.is-deadline-past .pick-deadline{color:#8a8f93!important}',

        /* 次に締切を迎えるレース：強すぎない黄色系で強調 */
        '.race-button.is-deadline-next{opacity:1!important;filter:none!important;background:#fff7df!important;border-color:#d8a94c!important;box-shadow:0 0 0 2px rgba(216,169,76,.18)!important}',
        '.race-button.is-deadline-next .deadline-time{color:#a86d00!important;font-size:9px!important}',
        '.pick-item.is-deadline-next{opacity:1!important;filter:none!important;background:#fff8e7!important;border-color:#d8a94c!important;box-shadow:0 1px 7px rgba(172,122,22,.12)!important}',
        '.pick-item.is-deadline-next .pick-deadline{color:#a86d00!important}',
        '.pick-item-solid.is-deadline-next{border-left-color:#d8a94c!important}',
        '.pick-item-upset.is-deadline-next{border-left-color:#d8a94c!important}'
    ].join('');
    document.head.appendChild(style);

    function raceCodeFromLink(link) {
        try {
            const url = new URL(link.href, window.location.origin);
            const place = String(url.searchParams.get('place') || '').toUpperCase();
            const raceNo = Number(url.searchParams.get('race') || 0);
            if (!datePrefix || !place || raceNo < 1 || raceNo > 12) return '';
            return datePrefix + place + String(raceNo).padStart(2, '0');
        } catch (e) {
            return '';
        }
    }

    function deadlineMs(time) {
        const value = String(time || '').trim();
        if (!/^\d{2}:\d{2}$/.test(value) || !/^\d{4}-\d{2}-\d{2}$/.test(date)) return NaN;
        return Date.parse(date + 'T' + value + ':00+09:00');
    }

    function refresh() {
        const helper = window.BoatRaceHomeDeadlines;
        if (!helper || typeof helper.getData !== 'function') return;

        const data = helper.getData();
        const deadlines = data && data.deadlines && typeof data.deadlines === 'object'
            ? data.deadlines
            : null;
        if (!deadlines) return;

        const now = Date.now();
        let nextCode = '';
        let nextMs = Infinity;

        Object.keys(deadlines).forEach(function (code) {
            const ms = deadlineMs(deadlines[code]);
            if (Number.isFinite(ms) && ms > now && ms < nextMs) {
                nextMs = ms;
                nextCode = String(code).toUpperCase();
            }
        });

        document.querySelectorAll('[data-race-button], .pick-item').forEach(function (link) {
            const code = raceCodeFromLink(link);
            link.classList.toggle('is-deadline-next', !!nextCode && code === nextCode);
        });
    }

    document.addEventListener('boatrace:deadlines', refresh);
    document.addEventListener('boatrace:clock', refresh);

    if (window.BoatRaceDeadlinesPromise && typeof window.BoatRaceDeadlinesPromise.then === 'function') {
        window.BoatRaceDeadlinesPromise.then(refresh).catch(function () {});
    } else {
        window.setTimeout(refresh, 500);
    }
})();

// 開催一覧の「開催場のみ / 全場表示」切替は独立ファイルで管理する。
(function () {
    'use strict';
    const script = document.createElement('script');
    script.src = '/web/assets/js/home_venue_toggle.js?v=20260910a';
    script.defer = true;
    document.head.appendChild(script);
})();

// カチカチ候補・荒れ警戒は、終了済みを下へ送り直近レースを常に先頭にする。
(function () {
    'use strict';
    const script = document.createElement('script');
    script.src = '/web/assets/js/home_candidate_order.js?v=20260910a';
    script.defer = true;
    document.head.appendChild(script);
})();

// 荒れ警戒のサイン一覧・レース別該当サイン詳細を追加する。
(function () {
    'use strict';
    const script = document.createElement('script');
    script.src = '/web/assets/js/home_upset_details.js?v=20260910a';
    script.defer = true;
    document.head.appendChild(script);
})();