<?php

namespace App\Actions\Tickets;

use App\Enums\TicketActivityType;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AssignTicket
{
    public function __construct(private TicketMutation $mutation) {}

    /** @param array{version: int, assigned_to: int|null} $input */
    public function execute(User $actor, Ticket $ticket, array $input): Ticket
    {
        $version = TicketInput::validate($input, ['version' => ['required', 'integer', 'min:1']])['version'];

        return $this->mutation->run($ticket, (int) $version, function (Ticket $current) use ($actor, $input): Ticket {
            $data = TicketInput::validate($input, TicketInput::assignmentRules());
            $assigneeId = isset($data['assigned_to']) ? (int) $data['assigned_to'] : null;
            $actor->refresh();
            Gate::forUser($actor)->authorize('assign', [$current, $assigneeId]);
            if ($assigneeId === null && $current->status !== TicketStatus::New) {
                throw ValidationException::withMessages(['assigned_to' => 'Seul un ticket nouveau peut être libéré.']);
            }

            return $this->mutation->save($current, ['assigned_to' => $assigneeId], $actor, TicketActivityType::AssignmentChanged);
        });
    }
}
