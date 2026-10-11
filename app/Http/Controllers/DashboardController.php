<?php

namespace App\Http\Controllers;

use App\Actions\Tickets\OperationsDashboard;
use App\Http\Requests\DashboardRequest;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __invoke(DashboardRequest $request, OperationsDashboard $dashboard): View
    {
        return view('dashboard', $dashboard->read($request->user(), $request->validated('scope') === 'mine'));
    }
}
