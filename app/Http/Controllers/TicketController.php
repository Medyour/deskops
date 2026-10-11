<?php

namespace App\Http\Controllers;

use App\Actions\Tickets\TicketWorkspace;
use App\Http\Requests\IndexTicketsRequest;
use App\Models\Ticket;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TicketController extends Controller
{
    public function index(IndexTicketsRequest $request, TicketWorkspace $workspace): View
    {
        $filters = $request->safe()->except('page');
        $tickets = Ticket::query()->filtered($filters, $request->user()->id)
            ->with('assignee:id,name')->withMax('activities as last_activity_at', 'created_at')
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(20)->appends($filters);

        return view('tickets.index', ['tickets' => $tickets, 'filters' => $filters, 'agents' => $workspace->agents()]);
    }

    public function create(): View
    {
        Gate::authorize('create', Ticket::class);

        return view('tickets.create');
    }

    public function show(Request $request, Ticket $ticket, TicketWorkspace $workspace): View
    {
        Gate::authorize('view', $ticket);

        return view('tickets.show', $workspace->detail($request->user(), $ticket));
    }

    public function edit(Ticket $ticket): View
    {
        Gate::authorize('update', $ticket);

        return view('tickets.edit', ['ticket' => $ticket]);
    }
}
