<?php

namespace App\Actions\Tickets;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class TicketWorkspace
{
    public function __construct(private ChangeTicketStatus $statuses, private AssignTicket $assignment) {}

    /** @return Collection<int, User> */
    public function agents(): Collection
    {
        return User::query()->where('role', UserRole::Agent)->orderBy('name')->orderBy('id')->get(['id', 'name', 'role']);
    }

    /** @return array{ticket: Ticket, agents: Collection<int, User>, historyUsers: Collection<int, User>, transitions: list<TicketStatus>, allowsRelease: bool} */
    public function detail(User $viewer, Ticket $ticket): array
    {
        $ticket->load(['creator:id,name', 'assignee:id,name', 'comments.author:id,name', 'activities.actor:id,name']);
        $historyIds = $ticket->activities->flatMap(fn (TicketActivity $activity): array => [
            $activity->changes['assigned_to']['from'] ?? null, $activity->changes['assigned_to']['to'] ?? null,
        ])->filter()->unique();
        $historyUsers = $historyIds->isEmpty() ? new Collection : User::query()->whereIn('id', $historyIds)->get(['id', 'name'])->keyBy('id');

        return [
            'ticket' => $ticket, 'agents' => $this->agents(), 'historyUsers' => $historyUsers,
            'transitions' => $this->statuses->availableTransitions($viewer, $ticket),
            'allowsRelease' => $this->assignment->allowsRelease($ticket),
        ];
    }
}
