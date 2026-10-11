<?php

namespace App\Http\Controllers;

use App\Actions\Tickets\AssignTicket;
use App\Actions\Tickets\ChangeTicketStatus;
use App\Actions\Tickets\CreateTicket;
use App\Http\Requests\AssignTicketRequest;
use App\Http\Requests\ChangeTicketStatusRequest;
use App\Http\Requests\StoreTicketRequest;
use App\Models\Ticket;
use Illuminate\Http\RedirectResponse;

class TicketMutationController extends Controller
{
    public function store(StoreTicketRequest $request, CreateTicket $action): RedirectResponse
    {
        $ticket = $action->execute($request->user(), $request->validated());

        return to_route('dashboard')->with('status', 'Ticket '.$ticket->reference.' créé.');
    }

    public function assign(AssignTicketRequest $request, Ticket $ticket, AssignTicket $action): RedirectResponse
    {
        $action->execute($request->user(), $ticket, $request->validated());

        return to_route('dashboard')->with('status', 'Assignation enregistrée.');
    }

    public function changeStatus(ChangeTicketStatusRequest $request, Ticket $ticket, ChangeTicketStatus $action): RedirectResponse
    {
        $action->execute($request->user(), $ticket, $request->validated());

        return to_route('dashboard')->with('status', 'Statut enregistré.');
    }
}
