self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', event => {
    event.waitUntil(self.clients.claim());
});

/*
 * 現段階ではキャッシュしない。
 * レース・展示情報は常に最新データをWebから取得する。
 */
