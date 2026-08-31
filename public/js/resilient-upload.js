// Shared by the bulk/folder upload modals (sections, divisions, folders show pages).
// Fixes two failure modes hit on a real 45-file folder upload over the Cloudflare Tunnel:
//   1. documents.store is capped at 40 requests/min per user (throttle:uploads, raised from
//      20/min in M97). Firing many requests back to back can still blow through that on a
//      big batch, so everything past the cap starts failing. PACE_MS spaces requests out to
//      stay under it; request() also honors a 429's Retry-After if the cap is still hit.
//   2. The CSRF token captured on page load goes stale if the session expires mid-batch
//      (idle time picking a big folder, or a slow connection stretching the whole upload
//      past SESSION_LIFETIME) — once that happens every remaining request 419s forever.
//      request() fetches a fresh token from `page.csrfTokenUrl` and retries once instead.
// Queue additionally persists the picked files in IndexedDB as they're queued, so a tab
// reload or crash mid-batch doesn't force re-picking all 45 files from scratch.
(function () {
    const PACE_MS = 1800; // ~33 requests/min, safely under the 40/min upload throttle

    function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

    async function refreshToken(page) {
        try {
            const res = await fetch(page.csrfTokenUrl, { headers: { Accept: 'application/json' } });
            if (!res.ok) return false;
            const json = await res.json();
            if (json.token) { page.csrfToken = json.token; return true; }
        } catch (e) { /* network hiccup — caller's retry loop just moves on */ }
        return false;
    }

    // Uploads one file. `fd` must NOT already contain `_token` — this sets/refreshes it on
    // each attempt. Returns {ok, status, json}, the same shape callers already parse
    // json.errors / json.message / json.redirect from.
    async function request(url, fd, page) {
        let usedTokenRefresh = false;
        for (let attempt = 0; attempt < 4; attempt++) {
            fd.set('_token', page.csrfToken);
            const res = await fetch(url, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': page.csrfToken },
                body: fd,
            });

            if (res.status === 419 && !usedTokenRefresh) {
                usedTokenRefresh = true;
                if (await refreshToken(page)) continue;
            }
            if (res.status === 429) {
                const wait = Number(res.headers.get('Retry-After')) || 5;
                await sleep(wait * 1000);
                continue;
            }

            const ct = res.headers.get('Content-Type') || '';
            if (!ct.includes('application/json')) return { ok: false, status: res.status, json: null };
            const json = await res.json().catch(() => null);
            return { ok: res.ok, status: res.status, json };
        }
        return { ok: false, status: 0, json: null };
    }

    // ── IndexedDB-backed pending queue ──────────────────────────────────────────────────
    const DB_NAME = 'excise-upload-queue';
    const STORE   = 'pending';

    function openDb() {
        return new Promise((resolve, reject) => {
            const req = indexedDB.open(DB_NAME, 1);
            req.onupgradeneeded = () => req.result.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
            req.onsuccess = () => resolve(req.result);
            req.onerror   = () => reject(req.error);
        });
    }

    class Queue {
        constructor(key) { this.key = key; } // namespaces rows so different pages don't mix

        // Returns the new row's id, or null if IndexedDB is unavailable (private-mode Safari etc.)
        async add(file, folderId) {
            let db;
            try { db = await openDb(); } catch (e) { return null; }
            return new Promise(resolve => {
                const tx  = db.transaction(STORE, 'readwrite');
                const req = tx.objectStore(STORE).add({ key: this.key, file, folderId: folderId || null });
                req.onsuccess = () => resolve(req.result);
                req.onerror   = () => resolve(null);
            });
        }

        async all() {
            let db;
            try { db = await openDb(); } catch (e) { return []; }
            return new Promise(resolve => {
                const req = db.transaction(STORE, 'readonly').objectStore(STORE).getAll();
                req.onsuccess = () => resolve(req.result.filter(r => r.key === this.key));
                req.onerror   = () => resolve([]);
            });
        }

        async remove(id) {
            if (id == null) return;
            let db;
            try { db = await openDb(); } catch (e) { return; }
            db.transaction(STORE, 'readwrite').objectStore(STORE).delete(id);
        }
    }

    window.ResilientUpload = { request, sleep, PACE_MS, Queue };
})();
