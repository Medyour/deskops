<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RejectDisabledDemo
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('demo/login') && ! config('app.demo_mode')) {
            abort(404);
        }

        return $next($request);
    }
}
