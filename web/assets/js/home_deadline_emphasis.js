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
        '.pick-item-upset.is-deadline-next{border-left-color:#d8a94c!important}',

        /* 多摩川4コース：★=攻め / ★★=4軸 / ★★★=4頭強 */
        '.race-button .tmg-lane4-star{display:inline-block;margin-left:2px;font-size:13px;font-weight:1000;line-height:1;vertical-align:1px;text-shadow:0 1px 0 rgba(255,255,255,.7);letter-spacing:-1px}',
        '.race-button.is-tmg-lane4-star-1 .tmg-lane4-star{color:#d18b00}',
        '.race-button.is-tmg-lane4-star-2 .tmg-lane4-star{color:#c56f00}',
        '.race-button.is-tmg-lane4-star-3 .tmg-lane4-star{color:#b94d00}',
        '.race-button.is-tmg-lane4-strong{border-color:#d8a94c!important;box-shadow:inset 0 0 0 1px rgba(216,169,76,.16)}',
        '.race-button.is-tmg-lane4-star-2{box-shadow:inset 0 0 0 1px rgba(197,111,0,.24)!important}',
        '.race-button.is-tmg-lane4-star-3{border-color:#c66a2b!important;box-shadow:inset 0 0 0 1px rgba(185,77,0,.30)!important}'
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

    function clearTamagawaStar(link) {
        link.classList.remove(
            'is-tmg-lane4-strong',
            'is-tmg-lane4-star-1',
            'is-tmg-lane4-star-2',
            'is-tmg-lane4-star-3'
        );
        delete link.dataset.tmgLane4Level;
        const oldStar = link.querySelector('.tmg-lane4-star');
        if (oldStar) oldStar.remove();
        if (link.dataset.tmgLane4BaseTitle !== undefined) {
            link.title = link.dataset.tmgLane4BaseTitle;
        }
    }

    function levelLabel(level) {
        if (level >= 3) return '4頭強';
        if (level >= 2) return '4軸';
        return '4攻め';
    }

    // 多摩川のみ。
    // ★   = 過去12ヶ月4コースまくり率15%以上 + 4が3より平均ST順位上
    // ★★  = ★ + 二次24以上 + TOP差5以内
    // ★★★ = ★★ + 二次27以上 + 直線評価4以上（検証中）
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
            const matches = data.matches && typeof data.matches === 'object' ? data.matches : {};
            document.querySelectorAll('[data-race-button]').forEach(function (link) {
                const code = raceCodeFromLink(link);
                const detail = code ? matches[code] : null;
                if (!detail) {
                    clearTamagawaStar(link);
                    return;
                }

                const rawLevel = Number(detail.star_level || 1);
                const level = Math.max(1, Math.min(3, Number.isFinite(rawLevel) ? Math.round(rawLevel) : 1));
                const stars = '★'.repeat(level);
                const label = String(detail.signal || levelLabel(level));

                link.classList.remove('is-tmg-lane4-star-1', 'is-tmg-lane4-star-2', 'is-tmg-lane4-star-3');
                link.classList.add('is-tmg-lane4-strong', 'is-tmg-lane4-star-' + level);
                link.dataset.tmgLane4Level = String(level);

                const raceLabel = link.querySelector('strong');
                let star = link.querySelector('.tmg-lane4-star');
                if (raceLabel && !star) {
                    star = document.createElement('span');
                    star.className = 'tmg-lane4-star';
                    raceLabel.appendChild(star);
                }
                if (star) {
                    star.textContent = stars;
                    star.setAttribute('aria-label', '多摩川4コース ' + label + 'サイン');
                    star.title = stars + ' ' + label;
                }

                const baseTitle = String(link.dataset.tmgLane4BaseTitle || link.title || '');
                link.dataset.tmgLane4BaseTitle = baseTitle;

                let info = stars + ' ' + label + 'サイン'
                    + ' / 4まくり率 ' + Number(detail.makuri_rate).toFixed(1) + '%'
                    + ' / ST順位 4=' + Number(detail.lane4_avg_rank).toFixed(2)
                    + ' < 3=' + Number(detail.lane3_avg_rank).toFixed(2);

                if (detail.secondary_ready) {
                    info += ' / 二次 ' + Number(detail.second_score).toFixed(0)
                        + ' / TOP差 ' + Number(detail.gap_to_top).toFixed(0)
                        + ' / 直線 ' + Number(detail.straight_score).toFixed(0);
                } else {
                    info += ' / 展示前';
                }

                if (level >= 3) {
                    info += ' / ★★★は前方検証中';
                }
                link.title = (baseTitle ? baseTitle + ' / ' : '') + info;
            });
        })
        .catch(function () {
            // TOP表示本体を壊さないため、強条件API失敗時は星を出さないだけにする。
        });
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