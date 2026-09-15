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

        /* 多摩川コースサイン：1Cは赤系、2C差しは緑、2Cまくりは紫、3Cは青、4Cは橙、5Cは藤色、6Cは濃灰。 */
        '.race-button .tmg-course-star{display:inline-block;margin-left:3px;padding:1px 3px;border-radius:3px;font-size:10px;font-weight:1000;line-height:1.2;vertical-align:1px;text-shadow:0 1px 0 rgba(255,255,255,.7);letter-spacing:-.4px}',
        '.race-button .tmg-lane1-star{color:#9a3f4b;background:#fff0f2;border:1px solid #e0a8b1}',
        '.race-button .tmg-lane2-sashi-star{color:#287a67;background:#e8f7f0;border:1px solid #9bd2bf}',
        '.race-button .tmg-lane2-makuri-star{color:#7042a8;background:#f3e8ff;border:1px solid #c4a7e7}',
        '.race-button .tmg-lane3-star{color:#176c9f;background:#e9f7ff;border:1px solid #9bcce7}',
        '.race-button .tmg-lane4-star{color:#b96b00;background:#fff4d8;border:1px solid #dfbd72}',
        '.race-button .tmg-lane5-star{color:#7042a8;background:#f3e8ff;border:1px solid #c4a7e7}',
        '.race-button .tmg-lane6-star{color:#4f5964;background:#edf0f2;border:1px solid #aeb7bf}',
        '.race-button.is-tmg-lane3-star-3 .tmg-lane3-star{color:#07537f;background:#d9f1ff;border-color:#66acd2}',
        '.race-button.is-tmg-lane2-star-3 .tmg-lane2-sashi-star{color:#17614f;background:#d8f0e5;border-color:#69b99c}',
        '.race-button.is-tmg-lane2-star-3 .tmg-lane2-makuri-star{color:#57228d;background:#ead7ff;border-color:#9b6aca}',
        '.race-button.is-tmg-lane4-star-3 .tmg-lane4-star{color:#a84200;background:#ffead0;border-color:#cf8b51}',
        '.race-button.is-tmg-lane5-star-3 .tmg-lane5-star{color:#57228d;background:#ead7ff;border-color:#9b6aca}',
        '.race-button.is-tmg-lane6-star-2 .tmg-lane6-star{color:#35404a;background:#e1e6ea;border-color:#87939d}',
        '.race-button.is-tmg-lane6-star-3 .tmg-lane6-star{color:#25313b;background:#d5dce1;border-color:#687680}',
        '.race-button.is-tmg-lane1-star-2 .tmg-lane1-star{color:#7f2737;background:#ffe3e7;border-color:#cc7b89}',
        '.race-button.is-tmg-lane1-star-3 .tmg-lane1-star{color:#651b2a;background:#ffd5db;border-color:#b85a6a}',
        '.race-button.is-tmg-lane3-strong{border-color:#79b7d9!important;box-shadow:inset 0 0 0 1px rgba(41,132,181,.16)}',
        '.race-button.is-tmg-lane2-strong{border-color:#86c6b0!important;box-shadow:inset 0 0 0 1px rgba(40,122,103,.16)}',
        '.race-button.is-tmg-lane4-strong{border-color:#d8a94c!important;box-shadow:inset 0 0 0 1px rgba(216,169,76,.16)}',
        '.race-button.is-tmg-lane5-strong{border-color:#a987c9!important;box-shadow:inset 0 0 0 1px rgba(112,66,168,.18)}',
        '.race-button.is-tmg-lane6-strong{border-color:#9aa6af!important;box-shadow:inset 0 0 0 1px rgba(79,89,100,.18)}',
        '.race-button.is-tmg-lane1-strong{border-color:#d59aa4!important;box-shadow:inset 0 0 0 1px rgba(154,63,75,.18)}',
        '.race-button.is-tmg-lane3-strong.is-tmg-lane4-strong{border-color:#8e83be!important;box-shadow:inset 0 0 0 1px rgba(93,102,184,.20)!important}',
        '.race-button.is-tmg-lane4-star-2{box-shadow:inset 0 0 0 1px rgba(197,111,0,.24)!important}',
        '.race-button.is-tmg-lane2-star-2{box-shadow:inset 0 0 0 1px rgba(52,130,104,.24)!important}',
        '.race-button.is-tmg-lane4-star-3{border-color:#c66a2b!important;box-shadow:inset 0 0 0 1px rgba(185,77,0,.30)!important}',
        '.race-button.is-tmg-lane5-star-2{box-shadow:inset 0 0 0 1px rgba(112,66,168,.24)!important}',
        '.race-button.is-tmg-lane5-star-3{border-color:#8751b5!important;box-shadow:inset 0 0 0 1px rgba(112,66,168,.30)!important}',
        '.race-button.is-tmg-lane6-star-2{box-shadow:inset 0 0 0 1px rgba(79,89,100,.24)!important}',
        '.race-button.is-tmg-lane6-star-3{border-color:#687680!important;box-shadow:inset 0 0 0 1px rgba(79,89,100,.30)!important}',
        '.race-button.is-tmg-lane1-star-2{box-shadow:inset 0 0 0 1px rgba(154,63,75,.24)!important}',
        '.race-button.is-tmg-lane1-star-3{border-color:#b85a6a!important;box-shadow:inset 0 0 0 1px rgba(154,63,75,.30)!important}'
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

    function clearTamagawaStars(link) {
        link.classList.remove(
            'is-tmg-lane3-strong',
            'is-tmg-lane3-star-1',
            'is-tmg-lane3-star-2',
            'is-tmg-lane3-star-3',
            'is-tmg-lane2-strong',
            'is-tmg-lane2-star-1',
            'is-tmg-lane2-star-2',
            'is-tmg-lane2-star-3',
            'is-tmg-lane4-strong',
            'is-tmg-lane4-star-1',
            'is-tmg-lane4-star-2',
            'is-tmg-lane4-star-3',
            'is-tmg-lane5-strong',
            'is-tmg-lane5-star-1',
            'is-tmg-lane5-star-2',
            'is-tmg-lane5-star-3',
            'is-tmg-lane6-strong',
            'is-tmg-lane6-star-1',
            'is-tmg-lane6-star-2',
            'is-tmg-lane6-star-3',
            'is-tmg-lane1-strong',
            'is-tmg-lane1-star-1',
            'is-tmg-lane1-star-2',
            'is-tmg-lane1-star-3'
        );
        delete link.dataset.tmgLane3Level;
        delete link.dataset.tmgLane4Level;
        delete link.dataset.tmgLane6Level;
        delete link.dataset.tmgLane1Level;
        link.querySelectorAll('.tmg-course-star').forEach(function (star) { star.remove(); });
        if (link.dataset.tmgLane4BaseTitle !== undefined) {
            link.title = link.dataset.tmgLane4BaseTitle;
        }
    }

    function levelLabel(course, level) {
        if (level >= 3) return course + '頭強';
        if (level >= 2) return course + '軸';
        return course + '攻め';
    }

    function addTamagawaSignal(link, detail, course, placeName) {
        const rawLevel = Number(detail.star_level || 1);
        const level = Math.max(1, Math.min(3, Number.isFinite(rawLevel) ? Math.round(rawLevel) : 1));
        const stars = '★'.repeat(level);
        const label = String(detail.signal || levelLabel(course, level));
        const classPrefix = 'is-tmg-lane' + course;

        link.classList.add(classPrefix + '-strong', classPrefix + '-star-' + level);
        link.dataset['tmgLane' + course + 'Level'] = String(level);

        const raceLabel = link.querySelector('strong');
        if (raceLabel) {
            const star = document.createElement('span');
            const variant = course === 2 ? (detail.technique === 'makuri' ? 'makuri' : 'sashi') : '';
            star.className = 'tmg-course-star ' + (variant ? 'tmg-lane2-' + variant + '-star' : 'tmg-lane' + course + '-star');
            star.textContent = course + 'C' + stars;
            star.setAttribute('aria-label', (placeName || '開催場') + course + 'コース ' + label + 'サイン');
            star.title = course + 'C' + stars + ' ' + label;
            raceLabel.appendChild(star);
        }

        let info = course + 'C' + stars + ' ' + label + 'サイン';
        if (course === 1) {
            info += ' / 1C逃げ率 ' + Number(detail.nige_rate).toFixed(1) + '%';
        } else if (course === 2 && detail.technique === 'makuri') {
            info += ' / 2まくり率 ' + Number(detail.makuri_rate).toFixed(1) + '%'
                + ' / ST順位 2=' + Number(detail.lane2_avg_rank).toFixed(2)
                + ' < 1=' + Number(detail.lane1_avg_rank).toFixed(2);
        } else if (course === 2) {
            info += ' / 2差し率 ' + Number(detail.sashi_rate).toFixed(1) + '%';
        } else if (course === 3) {
            info += ' / 3攻め率 ' + Number(detail.attack_rate).toFixed(1) + '%';
        } else if (course === 4) {
            info += ' / 4まくり率 ' + Number(detail.makuri_rate).toFixed(1) + '%'
                + ' / ST順位 4=' + Number(detail.lane4_avg_rank).toFixed(2)
                + ' < 3=' + Number(detail.lane3_avg_rank).toFixed(2);
        } else if (course === 5) {
            info += ' / 5攻め率 ' + Number(detail.attack_rate).toFixed(1) + '%';
        } else if (course === 6) {
            info += ' / 6攻め率 ' + Number(detail.attack_rate).toFixed(1) + '%'
                + ' / ST順位 6=' + Number(detail.lane6_avg_rank).toFixed(2)
                + ' < 5=' + Number(detail.lane5_avg_rank).toFixed(2);
        }

        if (detail.secondary_ready) {
            info += ' / 二次 ' + Number(detail.second_score).toFixed(0)
                + ' / TOP差 ' + Number(detail.gap_to_top).toFixed(0)
                + ' / 直線 ' + Number(detail.straight_score).toFixed(0);
            if (course === 2) {
                info += ' / 二次順位 ' + Number(detail.second_rank).toFixed(0)
                    + ' / 周回 ' + Number(detail.lap_score).toFixed(0);
            } else if (course === 3 && Number.isFinite(Number(detail.mawari_score))) {
                info += ' / 周り足 ' + Number(detail.mawari_score).toFixed(0);
            } else if (course === 5) {
                info += ' / 二次順位 ' + Number(detail.second_rank).toFixed(0)
                    + ' / 周回 ' + Number(detail.lap_score).toFixed(0);
            } else if (course === 6 || course === 1) {
                info += ' / 二次順位 ' + Number(detail.second_rank).toFixed(0)
                    + ' / 周回 ' + Number(detail.lap_score).toFixed(0);
            }
        } else {
            info += ' / 展示前';
        }
        if (level >= 3 && course !== 1 && course !== 2 && course !== 5) info += ' / ★★★は前方検証中';
        return info;
    }

    // 開催場ごとに場別設定を取得する。サインは表示専用で、予想順位・買い目には接続しない。
    const linksByPlace = {};
    document.querySelectorAll('[data-race-button]').forEach(function (link) {
        const code = raceCodeFromLink(link);
        const place = code ? code.slice(8, 11) : '';
        if (!place) return;
        const baseTitle = String(link.dataset.tmgLane4BaseTitle || link.title || '');
        link.dataset.tmgLane4BaseTitle = baseTitle;
        clearTamagawaStars(link);
        if (!linksByPlace[place]) linksByPlace[place] = [];
        linksByPlace[place].push({link: link, code: code});
    });

    Object.keys(linksByPlace).forEach(function (place) {
        fetch('/web/tamagawa_lane4_star_api.php?date=' + encodeURIComponent(date) + '&place=' + encodeURIComponent(place), {cache: 'no-store'})
            .then(function (response) {
                return response.json().then(function (data) {
                    if (!response.ok || !data || data.status !== 'ok') {
                        throw new Error(String((data && data.error) || ('HTTP ' + response.status)));
                    }
                    return data;
                });
            })
            .then(function (data) {
            const lane1Matches = data.lane1_matches && typeof data.lane1_matches === 'object' ? data.lane1_matches : {};
            const lane2SashiMatches = data.lane2_sashi_matches && typeof data.lane2_sashi_matches === 'object' ? data.lane2_sashi_matches : {};
            const lane2MakuriMatches = data.lane2_makuri_matches && typeof data.lane2_makuri_matches === 'object' ? data.lane2_makuri_matches : {};
            const lane3Matches = data.lane3_matches && typeof data.lane3_matches === 'object' ? data.lane3_matches : {};
            const lane4Matches = data.matches && typeof data.matches === 'object' ? data.matches : {};
            const lane5Matches = data.lane5_matches && typeof data.lane5_matches === 'object' ? data.lane5_matches : {};
            const lane6Matches = data.lane6_matches && typeof data.lane6_matches === 'object' ? data.lane6_matches : {};
            const placeName = String(data.place_name || place);
            linksByPlace[place].forEach(function (item) {
                const link = item.link;
                const code = item.code;
                const infos = [];
                if (code && lane1Matches[code]) infos.push(addTamagawaSignal(link, lane1Matches[code], 1, placeName));
                if (code && lane2SashiMatches[code]) infos.push(addTamagawaSignal(link, lane2SashiMatches[code], 2, placeName));
                if (code && lane2MakuriMatches[code]) infos.push(addTamagawaSignal(link, lane2MakuriMatches[code], 2, placeName));
                if (code && lane3Matches[code]) infos.push(addTamagawaSignal(link, lane3Matches[code], 3, placeName));
                if (code && lane4Matches[code]) infos.push(addTamagawaSignal(link, lane4Matches[code], 4, placeName));
                if (code && lane5Matches[code]) infos.push(addTamagawaSignal(link, lane5Matches[code], 5, placeName));
                if (code && lane6Matches[code]) infos.push(addTamagawaSignal(link, lane6Matches[code], 6, placeName));
                link.title = baseTitle + (infos.length ? (baseTitle ? ' / ' : '') + infos.join(' / ') : '');
            });
            })
            .catch(function () {
                // TOP表示本体を壊さないため、場別API失敗時はその場の星を出さないだけにする。
            });
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
