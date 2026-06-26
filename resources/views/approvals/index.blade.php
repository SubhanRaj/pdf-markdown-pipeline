<x-layout
    title="Approval Queue"
    page-title="Approval Queue"
    page-subtitle="{{ $isApprover ? 'Review pending uploads and take action.' : 'Track the status of your submitted documents.' }}"
>
    <x-slot:breadcrumb>
        <a href="{{ route('home') }}">Home</a>
        <i class="ti ti-chevron-right text-slate-400 dark:text-slate-600 text-xs"></i>
        <span>Approval Queue</span>
    </x-slot:breadcrumb>

    {{-- JSON data islands --}}
    <script id="pending-docs-data"  type="application/json">@json($pendingData)</script>
    <script id="rejected-docs-data" type="application/json">@json($rejectedData)</script>
    <script id="my-docs-data"       type="application/json">@json($myData)</script>
    <script id="all-depts-data"     type="application/json">@json($allDepts)</script>
    <script id="all-sections-data"  type="application/json">@json($allSections)</script>
    <script id="all-divisions-data" type="application/json">@json($allDivisions)</script>
    <script id="all-rule-sets-data" type="application/json">@json($allRuleSets)</script>
    <script id="approvals-config"   type="application/json">@json(['csrf' => csrf_token(), 'isApprover' => $isApprover])</script>

    {{-- Tab pills --}}
    <div class="mb-6 flex items-center gap-1 border-b border-slate-200 dark:border-slate-700">
        @php
            $tabs = [
                'pending'  => ['label' => 'Pending Approval', 'count' => count($pendingData),  'color' => 'amber'],
                'rejected' => ['label' => 'Rejected',         'count' => count($rejectedData), 'color' => 'red'],
                'mine'     => ['label' => 'My Submissions',   'count' => count($myData),        'color' => 'slate'],
            ];
        @endphp
        @foreach($tabs as $tabKey => $tabMeta)
        <button
            data-tab="{{ $tabKey }}"
            onclick="switchTab('{{ $tabKey }}')"
            class="tab-pill flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors
                   {{ $tab === $tabKey
                       ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                       : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:border-slate-300 dark:hover:border-slate-600' }}"
        >
            {{ $tabMeta['label'] }}
            @if($tabMeta['count'] > 0)
            <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded
                {{ $tabKey === 'pending'  ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400'  : '' }}
                {{ $tabKey === 'rejected' ? 'bg-red-100   text-red-700   dark:bg-red-900/40   dark:text-red-400'    : '' }}
                {{ $tabKey === 'mine'     ? 'bg-slate-100 text-slate-600  dark:bg-slate-700    dark:text-slate-300'  : '' }}
            ">{{ $tabMeta['count'] }}</span>
            @endif
        </button>
        @endforeach
    </div>

    {{-- Tab panels --}}
    <div id="tab-pending"  class="{{ $tab !== 'pending'  ? 'hidden' : '' }}">
        @include('approvals._table', ['tableId' => 'pending-table',  'emptyMsg' => 'No documents pending approval.', 'tabKey' => 'pending'])
    </div>
    <div id="tab-rejected" class="{{ $tab !== 'rejected' ? 'hidden' : '' }}">
        @include('approvals._table', ['tableId' => 'rejected-table', 'emptyMsg' => 'No rejected documents.',          'tabKey' => 'rejected'])
    </div>
    <div id="tab-mine"     class="{{ $tab !== 'mine'     ? 'hidden' : '' }}">
        @include('approvals._table', ['tableId' => 'mine-table',     'emptyMsg' => 'You have no submitted documents pending review.', 'tabKey' => 'mine'])
    </div>

    {{-- ── Slide-over drawer ── --}}
    <div id="approval-drawer"
         class="fixed inset-0 z-50 hidden"
         role="dialog" aria-modal="true">
        {{-- Backdrop --}}
        <div id="drawer-backdrop"
             class="absolute inset-0 bg-slate-900/50 dark:bg-black/60 backdrop-blur-sm"
             onclick="closeDrawer()"></div>
        {{-- Panel --}}
        <div id="drawer-panel"
             class="absolute right-0 top-0 h-full w-full max-w-2xl bg-white dark:bg-slate-900 shadow-2xl flex flex-col transform translate-x-full transition-transform duration-300 ease-in-out overflow-hidden">
            <div class="flex items-start justify-between p-5 border-b border-slate-200 dark:border-slate-700 flex-shrink-0">
                <div>
                    <h3 id="drawer-title" class="text-base font-semibold text-slate-900 dark:text-white"></h3>
                    <p id="drawer-context" class="mt-0.5 text-xs text-slate-500 dark:text-slate-400"></p>
                </div>
                <button onclick="closeDrawer()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors ml-4 mt-0.5">
                    <i class="ti ti-x text-lg"></i>
                </button>
            </div>

            {{-- PDF preview --}}
            <div class="flex-1 flex flex-col overflow-hidden">
                <div id="drawer-pdf-wrap" class="flex-1 overflow-hidden">
                    <iframe id="drawer-pdf" src="" class="w-full h-full border-0" title="Document preview"></iframe>
                </div>
                <div id="drawer-no-pdf" class="hidden flex-1 flex items-center justify-center text-slate-400 dark:text-slate-600">
                    <div class="text-center">
                        <i class="ti ti-file-off text-4xl mb-2"></i>
                        <p class="text-sm">No PDF available for preview.</p>
                    </div>
                </div>
            </div>

            {{-- Metadata strip --}}
            <div class="flex-shrink-0 border-t border-slate-200 dark:border-slate-700 p-4 space-y-2 text-xs text-slate-600 dark:text-slate-400">
                <div class="grid grid-cols-2 gap-x-4 gap-y-1">
                    <div><span class="font-medium text-slate-500 dark:text-slate-500">Type:</span> <span id="drawer-type"></span></div>
                    <div><span class="font-medium text-slate-500 dark:text-slate-500">Status:</span> <span id="drawer-status"></span></div>
                    <div><span class="font-medium text-slate-500 dark:text-slate-500">Uploaded by:</span> <span id="drawer-uploader"></span></div>
                    <div><span class="font-medium text-slate-500 dark:text-slate-500">Uploaded at:</span> <span id="drawer-date"></span></div>
                </div>
                <div id="drawer-rejection-row" class="hidden pt-1 border-t border-slate-200 dark:border-slate-700">
                    <span class="font-medium text-red-500">Rejection reason:</span>
                    <span id="drawer-rejection-reason" class="ml-1 text-red-600 dark:text-red-400"></span>
                </div>
            </div>

            {{-- Drawer actions --}}
            <div id="drawer-actions" class="flex-shrink-0 border-t border-slate-200 dark:border-slate-700 p-4 flex items-center gap-3 flex-wrap">
            </div>
        </div>
    </div>

    {{-- ── Reclassify modal ── --}}
    <div id="modal-reclassify" class="fixed inset-0 z-60 hidden items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeReclassifyModal()"></div>
        <div class="relative bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-lg p-6 space-y-4">
            <h3 class="text-base font-semibold text-slate-900 dark:text-white flex items-center gap-2">
                <i class="ti ti-arrows-transfer-up text-indigo-500"></i>
                Reclassify Document
            </h3>
            <p class="text-xs text-slate-500 dark:text-slate-400">Move this document to the correct section, division, or rule set. The file will be physically moved on the server.</p>

            <form id="reclassify-form" method="POST" action="" novalidate>
                @csrf
                <div class="space-y-3">
                    <div>
                        <label class="field-label">Target Context</label>
                        <select id="rc-context-type" class="field-input" onchange="rcContextTypeChanged(this.value)">
                            <option value="section">Section / Division</option>
                            <option value="rule_set">Rule Set</option>
                        </select>
                    </div>

                    <div id="rc-section-wrap" class="space-y-3">
                        <div>
                            <label class="field-label">Section</label>
                            <select id="rc-section" name="new_section_id" class="field-input" onchange="rcSectionChanged(this.value)">
                                <option value="">— Select section —</option>
                            </select>
                        </div>
                        <div id="rc-division-wrap">
                            <label class="field-label">Division <span class="text-slate-400 dark:text-slate-500 font-normal">(optional)</span></label>
                            <select id="rc-division" name="new_division_id" class="field-input">
                                <option value="">— No division (direct section doc) —</option>
                            </select>
                        </div>
                    </div>

                    <div id="rc-rule-set-wrap" class="hidden">
                        <label class="field-label">Rule Set</label>
                        <select id="rc-rule-set" name="new_rule_set_id" class="field-input">
                            <option value="">— Select rule set —</option>
                        </select>
                    </div>

                    <div>
                        <label class="field-label">Note <span class="text-slate-400 dark:text-slate-500 font-normal">(optional)</span></label>
                        <textarea id="rc-note" name="note" rows="2" maxlength="500"
                            class="field-input resize-none"
                            placeholder="Reason for reclassification…"></textarea>
                    </div>

                    @if($isApprover)
                    <label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300 cursor-pointer">
                        <input type="checkbox" name="approve" value="1" id="rc-approve"
                            class="w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-green-600 focus:ring-green-500">
                        Approve after reclassifying
                    </label>
                    @endif
                </div>

                <div class="mt-5 flex items-center justify-end gap-3">
                    <button type="button" onclick="closeReclassifyModal()"
                        class="px-4 py-2 text-sm text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors">
                        Cancel
                    </button>
                    <button type="submit" id="rc-submit"
                        class="px-4 py-2 text-sm font-semibold bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg transition-colors flex items-center gap-2">
                        <i class="ti ti-arrows-transfer-up"></i> Reclassify
                    </button>
                </div>
            </form>
        </div>
    </div>

    @push('scripts')
    <script>
    (function () {
        try {
            const pendingDocs  = JSON.parse(document.getElementById('pending-docs-data').textContent);
            const rejectedDocs = JSON.parse(document.getElementById('rejected-docs-data').textContent);
            const myDocs       = JSON.parse(document.getElementById('my-docs-data').textContent);
            const allDepts     = JSON.parse(document.getElementById('all-depts-data').textContent);
            const allSections  = JSON.parse(document.getElementById('all-sections-data').textContent);
            const allDivisions = JSON.parse(document.getElementById('all-divisions-data').textContent);
            const allRuleSets  = JSON.parse(document.getElementById('all-rule-sets-data').textContent);
            const cfg          = JSON.parse(document.getElementById('approvals-config').textContent);

            const docsByTab = { pending: pendingDocs, rejected: rejectedDocs, mine: myDocs };

            // ── Tab switching ──────────────────────────────────────────────────
            window.switchTab = function (key) {
                ['pending', 'rejected', 'mine'].forEach(t => {
                    document.getElementById('tab-' + t).classList.toggle('hidden', t !== key);
                    document.querySelectorAll('[data-tab="' + t + '"]').forEach(el => {
                        el.classList.toggle('border-indigo-500', t === key);
                        el.classList.toggle('text-indigo-600', t === key);
                        el.classList.toggle('dark:text-indigo-400', t === key);
                        el.classList.toggle('border-transparent', t !== key);
                        el.classList.toggle('text-slate-500', t !== key);
                        el.classList.toggle('dark:text-slate-400', t !== key);
                    });
                });
                const url = new URL(window.location);
                url.searchParams.set('tab', key);
                history.replaceState(null, '', url);
            };

            // ── Drawer ────────────────────────────────────────────────────────
            let currentDoc = null;

            window.openDrawer = function (id, tabKey) {
                const docs = docsByTab[tabKey] || [];
                currentDoc = docs.find(d => d.id === id);
                if (! currentDoc) return;

                document.getElementById('drawer-title').textContent   = currentDoc.title;
                document.getElementById('drawer-context').textContent = currentDoc.department + ' › ' + currentDoc.context_name;
                document.getElementById('drawer-type').textContent    = currentDoc.document_type;
                document.getElementById('drawer-status').textContent  = currentDoc.status_label;
                document.getElementById('drawer-uploader').textContent = currentDoc.uploaded_by;
                document.getElementById('drawer-date').textContent    = currentDoc.uploaded_at;

                const rejRow    = document.getElementById('drawer-rejection-row');
                const rejReason = document.getElementById('drawer-rejection-reason');
                if (currentDoc.rejection_reason) {
                    rejRow.classList.remove('hidden');
                    rejReason.textContent = currentDoc.rejection_reason + (currentDoc.rejected_by ? ' — ' + currentDoc.rejected_by : '');
                } else {
                    rejRow.classList.add('hidden');
                }

                const pdfWrap = document.getElementById('drawer-pdf-wrap');
                const noPdf   = document.getElementById('drawer-no-pdf');
                if (currentDoc.pdf_url) {
                    document.getElementById('drawer-pdf').src = currentDoc.pdf_url;
                    pdfWrap.classList.remove('hidden');
                    noPdf.classList.add('hidden');
                } else {
                    document.getElementById('drawer-pdf').src = '';
                    pdfWrap.classList.add('hidden');
                    noPdf.classList.remove('hidden');
                }

                renderDrawerActions(currentDoc, tabKey);

                const overlay = document.getElementById('approval-drawer');
                const panel   = document.getElementById('drawer-panel');
                overlay.classList.remove('hidden');
                requestAnimationFrame(() => {
                    panel.classList.remove('translate-x-full');
                    panel.classList.add('translate-x-0');
                });
            };

            window.closeDrawer = function () {
                const panel = document.getElementById('drawer-panel');
                panel.classList.remove('translate-x-0');
                panel.classList.add('translate-x-full');
                setTimeout(() => {
                    document.getElementById('approval-drawer').classList.add('hidden');
                    document.getElementById('drawer-pdf').src = '';
                }, 300);
            };

            document.addEventListener('keydown', e => {
                if (e.key === 'Escape') closeDrawer();
            });

            function renderDrawerActions(doc, tabKey) {
                const wrap = document.getElementById('drawer-actions');
                wrap.innerHTML = '';
                const isDark = document.documentElement.classList.contains('dark');

                if (doc.can_act) {
                    // Approve button
                    const btnApprove = document.createElement('button');
                    btnApprove.className = 'inline-flex items-center gap-1.5 px-3.5 py-2 text-sm font-semibold bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors';
                    btnApprove.innerHTML = '<i class="ti ti-check"></i> Approve';
                    btnApprove.onclick   = () => approveDoc(doc.id, doc.title, doc.approve_url);
                    wrap.appendChild(btnApprove);

                    // Reject button
                    const btnReject = document.createElement('button');
                    btnReject.className = 'inline-flex items-center gap-1.5 px-3.5 py-2 text-sm font-semibold bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors';
                    btnReject.innerHTML = '<i class="ti ti-x"></i> Reject';
                    btnReject.onclick   = () => rejectDoc(doc.id, doc.title, doc.reject_url);
                    wrap.appendChild(btnReject);

                    // Reclassify button
                    const btnRc = document.createElement('button');
                    btnRc.className = 'inline-flex items-center gap-1.5 px-3.5 py-2 text-sm font-semibold bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg transition-colors';
                    btnRc.innerHTML = '<i class="ti ti-arrows-transfer-up"></i> Reclassify';
                    btnRc.onclick   = () => { closeDrawer(); openReclassifyModal(doc); };
                    wrap.appendChild(btnRc);
                }

                if (doc.can_resubmit) {
                    const btnRs = document.createElement('button');
                    btnRs.className = 'inline-flex items-center gap-1.5 px-3.5 py-2 text-sm font-semibold bg-amber-600 hover:bg-amber-700 text-white rounded-lg transition-colors';
                    btnRs.innerHTML = '<i class="ti ti-refresh"></i> Resubmit';
                    btnRs.onclick   = () => resubmitDoc(doc.id, doc.title, doc.resubmit_url);
                    wrap.appendChild(btnRs);
                }

                if (! doc.can_act && ! doc.can_resubmit) {
                    wrap.innerHTML = '<span class="text-xs text-slate-400 dark:text-slate-500 italic">No actions available for this document.</span>';
                }
            }

            // ── Approve ───────────────────────────────────────────────────────
            window.approveDoc = async function (id, title, url) {
                const isDark = document.documentElement.classList.contains('dark');
                const result = await Swal.fire({
                    title: 'Approve document?',
                    html: '<div class="text-sm text-left mb-2 text-slate-600 dark:text-slate-400">"' + esc(title) + '" will be approved and become immediately active.</div>' +
                          '<textarea id="swal-note" class="w-full mt-2 text-sm border border-slate-300 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 rounded-lg px-3 py-2 resize-none" rows="2" placeholder="Optional note…" maxlength="500"></textarea>',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Approve',
                    confirmButtonColor: '#16a34a',
                    cancelButtonText: 'Cancel',
                    background: isDark ? '#1e293b' : '#fff',
                    color: isDark ? '#e2e8f0' : '#1e293b',
                    preConfirm: () => ({
                        note: document.getElementById('swal-note').value.trim(),
                    }),
                });
                if (! result.isConfirmed) return;

                try {
                    const fd = new FormData();
                    fd.append('_token', cfg.csrf);
                    if (result.value.note) fd.append('note', result.value.note);

                    const res = await fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd });
                    if (res.redirected || res.ok) { window.location.href = res.url || url.replace('/approve', '?tab=pending'); }
                    else { Swal.fire({ icon: 'error', title: 'Failed', text: 'Could not approve. Please try again.', background: isDark ? '#1e293b' : '#fff', color: isDark ? '#e2e8f0' : '#1e293b' }); }
                } catch(e) {
                    console.error(e);
                    Swal.fire({ icon: 'error', title: 'Error', text: 'An unexpected error occurred.', background: isDark ? '#1e293b' : '#fff', color: isDark ? '#e2e8f0' : '#1e293b' });
                }
            };

            // ── Reject ────────────────────────────────────────────────────────
            window.rejectDoc = async function (id, title, url) {
                const isDark = document.documentElement.classList.contains('dark');
                const result = await Swal.fire({
                    title: 'Reject upload?',
                    html: '<div class="text-sm text-left mb-2 text-slate-600 dark:text-slate-400">"' + esc(title) + '"</div>' +
                          '<textarea id="swal-reason" class="w-full mt-1 text-sm border border-slate-300 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 rounded-lg px-3 py-2 resize-none" rows="3" placeholder="Rejection reason (required, min 5 chars)…" maxlength="500"></textarea>',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Reject',
                    confirmButtonColor: '#dc2626',
                    cancelButtonText: 'Cancel',
                    background: isDark ? '#1e293b' : '#fff',
                    color: isDark ? '#e2e8f0' : '#1e293b',
                    preConfirm: () => {
                        const reason = document.getElementById('swal-reason').value.trim();
                        if (reason.length < 5) {
                            Swal.showValidationMessage('Please enter at least 5 characters.');
                            return false;
                        }
                        return { reason };
                    },
                });
                if (! result.isConfirmed) return;

                try {
                    const fd = new FormData();
                    fd.append('_token', cfg.csrf);
                    fd.append('reason', result.value.reason);

                    const res = await fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd });
                    if (res.redirected || res.ok) { window.location.reload(); }
                    else { Swal.fire({ icon: 'error', title: 'Failed', text: 'Could not reject. Please try again.', background: isDark ? '#1e293b' : '#fff', color: isDark ? '#e2e8f0' : '#1e293b' }); }
                } catch(e) {
                    console.error(e);
                    Swal.fire({ icon: 'error', title: 'Error', text: 'An unexpected error occurred.', background: isDark ? '#1e293b' : '#fff', color: isDark ? '#e2e8f0' : '#1e293b' });
                }
            };

            // ── Resubmit ──────────────────────────────────────────────────────
            window.resubmitDoc = async function (id, title, url) {
                const isDark = document.documentElement.classList.contains('dark');
                const result = await Swal.fire({
                    title: 'Resubmit for approval?',
                    text: '"' + title + '" will be sent back to the approval queue.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Resubmit',
                    confirmButtonColor: '#d97706',
                    background: isDark ? '#1e293b' : '#fff',
                    color: isDark ? '#e2e8f0' : '#1e293b',
                });
                if (! result.isConfirmed) return;

                try {
                    const fd = new FormData();
                    fd.append('_token', cfg.csrf);

                    const res = await fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd });
                    if (res.redirected || res.ok) { window.location.reload(); }
                    else { Swal.fire({ icon: 'error', title: 'Failed', text: 'Could not resubmit. Please try again.', background: isDark ? '#1e293b' : '#fff', color: isDark ? '#e2e8f0' : '#1e293b' }); }
                } catch(e) {
                    console.error(e);
                }
            };

            // ── Reclassify modal ──────────────────────────────────────────────
            window.openReclassifyModal = function (doc) {
                const modal = document.getElementById('modal-reclassify');
                document.getElementById('reclassify-form').action = doc.reclassify_url;
                document.getElementById('rc-note').value = '';
                const cbApprove = document.getElementById('rc-approve');
                if (cbApprove) cbApprove.checked = false;

                // Reset selects
                document.getElementById('rc-context-type').value = 'section';
                rcContextTypeChanged('section');
                populateRcSections();

                modal.classList.remove('hidden');
                modal.classList.add('flex');
            };

            window.closeReclassifyModal = function () {
                const modal = document.getElementById('modal-reclassify');
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            };

            window.rcContextTypeChanged = function (val) {
                document.getElementById('rc-section-wrap').classList.toggle('hidden', val !== 'section');
                document.getElementById('rc-rule-set-wrap').classList.toggle('hidden', val !== 'rule_set');

                // Toggle name attributes so only the active context's value is submitted
                const sectionSel  = document.getElementById('rc-section');
                const divisionSel = document.getElementById('rc-division');
                const ruleSetSel  = document.getElementById('rc-rule-set');

                if (val === 'section') {
                    sectionSel.name  = 'new_section_id';
                    divisionSel.name = 'new_division_id';
                    ruleSetSel.removeAttribute('name');
                } else {
                    sectionSel.removeAttribute('name');
                    divisionSel.removeAttribute('name');
                    ruleSetSel.name = 'new_rule_set_id';
                    populateRcRuleSets();
                }
            };

            function populateRcSections() {
                const sel = document.getElementById('rc-section');
                sel.innerHTML = '<option value="">— Select section —</option>';
                allSections.forEach(s => {
                    const opt = document.createElement('option');
                    opt.value       = s.id;
                    opt.textContent = (s.wing ? s.wing + ' › ' : '') + s.name;
                    sel.appendChild(opt);
                });
                rcSectionChanged('');
            }

            window.rcSectionChanged = function (sectionId) {
                const divSel = document.getElementById('rc-division');
                divSel.innerHTML = '<option value="">— No division (direct section doc) —</option>';

                if (! sectionId) return;
                allDivisions
                    .filter(d => String(d.section_id) === String(sectionId))
                    .forEach(d => {
                        const opt = document.createElement('option');
                        opt.value       = d.id;
                        opt.textContent = d.name;
                        divSel.appendChild(opt);
                    });
            };

            function populateRcRuleSets() {
                const sel = document.getElementById('rc-rule-set');
                sel.innerHTML = '<option value="">— Select rule set —</option>';
                allRuleSets.forEach(r => {
                    const opt = document.createElement('option');
                    opt.value       = r.id;
                    opt.textContent = r.name;
                    sel.appendChild(opt);
                });
            }

            // ── Bulk approve / bulk reject ────────────────────────────────────
            function selectedIds(tabKey) {
                const tableId = tabKey + '-table';
                return Array.from(
                    document.querySelectorAll('#' + tableId + ' input[type=checkbox][name="bulk-ids"]:checked')
                ).map(cb => parseInt(cb.value, 10));
            }

            function toggleBulkBar(tabKey) {
                const bar = document.getElementById('bulk-bar-' + tabKey);
                if (! bar) return;
                const count = selectedIds(tabKey).length;
                bar.classList.toggle('hidden', count === 0);
                const label = bar.querySelector('.bulk-count');
                if (label) label.textContent = count + ' selected';
            }

            document.querySelectorAll('input[name="bulk-ids"]').forEach(cb => {
                cb.addEventListener('change', () => {
                    const tabKey = cb.closest('[data-tab-body]')?.dataset.tabBody;
                    if (tabKey) toggleBulkBar(tabKey);
                });
            });

            document.querySelectorAll('.select-all-cb').forEach(cb => {
                cb.addEventListener('change', function () {
                    const tabKey  = this.dataset.tab;
                    const tableId = tabKey + '-table';
                    document.querySelectorAll('#' + tableId + ' input[name="bulk-ids"]').forEach(c => c.checked = this.checked);
                    toggleBulkBar(tabKey);
                });
            });

            window.bulkApprove = async function (tabKey) {
                const ids = selectedIds(tabKey);
                if (! ids.length) return;
                const isDark = document.documentElement.classList.contains('dark');
                const result = await Swal.fire({
                    title: 'Approve ' + ids.length + ' document(s)?',
                    text: 'All selected pending documents will be approved and become active.',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Approve All',
                    confirmButtonColor: '#16a34a',
                    background: isDark ? '#1e293b' : '#fff',
                    color: isDark ? '#e2e8f0' : '#1e293b',
                });
                if (! result.isConfirmed) return;

                for (const id of ids) {
                    const doc = pendingDocs.find(d => d.id === id);
                    if (! doc || ! doc.can_act) continue;
                    try {
                        const fd = new FormData(); fd.append('_token', cfg.csrf);
                        await fetch(doc.approve_url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd });
                    } catch(e) { console.error(e); }
                }
                window.location.reload();
            };

            window.bulkReject = async function (tabKey) {
                const ids = selectedIds(tabKey);
                if (! ids.length) return;
                const isDark = document.documentElement.classList.contains('dark');
                const result = await Swal.fire({
                    title: 'Reject ' + ids.length + ' document(s)?',
                    html: '<textarea id="swal-bulk-reason" class="w-full mt-2 text-sm border border-slate-300 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 rounded-lg px-3 py-2 resize-none" rows="3" placeholder="Rejection reason (required)…" maxlength="500"></textarea>',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Reject All',
                    confirmButtonColor: '#dc2626',
                    background: isDark ? '#1e293b' : '#fff',
                    color: isDark ? '#e2e8f0' : '#1e293b',
                    preConfirm: () => {
                        const reason = document.getElementById('swal-bulk-reason').value.trim();
                        if (reason.length < 5) { Swal.showValidationMessage('Minimum 5 characters.'); return false; }
                        return { reason };
                    },
                });
                if (! result.isConfirmed) return;

                for (const id of ids) {
                    const doc = pendingDocs.find(d => d.id === id);
                    if (! doc || ! doc.can_act) continue;
                    try {
                        const fd = new FormData(); fd.append('_token', cfg.csrf); fd.append('reason', result.value.reason);
                        await fetch(doc.reject_url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd });
                    } catch(e) { console.error(e); }
                }
                window.location.reload();
            };

            // ── Utility ───────────────────────────────────────────────────────
            function esc(str) {
                return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
            }

            // Populate table rows from JSON data islands
            function buildRows(tabKey) {
                const docs    = docsByTab[tabKey] || [];
                const tableId = tabKey + '-table';
                const tbody   = document.querySelector('#' + tableId + ' tbody');
                const empty   = document.getElementById(tableId + '-empty');

                if (! tbody) return;

                if (! docs.length) {
                    if (empty) empty.classList.remove('hidden');
                    return;
                }
                if (empty) empty.classList.add('hidden');

                tbody.innerHTML = '';
                docs.forEach(doc => {
                    const statusColors = {
                        pending_approval: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400',
                        rejected:         'bg-red-100   text-red-700   dark:bg-red-900/40   dark:text-red-400',
                        uploaded:         'bg-slate-100 text-slate-600  dark:bg-slate-700    dark:text-slate-300',
                    };
                    const statusCls = statusColors[doc.status] || 'bg-slate-100 text-slate-600';

                    const tr = document.createElement('tr');
                    tr.className = 'hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors';
                    tr.innerHTML = `
                        <td class="px-4 py-3 w-10">
                            ${doc.can_act ? `<input type="checkbox" name="bulk-ids" value="${doc.id}" class="w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-indigo-600">` : ''}
                        </td>
                        <td class="px-4 py-3">
                            <button onclick="openDrawer(${doc.id}, '${tabKey}')" class="font-medium text-slate-900 dark:text-white hover:text-indigo-600 dark:hover:text-indigo-400 text-left transition-colors text-sm line-clamp-2">${esc(doc.title)}</button>
                            <div class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">${esc(doc.department)} › ${esc(doc.context_name)}</div>
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400 hidden sm:table-cell">${esc(doc.document_type)}</td>
                        <td class="px-4 py-3 hidden md:table-cell">
                            <span class="text-[11px] font-semibold px-2 py-0.5 rounded ${statusCls}">${esc(doc.status_label)}</span>
                        </td>
                        <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400 hidden lg:table-cell">${esc(doc.uploaded_by)}</td>
                        <td class="px-4 py-3 text-xs text-slate-400 dark:text-slate-500 hidden xl:table-cell">${esc(doc.uploaded_at)}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2 flex-wrap">
                                <button onclick="openDrawer(${doc.id}, '${tabKey}')"
                                    class="inline-flex items-center gap-1 text-xs px-2.5 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-400 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                    <i class="ti ti-eye"></i> View
                                </button>
                                ${doc.can_act ? `
                                <button onclick="approveDoc(${doc.id}, ${JSON.stringify(doc.title)}, ${JSON.stringify(doc.approve_url)})"
                                    class="inline-flex items-center gap-1 text-xs px-2.5 py-1.5 rounded-lg bg-green-600 hover:bg-green-700 text-white transition-colors">
                                    <i class="ti ti-check"></i> Approve
                                </button>
                                <button onclick="rejectDoc(${doc.id}, ${JSON.stringify(doc.title)}, ${JSON.stringify(doc.reject_url)})"
                                    class="inline-flex items-center gap-1 text-xs px-2.5 py-1.5 rounded-lg bg-red-600 hover:bg-red-700 text-white transition-colors">
                                    <i class="ti ti-x"></i> Reject
                                </button>
                                <button onclick="openReclassifyModal(${JSON.stringify(doc)})"
                                    class="inline-flex items-center gap-1 text-xs px-2.5 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white transition-colors">
                                    <i class="ti ti-arrows-transfer-up"></i>
                                </button>` : ''}
                                ${doc.can_resubmit ? `
                                <button onclick="resubmitDoc(${doc.id}, ${JSON.stringify(doc.title)}, ${JSON.stringify(doc.resubmit_url)})"
                                    class="inline-flex items-center gap-1 text-xs px-2.5 py-1.5 rounded-lg bg-amber-600 hover:bg-amber-700 text-white transition-colors">
                                    <i class="ti ti-refresh"></i> Resubmit
                                </button>` : ''}
                            </div>
                        </td>
                    `;
                    tbody.appendChild(tr);
                });
            }

            ['pending', 'rejected', 'mine'].forEach(buildRows);

        } catch (err) {
            console.error('Approvals init error:', err);
        }
    })();
    </script>
    @endpush
</x-layout>
