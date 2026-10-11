<?php

namespace App\Actions\Tickets;

use App\Actions\Demo\DemoSession;
use App\Enums\TicketActivityType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CreateTicket
{
    /** @param array{title: string, description: string, requester_name: string, requester_email: string, priority?: string} $input */
    public function execute(User $actor, array $input): Ticket
    {
        $attributes = TicketInput::validate($input, TicketInput::creationRules());

        return DB::transaction(function () use ($actor, $attributes): Ticket {
            app(DemoSession::class)->validate(lock: true);
            $actor->refresh();
            Gate::forUser($actor)->authorize('create', Ticket::class);
            $ticket = new Ticket;
            $ticket->forceFill([
                ...$attributes, 'created_by' => $actor->id, 'assigned_to' => null, 'is_demo' => $actor->isDemo(),
                'status' => TicketStatus::New, 'priority' => $attributes['priority'] ?? TicketPriority::Normal, 'version' => 1,
            ])->save();
            $changes = [];
            foreach (['title', 'description', 'requester_name', 'requester_email', 'priority', 'status'] as $field) {
                $changes[$field] = ['from' => null, 'to' => $ticket->getRawOriginal($field)];
            }
            $ticket->activities()->create(['actor_id' => $actor->id, 'type' => TicketActivityType::TicketCreated, 'changes' => $changes]);

            return $ticket->refresh();
        }, attempts: 3);
    }
}
