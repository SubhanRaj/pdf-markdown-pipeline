<!DOCTYPE html>
<html lang="en" class="h-full">

<x-head title="Activate Account" />

<body class="bg-slate-100 dark:bg-slate-950 h-full flex items-center justify-center p-4 transition-colors duration-200">

<div class="w-full max-w-md">

    {{-- Logo / App identity --}}
    <div class="text-center mb-8">
        <div class="w-14 h-14 rounded-2xl bg-indigo-600 flex items-center justify-center mx-auto mb-4 shadow-lg">
            <i class="ti ti-file-text-ai text-white text-2xl"></i>
        </div>
        <h1 class="text-xl font-bold text-slate-800 dark:text-slate-100">Document Vault</h1>
        <p class="text-sm text-slate-400 dark:text-slate-500 mt-1">UP Department of Excise</p>
    </div>

    {{-- Card --}}
    <div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm p-8">

        <h2 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-1">Welcome, {{ $user->name }}</h2>
        <p class="text-sm text-slate-400 dark:text-slate-500 mb-6">Set a password to activate your account ({{ $user->email }}).</p>

        @if($errors->any())
        <div class="mb-4 flex items-start gap-2 text-sm text-red-700 dark:text-red-400 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-700 rounded-lg px-4 py-3">
            <i class="ti ti-alert-circle flex-shrink-0 mt-0.5"></i>
            <span>{{ $errors->first() }}</span>
        </div>
        @endif

        <form method="POST" action="{{ $signedUrl }}" class="space-y-4">
            @csrf

            <div>
                <label for="password" class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1.5 uppercase tracking-wide">
                    New password
                </label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    required
                    autofocus
                    autocomplete="new-password"
                    placeholder="••••••••"
                    class="w-full px-4 py-2.5 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded-lg text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent @error('password') border-red-400 dark:border-red-500 bg-red-50 dark:bg-red-900/20 @enderror"
                >
                <p class="text-xs text-slate-400 dark:text-slate-500 mt-1.5">Min 8 chars · uppercase · lowercase · number · symbol.</p>
            </div>

            <div>
                <label for="password_confirmation" class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1.5 uppercase tracking-wide">
                    Confirm password
                </label>
                <input
                    id="password_confirmation"
                    name="password_confirmation"
                    type="password"
                    required
                    autocomplete="new-password"
                    placeholder="••••••••"
                    class="w-full px-4 py-2.5 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-600 rounded-lg text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                >
            </div>

            <button
                type="submit"
                class="w-full bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold py-2.5 px-4 rounded-lg transition-colors flex items-center justify-center gap-2 mt-2"
            >
                <i class="ti ti-lock-check"></i>
                Activate Account
            </button>
        </form>
    </div>

    <p class="text-center text-xs text-slate-400 dark:text-slate-600 mt-6">
        Internal use only &middot; Unauthorized access is prohibited
    </p>

</div>

</body>
</html>
