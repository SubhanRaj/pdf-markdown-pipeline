<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    // Human-readable labels for route-name actions
    private const ACTION_LABELS = [
        'auth.login'                        => 'Login',
        'documents.store'                   => 'Upload Document',
        'documents.update'                  => 'Edit Document',
        'documents.rules.update'            => 'Edit Document',
        'documents.divisions.update'        => 'Edit Document',
        'documents.destroy'                 => 'Archive Document',
        'documents.rules.destroy'           => 'Archive Document',
        'documents.divisions.destroy'       => 'Archive Document',
        'documents.bulk-destroy'            => 'Bulk Archive',
        'documents.restore'                 => 'Restore Document',
        'documents.trash.bulk-restore'      => 'Bulk Restore',
        'documents.force-destroy'           => 'Permanently Delete',
        'documents.trash.bulk-force-destroy'=> 'Bulk Permanent Delete',
        'departments.store'                 => 'Create Department',
        'departments.update'                => 'Update Department',
        'departments.destroy'               => 'Delete Department',
        'departments.sections.store'        => 'Create Section',
        'departments.sections.update'       => 'Update Section',
        'departments.sections.destroy'      => 'Delete Section',
        'departments.sections.divisions.store'  => 'Create Division',
        'departments.sections.divisions.update' => 'Update Division',
        'departments.sections.divisions.destroy'=> 'Delete Division',
        'departments.rules.store'           => 'Create Rule Set',
        'departments.rules.update'          => 'Update Rule Set',
        'departments.rules.destroy'         => 'Delete Rule Set',
        'admin.users.store'                 => 'Create User',
        'admin.users.update'                => 'Edit User',
        'admin.users.destroy'               => 'Delete User',
        'profile.update'                    => 'Update Profile',
    ];

    // Tailwind color classes per action category
    private const ACTION_COLORS = [
        'auth.login'                         => 'bg-sky-100 text-sky-800 dark:bg-sky-900 dark:text-sky-200',
        'documents.store'                    => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        'documents.update'                   => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200',
        'documents.rules.update'             => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200',
        'documents.divisions.update'         => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200',
        'profile.update'                     => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200',
        'admin.users.store'                  => 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
        'admin.users.update'                 => 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
        'admin.users.destroy'                => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        'documents.force-destroy'            => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        'documents.trash.bulk-force-destroy' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
    ];

    private const DEFAULT_COLOR = 'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-200';

    public function index(Request $request)
    {
        $query = ActivityLog::with('user')
            ->latest('created_at');

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }

        if ($request->filled('ip')) {
            $query->where('ip_address', $request->input('ip'));
        }

        $logs  = $query->paginate(50)->withQueryString();
        $users = User::orderBy('name')->get(['id', 'name', 'username']);

        $actions = ActivityLog::select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        $labels = self::ACTION_LABELS;
        $colors = self::ACTION_COLORS;
        $defaultColor = self::DEFAULT_COLOR;

        return view('admin.activity-logs.index', compact(
            'logs', 'users', 'actions', 'labels', 'colors', 'defaultColor'
        ));
    }
}
