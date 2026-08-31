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

    ResilientUpload.allQueued().then(function (rows) {
        if (!rows.length) {
            document.getElementById('empty-state').style.display = 'block';
            return;
        }

        // Group by the page each row's batch belongs to, so "resume" sends someone back to
        // wherever the upload form (and destination context) actually lives.
        const groups = new Map();
        rows.forEach(function (r) {
            const groupKey = r.pageUrl || 'unknown';
            if (!groups.has(groupKey)) groups.set(groupKey, { label: r.pageLabel || 'Unknown destination', url: r.pageUrl, rows: [] });
            groups.get(groupKey).rows.push(r);
        });

        const wrap = document.getElementById('groups');
        groups.forEach(function (group) {
            const section = document.createElement('div');
            section.className = 'mb-6 last:mb-0';
            section.innerHTML =
                '<div class="flex items-center justify-between mb-2">' +
                    '<h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">' + escapeHtml(group.label) + ' <span class="text-slate-400 font-normal">(' + group.rows.length + ' file' + (group.rows.length > 1 ? 's' : '') + ')</span></h3>' +
                    (group.url ? '<a href="' + escapeHtml(group.url) + '" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline">Resume here →</a>' : '') +
                '</div>' +
                '<ul class="text-sm text-slate-500 dark:text-slate-400 divide-y divide-slate-100 dark:divide-slate-800">' +
                    group.rows.map(function (r) {
                        return '<li class="py-1.5 flex items-center justify-between gap-2">' +
                            '<span class="truncate">' + escapeHtml(r.file.name) + '</span>' +
                            '<span class="flex-shrink-0 text-xs text-slate-400">' + formatSize(r.file.size) + '</span>' +
                        '</li>';
                    }).join('') +
                '</ul>';
            wrap.appendChild(section);
        });
    });
})();
</script>

</x-layout>
