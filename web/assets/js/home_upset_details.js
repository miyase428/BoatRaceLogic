(function () {
    'use strict';

    const list = document.getElementById('upset-pick-list');
    if (!list) return;

    const card = list.closest('.pick-card-upset');
    if (!card) return;

    const style = document.createElement('style');
    style.textContent = [
        '.upset-reference-toggle,.upset-detail-toggle{border:1px solid #d8a6a6;border-radius:999px;background:#fff8f6;color:#9f3f3f;font-weight:800;cursor:pointer}',
        '.upset-reference-toggle{padding:4px 9px;font-size:10px;margin-left:8px}',
        '.upset-detail-toggle{padding:3px 8px;font-size:9px;margin-left:auto;white-space:nowrap}',
        '.upset-reference-panel,.upset-detail-panel{margin:7px 0 9px;padding:9px 10px;border:1px solid #ead6cf;border-radius:10px;background:#fffdf9;font-size:11px;line-height:1.55;color:#4d4744}',
        '.upset-reference-panel[hidden],.upset-detail-panel[hidden]{display:none!important}',
        '.upset-reference-title,.upset-detail-title{font-weight:900;color:#7e3131;margin-bottom:5px}',
        '.upset-reference-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px}',
        '.upset-reference-group{padding:7px;border:1px solid #eee0da;border-radius:8px;background:#fff}',
        '.upset-reference-group strong{display:block;margin-bottom:3px}',
        '.upset-reference-group ul,.upset-detail-panel ul{margin:4px 0 0 18px;padding:0}',
        '.upset-reference-types{margin-top:8px;padding-top:7px;border-top:1px dashed #e2ccc3}',
        '.upset-detail-head{display:flex;align-items:center;gap:6px;flex-wrap:wrap}',
        '.upset-detail-chip{display:inline-block;padding:2px 6px;border-radius:999px;background:#f5ece8;font-size:9px;font-weight:800}',
        '.upset-detail-section{margin-top:7px}',
        '.upset-detail-section strong{display:block;margin-bottom:2px}',
        '.upset-detail-empty{color:#8b817c}',
        '.upset-detail-error{color:#a33;font-weight:700}',
        '.pick-item-upset{position:relative}',
        '@media(max-width:700px){.upset-reference-grid{grid-template-columns:1fr}.upset-reference-toggle{margin-left:4px}.upset-detail-panel{font-size:10px}}'
    ].join('');
    document.head.appendChild(style);

    function referencePanel() {
        const panel = document.createElement('div');
        panel.className = 'upset-reference-panel';
        panel.hidden = true;
        panel.innerHTML = ''
            + '<div class="upset-reference-title">荒れ警戒サイン一覧</div>'
            + '<div class="upset-reference-grid">'
            + '  <div class="upset-reference-group"><strong>中配当 5,000〜9,999円</strong><ul>'
            + '    <li>イン逃げ50%未満</li><li>Web本命・対抗とも非1</li><li>序盤1〜4R</li><li>捲り最大20〜29%</li><li>攻め20%以上が2艇（観察）</li>'
            + '  </ul></div>'
            + '  <div class="upset-reference-group"><strong>高配当 10,000〜19,999円</strong><ul>'
            + '    <li>イン逃げ40%未満</li><li>Web本命・対抗とも非1</li><li>序盤1〜4R</li><li>強い攻め兆候</li><li>外コース勝率40%以上</li><li>攻め上位差3pt未満</li>'
            + '  </ul></div>'
            + '  <div class="upset-reference-group"><strong>大穴 20,000円以上</strong><ul>'
            + '    <li>イン逃げ60〜69%</li><li>後半9〜12R</li><li>捲り最大15〜19%</li><li>外コース勝率15%未満</li><li>攻め20%以上が2艇（観察）</li>'
            + '  </ul></div>'
            + '</div>'
            + '<div class="upset-reference-types"><strong>荒れ方：</strong> イン崩壊＝1号艇頭飛び警戒 / ヒモ荒れ＝1号艇を残して2・3着崩れ警戒 / 複合高配当＝複数要因の強ペア成立</div>'
            + '<div class="upset-reference-types">※TOP荒れ警戒は展示不要判定。現行TOP判定ではWeb本命・対抗を入力していないため、そのサインは実際のTOP判定では成立しません。</div>';
        return panel;
    }

    const head = card.querySelector('.pick-card-head');
    let refPanel = card.querySelector('.upset-reference-panel');
    if (!refPanel) {
        refPanel = referencePanel();
        if (head && head.parentNode) head.parentNode.insertBefore(refPanel, head.nextSibling);
        else card.insertBefore(refPanel, card.firstChild);
    }

    if (head && !head.querySelector('.upset-reference-toggle')) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'upset-reference-toggle';
        button.textContent = 'サイン一覧';
        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            refPanel.hidden = !refPanel.hidden;
            button.textContent = refPanel.hidden ? 'サイン一覧' : '一覧を閉じる';
        });
        head.appendChild(button);
    }

    function raceCodeFromItem(item) {
        try {
            const url = new URL(item.href, window.location.origin);
            const root = document.getElementById('home-highlights');
            const date = root ? String(root.dataset.date || '').replace(/\D/g, '') : '';
            const place = String(url.searchParams.get('place') || '').toUpperCase();
            const raceNo = Number(url.searchParams.get('race') || 0);
            if (!/^\d{8}$/.test(date) || !place || raceNo < 1 || raceNo > 12) return '';
            return date + place + String(raceNo).padStart(2, '0');
        } catch (e) {
            return '';
        }
    }

    function levelLabel(level) {
        if (level === 'strong') return '強';
        if (level === 'watch') return '注意';
        return '低';
    }

    function renderList(items, emptyText) {
        if (!Array.isArray(items) || !items.length) {
            return '<div class="upset-detail-empty">' + emptyText + '</div>';
        }
        return '<ul>' + items.map(function (v) {
            return '<li>' + escapeHtml(String(v)) + '</li>';
        }).join('') + '</ul>';
    }

    function escapeHtml(value) {
        return value.replace(/[&<>'"]/g, function (ch) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch];
        });
    }

    function detailHtml(data) {
        const chaos = data && data.chaos ? data.chaos : {};
        const payout = data && data.payout ? data.payout : {};
        const sections = [];

        ['medium', 'high', 'big'].forEach(function (key) {
            const bucket = payout[key] || {};
            const signals = Array.isArray(bucket.signals) ? bucket.signals : [];
            const pairs = Array.isArray(bucket.pairs) ? bucket.pairs : [];
            if (!signals.length && !pairs.length && String(bucket.level || 'low') === 'low') return;

            sections.push(''
                + '<div class="upset-detail-section">'
                + '<strong>' + escapeHtml(String(bucket.label || key)) + '：' + levelLabel(String(bucket.level || 'low'))
                + '（' + Number(bucket.score || 0) + '/' + Number(bucket.max_score || 0) + '）</strong>'
                + renderList(signals, '該当サインなし')
                + (pairs.length ? '<div><b>成立ペア</b>' + renderList(pairs, '') + '</div>' : '')
                + '</div>');
        });

        return ''
            + '<div class="upset-detail-head">'
            + '  <div class="upset-detail-title">今回の荒れ警戒詳細</div>'
            + '  <span class="upset-detail-chip">荒れ方：' + escapeHtml(String(chaos.primary || '平常')) + '</span>'
            + '</div>'
            + (Array.isArray(chaos.reasons) && chaos.reasons.length
                ? '<div class="upset-detail-section"><strong>判定理由</strong>' + renderList(chaos.reasons, '') + '</div>'
                : '')
            + sections.join('')
            + '<div class="upset-detail-section upset-detail-empty">展示前情報のみで判定 / Web本命・対抗はTOP判定には未使用</div>';
    }

    async function toggleDetail(item, button) {
        let panel = item.nextElementSibling;
        if (!panel || !panel.classList.contains('upset-detail-panel')) {
            panel = document.createElement('div');
            panel.className = 'upset-detail-panel';
            item.insertAdjacentElement('afterend', panel);
        }

        if (panel.dataset.loaded === '1') {
            panel.hidden = !panel.hidden;
            button.textContent = panel.hidden ? '詳細' : '閉じる';
            return;
        }

        const raceCode = raceCodeFromItem(item);
        if (!raceCode) return;

        panel.hidden = false;
        panel.innerHTML = '<div class="upset-detail-empty">サイン詳細を取得中…</div>';
        button.disabled = true;

        try {
            const response = await fetch('/web/home_upset_detail_api.php?race_code=' + encodeURIComponent(raceCode), {cache: 'no-store'});
            const data = await response.json();
            if (!response.ok || !data || data.status !== 'ok') {
                throw new Error(String((data && data.error) || ('HTTP ' + response.status)));
            }
            panel.innerHTML = detailHtml(data);
            panel.dataset.loaded = '1';
            button.textContent = '閉じる';
        } catch (error) {
            panel.innerHTML = '<div class="upset-detail-error">' + escapeHtml(String(error && error.message ? error.message : error)) + '</div>';
            button.textContent = '詳細';
        } finally {
            button.disabled = false;
        }
    }

    function decorate() {
        list.querySelectorAll('.pick-item-upset').forEach(function (item) {
            if (item.querySelector('.upset-detail-toggle')) return;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'upset-detail-toggle';
            button.textContent = '詳細';
            button.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                toggleDetail(item, button);
            });
            item.appendChild(button);
        });
    }

    decorate();
    const observer = new MutationObserver(decorate);
    observer.observe(list, {childList: true, subtree: true});
})();
