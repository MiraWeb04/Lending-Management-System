(function () {
    'use strict';

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('service-worker.js', { scope: './' }).catch(function () {
                /* Registration may fail on non-HTTPS except localhost — safe to ignore */
            });
        });
    }

    function ensureOfflineBanner() {
        var existing = document.getElementById('lendingOfflineBanner');
        if (existing) {
            return existing;
        }
        var banner = document.createElement('div');
        banner.id = 'lendingOfflineBanner';
        banner.className = 'lending-offline-banner';
        banner.setAttribute('role', 'status');
        banner.setAttribute('aria-live', 'polite');
        banner.hidden = true;
        banner.innerHTML =
            '<div class="lending-offline-banner__inner">' +
            '<i class="fas fa-wifi-slash" aria-hidden="true"></i>' +
            '<span><strong>Offline mode.</strong> Connect to the office network to sync live loan data. Cached pages and styles may still work.</span>' +
            '<button type="button" class="btn btn-sm btn-light lending-offline-banner__retry">Retry</button>' +
            '</div>';
        document.body.prepend(banner);
        banner.querySelector('.lending-offline-banner__retry').addEventListener('click', function () {
            window.location.reload();
        });
        return banner;
    }

    function setOfflineState(isOffline) {
        var banner = ensureOfflineBanner();
        banner.hidden = !isOffline;
        document.documentElement.classList.toggle('is-offline', isOffline);
    }

    function updateOnlineStatus() {
        setOfflineState(!navigator.onLine);
    }

    window.addEventListener('online', updateOnlineStatus);
    window.addEventListener('offline', updateOnlineStatus);
    document.addEventListener('DOMContentLoaded', updateOnlineStatus);
    updateOnlineStatus();
})();
