(function () {
    'use strict';

    function moveOtherAfterRecent() {
        const tabs = document.querySelector('.pc-main-tabs');
        if (!tabs) return false;

        const recent = tabs.querySelector('[data-pc-main-tab="recent"]');
        const other = tabs.querySelector('[data-pc-main-tab="other"]');
        if (!recent || !other) return false;

        // 「その他」は使用頻度が低い補助タブなので、直近60Rの直後へ固定する。
        if (other.previousElementSibling !== recent) {
            recent.insertAdjacentElement('afterend', other);
        }

        const count = tabs.querySelectorAll('.pc-main-tab').length;
        if (count > 0) {
            tabs.style.gridTemplateColumns = 'repeat(' + count + ', minmax(0, 1fr))';
        }
        return true;
    }

    function loadCurrentMeetScript() {
        if (document.querySelector('script[data-pc-current-meet-loader="1"]')) return;
        const script = document.createElement('script');
        script.src = '/web/assets/js/pc_current_meet.js?v=20260913d';
        script.dataset.pcCurrentMeetLoader = '1';
        script.async = false;
        document.head.appendChild(script);
    }

    function setup() {
        loadCurrentMeetScript();

        let tries = 100;
        let observer = null;

        function run() {
            const ready = moveOtherAfterRecent();
            const tabs = document.querySelector('.pc-main-tabs');

            if (tabs && !observer) {
                observer = new MutationObserver(function () {
                    moveOtherAfterRecent();
                });
                observer.observe(tabs, {childList: true});
            }

            if (!ready && tries-- > 0) {
                window.setTimeout(run, 60);
            }
        }

        run();
        window.setTimeout(moveOtherAfterRecent, 200);
        window.setTimeout(moveOtherAfterRecent, 700);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setup);
    } else {
        setup();
    }
})();
