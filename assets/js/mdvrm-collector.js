(function () {
    'use strict';

    if (!window.navigator || typeof window.navigator.sendBeacon !== 'function') {
        return;
    }

    const cfg = window.MDVRMCollectorSettings || {};
    if (!cfg.restUrl || !cfg.nonce) {
        return;
    }

    const sessionKey = cfg.sessionKey || 'mdvrm_session_id';
    const sessionId = ensureSession();

    let lcpTime = null;
    try {
        const po = new PerformanceObserver((entryList) => {
            const entries = entryList.getEntries();
            const last = entries[entries.length - 1];
            if (last) {
                lcpTime = (last.renderTime || last.loadTime || last.startTime) / 1000;
            }
        });
        po.observe({ type: 'largest-contentful-paint', buffered: true });
    } catch (e) {
        // LCP observer not supported; ignore.
    }

    function ensureSession() {
        try {
            if (!sessionStorage.getItem(sessionKey)) {
                const id = generateId();
                sessionStorage.setItem(sessionKey, id);
            }
            return sessionStorage.getItem(sessionKey);
        } catch (e) {
            return generateId();
        }
    }

    function generateId() {
        if (window.crypto && window.crypto.randomUUID) {
            return window.crypto.randomUUID();
        }
        return 'mdvrm-' + Math.random().toString(16).slice(2) + '-' + Date.now();
    }

    function getNavigationTimings() {
        const nav = performance.getEntriesByType('navigation')[0];
        if (nav) {
            return {
                ttfb: (nav.responseStart - nav.requestStart) / 1000,
                load: (nav.loadEventEnd - nav.requestStart) / 1000,
            };
        }
        const t = performance.timing;
        return {
            ttfb: (t.responseStart - t.requestStart) / 1000,
            load: (t.loadEventEnd - t.requestStart) / 1000,
        };
    }

    function deviceType() {
        const ua = navigator.userAgent;
        if (/Mobi|Android.*Mobile|iPhone|iPod/i.test(ua)) {
            return 'mobile';
        }
        if (/iPad|Android(?!.*Mobile)|Tablet/i.test(ua)) {
            return 'tablet';
        }
        return 'desktop';
    }

    function connectionType() {
        const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        return conn && conn.effectiveType ? conn.effectiveType : '';
    }

    let sent = false;

    function sendPayload() {
        if (sent) {
            return;
        }
        if (navigator.connection && navigator.connection.saveData) {
            return;
        }

        const timings = getNavigationTimings();

        // Skip if load event hasn't fired yet (timings incomplete).
        if (timings.load <= 0) {
            return;
        }

        // Detect stale server_time (Cached Page)
        // If PHP reported generating time is longer than the entire document fetch time, 
        // it means the HTML was served from cache with an old timestamp.
        let serverTime = Number(cfg.server && cfg.server.time ? cfg.server.time : 0);
        
        // Get document fetch duration (responseEnd - requestStart)
        const perf = window.performance && window.performance.getEntriesByType ? window.performance.getEntriesByType('navigation')[0] : null;
        if (perf) {
            const docDuration = (perf.responseEnd - perf.requestStart) / 1000; // in seconds
            // If Server Time is significantly larger than Doc Duration, it's a cached artifact.
            // We add a small 50ms buffer for timer mismatches.
            if (serverTime > (docDuration + 0.05)) {
                serverTime = 0; // Honest value: Server didn't run PHP for *this* request
            }
        }

        const payload = {
            event_time: cfg.timestamp,
            url: window.location.href,
            server_time: serverTime,
            ttfb: Number(timings.ttfb || 0),
            lcp: Number(lcpTime || 0),
            total_load: Number(timings.load || 0),
            memory_peak: Number(cfg.server && cfg.server.memoryPeak ? cfg.server.memoryPeak : 0),
            device: deviceType(),
            net: connectionType(),
            country: cfg.server && cfg.server.country ? cfg.server.country : '',
            session_id: sessionId,
        };

        sent = true;

        // Use sendBeacon as primary — it's designed for page-unload data and
        // doesn't require custom headers.  Append nonce as a query param.
        if (window.navigator.sendBeacon) {
            const blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });
            const url = new URL(cfg.restUrl);
            url.searchParams.set('mdvrm_token', cfg.nonce);
            window.navigator.sendBeacon(url.toString(), blob);
        } else if (window.fetch) {
            fetch(cfg.restUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-MDVRM-Nonce': cfg.nonce
                },
                body: JSON.stringify(payload),
                keepalive: true
            }).catch(function () {});
        }
    }

    // Primary trigger: after load + 3 s delay so LCP stabilizes.
    function onLoaded() {
        setTimeout(sendPayload, 3000);
    }

    if (document.readyState === 'complete') {
        onLoaded();
    } else {
        window.addEventListener('load', onLoaded);
    }

    // Backup: capture quick bounces (user leaves before load + 3 s).
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            sendPayload();
        }
    });

    // Backup: pagehide is more reliable than visibilitychange in some browsers.
    window.addEventListener('pagehide', sendPayload);
})();
