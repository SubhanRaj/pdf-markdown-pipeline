<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    // Human-readable labels for route-name actions. Any action reaching the view without an
    // entry here just falls back to its raw route name — keep this in sync with routes/web.php
    // whenever a new POST/PATCH/DELETE route is added (see LogMutation for what gets excluded
    // entirely — resend/retry/pre-auth steps that aren't a meaningful state change on their own).
    private const ACTION_LABELS = [
        // Auth
        'auth.login'                            => 'Login',
        'auth.logout'                            => 'Logout',
        'admin.users.resend-activation'         => 'Resend Activation Link',

        // Documents — core
        'documents.store'                       => 'Upload Document',
        'documents.update'                      => 'Edit Document',
        'documents.destroy'                     => 'Archive Document',
        'documents.bulk-destroy'                => 'Bulk Archive',
        'documents.restore'                     => 'Restore Document',
        'documents.trash.bulk-restore'          => 'Bulk Restore',
        'documents.force-destroy'               => 'Permanently Delete Document',
        'documents.trash.bulk-force-destroy'    => 'Bulk Permanent Delete',
        'documents.convert'                     => 'Convert to Markdown',
        'documents.convert-ocr'                 => 'Run OCR Extraction',
        'documents.revert-ocr'                  => 'Revert OCR',
        'documents.markdown.update'             => 'Edit Markdown Draft',
        'documents.markdown.discard'            => 'Discard Markdown Draft',

        // Documents — rule set / policy / division / folder variants (same actions, different scopes)
        'documents.rules.update'                => 'Edit Document',
        'documents.rules.destroy'               => 'Archive Document',
        'documents.policy.update'                => 'Edit Document',
        'documents.policy.destroy'               => 'Archive Document',
        'documents.divisions.update'            => 'Edit Document',
        'documents.divisions.destroy'           => 'Archive Document',
        'documents.folders.update'              => 'Edit Document',
        'documents.folders.destroy'             => 'Archive Document',
        'documents.divisions.folders.update'    => 'Edit Document',
        'documents.divisions.folders.destroy'   => 'Archive Document',

        // Approvals
        'approvals.approve'                     => 'Approve Document',
        'approvals.reject'                      => 'Reject Document',
        'approvals.reclassify'                  => 'Reclassify Document',
        'approvals.resubmit'                    => 'Resubmit Document',

        // Departments / sections / divisions / folders / rule sets / policy
        'departments.store'                     => 'Create Department',
        'departments.update'                    => 'Update Department',
        'departments.destroy'                   => 'Delete Department',
        'departments.sections.store'            => 'Create Section',
        'departments.sections.update'           => 'Update Section',
        'departments.sections.destroy'          => 'Delete Section',
        'departments.sections.divisions.store'  => 'Create Division',
        'departments.sections.divisions.update' => 'Update Division',
        'departments.sections.divisions.destroy'=> 'Delete Division',
        'departments.sections.folders.store'            => 'Create Folder',
        'departments.sections.folders.update'           => 'Update Folder',
        'departments.sections.folders.destroy'          => 'Delete Folder',
        'departments.sections.divisions.folders.store'  => 'Create Folder',
        'departments.sections.divisions.folders.update' => 'Update Folder',
        'departments.sections.divisions.folders.destroy'=> 'Delete Folder',
        'departments.rules.store'               => 'Create Rule Set',
        'departments.rules.update'              => 'Update Rule Set',
        'departments.rules.destroy'             => 'Delete Rule Set',
        'departments.policy.store'               => 'Create Policy',
        'departments.policy.update'              => 'Update Policy',
        'departments.policy.destroy'             => 'Delete Policy',
        'departments.policy.periods.store'       => 'Create Policy Document',
        'departments.policy.periods.update'      => 'Update Policy Document',
        'departments.policy.periods.destroy'     => 'Delete Policy Document',

        // Users / profile
        'admin.users.store'                     => 'Create User',
        'admin.users.update'                    => 'Edit User',
        'admin.users.destroy'                   => 'Delete User',
        'profile.update'                        => 'Update Profile',

        // Misc
        'storage.local.upload'                  => 'Upload File (local dev)',
    ];

    // Tailwind color classes per action category
    private const ACTION_COLORS = [
        'auth.login'                         => 'bg-sky-100 text-sky-800 dark:bg-sky-900 dark:text-sky-200',
        'auth.logout'                        => 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300',
        'documents.store'                    => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        'documents.update'                   => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200',
        'documents.rules.update'             => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200',
        'documents.policy.update'             => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200',
        'documents.divisions.update'         => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200',
        'documents.folders.update'           => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200',
        'documents.divisions.folders.update' => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200',
        'documents.convert'                  => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        'documents.convert-ocr'              => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        'documents.markdown.update'          => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
        'approvals.approve'                  => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
        'approvals.reject'                   => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        'profile.update'                     => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200',
        'admin.users.store'                  => 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
        'admin.users.update'                 => 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
        'admin.users.resend-activation'      => 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
        'admin.users.destroy'                => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        'documents.destroy'                  => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
        'documents.rules.destroy'            => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
        'documents.policy.destroy'            => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
        'documents.divisions.destroy'        => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
        'documents.folders.destroy'          => 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
        'documents.divisions.folders.destroy'=> 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200',
        'documents.force-destroy'            => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        'documents.trash.bulk-force-destroy' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        'departments.destroy'                => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        'departments.sections.destroy'       => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        'departments.sections.divisions.destroy' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        'departments.rules.destroy'          => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
        'departments.policy.destroy'          => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
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
