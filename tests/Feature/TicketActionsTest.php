<?php

use App\Actions\Tickets\AddComment;
use App\Actions\Tickets\AssignTicket;
use App\Actions\Tickets\ChangeTicketStatus;
use App\Actions\Tickets\CreateTicket;
use App\Enums\TicketActivityType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Exceptions\StaleTicket;
use App\Models\Ticket;
use App\Models\User;
use App\Policies\TicketPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

test('both roles create a new unassigned ticket with server owned identity and history', function (bool $administrator) {
    $this->travelTo('2026-02-01 12:00:00');
    $actor = $administrator ? User::factory()->administrator()->create() : User::factory()->create();

    $ticket = app(CreateTicket::class)->execute($actor, [...ticketCoreInput(), 'title' => '  Une demande fictive  ']);

    expect($ticket->reference)->toBe('DS-0001');
    expect($ticket->title)->toBe('Une demande fictive');
    expect($ticket->status)->toBe(TicketStatus::New);
    expect($ticket->priority)->toBe(TicketPriority::Normal);
    expect($ticket->version)->toBe(1);
    expect($ticket->creator->is($actor))->toBeTrue();
    expect($ticket->assignee)->toBeNull();
    expect($actor->createdTickets->sole()->is($ticket))->toBeTrue();
    $activity = $ticket->activities->sole();
    expect($activity->type)->toBe(TicketActivityType::TicketCreated);
    expect($activity->actor->is($actor))->toBeTrue();
    expect($activity->created_at->toDateTimeString())->toBe('2026-02-01 12:00:00');
    expect(array_keys($activity->changes))->toBe(['title', 'description', 'requester_name', 'requester_email', 'priority', 'status']);
    expect($activity->changes['status'])->toBe(['from' => null, 'to' => 'new']);
    $this->assertDatabaseCount('tickets', 1);
    $this->assertDatabaseCount('ticket_activities', 1);
})->with(['administrator' => true, 'agent' => false]);

test('creation refuses controlled attributes even when empty', function (string $field) {
    $actor = User::factory()->create();

    expect(fn () => app(CreateTicket::class)->execute($actor, [...ticketCoreInput(), $field => null]))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseCount('tickets', 0);
    $this->assertDatabaseCount('ticket_activities', 0);
})->with(['id', 'reference', 'status', 'created_by', 'assigned_to', 'resolved_at', 'closed_at', 'version', 'role', 'created_at', 'updated_at']);

test('creation rejects invalid ticket fields without an activity', function (string $field, mixed $value) {
    $actor = User::factory()->create();

    try {
        app(CreateTicket::class)->execute($actor, [...ticketCoreInput(), $field => $value]);
        $this->fail('Invalid ticket was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($field);
    }

    $this->assertDatabaseCount('tickets', 0);
    $this->assertDatabaseCount('ticket_activities', 0);
})->with([
    'short title' => ['title', 'abcd'], 'long title' => ['title', str_repeat('a', 161)],
    'blank title' => ['title', " \t\n "], 'non text title' => ['title', ['title']],
    'short description' => ['description', 'abcdefghi'], 'long description' => ['description', str_repeat('a', 10001)],
    'short contact' => ['requester_name', 'a'], 'long contact' => ['requester_name', str_repeat('a', 121)],
    'invalid email' => ['requester_email', 'not-an-email'], 'long email' => ['requester_email', str_repeat('a', 255).'@deskops.example'],
    'unknown priority' => ['priority', 'critical'], 'null priority' => ['priority', null],
]);

test('creation accepts the documented length boundaries and explicit priority', function (int $titleLength, int $descriptionLength, int $nameLength) {
    $actor = User::factory()->create();

    $ticket = app(CreateTicket::class)->execute($actor, [
        ...ticketCoreInput(), 'title' => str_repeat('a', $titleLength), 'description' => str_repeat('d', $descriptionLength),
        'requester_name' => str_repeat('n', $nameLength), 'priority' => 'urgent',
    ]);

    expect($ticket->priority)->toBe(TicketPriority::Urgent);
    $this->assertModelExists($ticket);
})->with(['minimum' => [5, 10, 2], 'maximum' => [160, 10000, 120]]);

$transitions = [
    'start' => ['new', 'in_progress'], 'wait' => ['in_progress', 'waiting_customer'],
    'resolve running' => ['in_progress', 'resolved'], 'resume waiting' => ['waiting_customer', 'in_progress'],
    'resolve waiting' => ['waiting_customer', 'resolved'], 'reopen resolved' => ['resolved', 'in_progress'],
    'close' => ['resolved', 'closed'], 'reopen closed' => ['closed', 'in_progress'],
];

test('each allowed transition persists the correct dates and append-only activity', function (string $from, string $to) {
    $this->travelTo('2026-02-01 12:00:00');
    $admin = User::factory()->administrator()->create();
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->inStatus(TicketStatus::from($from))->assignedTo($agent)->create();
    $this->travelTo('2026-02-01 13:00:00');

    $result = app(ChangeTicketStatus::class)->execute($admin, $ticket, ['version' => 1, 'status' => $to, 'reason' => 'Motif fictif de transition']);

    expect($result->status)->toBe(TicketStatus::from($to));
    expect($result->assigned_to)->toBe($agent->id);
    expect($result->version)->toBe(2);
    expect($result->resolved_at?->toDateTimeString())->toBe(match ($to) {
        'resolved' => '2026-02-01 13:00:00', 'closed' => '2026-02-01 12:00:00', default => null,
    });
    expect($result->closed_at?->toDateTimeString())->toBe($to === 'closed' ? '2026-02-01 13:00:00' : null);
    $activity = $result->activities->sole();
    expect($activity->type)->toBe(TicketActivityType::StatusChanged);
    expect($activity->changes['status'])->toBe(['from' => $from, 'to' => $to]);
    expect($activity->actor_id)->toBe($admin->id);
    expect($activity->reason)->toBe('Motif fictif de transition');
    $this->assertDatabaseCount('ticket_activities', 1);
})->with($transitions);

test('assigned agents may perform every non administrative transition', function (string $from, string $to) {
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->inStatus(TicketStatus::from($from))->assignedTo($agent)->create();

    $result = app(ChangeTicketStatus::class)->execute($agent, $ticket, ['version' => 1, 'status' => $to, 'reason' => 'Motif de démonstration']);

    expect($result->status->value)->toBe($to);
    expect($result->activities->sole()->actor_id)->toBe($agent->id);
})->with(array_slice($transitions, 0, 6));

test('illegal graph transitions do not change the ticket or history', function (string $from, string $to) {
    $admin = User::factory()->administrator()->create();
    $ticket = Ticket::factory()->inStatus(TicketStatus::from($from))->create();
    $before = $ticket->fresh()->getAttributes();

    expect(fn () => app(ChangeTicketStatus::class)->execute($admin, $ticket, ['version' => 1, 'status' => $to, 'reason' => 'Motif fictif']))
        ->toThrow(ValidationException::class);

    expect($ticket->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('ticket_activities', 0);
})->with([
    ['new', 'waiting_customer'], ['new', 'resolved'], ['new', 'closed'],
    ['in_progress', 'new'], ['in_progress', 'closed'], ['waiting_customer', 'new'], ['waiting_customer', 'closed'],
    ['resolved', 'new'], ['resolved', 'waiting_customer'], ['closed', 'new'], ['closed', 'waiting_customer'], ['closed', 'resolved'],
]);

test('repeating an authorized status is a true no-op', function (TicketStatus $status) {
    $admin = User::factory()->administrator()->create();
    $ticket = Ticket::factory()->inStatus($status)->create();
    $before = $ticket->fresh()->getAttributes();

    app(ChangeTicketStatus::class)->execute($admin, $ticket, ['version' => 1, 'status' => $status->value]);

    expect($ticket->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('ticket_activities', 0);
})->with(TicketStatus::cases());

test('required reasons cannot be omitted for any requiring transition', function (string $from, string $to) {
    $admin = User::factory()->administrator()->create();
    $ticket = Ticket::factory()->inStatus(TicketStatus::from($from))->create();

    expect(fn () => app(ChangeTicketStatus::class)->execute($admin, $ticket, ['version' => 1, 'status' => $to]))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => $from, 'version' => 1]);
    $this->assertDatabaseCount('ticket_activities', 0);
})->with([['in_progress', 'waiting_customer'], ['in_progress', 'resolved'], ['waiting_customer', 'resolved'], ['resolved', 'in_progress'], ['closed', 'in_progress']]);

test('invalid resolution summaries are refused even for trimmed target statuses', function (mixed $reason) {
    $admin = User::factory()->administrator()->create();
    $ticket = Ticket::factory()->inStatus(TicketStatus::InProgress)->create();

    expect(fn () => app(ChangeTicketStatus::class)->execute($admin, $ticket, ['version' => 1, 'status' => ' resolved ', 'reason' => $reason]))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => 'in_progress', 'version' => 1]);
    $this->assertDatabaseCount('ticket_activities', 0);
})->with(['short' => 'abcd', 'long' => str_repeat('a', 501), 'blank' => '     ', 'array' => [['reason']]]);

test('a new ticket cannot start before an agent is assigned', function () {
    $admin = User::factory()->administrator()->create();
    $ticket = Ticket::factory()->create();

    expect(fn () => app(ChangeTicketStatus::class)->execute($admin, $ticket, ['version' => 1, 'status' => 'in_progress']))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => 'new', 'version' => 1]);
    $this->assertDatabaseCount('ticket_activities', 0);
});

test('administrators reassign without altering status and record exact before and after values', function () {
    $admin = User::factory()->administrator()->create();
    $first = User::factory()->create();
    $second = User::factory()->create();
    $ticket = Ticket::factory()->inStatus(TicketStatus::InProgress)->assignedTo($first)->create();

    $result = app(AssignTicket::class)->execute($admin, $ticket, ['version' => 1, 'assigned_to' => $second->id]);

    expect($result->assignee->is($second))->toBeTrue();
    expect($second->assignedTickets->sole()->is($result))->toBeTrue();
    expect($result->status)->toBe(TicketStatus::InProgress);
    expect($result->activities->sole()->changes)->toBe(['assigned_to' => ['from' => $first->id, 'to' => $second->id]]);
    expect($result->version)->toBe(2);
});

test('an agent may take a new unassigned ticket for themselves', function () {
    $actor = User::factory()->create();
    $ticket = Ticket::factory()->create();

    $result = app(AssignTicket::class)->execute($actor, $ticket, ['version' => 1, 'assigned_to' => $actor->id]);

    expect($result->assigned_to)->toBe($actor->id);
    expect($result->version)->toBe(2);
    $this->assertDatabaseCount('ticket_activities', 1);
});

test('an agent cannot assign a new ticket to another agent', function () {
    $actor = User::factory()->create();
    $other = User::factory()->create();
    $ticket = Ticket::factory()->create();

    expect(fn () => app(AssignTicket::class)->execute($actor, $ticket, ['version' => 1, 'assigned_to' => $other->id]))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'assigned_to' => null, 'version' => 1]);
    $this->assertDatabaseCount('ticket_activities', 0);
});

test('invalid assignees are rejected without writes', function (string $kind) {
    $admin = User::factory()->administrator()->create();
    $ticket = Ticket::factory()->create();
    $target = match ($kind) {
        'admin' => $admin->id, 'unknown' => 999999, 'array' => ['1'], default => 'not-an-id'
    };

    expect(fn () => app(AssignTicket::class)->execute($admin, $ticket, ['version' => 1, 'assigned_to' => $target]))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'assigned_to' => null, 'version' => 1]);
    $this->assertDatabaseCount('ticket_activities', 0);
})->with(['admin', 'unknown', 'array', 'text']);

test('administrators may release a new ticket', function () {
    $admin = User::factory()->administrator()->create();
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->assignedTo($agent)->create();

    $result = app(AssignTicket::class)->execute($admin, $ticket, ['version' => 1, 'assigned_to' => null]);

    expect($result->assigned_to)->toBeNull();
    expect($result->status)->toBe(TicketStatus::New);
    expect($result->activities->sole()->changes)->toBe(['assigned_to' => ['from' => $agent->id, 'to' => null]]);
    $this->assertDatabaseCount('ticket_activities', 1);
});

test('administrators cannot release tickets after the new state', function (TicketStatus $status) {
    $admin = User::factory()->administrator()->create();
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->inStatus($status)->assignedTo($agent)->create();
    $operation = fn () => app(AssignTicket::class)->execute($admin, $ticket, ['version' => 1, 'assigned_to' => null]);

    expect($operation)->toThrow($status === TicketStatus::Closed ? AuthorizationException::class : ValidationException::class);
    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'assigned_to' => $agent->id, 'version' => 1]);
    $this->assertDatabaseCount('ticket_activities', 0);
})->with([TicketStatus::InProgress, TicketStatus::WaitingCustomer, TicketStatus::Resolved, TicketStatus::Closed]);

test('repeating an administrator assignment does not increment the version or add history', function () {
    $admin = User::factory()->administrator()->create();
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->assignedTo($agent)->create();
    $before = $ticket->fresh()->getAttributes();

    app(AssignTicket::class)->execute($admin, $ticket, ['version' => 1, 'assigned_to' => $agent->id]);

    expect($ticket->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('ticket_activities', 0);
});

test('obsolete mutations including no-ops fail with an explicit conflict', function (string $operation) {
    $admin = User::factory()->administrator()->create();
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->create();
    app(AssignTicket::class)->execute($admin, $ticket, ['version' => 1, 'assigned_to' => $agent->id]);

    expect(fn () => match ($operation) {
        'assign' => app(AssignTicket::class)->execute($admin, $ticket, ['version' => 1, 'assigned_to' => $agent->id]),
        'status' => app(ChangeTicketStatus::class)->execute($admin, $ticket, ['version' => 1, 'status' => 'in_progress']),
        'no-op' => app(ChangeTicketStatus::class)->execute($admin, $ticket, ['version' => 1, 'status' => 'new']),
        'comment' => app(AddComment::class)->execute($admin, $ticket, ['version' => 1, 'body' => 'Commentaire fictif']),
    })->toThrow(StaleTicket::class);

    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'assigned_to' => $agent->id, 'status' => 'new', 'version' => 2]);
    $this->assertDatabaseCount('ticket_activities', 1);
    $this->assertDatabaseCount('comments', 0);
})->with(['assign', 'status', 'no-op', 'comment']);

test('ticket permissions depend on assignment not permanent creator privileges', function (TicketStatus $status) {
    $admin = User::factory()->administrator()->create();
    $creator = User::factory()->create();
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->inStatus($status)->assignedTo($agent)->create(['created_by' => $creator->id]);
    $policy = new TicketPolicy;

    expect($policy->view($creator, $ticket))->toBeTrue();
    expect($policy->view($admin, $ticket))->toBeTrue();
    expect($policy->create($creator))->toBeTrue();
    expect($policy->update($creator, $ticket))->toBeFalse();
    expect($policy->comment($creator, $ticket))->toBeFalse();
    expect($policy->assign($creator, $ticket, $creator->id))->toBeFalse();
    expect($policy->changeStatus($creator, $ticket, TicketStatus::InProgress))->toBeFalse();
    expect($policy->update($agent, $ticket))->toBe($status !== TicketStatus::Closed);
    expect($policy->comment($agent, $ticket))->toBe($status !== TicketStatus::Closed);
    expect($policy->update($admin, $ticket))->toBe($status !== TicketStatus::Closed);
    expect($policy->assign($admin, $ticket, $agent->id))->toBe($status !== TicketStatus::Closed);
    expect($policy->changeStatus($agent, $ticket, TicketStatus::Closed))->toBeFalse();
    expect($policy->changeStatus($agent, $ticket, TicketStatus::InProgress))->toBe($status !== TicketStatus::Closed);
    expect($policy->changeStatus($admin, $ticket, TicketStatus::InProgress))->toBeTrue();
    expect($policy->delete($admin, $ticket))->toBeFalse();
})->with(TicketStatus::cases());

test('agents cannot close resolved tickets or reopen closed tickets', function (string $from, string $to) {
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->inStatus(TicketStatus::from($from))->assignedTo($agent)->create();

    expect(fn () => app(ChangeTicketStatus::class)->execute($agent, $ticket, ['version' => 1, 'status' => $to, 'reason' => 'Motif fictif']))
        ->toThrow(AuthorizationException::class);
    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => $from, 'version' => 1]);
    $this->assertDatabaseCount('ticket_activities', 0);
})->with([['resolved', 'closed'], ['closed', 'in_progress']]);

test('comment activity references the comment without duplicating its body', function () {
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->inStatus(TicketStatus::InProgress)->assignedTo($agent)->create();

    $result = app(AddComment::class)->execute($agent, $ticket, ['version' => 1, 'body' => 'Suivi interne fictif.']);

    $comment = $result->comments->sole();
    expect($comment->author->is($agent))->toBeTrue();
    expect($result->activities->sole()->changes)->toBe(['comment_id' => ['from' => null, 'to' => $comment->id]]);
    expect($result->version)->toBe(2);
});

test('blank or oversized comments are refused without writes', function (string $body) {
    $admin = User::factory()->administrator()->create();
    $ticket = Ticket::factory()->create();

    expect(fn () => app(AddComment::class)->execute($admin, $ticket, ['version' => 1, 'body' => $body]))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseCount('comments', 0);
    $this->assertDatabaseCount('ticket_activities', 0);
    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'version' => 1]);
})->with(['spaces' => " \t\n ", 'unicode whitespace' => "\u{00A0}\u{2003}", 'long' => str_repeat('a', 5001)]);

test('neither role may comment on a closed ticket', function (bool $administrator) {
    $agent = User::factory()->create();
    $actor = $administrator ? User::factory()->administrator()->create() : $agent;
    $ticket = Ticket::factory()->inStatus(TicketStatus::Closed)->assignedTo($agent)->create();

    expect(fn () => app(AddComment::class)->execute($actor, $ticket, ['version' => 1, 'body' => 'Commentaire refusé']))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => 'closed', 'version' => 1]);
    $this->assertDatabaseCount('comments', 0);
    $this->assertDatabaseCount('ticket_activities', 0);
})->with(['administrator' => true, 'agent' => false]);
