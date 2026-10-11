<?php

namespace App\Http\Controllers;

use App\Enums\DemoIdentity;
use App\Http\Requests\DemoLoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DemoLoginController extends Controller
{
    public function __invoke(DemoLoginRequest $request): RedirectResponse
    {
        $identity = $request->validated('role') === 'administrator' ? DemoIdentity::Administrator : DemoIdentity::Alex;
        $user = User::query()->where('demo_identity', $identity)->where('role', $identity->role())->where('email', $identity->email())->first();
        abort_if($user === null, 503, 'La démonstration est momentanément indisponible.');
        $request->session()->invalidate();
        Auth::login($user, remember: false);
        $request->session()->regenerate();
        $request->session()->put([
            'demo_login' => true,
            'demo_generation' => (int) DB::table('demo_state')->where('id', 1)->value('generation'),
        ]);

        return redirect()->route('dashboard');
    }
}
