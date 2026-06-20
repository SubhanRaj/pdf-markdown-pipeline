<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Department;
use App\Models\Section;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    public function index(): View
    {
        $users = User::with(['department', 'section'])
            ->latest()
            ->paginate(20);

        return view('admin.users.index', compact('users'));
    }

    public function create(): View
    {
        $departments = Department::orderBy('name')->get(['id', 'name', 'level']);
        $sections    = Section::orderBy('name')->get(['id', 'name', 'department_id']);

        return view('admin.users.create', compact('departments', 'sections'));
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request) {
                User::create([
                    'name'              => $request->name,
                    'username'          => $request->username,
                    'email'             => $request->email,
                    'mobile'            => $request->mobile ?: null,
                    'password'          => $request->password,
                    'post'              => $request->post ?: null,
                    'role'              => $request->role,
                    'privileges'        => $request->privileges ?? [],
                    'department_id'     => $request->department_id,
                    'section_id'        => $request->section_id,
                    'email_verified_at' => now(),
                ]);
            });

            flash()->success("Account for {$request->name} created successfully.");

            return redirect()->route('admin.users.index');

        } catch (\Throwable $e) {
            Log::error('UserManagementController@store failed', [
                'error' => $e->getMessage(),
                'input' => $request->except(['password', 'password_confirmation']),
            ]);

            flash()->error('Failed to create user account. Please try again.');

            return back()->withInput($request->except(['password', 'password_confirmation']));
        }
    }

    public function show(User $user): View
    {
        $user->load(['department', 'section']);

        return view('admin.users.show', compact('user'));
    }

    public function edit(User $user): View
    {
        $departments = Department::orderBy('name')->get(['id', 'name', 'level']);
        $sections    = Section::orderBy('name')->get(['id', 'name', 'department_id']);

        return view('admin.users.edit', compact('user', 'departments', 'sections'));
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $user) {
                $data = [
                    'name'          => $request->name,
                    'username'      => $request->username,
                    'email'         => $request->email,
                    'mobile'        => $request->mobile ?: null,
                    'post'          => $request->post ?: null,
                    'role'          => $request->role,
                    'privileges'    => $request->privileges ?? [],
                    'department_id' => $request->department_id,
                    'section_id'    => $request->section_id,
                ];

                if (filled($request->password)) {
                    $data['password'] = $request->password;
                }

                $user->update($data);
            });

            flash()->success("Account for {$user->name} updated.");

            return redirect()->route('admin.users.index');

        } catch (\Throwable $e) {
            Log::error('UserManagementController@update failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            flash()->error('Failed to update user account. Please try again.');

            return back()->withInput($request->except(['password', 'password_confirmation']));
        }
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->id === auth()->user()?->id) {
            flash()->warning('You cannot delete your own account.');
            return back();
        }

        try {
            DB::transaction(fn () => $user->delete());

            flash()->success("{$user->name}'s account has been deactivated.");

            return redirect()->route('admin.users.index');

        } catch (\Throwable $e) {
            Log::error('UserManagementController@destroy failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            flash()->error('Failed to deactivate account. Please try again.');

            return back();
        }
    }
}
