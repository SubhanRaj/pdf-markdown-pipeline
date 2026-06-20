<?php

use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FrontendController;
use App\Http\Controllers\SectionController;
use Illuminate\Support\Facades\Route;

// ── Auth shortcuts ────────────────────────────────────────────────────────────

// /admin with no sub-path → land on users list (auth middleware redirects to /login if needed)
Route::get('/admin', function () {
    return redirect()->route('admin.users.index');
})->middleware('auth')->name('admin');

// ── Public ────────────────────────────────────────────────────────────────────

Route::get('/', [FrontendController::class, 'dashboard'])->name('home');

// Documents — read-only browse is public
Route::prefix('documents')->name('documents.')->group(function () {
    Route::get('/',           [DocumentController::class, 'index'])->name('index');
    Route::get('/{document}', [DocumentController::class, 'show'])->name('show');
});

// Departments & sections — read-only public
Route::prefix('departments')->name('departments.')->group(function () {
    Route::get('/',             [DepartmentController::class, 'index'])->name('index');
    Route::get('/{department}', [DepartmentController::class, 'show'])->name('show');

    Route::prefix('/{department}/sections')->name('sections.')->group(function () {
        Route::get('/',          [SectionController::class, 'index'])->name('index');
        Route::get('/{section}', [SectionController::class, 'show'])->name('show');
    });
});

// ── Auth-protected mutations ──────────────────────────────────────────────────

Route::middleware('auth')->group(function () {

    // Documents — mutations
    // Note: /upload must be before /{document} to avoid wildcard collision
    Route::prefix('documents')->name('documents.')->group(function () {
        Route::get('/upload',            [DocumentController::class, 'create'])->name('create');
        Route::post('/',                 [DocumentController::class, 'store'])->name('store');
        Route::get('/{document}/review', [DocumentController::class, 'edit'])->name('edit');
        Route::patch('/{document}',      [DocumentController::class, 'update'])->name('update');
        Route::delete('/{document}',     [DocumentController::class, 'destroy'])->name('destroy');
    });

    // Departments — mutations
    Route::prefix('departments')->name('departments.')->group(function () {
        Route::get('/create',            [DepartmentController::class, 'create'])->name('create');
        Route::post('/',                 [DepartmentController::class, 'store'])->name('store');
        Route::get('/{department}/edit', [DepartmentController::class, 'edit'])->name('edit');
        Route::patch('/{department}',    [DepartmentController::class, 'update'])->name('update');
        Route::delete('/{department}',   [DepartmentController::class, 'destroy'])->name('destroy');

        // Sections — mutations
        Route::prefix('/{department}/sections')->name('sections.')->group(function () {
            Route::get('/create',          [SectionController::class, 'create'])->name('create');
            Route::post('/',               [SectionController::class, 'store'])->name('store');
            Route::get('/{section}/edit',  [SectionController::class, 'edit'])->name('edit');
            Route::patch('/{section}',     [SectionController::class, 'update'])->name('update');
            Route::delete('/{section}',    [SectionController::class, 'destroy'])->name('destroy');
        });
    });
});

// ── Admin-only ────────────────────────────────────────────────────────────────

Route::prefix('admin')->name('admin.')->middleware('auth')->group(function () {

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
