<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ForgotPasswordController extends Controller
{
    use PasswordValidationRules;

    public function showRequestForm(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Always redirects with the same message regardless of whether the email matches an
     * account — Password::sendResetLink() no-ops silently for an unknown email, and returning
     * a different message here would let an attacker enumerate registered accounts.
     */
    public function sendResetLink(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        flash()->success('If an account exists for that email, a password reset link is on its way.');

        return redirect()->route('login');
    }

    public function showResetForm(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    /**
     * On success, redirects to /login (never auto-authenticates) — password reset re-enters
     * the normal email + password + OTP flow from a clean slate, same as any other login.
     */
    public function reset(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token'    => ['required'],
            'email'    => ['required', 'email'],
            'password' => $this->passwordRules(),
        ]);

        $status = Password::reset($validated, function (User $user, string $password) {
            $user->forceFill([
                'password' => $password,
            ])->setRememberToken(Str::random(60));

            $user->save();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => [__($status)]]);
        }

        flash()->success('Your password has been reset — please sign in.');

        return redirect()->route('login');
    }
}
