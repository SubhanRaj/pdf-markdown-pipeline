<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DivisionController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\FrontendController;
use App\Http\Controllers\PolicyPeriodController;
use App\Http\Controllers\RuleSetController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SectionController;
use Illuminate\Support\Facades\Route;

// ── Auth shortcuts ────────────────────────────────────────────────────────────

// /admin with no sub-path → land on users list (auth middleware redirects to /login if needed)
Route::get('/admin', function () {
    return redirect()->route('admin.users.index');
})->middleware('auth')->name('admin');

// ── Public ────────────────────────────────────────────────────────────────────

Route::get('/', [FrontendController::class, 'dashboard'])->name('home');

Route::get('/search', [SearchController::class, 'index'])->name('search.index');

// Documents — read-only browse is public
// Hierarchical URLs: /documents/{level}/{department}/{section}/{document}
// {level} = 'dept' (department_level) | 'sectt' (secretariat_level)
Route::prefix('documents')->name('documents.')->group(function () {
    Route::get('/', [DocumentController::class, 'index'])->name('index');
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

    Route::prefix('/{level}/{department}/sections')->name('sections.')->group(function () {
        Route::get('/',          [SectionController::class, 'index'])->name('index');
        Route::get('/create',    [SectionController::class, 'create'])->name('create')->middleware(['auth', 'throttle:mutations']);
        Route::get('/{section}', [SectionController::class, 'show'])->name('show');
        // Internal divisions — public show only
        Route::prefix('/{section}/divisions')->name('divisions.')->group(function () {
            Route::get('/create',     [DivisionController::class, 'create'])->name('create')->middleware(['auth', 'throttle:mutations']);
            Route::get('/{division}', [DivisionController::class, 'show'])->name('show');
            // Division folders — public show only
            Route::prefix('/{division}/folders')->name('folders.')->group(function () {
                Route::get('/create',   [FolderController::class, 'createForDivision'])->name('create')->middleware(['auth', 'throttle:mutations']);
                Route::get('/{folder}', [FolderController::class, 'showForDivision'])->name('show');
            });
        });
        // Section folders — public show only
        Route::prefix('/{section}/folders')->name('folders.')->group(function () {
            Route::get('/create',   [FolderController::class, 'create'])->name('create')->middleware(['auth', 'throttle:mutations']);
            Route::get('/{folder}', [FolderController::class, 'show'])->name('show');
        });
    });

    Route::prefix('/{level}/{department}/rules')->name('rules.')->group(function () {
        Route::get('/',            [RuleSetController::class, 'index'])->name('index')->defaults('kind', 'rules');
        Route::get('/create',     [RuleSetController::class, 'create'])->name('create')->middleware(['auth', 'throttle:mutations'])->defaults('kind', 'rules');
        Route::get('/{rule_set}', [RuleSetController::class, 'show'])->name('show')->defaults('kind', 'rules');
    });
    // Policy — department-level only, available to every department (existing or future)
    Route::prefix('/{level}/{department}/policy')->name('policy.')->group(function () {
        Route::get('/',            [RuleSetController::class, 'index'])->name('index')->defaults('kind', 'policy');
        Route::get('/create',     [RuleSetController::class, 'create'])->name('create')->middleware(['auth', 'throttle:mutations'])->defaults('kind', 'policy');
        Route::get('/{rule_set}', [RuleSetController::class, 'show'])->name('show')->defaults('kind', 'policy');
    });
    // Policy periods (e.g. 2024-25, 2025-26) — yearly documents under a policy container
    Route::prefix('/{level}/{department}/policy/{policy}/periods')->name('policy.periods.')->group(function () {
        Route::get('/create',    [PolicyPeriodController::class, 'create'])->name('create')->middleware(['auth', 'throttle:mutations']);
        Route::get('/{period}',  [PolicyPeriodController::class, 'show'])->name('show');
    });
});

// ── Auth-protected reads (status polling / listing pages) ──────────────────────
// throttle:reads = 600/min/user (defined in AppServiceProvider) — separate from
// throttle:mutations so the pipeline monitor's 5s-interval convert-status polling,
// and viewers just watching a bulk run, never compete with the mutation cap.

Route::middleware(['auth', 'throttle:reads'])->prefix('documents')->name('documents.')->group(function () {
    Route::get('/bulk-upload',         [DocumentController::class, 'bulkUploadForm'])->name('bulk-upload');
    Route::get('/pipeline',            [DocumentController::class, 'pipeline'])->name('pipeline');
    Route::get('/trash',               [DocumentController::class, 'trash'])->name('trash');
    Route::get('/trash/{id}/pdf',      [DocumentController::class, 'trashedPdf'])->name('trashed.pdf');
    Route::get('/{id}/convert-status', [DocumentController::class, 'conversionStatus'])->where('id', '[0-9]+')->name('convert-status');
    Route::get('/{id}/structure',      [DocumentController::class, 'structureJson'])->where('id', '[0-9]+')->name('structure');
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
        Route::delete('/{id}/markdown',            [DocumentController::class, 'discardMarkdown'])->where('id', '[0-9]+')->name('markdown.discard');
        Route::post('/', [DocumentController::class, 'store'])->name('store')->middleware('throttle:uploads');
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
                });
            });

            // Section folders — mutations (scope enforced in Form Request authorize())
            Route::prefix('/{section}/folders')->name('folders.')->group(function () {
                Route::post('/',              [FolderController::class, 'store'])->name('store');
                Route::get('/{folder}/edit',  [FolderController::class, 'edit'])->name('edit');
                Route::patch('/{folder}',     [FolderController::class, 'update'])->name('update');
                Route::delete('/{folder}',    [FolderController::class, 'destroy'])->name('destroy');
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
            Route::post('/',              [PolicyPeriodController::class, 'store'])->name('store');
            Route::get('/{period}/edit',  [PolicyPeriodController::class, 'edit'])->name('edit');
            Route::patch('/{period}',     [PolicyPeriodController::class, 'update'])->name('update');
            Route::delete('/{period}',    [PolicyPeriodController::class, 'destroy'])->name('destroy');
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
    });
});

// ── Fallback ──────────────────────────────────────────────────────────────────

Route::fallback(function () {
    return redirect()->route('login');
});
