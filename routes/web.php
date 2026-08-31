<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\DesignationController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\OnboardingController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DivisionController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\FrontendController;
use App\Http\Controllers\PolicyDocumentController;
use App\Http\Controllers\QuickConversionController;
use App\Http\Controllers\RuleSetController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SectionController;
use Illuminate\Support\Facades\Route;

// ── Auth shortcuts ────────────────────────────────────────────────────────────

// /admin with no sub-path → land on users list (auth middleware redirects to /login if needed)
Route::get('/admin', function () {
    return redirect()->route('admin.users.index');
})->middleware('auth')->name('admin');

// ── Login (custom — replaces Fortify's own login routes, see FortifyServiceProvider) ──────────
// Password step never calls Auth::login() directly; it only ever unlocks the OTP step. No user
// is authenticated until POST /login/otp/verify succeeds, so no extra "half-logged-in" gate is
// needed on protected routes — plain `auth` middleware already covers this gap by construction.
Route::get('/login',              [LoginController::class, 'showLogin'])->middleware('guest')->name('login');
Route::post('/login',             [LoginController::class, 'login'])->middleware(['guest', 'throttle:login'])->name('login.attempt');
Route::get('/login/otp',          [LoginController::class, 'showOtp'])->middleware('guest')->name('otp.show');
Route::post('/login/otp/verify',  [LoginController::class, 'verifyOtp'])->middleware(['guest', 'throttle:two-factor'])->name('otp.verify');
Route::post('/login/otp/resend',  [LoginController::class, 'resendOtp'])->middleware(['guest', 'throttle:two-factor'])->name('otp.resend');
Route::post('/logout',            [LoginController::class, 'logout'])->middleware('auth')->name('logout');

// ── Forgot / reset password (Laravel's core Password broker — password_reset_tokens table,
// single-use hashed token, 60-min expiry — with our own branded views/mail instead of
// Fortify's). Never auto-authenticates on success; lands back on /login to re-enter the
// normal email + password + OTP flow. ─────────────────────────────────────────────────────
Route::get('/forgot-password',    [ForgotPasswordController::class, 'showRequestForm'])->middleware('guest')->name('password.request');
Route::post('/forgot-password',   [ForgotPasswordController::class, 'sendResetLink'])->middleware(['guest', 'throttle:password-reset'])->name('password.email');
Route::get('/reset-password/{token}', [ForgotPasswordController::class, 'showResetForm'])->middleware('guest')->name('password.reset');
Route::post('/reset-password',    [ForgotPasswordController::class, 'reset'])->middleware(['guest', 'throttle:password-reset'])->name('password.update');

// ── Onboarding (signed, single-use link mailed on admin-created accounts) ─────────────────────
// Explicitly bound by id, not the app-wide username route key (User::getRouteKeyName()) — this
// URL is generated as a signed link with the numeric id (UserManagementController::sendOnboardingLink()),
// not browsed/typed, so it must resolve on id regardless of the global slug convention.
Route::get('/onboarding/{user:id}',  [OnboardingController::class, 'show'])->middleware('signed')->name('onboarding.show');
Route::post('/onboarding/{user:id}', [OnboardingController::class, 'store'])->middleware(['signed', 'throttle:login'])->name('onboarding.store');

// ── Public ────────────────────────────────────────────────────────────────────

Route::get('/', [FrontendController::class, 'dashboard'])->name('home');

Route::get('/sitemap.xml', [\App\Http\Controllers\SitemapController::class, 'index'])->name('sitemap');

Route::get('/search', [SearchController::class, 'index'])->name('search.index');

// Documents — read-only browse is public
// Hierarchical URLs: /documents/{level}/{department}/{section}/{document}
// {level} = 'dept' (department_level) | 'sectt' (secretariat_level)
Route::prefix('documents')->name('documents.')->group(function () {
    Route::get('/', [DocumentController::class, 'index'])->name('index');
    // Page-1 thumbnail for social share previews (WhatsApp/Slack/etc.) — gated on the
    // document's own visibility inside the controller, not this route, since the URL
    // itself has to be reachable unauthenticated for a crawler to fetch it at all.
    Route::get('/{document}/og-image.jpg', [DocumentController::class, 'ogImage'])->name('og-image');
    // Section-based documents (direct — no division)
    Route::get('/{level}/{department}/{section}/{document}',     [DocumentController::class, 'show'])->name('show');
    Route::get('/{level}/{department}/{section}/{document}/pdf', [DocumentController::class, 'pdf'])->name('pdf');
    // Division-based documents
    Route::prefix('/{level}/{department}/{section}/divisions/{division}')->name('divisions.')->group(function () {
        Route::get('/{document}',     [DocumentController::class, 'showDivisionDoc'])->name('show');
        Route::get('/{document}/pdf', [DocumentController::class, 'pdfDivisionDoc'])->name('pdf');
    });
    // Rule-set-based documents
    Route::prefix('/{level}/{department}/rules/{rule_set}')->name('rules.')->group(function () {
        Route::get('/{document}',     [DocumentController::class, 'showRuleSetDoc'])->name('show')->defaults('kind', 'rules');
        Route::get('/{document}/pdf', [DocumentController::class, 'pdfRuleSetDoc'])->name('pdf')->defaults('kind', 'rules');
    });
    // Policy-based documents (same controller methods as rule-set docs — RuleSet.kind discriminates)
    Route::prefix('/{level}/{department}/policy/{rule_set}')->name('policy.')->group(function () {
        Route::get('/{document}',     [DocumentController::class, 'showRuleSetDoc'])->name('show')->defaults('kind', 'policy');
        Route::get('/{document}/pdf', [DocumentController::class, 'pdfRuleSetDoc'])->name('pdf')->defaults('kind', 'policy');
    });
    // Section-folder documents
    Route::prefix('/{level}/{department}/{section}/folders/{folder}')->name('folders.')->group(function () {
        Route::get('/{document}',     [DocumentController::class, 'showSectionFolderDoc'])->name('show');
        Route::get('/{document}/pdf', [DocumentController::class, 'pdfSectionFolderDoc'])->name('pdf');
    });
    // Division-folder documents
    Route::prefix('/{level}/{department}/{section}/divisions/{division}/folders/{folder}')->name('divisions.folders.')->group(function () {
        Route::get('/{document}',     [DocumentController::class, 'showDivisionFolderDoc'])->name('show');
        Route::get('/{document}/pdf', [DocumentController::class, 'pdfDivisionFolderDoc'])->name('pdf');
    });
});

// Departments & sections — read-only public
// {level} = 'dept' | 'sectt' disambiguates departments that share a slug across levels
Route::prefix('departments')->name('departments.')->group(function () {
    Route::get('/',        [DepartmentController::class, 'index'])->name('index');
    // /create must be before /{level}/{department} — no collision risk since /create has only one segment
    Route::get('/create',  [DepartmentController::class, 'create'])->name('create')->middleware(['auth', 'throttle:mutations']);
    Route::get('/{level}/{department}', [DepartmentController::class, 'show'])->name('show');
    // Entire department as one zip (all sections/divisions/folders + rule sets + policy).
    Route::get('/{level}/{department}/download', [DownloadController::class, 'department'])->name('download');
    // Government Orders — cross-cutting view of document_type='go' across every section/division/
    // folder/rule-set in the department (GOs aren't a separate entity, they're scattered Document
    // rows tagged by type — same reasoning as the search page's document_type filter).
    Route::get('/{level}/{department}/government-orders', [DepartmentController::class, 'governmentOrders'])->name('government-orders');

    Route::prefix('/{level}/{department}/sections')->name('sections.')->group(function () {
        Route::get('/',          [SectionController::class, 'index'])->name('index');
        Route::get('/create',    [SectionController::class, 'create'])->name('create')->middleware(['auth', 'throttle:mutations']);
        Route::get('/{section}', [SectionController::class, 'show'])->name('show');
        Route::get('/{section}/download', [DownloadController::class, 'section'])->name('download');
        // Internal divisions — public show only
        Route::prefix('/{section}/divisions')->name('divisions.')->group(function () {
            Route::get('/create',     [DivisionController::class, 'create'])->name('create')->middleware(['auth', 'throttle:mutations']);
            Route::get('/{division}', [DivisionController::class, 'show'])->name('show');
            Route::get('/{division}/download', [DownloadController::class, 'division'])->name('download');
            // Division folders — public show only
            Route::prefix('/{division}/folders')->name('folders.')->group(function () {
                Route::get('/create',   [FolderController::class, 'createForDivision'])->name('create')->middleware(['auth', 'throttle:mutations']);
                Route::get('/{folder}', [FolderController::class, 'showForDivision'])->name('show');
                Route::get('/{folder}/download', [DownloadController::class, 'divisionFolder'])->name('download');
                // Subfolders — one level deep. Show/edit/download reuse the routes above,
                // since a subfolder is bound as {folder} the same as a root folder.
                Route::get('/{folder}/subfolders/create', [FolderController::class, 'createSubfolderForDivision'])->name('subfolders.create')->middleware(['auth', 'throttle:mutations']);
            });
        });
        // Section folders — public show only
        Route::prefix('/{section}/folders')->name('folders.')->group(function () {
            Route::get('/create',   [FolderController::class, 'create'])->name('create')->middleware(['auth', 'throttle:mutations']);
            Route::get('/{folder}', [FolderController::class, 'show'])->name('show');
            Route::get('/{folder}/download', [DownloadController::class, 'folder'])->name('download');
            // Subfolders — one level deep. Show/edit/download reuse the routes above.
            Route::get('/{folder}/subfolders/create', [FolderController::class, 'createSubfolder'])->name('subfolders.create')->middleware(['auth', 'throttle:mutations']);
        });
    });

    Route::prefix('/{level}/{department}/rules')->name('rules.')->group(function () {
        Route::get('/',            [RuleSetController::class, 'index'])->name('index')->defaults('kind', 'rules');
        Route::get('/create',     [RuleSetController::class, 'create'])->name('create')->middleware(['auth', 'throttle:mutations'])->defaults('kind', 'rules');
        // Must be registered before /{rule_set} — otherwise route-model-binding would try to
        // resolve "download-all" as a rule set slug and 404.
        Route::get('/download-all', [DownloadController::class, 'rules'])->name('download-all');
        Route::get('/{rule_set}', [RuleSetController::class, 'show'])->name('show')->defaults('kind', 'rules');
        Route::get('/{rule_set}/download', [DownloadController::class, 'ruleSet'])->name('download');
    });
    // Policy — department-level only, available to every department (existing or future)
    Route::prefix('/{level}/{department}/policy')->name('policy.')->group(function () {
        Route::get('/',            [RuleSetController::class, 'index'])->name('index')->defaults('kind', 'policy');
        Route::get('/create',     [RuleSetController::class, 'create'])->name('create')->middleware(['auth', 'throttle:mutations'])->defaults('kind', 'policy');
        // Must be registered before /{rule_set} — otherwise route-model-binding would try to
        // resolve "state"/"other-states" as a policy container slug and 404.
        Route::get('/other-states',    [RuleSetController::class, 'policyOtherStates'])->name('other-states');
        Route::get('/state/{state}',   [RuleSetController::class, 'policyState'])->name('state');
        Route::get('/state/{state}/download', [DownloadController::class, 'policyState'])->name('state.download');
        Route::get('/{rule_set}', [RuleSetController::class, 'show'])->name('show')->defaults('kind', 'policy');
        Route::get('/{rule_set}/download', [DownloadController::class, 'ruleSet'])->name('download');
    });
    // Policy documents (e.g. "Excise Policy 2024-25", "Excise Policy 2025-26") — yearly
    // documents under a policy container. URI/route-name segment stays "periods" (the
    // timeframe each one covers), the entity itself is a policy document, not "a period".
    Route::prefix('/{level}/{department}/policy/{policy}/periods')->name('policy.periods.')->group(function () {
        Route::get('/create',    [PolicyDocumentController::class, 'create'])->name('create')->middleware(['auth', 'throttle:mutations']);
        Route::get('/{policyDoc}',  [PolicyDocumentController::class, 'show'])->name('show');
        Route::get('/{policyDoc}/download', [DownloadController::class, 'policyPeriod'])->name('download');
    });
});

// ── Auth-protected reads (status polling / listing pages) ──────────────────────
// throttle:reads = 600/min/user (defined in AppServiceProvider) — separate from
// throttle:mutations so the pipeline monitor's 5s-interval convert-status polling,
// and viewers just watching a bulk run, never compete with the mutation cap.

Route::middleware(['auth', 'throttle:reads'])->prefix('documents')->name('documents.')->group(function () {
    // Lets the bulk/folder upload modals recover from a stale CSRF token mid-batch instead
    // of 419-ing on every remaining file — see public/js/resilient-upload.js.
    Route::get('/csrf-token',          fn () => response()->json(['token' => csrf_token()]))->name('csrf-token');
    Route::get('/bulk-upload',         [DocumentController::class, 'bulkUploadForm'])->name('bulk-upload');
    Route::get('/pipeline',            [DocumentController::class, 'pipeline'])->name('pipeline');
    // Lists files still queued in this browser's IndexedDB from an upload batch abandoned
    // mid-way (e.g. navigated off the page) — read entirely client-side, see resilient-upload.js
    // and the header's pending-uploads indicator. No server data of its own, so no controller.
    Route::view('/my-uploads', 'documents.my-uploads')->name('my-uploads.index');
    Route::get('/pipeline/health',     [DocumentController::class, 'pipelineHealth'])->name('pipeline.health')->middleware('is_admin');
    Route::get('/trash',               [DocumentController::class, 'trash'])->name('trash');
    Route::get('/trash/{id}/pdf',      [DocumentController::class, 'trashedPdf'])->name('trashed.pdf');
    Route::get('/{id}/convert-status', [DocumentController::class, 'conversionStatus'])->where('id', '[0-9]+')->name('convert-status');
    Route::get('/{id}/structure',      [DocumentController::class, 'structureJson'])->where('id', '[0-9]+')->name('structure');
});

// ── Standalone "New Conversion" — upload & convert without picking a destination first ────────
// See NEW_CONVERSION_PLAN.md. Auth-only, same as the rest of the conversion pipeline — public
// visitors never see this, only Documents explicitly marked public on upload.
Route::middleware(['auth', 'throttle:reads'])->prefix('conversions')->name('conversions.')->group(function () {
    Route::get('/',                   [QuickConversionController::class, 'index'])->name('index');
    Route::get('/new',                [QuickConversionController::class, 'create'])->name('create');
    Route::get('/{quickConversion}',          [QuickConversionController::class, 'show'])->name('show');
    Route::get('/{quickConversion}/status',   [QuickConversionController::class, 'status'])->name('status');
    Route::get('/{quickConversion}/download', [QuickConversionController::class, 'download'])->name('download');
});

// ── Auth-protected mutations ──────────────────────────────────────────────────
// throttle:mutations = 60 state-changing requests/minute/user (defined in AppServiceProvider)

Route::middleware(['auth', 'throttle:mutations'])->group(function () {

    // Documents — mutations
    Route::prefix('documents')->name('documents.')->group(function () {
        Route::post('/bulk-destroy',               [DocumentController::class, 'bulkDestroy'])->name('bulk-destroy');
        Route::post('/trash/bulk-restore',         [DocumentController::class, 'bulkRestore'])->name('trash.bulk-restore');
        Route::delete('/trash/bulk-force-destroy', [DocumentController::class, 'bulkForceDestroy'])->name('trash.bulk-force-destroy');
        Route::post('/trash/{id}/restore',         [DocumentController::class, 'restore'])->name('restore');
        Route::delete('/trash/{id}',               [DocumentController::class, 'forceDestroy'])->name('force-destroy');
        // Markdown conversion — numeric ID, applies across all five document contexts
        Route::post('/{id}/convert',               [DocumentController::class, 'convert'])->where('id', '[0-9]+')->name('convert');
        Route::post('/{id}/convert-ocr',           [DocumentController::class, 'convertOcr'])->where('id', '[0-9]+')->name('convert-ocr');
        Route::post('/{id}/revert-ocr',            [DocumentController::class, 'revertOcr'])->where('id', '[0-9]+')->name('revert-ocr');
        Route::patch('/{id}/markdown',             [DocumentController::class, 'updateMarkdown'])->where('id', '[0-9]+')->name('markdown.update');
        Route::post('/{id}/verify',                [DocumentController::class, 'verify'])->where('id', '[0-9]+')->name('verify');
        Route::delete('/{id}/markdown',            [DocumentController::class, 'discardMarkdown'])->where('id', '[0-9]+')->name('markdown.discard');
        // Move/copy to another section/division/folder — same department only (see guardMovable()).
        Route::post('/{id}/move',                  [DocumentController::class, 'move'])->where('id', '[0-9]+')->name('move');
        Route::post('/{id}/copy',                  [DocumentController::class, 'copy'])->where('id', '[0-9]+')->name('copy');
        Route::post('/', [DocumentController::class, 'store'])->name('store')->middleware('throttle:uploads');
        // One piece of a client-split large PDF — see StoreDocumentChunkRequest.
        Route::post('/chunk', [DocumentController::class, 'storeChunk'])->name('store-chunk')->middleware('throttle:uploads');
        Route::get('/{level}/{department}/{section}/{document}/review', [DocumentController::class, 'edit'])->name('edit');
        Route::patch('/{level}/{department}/{section}/{document}',      [DocumentController::class, 'update'])->name('update');
        Route::delete('/{level}/{department}/{section}/{document}',     [DocumentController::class, 'destroy'])->name('destroy');
        // Rule-set document mutations
        Route::prefix('/{level}/{department}/rules/{rule_set}')->name('rules.')->group(function () {
            Route::get('/{document}/review', [DocumentController::class, 'editRuleSetDoc'])->name('edit')->defaults('kind', 'rules');
            Route::patch('/{document}',      [DocumentController::class, 'updateRuleSetDoc'])->name('update')->defaults('kind', 'rules');
            Route::delete('/{document}',     [DocumentController::class, 'destroyRuleSetDoc'])->name('destroy')->defaults('kind', 'rules');
        });
        // Policy document mutations (same controller methods as rule-set docs)
        Route::prefix('/{level}/{department}/policy/{rule_set}')->name('policy.')->group(function () {
            Route::get('/{document}/review', [DocumentController::class, 'editRuleSetDoc'])->name('edit')->defaults('kind', 'policy');
            Route::patch('/{document}',      [DocumentController::class, 'updateRuleSetDoc'])->name('update')->defaults('kind', 'policy');
            Route::delete('/{document}',     [DocumentController::class, 'destroyRuleSetDoc'])->name('destroy')->defaults('kind', 'policy');
        });
        // Division document mutations
        Route::prefix('/{level}/{department}/{section}/divisions/{division}')->name('divisions.')->group(function () {
            Route::get('/{document}/review', [DocumentController::class, 'editDivisionDoc'])->name('edit');
            Route::patch('/{document}',      [DocumentController::class, 'updateDivisionDoc'])->name('update');
            Route::delete('/{document}',     [DocumentController::class, 'destroyDivisionDoc'])->name('destroy');
        });
        // Section-folder document mutations
        Route::prefix('/{level}/{department}/{section}/folders/{folder}')->name('folders.')->group(function () {
            Route::get('/{document}/review', [DocumentController::class, 'editSectionFolderDoc'])->name('edit');
            Route::patch('/{document}',      [DocumentController::class, 'updateSectionFolderDoc'])->name('update');
            Route::delete('/{document}',     [DocumentController::class, 'destroySectionFolderDoc'])->name('destroy');
        });
        // Division-folder document mutations
        Route::prefix('/{level}/{department}/{section}/divisions/{division}/folders/{folder}')->name('divisions.folders.')->group(function () {
            Route::get('/{document}/review', [DocumentController::class, 'editDivisionFolderDoc'])->name('edit');
            Route::patch('/{document}',      [DocumentController::class, 'updateDivisionFolderDoc'])->name('update');
            Route::delete('/{document}',     [DocumentController::class, 'destroyDivisionFolderDoc'])->name('destroy');
        });
    });

    // Standalone "New Conversion" — mutations
    Route::prefix('conversions')->name('conversions.')->group(function () {
        Route::post('/', [QuickConversionController::class, 'store'])->name('store')->middleware('throttle:uploads');
        Route::post('/{quickConversion}/ocr',      [QuickConversionController::class, 'runOcr'])->name('ocr');
        Route::patch('/{quickConversion}',         [QuickConversionController::class, 'updateMarkdown'])->name('update');
        Route::post('/{quickConversion}/place',    [QuickConversionController::class, 'place'])->name('place');
        Route::delete('/{quickConversion}',        [QuickConversionController::class, 'destroy'])->name('destroy');
    });

    // Departments — mutations
    Route::prefix('departments')->name('departments.')->group(function () {
        Route::post('/',                        [DepartmentController::class, 'store'])->name('store');
        Route::get('/{level}/{department}/edit', [DepartmentController::class, 'edit'])->name('edit');
        Route::patch('/{level}/{department}',    [DepartmentController::class, 'update'])->name('update');
        Route::delete('/{level}/{department}',   [DepartmentController::class, 'destroy'])->name('destroy');

        // Sections — mutations
        Route::prefix('/{level}/{department}/sections')->name('sections.')->group(function () {
            Route::post('/',               [SectionController::class, 'store'])->name('store');
            Route::get('/{section}/edit',  [SectionController::class, 'edit'])->name('edit');
            Route::patch('/{section}',     [SectionController::class, 'update'])->name('update');
            Route::delete('/{section}',    [SectionController::class, 'destroy'])->name('destroy');

            // Internal divisions — mutations (admin only — enforced in Form Request authorize())
            Route::prefix('/{section}/divisions')->name('divisions.')->group(function () {
                Route::post('/',                [DivisionController::class, 'store'])->name('store');
                Route::get('/{division}/edit',  [DivisionController::class, 'edit'])->name('edit');
                Route::patch('/{division}',     [DivisionController::class, 'update'])->name('update');
                Route::delete('/{division}',    [DivisionController::class, 'destroy'])->name('destroy');

                // Division folders — mutations (scope enforced in Form Request authorize())
                Route::prefix('/{division}/folders')->name('folders.')->group(function () {
                    Route::post('/',              [FolderController::class, 'storeForDivision'])->name('store');
                    Route::get('/{folder}/edit',  [FolderController::class, 'editForDivision'])->name('edit');
                    Route::patch('/{folder}',     [FolderController::class, 'updateForDivision'])->name('update');
                    Route::delete('/{folder}',    [FolderController::class, 'destroyForDivision'])->name('destroy');
                    // Subfolders — edit/update/destroy reuse the routes above.
                    Route::post('/{folder}/subfolders', [FolderController::class, 'storeSubfolderForDivision'])->name('subfolders.store');
                    Route::post('/{folder}/convert-all', [DocumentController::class, 'convertFolderForDivision'])->name('convert-all');
                });
            });

            // Section folders — mutations (scope enforced in Form Request authorize())
            Route::prefix('/{section}/folders')->name('folders.')->group(function () {
                Route::post('/',              [FolderController::class, 'store'])->name('store');
                Route::get('/{folder}/edit',  [FolderController::class, 'edit'])->name('edit');
                Route::patch('/{folder}',     [FolderController::class, 'update'])->name('update');
                Route::delete('/{folder}',    [FolderController::class, 'destroy'])->name('destroy');
                // Subfolders — edit/update/destroy reuse the routes above.
                Route::post('/{folder}/subfolders', [FolderController::class, 'storeSubfolder'])->name('subfolders.store');
                Route::post('/{folder}/convert-all', [DocumentController::class, 'convertFolder'])->name('convert-all');
            });
        });

        // Rule sets — mutations (admin only — enforced in Form Request authorize())
        Route::prefix('/{level}/{department}/rules')->name('rules.')->group(function () {
            Route::post('/',               [RuleSetController::class, 'store'])->name('store')->defaults('kind', 'rules');
            Route::get('/{rule_set}/edit', [RuleSetController::class, 'edit'])->name('edit')->defaults('kind', 'rules');
            Route::patch('/{rule_set}',    [RuleSetController::class, 'update'])->name('update')->defaults('kind', 'rules');
            Route::delete('/{rule_set}',   [RuleSetController::class, 'destroy'])->name('destroy')->defaults('kind', 'rules');
        });

        // Policy — mutations (admin or department.head for their own department — enforced in Form Request authorize())
        Route::prefix('/{level}/{department}/policy')->name('policy.')->group(function () {
            Route::post('/',               [RuleSetController::class, 'store'])->name('store')->defaults('kind', 'policy');
            Route::get('/{rule_set}/edit', [RuleSetController::class, 'edit'])->name('edit')->defaults('kind', 'policy');
            Route::patch('/{rule_set}',    [RuleSetController::class, 'update'])->name('update')->defaults('kind', 'policy');
            Route::delete('/{rule_set}',   [RuleSetController::class, 'destroy'])->name('destroy')->defaults('kind', 'policy');
        });
        Route::prefix('/{level}/{department}/policy/{policy}/periods')->name('policy.periods.')->group(function () {
            Route::post('/',              [PolicyDocumentController::class, 'store'])->name('store');
            Route::get('/{policyDoc}/edit',  [PolicyDocumentController::class, 'edit'])->name('edit');
            Route::patch('/{policyDoc}',     [PolicyDocumentController::class, 'update'])->name('update');
            Route::delete('/{policyDoc}',    [PolicyDocumentController::class, 'destroy'])->name('destroy');
        });
    });
});

// ── Approval queue (maker-checker workflow) ───────────────────────────────────

Route::middleware(['auth', 'throttle:mutations'])->prefix('approvals')->name('approvals.')->group(function () {
    Route::get('/',                  [ApprovalController::class, 'index'])->name('index');
    Route::get('/{id}/pdf',          [ApprovalController::class, 'pdf'])->name('pdf');
    Route::post('/{id}/approve',     [ApprovalController::class, 'approve'])->name('approve');
    Route::post('/{id}/reject',      [ApprovalController::class, 'reject'])->name('reject');
    Route::post('/{id}/reclassify',  [ApprovalController::class, 'reclassify'])->name('reclassify');
    Route::post('/{id}/resubmit',    [ApprovalController::class, 'resubmit'])->name('resubmit');
});

// ── Profile (self-edit, any authenticated user) ───────────────────────────────

Route::middleware(['auth', 'throttle:mutations'])->prefix('profile')->name('profile.')->group(function () {
    Route::get('/edit',  [UserManagementController::class, 'editProfile'])->name('edit');
    Route::patch('/',    [UserManagementController::class, 'updateProfile'])->name('update');
});

// ── Admin-only ────────────────────────────────────────────────────────────────

Route::prefix('admin')->name('admin.')->middleware(['auth', 'is_admin', 'throttle:mutations'])->group(function () {

    // Activity log — admin audit trail of all authenticated mutations and logins
    Route::get('/activity-logs', [ActivityLogController::class, 'index'])->name('activity.index');

    // User management — admin creates and manages all vault accounts
    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/',            [UserManagementController::class, 'index'])->name('index');
        Route::get('/create',      [UserManagementController::class, 'create'])->name('create');
        Route::post('/',           [UserManagementController::class, 'store'])->name('store');
        Route::get('/{user}',      [UserManagementController::class, 'show'])->name('show');
        Route::get('/{user}/edit', [UserManagementController::class, 'edit'])->name('edit');
        Route::patch('/{user}',    [UserManagementController::class, 'update'])->name('update');
        Route::delete('/{user}',   [UserManagementController::class, 'destroy'])->name('destroy');
        Route::post('/{user}/resend-activation', [UserManagementController::class, 'resendActivation'])->name('resend-activation');
        Route::post('/{user}/send-password-reset', [UserManagementController::class, 'sendPasswordReset'])->name('send-password-reset');
        Route::post('/{user}/password-reset-link', [UserManagementController::class, 'passwordResetLink'])->name('password-reset-link');
    });

    // Designations — admin-managed presets mapping real-world posts to default scope/privileges
    Route::prefix('designations')->name('designations.')->group(function () {
        Route::get('/',                  [DesignationController::class, 'index'])->name('index');
        Route::get('/create',            [DesignationController::class, 'create'])->name('create');
        Route::post('/',                 [DesignationController::class, 'store'])->name('store');
        Route::get('/{designation}/edit', [DesignationController::class, 'edit'])->name('edit');
        Route::patch('/{designation}',   [DesignationController::class, 'update'])->name('update');
        Route::delete('/{designation}',  [DesignationController::class, 'destroy'])->name('destroy');
    });
});

// ── Fallback ──────────────────────────────────────────────────────────────────

// Any real protected page already redirects to login on its own via the 'auth' middleware —
// this only ever fires for a URL that matches no route at all (typo, truncated link, bad
// share). Redirecting *that* to login was actively wrong: a mistyped/incomplete public URL
// (e.g. a document link missing its final segment) would send an anonymous visitor — and any
// social-media crawler fetching it for a link preview — to a login page instead of a plain 404,
// so a broken share link silently became "Sign In" in WhatsApp/etc. instead of failing cleanly.
Route::fallback(function () {
    abort(404);
});
