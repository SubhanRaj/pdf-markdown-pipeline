<x-layout title="My Uploads" page-title="My Uploads" page-subtitle="Files queued on this device that haven't finished uploading">

<div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 p-4 sm:p-6">
    <div id="empty-state" class="text-center py-10 text-slate-400 dark:text-slate-500" style="display:none">
        <i class="ti ti-cloud-check text-3xl mb-2"></i>
        <p class="text-sm">Nothing queued — every file on this device has finished uploading.</p>
    </div>

    <div id="groups"></div>
</div>

<script src="{{ asset('js/resilient-upload.js') }}"></script>
<script>
(function () {
    function formatSize(bytes) {
        if (bytes >= 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        return Math.ceil(bytes / 1024) + ' KB';
    }

    // Folder/section/division names and local filenames are both user-controlled — escape
    // before building innerHTML strings with them.
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function render() {
        ResilientUpload.allQueued().then(function (rows) {
            const wrap = document.getElementById('groups');
            wrap.innerHTML = '';

            if (!rows.length) {
                document.getElementById('empty-state').style.display = 'block';
                return;
            }
            document.getElementById('empty-state').style.display = 'none';

            // Group by the page each row's batch belongs to, so "resume" sends someone back to
            // wherever the upload form (and destination context) actually lives. A row queued
            // before this page's {url, label} tagging existed has neither — still shown, but
            // with no resume link, just Discard.
            const groups = new Map();
            rows.forEach(function (r) {
                const groupKey = r.pageUrl || 'unknown';
                if (!groups.has(groupKey)) groups.set(groupKey, { label: r.pageLabel || 'Unknown destination (queued before this page could remember one — open the section/division/folder directly to re-upload, or discard below)', url: r.pageUrl, rows: [] });
                groups.get(groupKey).rows.push(r);
            });

            groups.forEach(function (group) {
                const ids = group.rows.map(r => r.id);
                const section = document.createElement('div');
                section.className = 'mb-6 last:mb-0';
                section.innerHTML =
                    '<div class="flex items-start justify-between gap-3 mb-2">' +
                        '<h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">' + escapeHtml(group.label) + ' <span class="text-slate-400 font-normal">(' + group.rows.length + ' file' + (group.rows.length > 1 ? 's' : '') + ')</span></h3>' +
                        '<div class="flex-shrink-0 flex items-center gap-3">' +
                            (group.url ? '<a href="' + escapeHtml(group.url) + '" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline whitespace-nowrap">Resume here →</a>' : '') +
                            '<button type="button" class="discard-group text-xs text-red-500 hover:underline whitespace-nowrap" data-ids="' + ids.join(',') + '">Discard all</button>' +
                        '</div>' +
                    '</div>' +
                    '<ul class="text-sm text-slate-500 dark:text-slate-400 divide-y divide-slate-100 dark:divide-slate-800">' +
                        group.rows.map(function (r) {
                            return '<li class="py-1.5 flex items-center justify-between gap-2">' +
                                '<span class="truncate">' + escapeHtml(r.file.name) + '</span>' +
                                '<span class="flex-shrink-0 flex items-center gap-2 text-xs text-slate-400">' +
                                    formatSize(r.file.size) +
                                    '<button type="button" class="discard-one text-red-400 hover:text-red-500" data-id="' + r.id + '" title="Discard this file">' +
                                        '<i class="ti ti-x"></i>' +
                                    '</button>' +
                                '</span>' +
                            '</li>';
                        }).join('') +
                    '</ul>';
                wrap.appendChild(section);
            });
        });
    }

    document.getElementById('groups').addEventListener('click', function (e) {
        const one = e.target.closest('.discard-one');
        if (one) {
            ResilientUpload.removeQueued(Number(one.dataset.id)).then(render);
            return;
        }
        const group = e.target.closest('.discard-group');
        if (group) {
            const ids = group.dataset.ids.split(',').map(Number);
            Promise.all(ids.map(id => ResilientUpload.removeQueued(id))).then(render);
        }
    });

    render();
})();
</script>

</x-layout>
