<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::loginView(fn (): View => view('auth.login'));
        Fortify::requestPasswordResetLinkView(fn (): View => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn (Request $request): View => view('auth.reset-password', [
            'token' => $request->route('token'),
            'email' => $request->input('email'),
        ]));
        Fortify::confirmPasswordView(fn (): View => view('auth.confirm-password'));

        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', $request->input('email'))->first();
            if ($user === null || (config('app.demo_mode') && $user->isDemo()) || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }

            return $user;
        });
        RateLimiter::for('public-demo', fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip()));

        RateLimiter::for('login', function (Request $request): Limit {
            $email = Str::transliterate(Str::lower((string) $request->input('email')));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });
    }
}
