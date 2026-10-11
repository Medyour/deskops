<?php

namespace App\Policies;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Ticket $ticket): bool
    {
        return $ticket->status !== TicketStatus::Closed && ($user->isAdministrator() || $ticket->assigned_to === $user->id);
    }

    public function comment(User $user, Ticket $ticket): bool
    {
        return $this->update($user, $ticket);
    }

    public function assign(User $user, Ticket $ticket, ?int $assigneeId): bool
    {
        if ($ticket->status === TicketStatus::Closed) {
            return false;
        }

        return $user->isAdministrator() || ($ticket->status === TicketStatus::New && $ticket->assigned_to === null && $assigneeId === $user->id);
    }

    public function changeStatus(User $user, Ticket $ticket, TicketStatus $target): bool
    {
        return $user->isAdministrator() || ($ticket->assigned_to === $user->id && $ticket->status !== TicketStatus::Closed && $target !== TicketStatus::Closed);
    }

    public function delete(User $user, Ticket $ticket): bool
    {
        return false;
    }
}
