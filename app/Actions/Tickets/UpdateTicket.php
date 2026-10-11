<?php

namespace App\Actions\Tickets;

use App\Enums\TicketActivityType;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class UpdateTicket
{
    public function __construct(private TicketMutation $mutation) {}

    /** @param array{version: int, title: string, description: string, requester_name: string, requester_email: string, priority: string} $input */
    public function execute(User $actor, Ticket $ticket, array $input): Ticket
    {
        $version = TicketInput::validate($input, ['version' => ['required', 'integer', 'min:1']])['version'];

        return $this->mutation->run($ticket, (int) $version, function (Ticket $current) use ($actor, $input): Ticket {
            $actor->refresh();
            Gate::forUser($actor)->authorize('update', $current);
            $data = TicketInput::validate($input, TicketInput::updateRules());
            $changes = [];
            foreach (['title', 'description', 'requester_name', 'requester_email', 'priority'] as $field) {
                if ($current->getRawOriginal($field) !== $data[$field]) {
                    $changes[$field] = ['from' => $current->getRawOriginal($field), 'to' => $data[$field]];
                }
            }
            if ($changes === []) {
                return $current;
            }

            $current->forceFill([...array_intersect_key($data, $changes), 'version' => $current->version + 1])->save();
            $priorityChange = array_intersect_key($changes, ['priority' => true]);
            $fieldChanges = array_diff_key($changes, $priorityChange);
            foreach ([TicketActivityType::TicketUpdated->value => $fieldChanges, TicketActivityType::PriorityChanged->value => $priorityChange] as $type => $categoryChanges) {
                if ($categoryChanges !== []) {
                    $current->activities()->create(['actor_id' => $actor->id, 'type' => $type, 'changes' => $categoryChanges]);
                }
            }

            return $current->refresh();
        });
    }
}
