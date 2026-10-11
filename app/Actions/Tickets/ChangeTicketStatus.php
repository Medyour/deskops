<?php

namespace App\Actions\Tickets;

use App\Enums\TicketActivityType;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ChangeTicketStatus
{
    public function __construct(private TicketMutation $mutation) {}

    /** @param array{version: int, status: string, reason?: string|null} $input */
    public function execute(User $actor, Ticket $ticket, array $input): Ticket
    {
        $version = TicketInput::validate($input, ['version' => ['required', 'integer', 'min:1']])['version'];

        return $this->mutation->run($ticket, (int) $version, function (Ticket $current) use ($actor, $input): Ticket {
            $target = is_string($input['status'] ?? null) ? TicketStatus::tryFrom(trim($input['status'])) : null;
            $data = TicketInput::validate($input, TicketInput::statusRules($current->status, $target));
            $target = TicketStatus::from($data['status']);
            $actor->refresh();
            Gate::forUser($actor)->authorize('changeStatus', [$current, $target]);
            if ($target === $current->status) {
                return $current;
            }
            if (! $current->status->canTransitionTo($target)) {
                throw ValidationException::withMessages(['status' => 'Cette transition de statut est interdite.']);
            }
            if ($current->assigned_to === null) {
                throw ValidationException::withMessages(['status' => 'Un agent doit être assigné avant cette transition.']);
            }
            $attributes = ['status' => $target->value];
            if ($target === TicketStatus::Resolved) {
                $attributes['resolved_at'] = now()->toDateTimeString();
            } elseif ($target === TicketStatus::Closed) {
                $attributes['closed_at'] = now()->toDateTimeString();
            } elseif ($target === TicketStatus::InProgress) {
                $attributes['resolved_at'] = null;
                $attributes['closed_at'] = null;
            }

            return $this->mutation->save($current, $attributes, $actor, TicketActivityType::StatusChanged, $data['reason'] ?? null);
        });
    }
}
