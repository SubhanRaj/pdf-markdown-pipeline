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

    // ── Client-side PDF split for files over the tunnel's own edge cap ─────────────────────
    // The app's own 300 MB validation limit is moot when the production Cloudflare Tunnel
    // enforces a lower cap first (100 MB on the current plan — see DEPLOY.md); a PDF over
    // SPLIT_THRESHOLD_BYTES never reaches PHP at all, it 413s at Cloudflare's edge. Split it
    // into pieces client-side (pdf-lib, loaded via CDN on pages that need this) and upload
    // each piece to documents.store-chunk; the server reassembles them with pdfunite and
    // creates one Document, same as a normal single-file upload — see storeChunk() and
    // StoreDocumentChunkRequest.
    const SPLIT_THRESHOLD_BYTES = 95 * 1024 * 1024;
    const MAX_CHUNK_BYTES       = 90 * 1024 * 1024;

    function pageRange(start, end) { return Array.from({ length: end - start }, (_, i) => start + i); }

    // ponytail: estimates pages-per-chunk from the file's average bytes/page rather than
    // packing exactly (which would mean re-serializing a growing trial chunk after every page —
    // O(n^2) for an n-page PDF). Real scanned documents have fairly uniform per-page size, so
    // this lands close to the target; a chunk can still come in over/under it on an unusual
    // PDF. Upgrade to exact packing if that ever proves too imprecise in practice.
    async function splitPdf(file, maxBytes) {
        const srcDoc    = await PDFLib.PDFDocument.load(await file.arrayBuffer(), { ignoreEncryption: true });
        const pageCount = srcDoc.getPageCount();
        if (pageCount === 0) throw new Error('This PDF has no pages.');
        const avgBytesPerPage = file.size / pageCount;
        const pagesPerChunk   = Math.max(1, Math.floor((maxBytes / avgBytesPerPage) * 0.85));

        const chunks = [];
        for (let start = 0; start < pageCount; start += pagesPerChunk) {
            const end      = Math.min(start + pagesPerChunk, pageCount);
            const chunkDoc = await PDFLib.PDFDocument.create();
            (await chunkDoc.copyPages(srcDoc, pageRange(start, end))).forEach(p => chunkDoc.addPage(p));
            chunks.push(new Blob([await chunkDoc.save()], { type: 'application/pdf' }));
        }
        return chunks;
    }

    // Splits `file` and uploads each piece in order via documents.store-chunk. `fields` is a
    // plain object of the same form fields a normal upload sends (title, document_type,
    // section_id/division_id/folder_id, visibility, …) — resent with every chunk since only
    // the server's last-chunk handling actually reads them. Returns the same {ok, status,
    // json} shape as request(), from whichever chunk finishes (or fails) last.
    async function uploadChunkedPdf(file, fields, page, onProgress) {
        const chunks   = await splitPdf(file, MAX_CHUNK_BYTES);
        const uploadId = crypto.randomUUID();
        let result;

        for (let i = 0; i < chunks.length; i++) {
            const fd = new FormData();
            Object.entries(fields).forEach(([k, v]) => { if (v !== null && v !== undefined && v !== '') fd.append(k, v); });
            fd.append('upload_id', uploadId);
            fd.append('chunk_index', i);
            fd.append('total_chunks', chunks.length);
            if (i === chunks.length - 1) fd.append('original_filename', file.name);
            fd.append('file', chunks[i], `${i}.pdf`);

            if (onProgress) onProgress(i + 1, chunks.length);
            result = await request(page.storeChunkUrl, fd, page);
            if (!result.ok) return result; // a chunk failing mid-way isn't resumable yet — stop and surface it
            if (i < chunks.length - 1) await sleep(PACE_MS);
        }
        return result;
    }

    // What every upload modal's submit loop should call instead of building FormData itself —
    // transparently splits a PDF over SPLIT_THRESHOLD_BYTES, otherwise uploads normally.
    // `fields` is the same plain object described in uploadChunkedPdf() above. Always resolves
    // to {ok, status, json} — the submit loops destructure this with no try/catch of their own
    // (request() already guarantees that shape for a normal upload), so a split/merge failure
    // here (a 0-page or otherwise unreadable PDF) must be caught and turned into the same
    // shape rather than thrown, or it would crash the whole batch loop mid-upload.
    async function uploadFile(file, fields, page, onProgress) {
        if (file.type === 'application/pdf' && file.size > SPLIT_THRESHOLD_BYTES && window.PDFLib) {
            try {
                return await uploadChunkedPdf(file, fields, page, onProgress);
            } catch (e) {
                return { ok: false, status: 0, json: { message: 'Could not split this PDF for upload: ' + e.message } };
            }
        }
        const fd = new FormData();
        Object.entries(fields).forEach(([k, v]) => { if (v !== null && v !== undefined && v !== '') fd.append(k, v); });
        fd.append('file', file);
        return request(page.storeUrl, fd, page);
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
        // `page` is {url, label} — the page to send someone back to so they can resume this
        // batch (upload logic lives on that page, not here) — shown by the site-wide pending-
        // uploads indicator in the header (see components/header.blade.php) so a batch queued
        // here is still visible after navigating away mid-upload.
        constructor(key, page) { this.key = key; this.page = page || null; }

        // Returns the new row's id, or null if IndexedDB is unavailable (private-mode Safari etc.)
        async add(file, folderId) {
            let db;
            try { db = await openDb(); } catch (e) { return null; }
            return new Promise(resolve => {
                const tx  = db.transaction(STORE, 'readwrite');
                const req = tx.objectStore(STORE).add({
                    key: this.key, file, folderId: folderId || null,
                    pageUrl: this.page?.url || null, pageLabel: this.page?.label || null,
                });
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

    // Every row across every page's queue, grouped by page — used by the header's pending-
    // uploads indicator, which has no page-specific key of its own to filter by.
    async function allQueued() {
        let db;
        try { db = await openDb(); } catch (e) { return []; }
        return new Promise(resolve => {
            const req = db.transaction(STORE, 'readonly').objectStore(STORE).getAll();
            req.onsuccess = () => resolve(req.result);
            req.onerror   = () => resolve([]);
        });
    }

    // Deletes a queued row by id regardless of which page's key it belongs to — for the
    // documents.my-uploads.index page's Discard action, which (unlike a single upload page's
    // own Queue instance) has no one key to scope a Queue to.
    async function removeQueued(id) {
        if (id == null) return;
        let db;
        try { db = await openDb(); } catch (e) { return; }
        db.transaction(STORE, 'readwrite').objectStore(STORE).delete(id);
    }

    window.ResilientUpload = { request, sleep, PACE_MS, Queue, uploadFile, SPLIT_THRESHOLD_BYTES, allQueued, removeQueued };
})();
