self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', event => {
    event.waitUntil(self.clients.claim());
});

// レース本体やAPI応答は常にネットワークから取得する一方、版番号付きの
// JavaScript / CSS だけは端末内に保持する。これによりPWAと通常ブラウザの
// 2回目以降の画面表示で、見た目・操作用アセットの取得待ちをなくす。
const STATIC_CACHE = 'boatrace-static-v3';

function isVersionedStaticAsset(request) {
    if (request.method !== 'GET') return false;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return false;
    if (!url.searchParams.has('v')) return false;

    return /^\/web\/assets\/(?:css|js)\/.+\.(?:css|js)$/.test(url.pathname);
}

self.addEventListener('fetch', event => {
    if (!isVersionedStaticAsset(event.request)) return;

    event.respondWith(
        caches.open(STATIC_CACHE).then(cache =>
            cache.match(event.request).then(cached => {
                if (cached) return cached;

                return fetch(event.request).then(response => {
                    if (response && response.ok) {
                        cache.put(event.request, response.clone());
                    }
                    return response;
                });
            })
        )
    );
});
