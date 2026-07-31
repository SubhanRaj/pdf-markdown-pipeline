<?php
$isPolicy  = $ruleSet->kind === 'policy';
$canManage = auth()->check() && (auth()->user()->isAdmin() || ($isPolicy && auth()->user()->canManagePolicy($ruleSet)));
// $isPolicy is only ever true here for a policy DOCUMENT (a container never reaches this
// view — RuleSetController::show() renders rule_sets.policy_container for those), and
// $policy (the container) is passed alongside it by PolicyDocumentController.
$showRoute    = $isPolicy ? route('departments.policy.periods.show',    [$department->levelAlias(), $department, $policy, $ruleSet]) : route('departments.rules.show',    [$department->levelAlias(), $department, $ruleSet]);
$editRoute    = $isPolicy ? route('departments.policy.periods.edit',    [$department->levelAlias(), $department, $policy, $ruleSet]) : route('departments.rules.edit',    [$department->levelAlias(), $department, $ruleSet]);
$destroyRoute = $isPolicy ? route('departments.policy.periods.destroy', [$department->levelAlias(), $department, $policy, $ruleSet]) : route('departments.rules.destroy', [$department->levelAlias(), $department, $ruleSet]);
$downloadRoute = $isPolicy ? route('departments.policy.periods.download', [$department->levelAlias(), $department, $policy, $ruleSet]) : route('departments.rules.download', [$department->levelAlias(), $department, $ruleSet]);
$ogDescription = $department->name . ' — ' . $ruleSet->name . ' · ' . $ruleSet->documents->count() . ' ' . Str::plural('document', $ruleSet->documents->count());
?>
<x-layout
    :title="$ruleSet->name"
    :page-title="$ruleSet->name"
    :page-subtitle="$department->name . ' · ' . ($isPolicy ? 'Policy' : 'Rules & Regulations')"
    :description="$ogDescription"
>

<x-breadcrumb :items="[
    ['name' => 'Home',                    'url' => route('home')],
    ['name' => 'Departments',             'url' => route('departments.index')],
    ['name' => $department->levelLabel(), 'url' => null],
    ['name' => $department->name,         'url' => route('departments.show', [$department->levelAlias(), $department])],
    ...($isPolicy
        ? [
            ...\App\Models\RuleSet::policyBreadcrumb($department, $policy->state),
            ['name' => $policy->name, 'url' => route('departments.policy.show', [$department->levelAlias(), $department, $policy])],
        ]
        : [['name' => 'Rules & Regulations', 'url' => route('departments.rules.index', [$department->levelAlias(), $department])]]),
    ['name' => $ruleSet->name,            'url' => null],
]" />

@if($isPolicy && $ruleSet->policy_status === 'superseded')
<div class="mb-4 px-4 py-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700/50 flex items-center gap-2 text-sm text-amber-700 dark:text-amber-300">
    <i class="ti ti-clock-pause text-base flex-shrink-0"></i>
    <span>
        Superseded — kept for historical reference only.
        @if($supersededBy)
        Current policy:
        <a href="{{ route('departments.policy.periods.show', [$department->levelAlias(), $department, $policy, $supersededBy]) }}" class="font-medium underline hover:no-underline">{{ $supersededBy->name }}</a>
        @endif
    </span>
</div>
@endif

@php
    $rootDocsOfType  = $rootDocuments->where('document_type', $isPolicy ? 'policy' : 'rule');
    $hasRuleDoc      = $rootDocsOfType->isNotEmpty();
    // A root document exists per-language, independently — applies to both policy and rules
    // documents (rules/GOs can be bilingual too): english, hindi, and a bilingual 'both'
    // document can all three coexist for the same rule set.
    $hasEnglishDoc   = $rootDocsOfType->where('language', 'english')->isNotEmpty();
    $hasHindiDoc     = $rootDocsOfType->where('language', 'hindi')->isNotEmpty();
    $hasBothDoc      = $rootDocsOfType->where('language', 'both')->isNotEmpty();
    // Root documents are per-language — only block re-upload once all three are covered.
    $canUploadRule   = ! ($hasEnglishDoc && $hasHindiDoc && $hasBothDoc);
    $canUploadAmend  = $hasRuleDoc;
@endphp

<script id="page-data" type="application/json">@json(['storeUrl' => route('documents.store'), 'csrfToken' => csrf_token(), 'parentOptions' => $parentOptions])</script>

{{-- ── Rule set header ─────────────────────────────────────────────────────── --}}
<div class="flex items-start justify-between gap-4 mb-6 flex-wrap">
    <div class="flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-indigo-500/10 dark:bg-indigo-500/20 flex items-center justify-center flex-shrink-0">
            <i class="ti {{ $isPolicy ? 'ti-file-certificate' : 'ti-book' }} text-indigo-500 dark:text-indigo-400 text-xl"></i>
        </div>
        <div>
            <h2 class="text-base font-semibold text-slate-800 dark:text-slate-100">{{ $ruleSet->name }}</h2>
            <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                <a href="{{ route('departments.show', [$department->levelAlias(), $department]) }}"
                   class="text-xs text-slate-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">
                    {{ $department->name }}
                </a>
                <span class="text-slate-300 dark:text-slate-600">·</span>
                <span class="text-xs text-slate-400 dark:text-slate-500">{{ $totalCount }} {{ Str::plural('document', $totalCount) }}</span>
            </div>
            @if($ruleSet->description)
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">{{ $ruleSet->description }}</p>
            @endif
        </div>
    </div>
    <div class="flex items-center gap-2 flex-wrap justify-end w-full sm:w-auto">
        <a href="{{ $downloadRoute }}"
           class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-emerald-400 dark:hover:border-emerald-500 text-slate-600 dark:text-slate-300 hover:text-emerald-600 dark:hover:text-emerald-400 text-sm font-medium px-3 py-2 rounded-lg transition-all"
           title="Download all markdown as ZIP">
            <i class="ti ti-file-zip text-base"></i>
            <span class="hidden sm:inline">Download ZIP</span>
        </a>
        @auth
        @if($isPolicy ? auth()->user()->canManagePolicy($ruleSet) : auth()->user()->canUploadTo($ruleSet))
        {{-- Upload a document — the root rule/policy type is capped at one (dropdown hides it
             once taken), but other types (checklist, notice, etc.) stay open indefinitely. --}}
        <button type="button"
                onclick="document.getElementById('modal-rule').style.display='block'"
                class="inline-flex items-center gap-1.5 border text-sm font-medium px-3 py-2 rounded-lg transition-all
                       bg-white dark:bg-slate-800 border-slate-200 dark:border-slate-700 hover:border-indigo-400 dark:hover:border-indigo-500 text-slate-600 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400 cursor-pointer">
            <i class="ti ti-file-plus text-base"></i>
            <span class="hidden sm:inline">Upload Document</span>
        </button>

        {{-- Upload Amendment — disabled until a root document exists --}}
        <button type="button"
                @if($canUploadAmend) onclick="document.getElementById('modal-amendment').style.display='block'" @endif
                @if(! $canUploadAmend) disabled title="{{ $isPolicy ? 'Upload the base policy document before adding amendments.' : 'Upload a base rule document before adding amendments.' }}" @endif
                class="inline-flex items-center gap-1.5 text-sm font-medium px-3 py-2 rounded-lg transition-colors
                       {{ $canUploadAmend
                          ? 'bg-indigo-600 hover:bg-indigo-700 text-white cursor-pointer'
                          : 'bg-indigo-100 dark:bg-indigo-900/20 text-indigo-300 dark:text-indigo-600 cursor-not-allowed' }}">
            <i class="ti ti-git-merge text-base"></i>
            <span class="hidden sm:inline">Upload Amendment</span>
        </button>
        @endif
        @endauth
        @auth @if($canManage)
        <a href="{{ $editRoute }}"
           class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-indigo-400 dark:hover:border-indigo-500 text-slate-600 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400 text-sm font-medium px-3 py-2 rounded-lg transition-all">
            <i class="ti ti-pencil text-base"></i>
        </a>
        <button type="button" id="delete-ruleset-btn"
                class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-red-400 dark:hover:border-red-500 text-slate-600 dark:text-slate-300 hover:text-red-600 dark:hover:text-red-400 text-sm font-medium px-3 py-2 rounded-lg transition-all">
            <i class="ti ti-trash text-base"></i>
        </button>
        <form id="delete-ruleset-form" method="POST"
              action="{{ $destroyRoute }}"
              style="display:none">
            @csrf @method('DELETE')
        </form>
        @endif @endauth
    </div>
</div>

{{-- ── Modal: Upload Rule ────────────────────────────────────────────────────── --}}
@auth
<div id="modal-rule"
     style="display:none;position:fixed;inset:0;z-index:50;background:rgba(0,0,0,0.6)"
     onclick="if(event.target===this)this.style.display='none'">
    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:min(960px,95vw);max-height:90vh;overflow-y:auto"
         class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl flex flex-col">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700 flex-shrink-0">
            <div class="flex items-center gap-3">
                <i class="ti ti-file-plus text-indigo-500 text-lg"></i>
                <span class="text-sm font-semibold text-slate-800 dark:text-slate-100">Upload Document</span>
                <span class="text-xs text-slate-400 dark:text-slate-500">— {{ $ruleSet->name }}</span>
            </div>
            <button type="button" onclick="document.getElementById('modal-rule').style.display='none'"
                    class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                <i class="ti ti-x"></i>
            </button>
        </div>
        <div class="flex flex-col lg:flex-row flex-1 min-h-0">
            {{-- Left: file drop + queue --}}
            <div class="lg:w-1/2 border-b lg:border-b-0 lg:border-r border-slate-200 dark:border-slate-700 flex flex-col p-4 gap-3">
                <div id="rule-drop-zone" onclick="document.getElementById('rule-file').click()" style="cursor:pointer"
                     class="rounded-xl border-2 border-dashed border-slate-300 dark:border-slate-600 hover:border-indigo-400 dark:hover:border-indigo-500 transition-colors flex flex-col items-center justify-center gap-1.5 py-5 px-4 text-center flex-shrink-0">
                    <i class="ti ti-cloud-upload text-2xl text-slate-300 dark:text-slate-600"></i>
                    <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Click or drag files here</p>
                    <p class="text-xs text-slate-400 dark:text-slate-500">PDF · Word · Excel · Images · max 300 MB each · multiple files supported</p>
                    <input type="file" id="rule-file" name="file" multiple
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.rtf,.txt,.csv,.jpg,.jpeg,.png,.webp,.gif,.tiff,.tif,.bmp,.heic,.heif,.svg"
                           style="display:none">
                </div>
                <div id="rule-queue-wrap" class="flex-1 overflow-hidden flex flex-col min-h-0" style="display:none">
                    <div class="flex items-center justify-between mb-1.5 flex-shrink-0">
                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">
                            Queue &nbsp;<span id="rule-queue-count" class="text-indigo-500 font-bold normal-case">0</span>
                        </p>
                        <button type="button" id="rule-btn-clear" class="text-xs text-slate-400 hover:text-red-500 dark:hover:text-red-400 transition-colors">Clear all</button>
                    </div>
                    <div id="rule-queue" class="overflow-y-auto flex flex-col gap-1.5" style="max-height:240px"></div>
                </div>
                <p id="rule-queue-hint" class="text-xs text-slate-400 dark:text-slate-500 text-center py-1">Select one or more files — each gets its own title</p>
            </div>
            {{-- Right: form --}}
            <div class="lg:w-1/2 p-6 flex flex-col gap-4">
                <form id="rule-form" method="POST" action="{{ route('documents.store') }}" novalidate enctype="multipart/form-data" class="flex flex-col gap-4 flex-1">
                    @csrf
                    <input type="hidden" name="rule_set_id" value="{{ $ruleSet->id }}">
                    <div>
                        <label for="rule-type" class="field-label">Document Type <span class="text-red-500">*</span></label>
                        <select id="rule-type" name="document_type" class="field-input">
                            @foreach(\App\Models\Document::DOCUMENT_TYPES as $key => $label)
                                @continue($key === 'rule_amendment')
                                @php $isRootType = $key === ($isPolicy ? 'policy' : 'rule'); @endphp
                                <option value="{{ $key }}"
                                        @selected($canUploadRule ? $isRootType : $key === 'other')
                                        @disabled($isRootType && ! $canUploadRule)>
                                    {{ $label }}{{ $isRootType && ! $canUploadRule ? ' (already uploaded)' : '' }}
                                </option>
                            @endforeach
                        </select>
                        <p id="rule-err-type" class="field-err-msg" style="display:none"></p>
                    </div>
                    @php $defaultLanguage = ! $hasEnglishDoc ? 'english' : (! $hasHindiDoc ? 'hindi' : 'both'); @endphp
                    <div>
                        <label class="field-label">Language</label>
                        <div class="flex gap-3 mt-1">
                            <label class="flex items-center gap-2 {{ $hasEnglishDoc ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer' }}">
                                <input type="radio" name="language" value="english" @selected($defaultLanguage === 'english') @disabled($hasEnglishDoc) class="text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-slate-700 dark:text-slate-200">English only{{ $hasEnglishDoc ? ' (already uploaded)' : '' }}</span>
                            </label>
                            <label class="flex items-center gap-2 {{ $hasHindiDoc ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer' }}">
                                <input type="radio" name="language" value="hindi" @selected($defaultLanguage === 'hindi') @disabled($hasHindiDoc) class="text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-slate-700 dark:text-slate-200">Hindi only{{ $hasHindiDoc ? ' (already uploaded)' : '' }}</span>
                            </label>
                            <label class="flex items-center gap-2 {{ $hasBothDoc ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer' }}">
                                <input type="radio" name="language" value="both" @selected($defaultLanguage === 'both') @disabled($hasBothDoc) class="text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-slate-700 dark:text-slate-200">Both{{ $hasBothDoc ? ' (already uploaded)' : '' }}</span>
                            </label>
                        </div>
                        <p class="field-hint">"Both" is for a single bilingual document (one PDF covering English and Hindi together) — a separate, third document from the English-only and Hindi-only versions.</p>
                    </div>
                    <div>
                        <label class="field-label">Visibility</label>
                        <div class="flex gap-3 mt-1">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="visibility" value="public" checked class="text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-slate-700 dark:text-slate-200 flex items-center gap-1"><i class="ti ti-world text-sm text-green-500"></i> Public</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="visibility" value="authenticated" class="text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-slate-700 dark:text-slate-200 flex items-center gap-1"><i class="ti ti-lock text-sm text-amber-500"></i> Authenticated Only</span>
                            </label>
                        </div>
                    </div>
                    <div class="bg-slate-50 dark:bg-slate-800/60 rounded-lg px-4 py-3">
                        <p class="text-[10px] font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500 mb-1">Saving to</p>
                        <p class="text-xs text-indigo-600 dark:text-indigo-400 font-medium">
                            {{ Str::title(str_replace('_', ' ', $department->level)) }} › {{ $department->name }} › {{ $isPolicy ? 'Policy' : 'Rules' }} › {{ $ruleSet->name }}
                        </p>
                    </div>
                    <div class="flex items-center gap-3 mt-auto pt-2">
                        <button type="submit" id="rule-btn-submit"
                                class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-5 py-2.5 rounded-lg transition-colors">
                            <i class="ti ti-upload"></i>
                            <span id="rule-btn-label">Upload</span>
                        </button>
                        <span id="rule-upload-status" class="text-xs text-slate-400 dark:text-slate-500"></span>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endauth

{{-- ── Modal: Upload Amendment ──────────────────────────────────────────────── --}}
@auth
<div id="modal-amendment"
     style="display:none;position:fixed;inset:0;z-index:50;background:rgba(0,0,0,0.6)"
     onclick="if(event.target===this)this.style.display='none'">
    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:min(960px,95vw);max-height:90vh;overflow-y:auto"
         class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl flex flex-col">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700 flex-shrink-0">
            <div class="flex items-center gap-3">
                <i class="ti ti-git-merge text-amber-500 text-lg"></i>
                <span class="text-sm font-semibold text-slate-800 dark:text-slate-100">Upload Amendment</span>
                <span class="text-xs text-slate-400 dark:text-slate-500">— {{ $ruleSet->name }}</span>
            </div>
            <button type="button" onclick="document.getElementById('modal-amendment').style.display='none'"
                    class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                <i class="ti ti-x"></i>
            </button>
        </div>
        <div class="flex flex-col lg:flex-row flex-1 min-h-0">
            {{-- Left: file drop + queue --}}
            <div class="lg:w-1/2 border-b lg:border-b-0 lg:border-r border-slate-200 dark:border-slate-700 flex flex-col p-4 gap-3">
                <div id="amend-drop-zone" onclick="document.getElementById('amend-file').click()" style="cursor:pointer"
                     class="rounded-xl border-2 border-dashed border-amber-300 dark:border-amber-700 hover:border-amber-400 dark:hover:border-amber-500 transition-colors flex flex-col items-center justify-center gap-1.5 py-5 px-4 text-center flex-shrink-0">
                    <i class="ti ti-cloud-upload text-2xl text-amber-300 dark:text-amber-600"></i>
                    <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Click or drag amendment files here</p>
                    <p class="text-xs text-slate-400 dark:text-slate-500">PDF · Word · Excel · Images · max 300 MB each · multiple files supported</p>
                    <input type="file" id="amend-file" name="file" multiple
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.rtf,.txt,.csv,.jpg,.jpeg,.png,.webp,.gif,.tiff,.tif,.bmp,.heic,.heif,.svg"
                           style="display:none">
                </div>
                <div id="amend-queue-wrap" class="flex-1 overflow-hidden flex flex-col min-h-0" style="display:none">
                    <div class="flex items-center justify-between mb-1.5 flex-shrink-0">
                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">
                            Queue &nbsp;<span id="amend-queue-count" class="text-amber-500 font-bold normal-case">0</span>
                        </p>
                        <button type="button" id="amend-btn-clear" class="text-xs text-slate-400 hover:text-red-500 dark:hover:text-red-400 transition-colors">Clear all</button>
                    </div>
                    <div id="amend-queue" class="overflow-y-auto flex flex-col gap-1.5" style="max-height:240px"></div>
                </div>
                <p id="amend-queue-hint" class="text-xs text-slate-400 dark:text-slate-500 text-center py-1">Select one or more amendment files — each gets its own title</p>
            </div>
            {{-- Right: form --}}
            <div class="lg:w-1/2 p-6 flex flex-col gap-4">
                <form id="amend-form" method="POST" action="{{ route('documents.store') }}" novalidate enctype="multipart/form-data" class="flex flex-col gap-4 flex-1">
                    @csrf
                    <input type="hidden" name="rule_set_id" value="{{ $ruleSet->id }}">
                    {{-- Type is always rule_amendment; no dropdown needed --}}
                    <input type="hidden" name="document_type" value="rule_amendment">
                    <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700/50">
                        <i class="ti ti-git-merge text-amber-500 text-sm"></i>
                        <span class="text-xs font-medium text-amber-700 dark:text-amber-300">Type: Amendment</span>
                    </div>
                    <div>
                        <label for="amend-parent" class="field-label">Amends <span class="text-red-500">*</span></label>
                        <select id="amend-parent" name="parent_id" class="field-input">
                            <option value="">— Select document being amended —</option>
                        </select>
                        <p id="amend-err-parent" class="field-err-msg" style="display:none"></p>
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Select the base rule or earlier amendment this document formally modifies.</p>
                    </div>
                    {{-- Amendment number + effective date ──────────────────── --}}
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="amend-amendment-number" class="field-label">Amendment No.</label>
                            <input type="number" id="amend-amendment-number" name="amendment_number" min="1" max="999"
                                   placeholder="e.g. 5" class="field-input">
                            <p class="field-hint">Ordinal number of this amendment</p>
                        </div>
                        <div>
                            <label for="amend-effective-year" class="field-label">Effective Year</label>
                            <input type="number" id="amend-effective-year" name="effective_year" min="1900" max="2099"
                                   placeholder="e.g. 2019" class="field-input">
                        </div>
                        <div>
                            <label for="amend-effective-month" class="field-label">Month <span class="text-slate-400 font-normal">(optional)</span></label>
                            <select id="amend-effective-month" name="effective_month" class="field-input">
                                <option value="">—</option>
                                @foreach(['January','February','March','April','May','June','July','August','September','October','November','December'] as $mi => $mn)
                                <option value="{{ $mi + 1 }}">{{ $mn }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="amend-effective-day" class="field-label">Day <span class="text-slate-400 font-normal">(optional)</span></label>
                            <input type="number" id="amend-effective-day" name="effective_day" min="1" max="31"
                                   placeholder="1–31" class="field-input">
                        </div>
                    </div>
                    @if($isPolicy)
                    <div>
                        <label class="field-label">Language</label>
                        <div class="flex gap-3 mt-1">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="language" value="english" checked class="text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-slate-700 dark:text-slate-200">English only</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="language" value="hindi" class="text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-slate-700 dark:text-slate-200">Hindi only</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="language" value="both" class="text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-slate-700 dark:text-slate-200">Both</span>
                            </label>
                        </div>
                    </div>
                    @endif
                    <div>
                        <label class="field-label">Visibility</label>
                        <div class="flex gap-3 mt-1">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="visibility" value="public" checked class="text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-slate-700 dark:text-slate-200 flex items-center gap-1"><i class="ti ti-world text-sm text-green-500"></i> Public</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="visibility" value="authenticated" class="text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-slate-700 dark:text-slate-200 flex items-center gap-1"><i class="ti ti-lock text-sm text-amber-500"></i> Authenticated Only</span>
                            </label>
                        </div>
                    </div>
                    <div class="bg-slate-50 dark:bg-slate-800/60 rounded-lg px-4 py-3">
                        <p class="text-[10px] font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500 mb-1">Saving to</p>
                        <p class="text-xs text-indigo-600 dark:text-indigo-400 font-medium">
                            {{ Str::title(str_replace('_', ' ', $department->level)) }} › {{ $department->name }} › {{ $isPolicy ? 'Policy' : 'Rules' }} › {{ $ruleSet->name }}
                        </p>
                    </div>
                    <div class="flex items-center gap-3 mt-auto pt-2">
                        <button type="submit" id="amend-btn-submit"
                                class="inline-flex items-center gap-2 bg-amber-500 hover:bg-amber-600 text-white text-sm font-medium px-5 py-2.5 rounded-lg transition-colors">
                            <i class="ti ti-upload"></i>
                            <span id="amend-btn-label">Upload</span>
                        </button>
                        <span id="amend-upload-status" class="text-xs text-slate-400 dark:text-slate-500"></span>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endauth

{{-- ── Document hierarchy ────────────────────────────────────────────────── --}}
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">
    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between gap-4 flex-wrap">
        <div>
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Amendments &amp; Documents</h3>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
                {{ $totalCount }} {{ Str::plural('document', $totalCount) }}
                @guest · public only @endguest
                @if($filterYear) · filtered to {{ $filterYear }} @endif
            </p>
        </div>
        {{-- Sort / filter controls --}}
        @if($totalCount > 1)
        <form method="GET" action="{{ $showRoute }}"
              class="flex items-center gap-2 flex-wrap">
            <select name="sort" onchange="this.form.submit()"
                    class="text-xs border border-slate-200 dark:border-slate-700 rounded-lg px-2.5 py-1.5 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <option value="amendment_number_desc" @selected($sort === 'amendment_number_desc')>Amendment # ↓ newest first</option>
                <option value="amendment_number_asc"  @selected($sort === 'amendment_number_asc')>Amendment # ↑ oldest first</option>
                <option value="year_desc"             @selected($sort === 'year_desc')>Year ↓</option>
                <option value="year_asc"              @selected($sort === 'year_asc')>Year ↑</option>
                <option value="uploaded_desc"         @selected($sort === 'uploaded_desc')>Uploaded ↓</option>
                <option value="uploaded_asc"          @selected($sort === 'uploaded_asc')>Uploaded ↑</option>
            </select>
            @if($availableYears->isNotEmpty())
            <select name="year" onchange="this.form.submit()"
                    class="text-xs border border-slate-200 dark:border-slate-700 rounded-lg px-2.5 py-1.5 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <option value="">All years</option>
                @foreach($availableYears as $yr)
                <option value="{{ $yr }}" @selected($filterYear == $yr)>{{ $yr }}</option>
                @endforeach
            </select>
            @endif
            @if($filterYear)
            <a href="{{ $showRoute }}"
               class="text-xs text-slate-400 hover:text-red-500 dark:hover:text-red-400 transition-colors" title="Clear filter">
                <i class="ti ti-x"></i>
            </a>
            @endif
        </form>
        @endif
    </div>

    @if($rootDocuments->isEmpty() && $totalCount === 0)
    <div class="flex flex-col items-center justify-center py-16 text-center">
        <i class="ti ti-files text-3xl text-slate-200 dark:text-slate-600 mb-3"></i>
        <p class="text-sm text-slate-500 dark:text-slate-400">No documents yet</p>
        @auth
        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Use the buttons above to add documents or amendments.</p>
        @else
        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Documents will appear here once available.</p>
        @endauth
    </div>
    @else
    <div class="divide-y divide-slate-100 dark:divide-slate-700/60">
        @foreach($rootDocuments as $doc)

        {{-- Root document row --}}
        @include('rule_sets._doc_row', ['doc' => $doc, 'department' => $department, 'ruleSet' => $ruleSet, 'isAmendment' => false])

        {{-- Amendments indented beneath --}}
        @foreach($doc->amendments as $amendment)
        @include('rule_sets._doc_row', ['doc' => $amendment, 'department' => $department, 'ruleSet' => $ruleSet, 'isAmendment' => true])
        @endforeach

        @endforeach
    </div>
    @endif
</div>

@push('scripts')
<script>
// ── Shared upload helpers ──────────────────────────────────────────────────────
(function () {
    let page;
    try { page = JSON.parse(document.getElementById('page-data').textContent); }
    catch (e) { console.error('page-data parse failed', e); return; }

    function fileToTitle(name) {
        return name.replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' ').trim();
    }
    function badgeClass(state, accent) {
        const base = 'queue-status flex-shrink-0 text-[10px] px-1.5 py-0.5 rounded font-medium ';
        const map = {
            pending:   'bg-slate-100 dark:bg-slate-700 text-slate-400 dark:text-slate-500',
            uploading: 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400',
            done:      'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400',
            error:     'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
        };
        return base + (map[state] || map.pending);
    }
    function showErr(id, msg) {
        const el = document.getElementById(id);
        if (el) { el.textContent = msg; el.style.display = 'block'; }
    }
    function clearErr(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    }

    // Generic multi-file upload queue factory
    function makeQueue(ids) {
        const { fileInputId, dropZoneId, queueListId, queueCountId, queueWrapId, queueHintId,
                clearBtnId, formId, submitBtnId, submitLabelId, statusElId } = ids;

        const fileInput = document.getElementById(fileInputId);
        const dropZone  = document.getElementById(dropZoneId);
        const queueList = document.getElementById(queueListId);
        const countEl   = document.getElementById(queueCountId);
        const queueWrap = document.getElementById(queueWrapId);
        const queueHint = document.getElementById(queueHintId);
        const clearBtn  = document.getElementById(clearBtnId);
        const form      = document.getElementById(formId);
        const btnSubmit = document.getElementById(submitBtnId);
        const btnLabel  = document.getElementById(submitLabelId);
        const statusEl  = document.getElementById(statusElId);

        if (!fileInput || !form) return;

        let uploadFiles = [];
        let isUploading = false;

        function setRowStatus(item, state, msg) {
            item.statusBadge.className = badgeClass(state);
            const labels = { pending: 'Pending', uploading: 'Uploading…', done: '✓ Done' };
            item.statusBadge.textContent = state === 'error' ? ('✗ ' + (msg || 'Error')) : (labels[state] || state);
            if (state === 'done') item.row.style.opacity = '0.6';
        }

        function syncUI() {
            const n = uploadFiles.length;
            countEl.textContent = n;
            queueWrap.style.display = n ? 'flex' : 'none';
            queueHint.style.display = n ? 'none' : 'block';
            btnLabel.textContent = n > 1 ? ('Upload ' + n + ' files') : 'Upload';
            btnSubmit.disabled = n === 0 || isUploading;
        }

        function addFiles(files) {
            Array.from(files).forEach(file => {
                const row = document.createElement('div');
                row.className = 'queue-row flex items-start gap-2 p-2 rounded-lg bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700';
                const icon = document.createElement('i');
                icon.className = 'ti ti-file-text text-slate-400 dark:text-slate-500 flex-shrink-0 text-sm mt-1.5';
                const meta = document.createElement('div');
                meta.className = 'flex-1 min-w-0 flex flex-col gap-1';
                const titleLabel = document.createElement('label');
                titleLabel.className = 'text-[10px] font-medium text-slate-400 dark:text-slate-500 flex items-center gap-1';
                titleLabel.innerHTML = '<i class="ti ti-pencil text-[10px]"></i> Title (auto-filled — change if you like)';
                const titleInput = document.createElement('input');
                titleInput.type = 'text';
                titleInput.className = 'w-full text-xs font-medium text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-600 rounded-md px-2 py-1 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 outline-none';
                titleInput.value = fileToTitle(file.name);
                titleInput.placeholder = 'Document title';
                titleInput.maxLength = 255;
                meta.appendChild(titleLabel);
                const sizeLine = document.createElement('p');
                sizeLine.className = 'text-[10px] text-slate-400 dark:text-slate-500 truncate';
                sizeLine.textContent = (file.size / 1048576).toFixed(1) + ' MB · ' + file.name;
                meta.appendChild(titleInput);
                meta.appendChild(sizeLine);
                const statusBadge = document.createElement('span');
                statusBadge.className = badgeClass('pending');
                statusBadge.textContent = 'Pending';
                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'flex-shrink-0 text-slate-300 dark:text-slate-600 hover:text-red-400 transition-colors mt-1';
                removeBtn.innerHTML = '<i class="ti ti-x text-xs"></i>';
                row.appendChild(icon); row.appendChild(meta);
                row.appendChild(statusBadge); row.appendChild(removeBtn);
                queueList.appendChild(row);
                const item = { file, titleInput, statusBadge, row };
                uploadFiles.push(item);
                removeBtn.addEventListener('click', () => {
                    if (isUploading) return;
                    row.remove();
                    uploadFiles.splice(uploadFiles.indexOf(item), 1);
                    syncUI();
                });
            });
            syncUI();
        }

        fileInput.addEventListener('change', () => {
            if (fileInput.files.length) addFiles(fileInput.files);
            fileInput.value = '';
        });
        dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.style.borderColor = '#6366f1'; });
        dropZone.addEventListener('dragleave', () => { dropZone.style.borderColor = ''; });
        dropZone.addEventListener('drop', e => {
            e.preventDefault(); dropZone.style.borderColor = '';
            if (e.dataTransfer.files.length) addFiles(e.dataTransfer.files);
        });
        if (clearBtn) clearBtn.addEventListener('click', () => {
            if (isUploading) return;
            uploadFiles = []; queueList.innerHTML = ''; syncUI();
        });

        form.addEventListener('submit', async e => {
            e.preventDefault();
            if (isUploading || uploadFiles.length === 0) return;

            // Per-modal validation hooks (set by caller)
            if (ids.validate && !ids.validate()) return;

            const contextInput      = form.querySelector('[name="rule_set_id"]');
            const typeInput         = form.querySelector('[name="document_type"]');
            const parentInput       = form.querySelector('[name="parent_id"]');
            const visibility        = form.querySelector('[name="visibility"]:checked')?.value || 'public';
            const language          = form.querySelector('[name="language"]:checked')?.value || 'english';
            const amendmentNumber   = form.querySelector('[name="amendment_number"]')?.value?.trim() || '';
            const effectiveYear     = form.querySelector('[name="effective_year"]')?.value?.trim()   || '';
            const effectiveMonth    = form.querySelector('[name="effective_month"]')?.value          || '';
            const effectiveDay      = form.querySelector('[name="effective_day"]')?.value?.trim()    || '';

            isUploading = true;
            btnSubmit.disabled = true;
            statusEl.textContent = '';
            btnSubmit.onclick = null;

            let doneCount = 0, errorCount = 0, lastRedirect = null;

            for (let i = 0; i < uploadFiles.length; i++) {
                const item = uploadFiles[i];
                const title = item.titleInput.value.trim();
                if (!title) { setRowStatus(item, 'error', 'Title required'); errorCount++; continue; }

                setRowStatus(item, 'uploading');
                statusEl.textContent = 'Uploading ' + (i + 1) + ' of ' + uploadFiles.length + '…';

                try {
                    const fd = new FormData();
                    fd.append('_token', page.csrfToken);
                    if (contextInput) fd.append(contextInput.name, contextInput.value);
                    fd.append('title', title);
                    fd.append('document_type', typeInput ? typeInput.value : '');
                    fd.append('visibility', visibility);
                    fd.append('language', language);
                    if (parentInput && parentInput.value) fd.append('parent_id', parentInput.value);
                    if (amendmentNumber) fd.append('amendment_number', amendmentNumber);
                    if (effectiveYear)   fd.append('effective_year',   effectiveYear);
                    if (effectiveMonth)  fd.append('effective_month',  effectiveMonth);
                    if (effectiveDay)    fd.append('effective_day',    effectiveDay);
                    fd.append('file', item.file);

                    const res = await fetch(page.storeUrl, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': page.csrfToken },
                        body: fd,
                    });
                    const ct = res.headers.get('Content-Type') || '';
                    if (!ct.includes('application/json')) {
                        if (res.status === 419) throw new Error('Session expired — refresh and try again');
                        throw new Error('HTTP ' + res.status);
                    }
                    const json = await res.json();
                    if (!res.ok) {
                        setRowStatus(item, 'error', json.errors ? Object.values(json.errors).flat()[0] : (json.message || 'Upload failed'));
                        errorCount++; continue;
                    }
                    setRowStatus(item, 'done');
                    doneCount++;
                    lastRedirect = json.redirect;
                } catch (err) {
                    setRowStatus(item, 'error', err.message);
                    errorCount++;
                    console.error('Upload error:', item.file.name, err);
                }
            }

            isUploading = false;
            if (errorCount === 0 && lastRedirect) { window.location.href = lastRedirect; return; }
            if (doneCount > 0 && lastRedirect) {
                statusEl.textContent = doneCount + ' uploaded, ' + errorCount + ' failed.';
                btnSubmit.disabled = false; btnLabel.textContent = 'Go to page';
                btnSubmit.onclick = ev => { ev.preventDefault(); window.location.href = lastRedirect; };
            } else {
                statusEl.textContent = doneCount + ' uploaded, ' + errorCount + ' failed.';
                btnSubmit.disabled = false;
                btnLabel.textContent = errorCount > 0 ? ('Retry (' + errorCount + ' failed)') : 'Upload';
            }
            syncUI();
        });
    }

    // ── Init Rule modal ───────────────────────────────────────────────────────
    makeQueue({
        fileInputId:   'rule-file',
        dropZoneId:    'rule-drop-zone',
        queueListId:   'rule-queue',
        queueCountId:  'rule-queue-count',
        queueWrapId:   'rule-queue-wrap',
        queueHintId:   'rule-queue-hint',
        clearBtnId:    'rule-btn-clear',
        formId:        'rule-form',
        submitBtnId:   'rule-btn-submit',
        submitLabelId: 'rule-btn-label',
        statusElId:    'rule-upload-status',
        validate: function () {
            const typeEl = document.getElementById('rule-type');
            if (!typeEl || !typeEl.value) {
                showErr('rule-err-type', 'Select a document type.');
                return false;
            }
            clearErr('rule-err-type');
            return true;
        },
    });

    // ── Init Amendment modal ─────────────────────────────────────────────────
    // Populate parent dropdown from server-side data island
    const amendParent = document.getElementById('amend-parent');
    if (amendParent && page.parentOptions && page.parentOptions.length > 0) {
        page.parentOptions.forEach(function (opt) {
            const el = document.createElement('option');
            el.value = opt.id;
            el.textContent = opt.title + ' (' + opt.date + ')';
            amendParent.appendChild(el);
        });
        // Pre-select the first (and usually only) root rule doc
        if (page.parentOptions.length === 1) {
            amendParent.value = page.parentOptions[0].id;
        }
    }

    makeQueue({
        fileInputId:   'amend-file',
        dropZoneId:    'amend-drop-zone',
        queueListId:   'amend-queue',
        queueCountId:  'amend-queue-count',
        queueWrapId:   'amend-queue-wrap',
        queueHintId:   'amend-queue-hint',
        clearBtnId:    'amend-btn-clear',
        formId:        'amend-form',
        submitBtnId:   'amend-btn-submit',
        submitLabelId: 'amend-btn-label',
        statusElId:    'amend-upload-status',
        validate: function () {
            const parentEl = document.getElementById('amend-parent');
            if (!parentEl || !parentEl.value) {
                showErr('amend-err-parent', 'Select the document this amendment modifies.');
                return false;
            }
            clearErr('amend-err-parent');
            return true;
        },
    });

    // Close modals on Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            ['modal-rule', 'modal-amendment'].forEach(id => {
                const m = document.getElementById(id);
                if (m) m.style.display = 'none';
            });
        }
    });
})();
</script>

<script>
(function () {
    // ── Delete rule set ───────────────────────────────────────────────────────
    const deleteRuleSetBtn = document.getElementById('delete-ruleset-btn');
    if (deleteRuleSetBtn) {
        deleteRuleSetBtn.addEventListener('click', function () {
            const isDark = document.documentElement.classList.contains('dark');
            const docCount = {{ $totalCount }};
            Swal.fire({
                title: 'Delete {{ $isPolicy ? "Policy" : "Rule Set" }}?',
                html: '<p class="text-sm mb-2">You are about to delete <strong>{{ e($ruleSet->name) }}</strong>.</p>'
                    + (docCount > 0
                        ? '<p class="text-sm text-red-500">This will also move <strong>' + docCount + ' document(s)</strong> to trash.</p>'
                        : '<p class="text-sm text-gray-400">No documents are associated with this container.</p>'),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, delete it',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc2626',
                background: isDark ? '#1e293b' : '#ffffff',
                color: isDark ? '#f1f5f9' : '#0f172a',
            }).then(function (result) {
                if (result.isConfirmed) {
                    document.getElementById('delete-ruleset-form').submit();
                }
            });
        });
    }
})();
</script>

<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
        || document.querySelector('input[name="_token"]')?.value || '';

    function esc(str) {
        return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    document.querySelectorAll('.doc-delete-btn').forEach(btn => {
        btn.addEventListener('click', async function () {
            const action = this.dataset.action;
            const title  = this.dataset.title;
            const dark   = document.documentElement.classList.contains('dark');

            const { value: reason, isConfirmed } = await Swal.fire({
                title: 'Move to Trash',
                html: `<p class="text-sm mb-3">Moving <strong>${esc(title)}</strong> to trash.</p>
                       <textarea id="swal-reason" class="swal2-textarea" placeholder="Reason for deletion (required)" rows="3" style="resize:vertical"></textarea>`,
                showCancelButton: true,
                confirmButtonText: 'Move to Trash',
                confirmButtonColor: '#dc2626',
                cancelButtonText: 'Cancel',
                background: dark ? '#1e293b' : '#fff',
                color: dark ? '#f1f5f9' : '#1e293b',
                preConfirm: () => {
                    const r = document.getElementById('swal-reason').value.trim();
                    if (!r || r.length < 5) {
                        Swal.showValidationMessage('Please enter a reason (at least 5 characters).');
                        return false;
                    }
                    if (r.length > 500) {
                        Swal.showValidationMessage('Reason must be 500 characters or fewer.');
                        return false;
                    }
                    return r;
                },
            });

            if (!isConfirmed || !reason) return;

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = action;
            form.style.display = 'none';
            form.innerHTML = `<input name="_token" value="${csrfToken}"><input name="_method" value="DELETE"><input name="reason" value="${reason.replace(/"/g, '&quot;')}">`;
            document.body.appendChild(form);
            form.submit();
        });
    });
})();

// ── Live status pills for in-flight documents (processing/OCR) — same polling pattern as
// documents/pipeline.blade.php's per-row polling, reused verbatim so this page's badges stop
// needing a manual refresh too.
(function () {
    const statusColorClasses = {
        slate:  'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400',
        blue:   'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400',
        amber:  'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400',
        indigo: 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400',
        green:  'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400',
        red:    'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
    };
    const iconClasses = {
        slate:  { wrap: 'bg-slate-100 dark:bg-slate-700', icon: 'text-slate-400 dark:text-slate-500' },
        blue:   { wrap: 'bg-slate-100 dark:bg-slate-700', icon: 'text-slate-400 dark:text-slate-500' },
        amber:  { wrap: 'bg-slate-100 dark:bg-slate-700', icon: 'text-slate-400 dark:text-slate-500' },
        indigo: { wrap: 'bg-indigo-500/10 dark:bg-indigo-500/20', icon: 'text-indigo-500 dark:text-indigo-400' },
        green:  { wrap: 'bg-green-500/10 dark:bg-green-500/20', icon: 'text-green-500 dark:text-green-400' },
        red:    { wrap: 'bg-red-500/10 dark:bg-red-500/20', icon: 'text-red-500 dark:text-red-400' },
    };
    const statusMeta = {
        uploaded:    { label: 'Uploaded',    color: 'slate'  },
        processing:  { label: 'Processing',  color: 'blue'   },
        ocr_pending: { label: 'OCR Pending', color: 'amber'  },
        review:      { label: 'Review',      color: 'indigo' },
        verified:    { label: 'Verified',    color: 'green'  },
        failed:      { label: 'Failed',      color: 'red'    },
        rejected:    { label: 'Rejected',    color: 'red'    },
    };

    const pollRows = document.querySelectorAll('[data-poll="1"]');
    if (!pollRows.length) return;

    const interval = setInterval(() => {
        let stillPolling = false;
        const checks = Array.from(pollRows).map(row => {
            const id = row.dataset.docRow;
            return fetch(`/documents/${id}/convert-status`, { headers: { Accept: 'application/json' } })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'processing' || data.status === 'ocr_pending') {
                        stillPolling = true;
                        return;
                    }
                    const meta = statusMeta[data.status] || { label: data.status, color: 'slate' };
                    const badge = row.querySelector('.doc-status-badge');
                    if (badge) {
                        badge.className = 'doc-status-badge inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium ' + statusColorClasses[meta.color];
                        badge.textContent = meta.label;
                    }
                    const iconWrap = row.querySelector('.doc-status-icon-wrap');
                    const icon = row.querySelector('.doc-status-icon');
                    const colors = iconClasses[meta.color] || iconClasses.slate;
                    if (iconWrap) iconWrap.className = 'doc-status-icon-wrap w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5 ' + colors.wrap;
                    if (icon) icon.className = 'doc-status-icon ti ti-file-text text-sm ' + colors.icon;
                })
                .catch(() => {});
        });
        Promise.all(checks).then(() => {
            if (!stillPolling) {
                clearInterval(interval);
                window.location.reload();
            }
        });
    }, 5000);
})();
</script>
@endpush

</x-layout>
