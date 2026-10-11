<?php

namespace App\Actions\Demo;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DemoSession
{
    public function validate(bool $lock = false): void
    {
        $request = request();
        if (! $request->hasSession() || ! $request->session()->get('demo_login')) {
            return;
        }
        if ($lock) {
            DB::table('demo_state')->where('id', 1)->update(['generation' => DB::raw('generation')]);
        }
        $valid = config('app.demo_mode') && Auth::user()?->isDemo()
            && $request->session()->get('demo_generation') === (int) DB::table('demo_state')->where('id', 1)->value('generation');
        if (! $valid) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            throw new AuthenticationException;
        }
    }
}
