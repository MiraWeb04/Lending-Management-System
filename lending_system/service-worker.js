/* RJ & RR Finance — offline shell and static asset cache */
const CACHE_VERSION = 'rjrr-lending-v2';
const STATIC_CACHE = CACHE_VERSION + '-static';
const RUNTIME_CACHE = CACHE_VERSION + '-runtime';

const APP_SHELL = [
    './offline.html',
    './manifest.webmanifest',
    './index.php',
    './borrower_login_lending.php',
    './login_lending.php',
    './assets/vendor/bootstrap/bootstrap.min.css',
    './assets/vendor/bootstrap/bootstrap.bundle.min.js',
    './assets/vendor/fontawesome/css/all.min.css',
    './assets/vendor/fontawesome/webfonts/fa-solid-900.woff2',
    './assets/vendor/fontawesome/webfonts/fa-regular-400.woff2',
    './assets/vendor/fontawesome/webfonts/fa-brands-400.woff2',
    './assets/vendor/chartjs/chart.umd.min.js',
    './assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css',
    './assets/vendor/bootstrap-icons/font/fonts/bootstrap-icons.woff2',
    './assets/vendor/html2pdf/html2pdf.bundle.min.js',
    './assets/vendor/xlsx/xlsx.full.min.js',
    './assets/lending.js',
    './assets/lending_ui.js',
    './assets/pwa.js',
    './css/design-system.css',
    './css/lending_styles.css',
    './css/nav_styles.css',
    './css/pwa.css',
    './css/admin_pages.css',
    './css/borrower_public.css',
    './images/logo.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE).then((cache) => cache.addAll(APP_SHELL).catch(() => undefined)).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys.filter((key) => key.startsWith('rjrr-lending-') && key !== STATIC_CACHE && key !== RUNTIME_CACHE).map((key) => caches.delete(key))
            )
        ).then(() => self.clients.claim())
    );
});

function isStaticAssetRequest(url) {
    return (
        url.pathname.includes('/assets/') ||
        url.pathname.includes('/css/') ||
        url.pathname.includes('/images/') ||
        url.pathname.endsWith('.webmanifest') ||
        url.pathname.endsWith('offline.html')
    );
}

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    if (isStaticAssetRequest(url)) {
        event.respondWith(
            caches.match(request).then((cached) => {
                if (cached) {
                    return cached;
                }
                return fetch(request).then((response) => {
                    if (response && response.status === 200) {
                        const copy = response.clone();
                        caches.open(RUNTIME_CACHE).then((cache) => cache.put(request, copy));
                    }
                    return response;
                });
            })
        );
        return;
    }

    if (request.mode === 'navigate' || (request.headers.get('accept') || '').includes('text/html')) {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response && response.status === 200) {
                        const copy = response.clone();
                        caches.open(RUNTIME_CACHE).then((cache) => cache.put(request, copy));
                    }
                    return response;
                })
                .catch(() =>
                    caches.match(request).then((cached) => cached || caches.match('./offline.html'))
                )
        );
    }
});
