<?php

namespace App\Http\Middleware;

use App\Actions\Demo\DemoSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DemoMode
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.demo_mode') && $request->routeIs('password.request', 'password.email', 'password.reset', 'password.update', 'password.confirm', 'password.confirm.store')) {
            abort(404);
        }
        app(DemoSession::class)->validate();

        return $next($request);
    }
}
