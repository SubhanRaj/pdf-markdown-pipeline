<?php

use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FrontendController;
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
    // Section-based documents
    Route::get('/{level}/{department}/{section}/{document}',     [DocumentController::class, 'show'])->name('show');
    Route::get('/{level}/{department}/{section}/{document}/pdf', [DocumentController::class, 'pdf'])->name('pdf');
    // Rule-set-based documents
    Route::prefix('/{level}/{department}/rules/{rule_set}')->name('rules.')->group(function () {
        Route::get('/{document}',     [DocumentController::class, 'showRuleSetDoc'])->name('show');
        Route::get('/{document}/pdf', [DocumentController::class, 'pdfRuleSetDoc'])->name('pdf');
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
    });

    Route::prefix('/{level}/{department}/rules')->name('rules.')->group(function () {
        Route::get('/create',     [RuleSetController::class, 'create'])->name('create')->middleware(['auth', 'throttle:mutations']);
        Route::get('/{rule_set}', [RuleSetController::class, 'show'])->name('show');
    });
});

// ── Auth-protected mutations ──────────────────────────────────────────────────
// throttle:mutations = 60 state-changing requests/minute/user (defined in AppServiceProvider)

Route::middleware(['auth', 'throttle:mutations'])->group(function () {

    // Documents — mutations
    Route::prefix('documents')->name('documents.')->group(function () {
        Route::get('/trash',               [DocumentController::class, 'trash'])->name('trash');
        Route::post('/trash/{id}/restore', [DocumentController::class, 'restore'])->name('restore');
        Route::delete('/trash/{id}',       [DocumentController::class, 'forceDestroy'])->name('force-destroy');
        Route::post('/', [DocumentController::class, 'store'])->name('store')->middleware('throttle:uploads');
        Route::get('/{level}/{department}/{section}/{document}/review', [DocumentController::class, 'edit'])->name('edit');
        Route::patch('/{level}/{department}/{section}/{document}',      [DocumentController::class, 'update'])->name('update');
        Route::delete('/{level}/{department}/{section}/{document}',     [DocumentController::class, 'destroy'])->name('destroy');
        // Rule-set document mutations
        Route::prefix('/{level}/{department}/rules/{rule_set}')->name('rules.')->group(function () {
            Route::get('/{document}/review', [DocumentController::class, 'editRuleSetDoc'])->name('edit');
            Route::patch('/{document}',      [DocumentController::class, 'updateRuleSetDoc'])->name('update');
            Route::delete('/{document}',     [DocumentController::class, 'destroyRuleSetDoc'])->name('destroy');
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
        });

        // Rule sets — mutations (admin only — enforced in Form Request authorize())
        Route::prefix('/{level}/{department}/rules')->name('rules.')->group(function () {
            Route::post('/',               [RuleSetController::class, 'store'])->name('store');
            Route::get('/{rule_set}/edit', [RuleSetController::class, 'edit'])->name('edit');
            Route::patch('/{rule_set}',    [RuleSetController::class, 'update'])->name('update');
            Route::delete('/{rule_set}',   [RuleSetController::class, 'destroy'])->name('destroy');
        });
    });
});

// ── Admin-only ────────────────────────────────────────────────────────────────

Route::prefix('admin')->name('admin.')->middleware(['auth', 'throttle:mutations'])->group(function () {

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
