<?php

namespace App\Http\Requests;

use App\Enums\TicketPriority;
use App\Enums\TicketQueue;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class IndexTicketsRequest extends TicketFormRequest
{
    protected $redirectRoute = 'tickets.index';

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Ticket::class);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(TicketStatus::class)],
            'priority' => ['nullable', Rule::enum(TicketPriority::class)],
            'queue' => ['nullable', Rule::enum(TicketQueue::class)],
            'assignee' => ['nullable', Rule::in(['me', 'unassigned', ...User::query()->where('role', UserRole::Agent)->when($this->user()->demoRestricted(), fn (Builder $query): Builder => $query->whereNotNull('demo_identity'))->pluck('id')->map(fn (int $id): string => (string) $id)->all()])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
