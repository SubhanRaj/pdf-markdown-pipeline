<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Mail\AccountOnboarding;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Division;
use App\Models\Section;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    public function index(): View
    {
        $users = User::with(['department', 'section', 'designation'])
            ->latest()
            ->paginate(20);

        return view('admin.users.index', compact('users'));
    }

    public function create(): View
    {
        $departments   = Department::orderBy('name')->get(['id', 'name', 'level']);
        $sections      = Section::orderBy('name')->get(['id', 'name', 'department_id']);
        $divisions     = Division::orderBy('name')->get(['id', 'name', 'section_id']);
        $designations  = Designation::orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'department_id', 'default_scope', 'default_privileges']);

        return view('admin.users.create', compact('departments', 'sections', 'divisions', 'designations'));
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        try {
            $user = DB::transaction(function () use ($request) {
                return User::create([
                    'name'                     => $request->name,
                    'username'                 => $request->username,
                    'email'                    => $request->email,
                    'mobile'                   => $request->mobile ?: null,
                    // Unusable placeholder — never surfaced anywhere. Real password is set by
                    // the officer via the onboarding link mailed below.
                    'password'                 => Hash::make(Str::random(40)),
                    'post'                     => $request->post ?: null,
                    'designation_id'           => $request->designation_id ?: null,
                    'role'                     => $request->role,
                    'uploads_require_approval' => (bool) $request->uploads_require_approval,
                    'privileges'               => $request->privileges ?? [],
                    'department_id'            => $request->department_id,
                    'section_id'               => $request->section_id,
                    'division_id'              => $request->division_id,
                    // Left null on purpose — doubles as the onboarding-link's single-use gate
                    // (OnboardingController redirects to login once this is set) and as a
                    // genuine "this address was actually confirmed" flag.
                    'email_verified_at'        => null,
                ]);
            });

            // Account already exists at this point — a mail failure here must not be reported
            // as account-creation failure (the admin would retry and hit the unique-email
            // constraint). Log it and let them use "Resend activation link" instead.
            try {
                $this->sendOnboardingLink($user);
                flash()->success("Account for {$request->name} created — an activation email has been sent.");
            } catch (\Throwable $mailError) {
                Log::error('UserManagementController@store: onboarding mail failed', [
                    'user_id' => $user->id,
                    'error'   => $mailError->getMessage(),
                ]);
                flash()->warning("Account for {$request->name} created, but the activation email failed to send. Use \"Resend activation link\" on their profile.");
            }

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

    /** Re-send the onboarding link for a user who never completed activation. */
    public function resendActivation(User $user): RedirectResponse
    {
        if ($user->email_verified_at !== null) {
            flash()->warning("{$user->name}'s account is already active.");
            return back();
        }

        $this->sendOnboardingLink($user);

        flash()->success("Activation email re-sent to {$user->email}.");

        return back();
    }

    private function sendOnboardingLink(User $user): void
    {
        $url = URL::temporarySignedRoute('onboarding.show', now()->addHours(72), ['user' => $user->id]);

        Mail::to($user->email)->send(new AccountOnboarding($user, $url));
    }

    public function show(User $user): View
    {
        $user->load(['department', 'section']);

        return view('admin.users.show', compact('user'));
    }

    public function edit(User $user): View
    {
        $departments   = Department::orderBy('name')->get(['id', 'name', 'level']);
        $sections      = Section::orderBy('name')->get(['id', 'name', 'department_id']);
        $divisions     = Division::orderBy('name')->get(['id', 'name', 'section_id']);
        $designations  = Designation::orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'department_id', 'default_scope', 'default_privileges']);

        return view('admin.users.edit', compact('user', 'departments', 'sections', 'divisions', 'designations'));
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $user) {
                $data = [
                    'name'                     => $request->name,
                    'username'                 => $request->username,
                    'email'                    => $request->email,
                    'mobile'                   => $request->mobile ?: null,
                    'post'                     => $request->post ?: null,
                    'designation_id'           => $request->designation_id ?: null,
                    'role'                     => $request->role,
                    'uploads_require_approval' => (bool) $request->uploads_require_approval,
                    'privileges'               => $request->privileges ?? [],
                    'department_id'            => $request->department_id,
                    'section_id'               => $request->section_id,
                    'division_id'              => $request->division_id,
                ];

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

    public function editProfile(): View
    {
        $user        = auth()->user();
        $departments = Department::orderBy('name')->get(['id', 'name', 'level']);
        $sections    = Section::orderBy('name')->get(['id', 'name', 'department_id']);

        return view('profile.edit', compact('user', 'departments', 'sections'));
    }

    public function updateProfile(UpdateProfileRequest $request): RedirectResponse
    {
        $user = auth()->user();

        try {
            DB::transaction(function () use ($request, $user) {
                $data = [
                    'name'   => $request->name,
                    'username' => $request->username,
                    'email'  => $request->email,
                    'mobile' => $request->mobile ?: null,
                    'post'   => $request->post ?: null,
                ];

                if (filled($request->password)) {
                    $data['password'] = $request->password;
                }

                $user->update($data);
            });

            flash()->success('Profile updated successfully.');

            return redirect()->route('profile.edit');

        } catch (\Throwable $e) {
            Log::error('UserManagementController@updateProfile failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            flash()->error('Failed to update profile. Please try again.');

            return back()->withInput($request->except(['password', 'password_confirmation']));
        }
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
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
