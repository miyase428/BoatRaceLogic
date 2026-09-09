(function () {
    'use strict';

    let overlay = null;
    let active = false;

    function ensureOverlay() {
        if (overlay) return overlay;

        const style = document.createElement('style');
        style.textContent = [
            '.home-nav-loading{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;background:rgba(248,244,236,.72);backdrop-filter:blur(2px);-webkit-backdrop-filter:blur(2px);opacity:0;pointer-events:none;transition:opacity .12s ease}',
            '.home-nav-loading.is-visible{opacity:1;pointer-events:auto}',
            '.home-nav-loading-card{display:flex;align-items:center;gap:12px;padding:14px 18px;border:1px solid #d8cbb8;border-radius:14px;background:#fffaf2;box-shadow:0 8px 24px rgba(65,52,35,.16);color:#34495e;font-weight:800;font-size:14px}',
            '.home-nav-loading-spinner{width:22px;height:22px;border:3px solid #d8e7ef;border-top-color:#168bc3;border-radius:50%;animation:homeNavSpin .7s linear infinite}',
            '@keyframes homeNavSpin{to{transform:rotate(360deg)}}'
        ].join('');
        document.head.appendChild(style);

        overlay = document.createElement('div');
        overlay.className = 'home-nav-loading';
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'polite');
        overlay.innerHTML = '<div class="home-nav-loading-card"><span class="home-nav-loading-spinner" aria-hidden="true"></span><span>レース情報を読み込み中…</span></div>';
        document.body.appendChild(overlay);
        return overlay;
    }

    function showLoading() {
        if (active) return;
        active = true;
        const node = ensureOverlay();
        requestAnimationFrame(function () {
            node.classList.add('is-visible');
        });
    }

    function hideLoading() {
        active = false;
        if (overlay) overlay.classList.remove('is-visible');
    }

    function isRaceDetailLink(link) {
        if (!(link instanceof HTMLAnchorElement)) return false;
        const href = String(link.getAttribute('href') || '').trim();
        if (!href || href.charAt(0) === '#') return false;

        try {
            const url = new URL(link.href, window.location.href);
            if (url.origin !== window.location.origin) return false;
            if (!/^\/web\/(?:app|index)\.php$/.test(url.pathname)) return false;
            return url.searchParams.has('place') && url.searchParams.has('race');
        } catch (e) {
            return false;
        }
    }

    document.addEventListener('click', function (event) {
        if (event.defaultPrevented || event.button !== 0) return;
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        const link = event.target instanceof Element ? event.target.closest('a') : null;
        if (!isRaceDetailLink(link)) return;
        if (link.target && link.target !== '_self') return;

        showLoading();
    }, true);

    window.addEventListener('pageshow', hideLoading);
})();
