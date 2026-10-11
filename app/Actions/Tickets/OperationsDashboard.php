<?php

namespace App\Actions\Tickets;

use App\Enums\TicketPriority;
use App\Enums\TicketQueue;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class OperationsDashboard
{
    /**
     * @return array{
     *   mine: bool, generatedAt: CarbonImmutable, filters: array{assignee?: string},
     *   metrics: array{total: int, active: int, unassigned: int, high_priority: int, urgent: int, attention: int, completed: int},
     *   statuses: array<string, int>, priorities: array<string, int>, agents: Collection<int, User>,
     *   attentionTickets: Collection<int, Ticket>, recentTickets: Collection<int, Ticket>, recentActivities: Collection<int, TicketActivity>
     * }
     */
    public function read(User $viewer, bool $mine = false): array
    {
        $now = CarbonImmutable::now('UTC');
        $mine = $mine && ! $viewer->isAdministrator();
        $tickets = Ticket::query()->visibleTo($viewer)->when($mine, fn (Builder $query): Builder => $query->where('assigned_to', $viewer->id));
        $statuses = array_fill_keys(array_column(TicketStatus::cases(), 'value'), 0);
        $priorities = array_fill_keys(array_column(TicketPriority::cases(), 'value'), 0);
        $metrics = ['total' => 0, 'active' => 0, 'unassigned' => 0, 'high_priority' => 0, 'urgent' => 0, 'attention' => 0, 'completed' => 0];

        $groups = (clone $tickets)->toBase()->selectRaw('status, priority, COUNT(*) AS total, SUM(CASE WHEN assigned_to IS NULL THEN 1 ELSE 0 END) AS unassigned')
            ->groupBy('status', 'priority')->get();
        foreach ($groups as $group) {
            $count = (int) $group->total;
            $statuses[$group->status] += $count;
            $priorities[$group->priority] += $count;
            $metrics['total'] += $count;
            if (in_array($group->status, TicketQueue::completedStatuses(), true)) {
                $metrics['completed'] += $count;
            }
            if (in_array($group->status, TicketQueue::activeStatuses(), true)) {
                $highPriority = in_array($group->priority, TicketQueue::attentionPriorities(), true);
                $metrics['active'] += $count;
                $metrics['unassigned'] += (int) $group->unassigned;
                $metrics['high_priority'] += $highPriority ? $count : 0;
                $metrics['urgent'] += $group->priority === TicketPriority::Urgent->value ? $count : 0;
                $metrics['attention'] += $highPriority ? $count : (int) $group->unassigned;
            }
        }

        $agents = User::query()->where('role', UserRole::Agent)->when($viewer->demoRestricted(), fn (Builder $query): Builder => $query->whereNotNull('demo_identity'))->when($mine, fn (Builder $query): Builder => $query->whereKey($viewer->id))
            ->select(['id', 'name'])->withCount(['assignedTickets as active_tickets_count' => fn (Builder $query): Builder => $query->visibleTo($viewer)->inQueue(TicketQueue::Active)])
            ->orderByDesc('active_tickets_count')->orderBy('name')->orderBy('id')->get();
        $attention = (clone $tickets)->inQueue(TicketQueue::Attention)->with('assignee:id,name')
            ->orderByRaw('CASE priority WHEN ? THEN 0 WHEN ? THEN 1 WHEN ? THEN 2 ELSE 3 END', [TicketPriority::Urgent->value, TicketPriority::High->value, TicketPriority::Normal->value])
            ->orderBy('created_at')->orderBy('id')->limit(5)->get(['id', 'title', 'status', 'priority', 'assigned_to', 'created_at']);
        $recentTickets = (clone $tickets)->with('assignee:id,name')->orderByDesc('created_at')->orderByDesc('id')
            ->limit(5)->get(['id', 'title', 'status', 'priority', 'assigned_to', 'created_at']);
        $recentActivities = TicketActivity::query()->whereHas('ticket', fn (Builder $ticket): Builder => $ticket->visibleTo($viewer))->when($mine, fn (Builder $query): Builder => $query->whereHas('ticket', fn (Builder $ticket): Builder => $ticket->where('assigned_to', $viewer->id)))
            ->with(['ticket:id,title', 'actor:id,name'])->orderByDesc('created_at')->orderByDesc('id')->limit(5)
            ->get(['id', 'ticket_id', 'actor_id', 'type', 'created_at']);

        return [
            'mine' => $mine, 'generatedAt' => $now, 'filters' => $mine ? ['assignee' => 'me'] : [],
            'metrics' => $metrics, 'statuses' => $statuses, 'priorities' => $priorities, 'agents' => $agents,
            'attentionTickets' => $attention, 'recentTickets' => $recentTickets, 'recentActivities' => $recentActivities,
        ];
    }
}
