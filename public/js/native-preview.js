// Renders a Word/Excel/PowerPoint/... document natively in the browser where a good
// self-hosted open-source renderer exists (docx-preview for .docx, SheetJS for .xlsx/.xls) —
// see claude.md for why these two formats and not the others. Every other native format
// (legacy .doc/.ppt, .odt/.ods/.odp, .rtf/.txt/.csv) falls back to a download link; there's no
// good open-source in-browser renderer for those, so pretending otherwise would just be a
// broken viewer. Used by documents/show.blade.php and quick_conversions/show.blade.php.
(function () {
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function fallback(containerEl, format, downloadUrl, filename) {
        containerEl.innerHTML =
            '<div class="native-preview-fallback" style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;min-height:200px;color:#94a3b8;gap:8px;text-align:center;padding:20px">' +
                '<i class="ti ti-file-off" style="font-size:32px"></i>' +
                '<p style="font-size:13px">' + escapeHtml(format ? format.toUpperCase() : 'This file') + ' has no in-browser preview.</p>' +
                (downloadUrl ? '<a href="' + escapeHtml(downloadUrl) + '" style="font-size:13px;color:#4f46e5;text-decoration:underline">Download ' + escapeHtml(filename || 'original file') + ' &rarr;</a>' : '') +
            '</div>';
    }

    // format: real extension (docx, xlsx, doc, xls, ppt, pptx, odt, ods, odp, rtf, txt, csv).
    async function renderNativePreview(containerEl, fileUrl, format, downloadUrl, filename) {
        format = (format || '').toLowerCase();

        try {
            if (format === 'docx' && window.docx) {
                const blob = await (await fetch(fileUrl)).blob();
                containerEl.innerHTML = '';
                await window.docx.renderAsync(blob, containerEl);
                return;
            }

            if ((format === 'xlsx' || format === 'xls') && window.XLSX) {
                const buf = await (await fetch(fileUrl)).arrayBuffer();
                const wb  = window.XLSX.read(buf, { type: 'array' });
                const wrap = document.createElement('div');
                wrap.style.cssText = 'padding:12px;overflow:auto;height:100%';
                wb.SheetNames.forEach(name => {
                    const html = window.XLSX.utils.sheet_to_html(wb.Sheets[name]);
                    const section = document.createElement('div');
                    section.style.marginBottom = '16px';
                    section.innerHTML = '<h4 style="margin:0 0 6px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#64748b">' + escapeHtml(name) + '</h4>' + html;
                    wrap.appendChild(section);
                });
                containerEl.innerHTML = '';
                containerEl.appendChild(wrap);
                return;
            }
        } catch (e) {
            console.error('Native preview failed:', e);
        }

        fallback(containerEl, format, downloadUrl, filename);
    }

    window.renderNativePreview = renderNativePreview;
})();
