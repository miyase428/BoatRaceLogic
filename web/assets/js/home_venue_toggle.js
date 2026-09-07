(function () {
    'use strict';

    const head = document.querySelector('.race-column-head');
    const cards = Array.from(document.querySelectorAll('[data-venue-card]'));
    if (!head || !cards.length) return;

    const activeCount = cards.filter(function (card) {
        return card.dataset.active === '1';
    }).length;
    const totalCount = cards.length;

    const style = document.createElement('style');
    style.textContent = [
        '.venue-card.is-venue-mode-hidden{display:none!important}',
        '.venue-view-toggle{display:inline-flex;gap:2px;padding:2px;border:1px solid #d8cbb8;border-radius:999px;background:#eee6da}',
        '.venue-view-toggle button{border:0;border-radius:999px;background:transparent;color:#6d7882;padding:5px 9px;font-size:10px;font-weight:800;cursor:pointer}',
        '.venue-view-toggle button.is-active{background:#fffaf2;color:#168bc3;box-shadow:0 1px 3px rgba(77,61,42,.12)}'
    ].join('');
    document.head.appendChild(style);

    const controls = document.createElement('div');
    controls.className = 'venue-view-toggle';

    const activeButton = document.createElement('button');
    activeButton.type = 'button';
    activeButton.textContent = '開催場のみ ' + activeCount;

    const allButton = document.createElement('button');
    allButton.type = 'button';
    allButton.textContent = '全場表示 ' + totalCount;

    controls.appendChild(activeButton);
    controls.appendChild(allButton);

    const visibleCount = document.getElementById('visible-race-count');
    if (visibleCount) {
        head.insertBefore(controls, visibleCount);
    } else {
        head.appendChild(controls);
    }

    function apply(activeOnly) {
        cards.forEach(function (card) {
            card.classList.toggle('is-venue-mode-hidden', activeOnly && card.dataset.active !== '1');
        });
        activeButton.classList.toggle('is-active', activeOnly);
        allButton.classList.toggle('is-active', !activeOnly);
        activeButton.setAttribute('aria-pressed', activeOnly ? 'true' : 'false');
        allButton.setAttribute('aria-pressed', activeOnly ? 'false' : 'true');
    }

    activeButton.addEventListener('click', function () { apply(true); });
    allButton.addEventListener('click', function () { apply(false); });

    apply(true);
})();