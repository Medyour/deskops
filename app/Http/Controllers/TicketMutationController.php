<?php

namespace App\Http\Controllers;

use App\Actions\Tickets\AddComment;
use App\Actions\Tickets\AssignTicket;
use App\Actions\Tickets\ChangeTicketStatus;
use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\TicketWorkspace;
use App\Actions\Tickets\UpdateTicket;
use App\Exceptions\StaleTicket;
use App\Http\Requests\AddCommentRequest;
use App\Http\Requests\AssignTicketRequest;
use App\Http\Requests\ChangeTicketStatusRequest;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\TicketFormRequest;
use App\Http\Requests\UpdateTicketRequest;
use App\Models\Ticket;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class TicketMutationController extends Controller
{
    public function store(StoreTicketRequest $request, CreateTicket $action): RedirectResponse
    {
        $ticket = $action->execute($request->user(), $request->validated());

        return to_route('tickets.show', $ticket)->with('status', 'Ticket '.$ticket->reference.' créé.');
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket, UpdateTicket $action): RedirectResponse|Response
    {
        return $this->mutate($request, $ticket, fn (): Ticket => $action->execute($request->user(), $ticket, $request->validated()), 'Informations enregistrées.');
    }

    public function comment(AddCommentRequest $request, Ticket $ticket, AddComment $action): RedirectResponse|Response
    {
        return $this->mutate($request, $ticket, fn (): Ticket => $action->execute($request->user(), $ticket, $request->validated()), 'Commentaire ajouté.');
    }

    public function assign(AssignTicketRequest $request, Ticket $ticket, AssignTicket $action): RedirectResponse|Response
    {
        return $this->mutate($request, $ticket, fn (): Ticket => $action->execute($request->user(), $ticket, $request->validated()), 'Assignation enregistrée.');
    }

    public function changeStatus(ChangeTicketStatusRequest $request, Ticket $ticket, ChangeTicketStatus $action): RedirectResponse|Response
    {
        return $this->mutate($request, $ticket, fn (): Ticket => $action->execute($request->user(), $ticket, $request->validated()), 'Statut enregistré.');
    }

    /** @param Closure(): Ticket $operation */
    private function mutate(TicketFormRequest $request, Ticket $ticket, Closure $operation, string $message): RedirectResponse|Response
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $exception->redirectTo($request->validationRedirectUrl());
            throw $exception;
        } catch (StaleTicket $exception) {
            if ($request->expectsJson()) {
                throw $exception;
            }

            $request->session()->forget('_old_input');

            return response()->view('tickets.conflict', [
                ...app(TicketWorkspace::class)->detail($request->user(), $ticket->fresh()),
                'draft' => $request->safe()->except('version'),
                'operation' => $request->route()->getName(),
                'observedVersion' => $request->validated('version'),
            ], 409);
        }

        return to_route('tickets.show', $ticket)->with('status', $message);
    }
}
