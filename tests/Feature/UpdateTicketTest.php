<?php

use App\Actions\Tickets\UpdateTicket;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('editing fields and priority writes two categories with a single version increment', function () {
    $this->freezeTime();
    $admin = User::factory()->administrator()->create();
    $ticket = Ticket::factory()->create(ticketCoreInput());
    $before = $ticket->fresh()->getAttributes();

    app(UpdateTicket::class)->execute($admin, $ticket, [...ticketCoreInput(), 'version' => 1, 'title' => 'Sujet fictif corrigé', 'priority' => 'urgent']);

    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'title' => 'Sujet fictif corrigé', 'priority' => 'urgent', 'version' => 2, 'status' => 'new', 'assigned_to' => null]);
    $this->assertDatabaseCount('ticket_activities', 2);
    expect($ticket->fresh()->activities->pluck('changes')->all())->toBe([
        ['title' => ['from' => $before['title'], 'to' => 'Sujet fictif corrigé']],
        ['priority' => ['from' => 'normal', 'to' => 'urgent']],
    ]);
    expect($ticket->fresh()->activities->pluck('actor_id')->unique()->all())->toBe([$admin->id]);
});

test('an unchanged edit leaves version timestamps and history intact', function () {
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->assignedTo($agent)->create(ticketCoreInput());
    $before = $ticket->fresh()->getAttributes();
    $this->travel(1)->hour();

    app(UpdateTicket::class)->execute($agent, $ticket, [...ticketCoreInput(), 'version' => 1, 'priority' => 'normal']);

    expect($ticket->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('ticket_activities', 0);
});

test('editing fields alone or priority alone records only the changed category', function (array $change, string $type) {
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->assignedTo($agent)->create(ticketCoreInput());

    app(UpdateTicket::class)->execute($agent, $ticket, [...ticketCoreInput(), 'version' => 1, 'priority' => 'normal', ...$change]);

    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'version' => 2, ...$change]);
    $this->assertDatabaseCount('ticket_activities', 1);
    $this->assertDatabaseHas('ticket_activities', ['ticket_id' => $ticket->id, 'type' => $type]);
})->with([
    'contact' => [['requester_name' => 'Contact fictif corrigé', 'requester_email' => 'autre@deskops.example', 'description' => 'Description fictive corrigée.'], 'ticket_updated'],
    'priority' => [['priority' => 'high'], 'priority_changed'],
]);

test('invalid edits return 422 without changing the ticket or its history', function (string $field, mixed $value) {
    $admin = User::factory()->administrator()->create();
    $ticket = Ticket::factory()->create(ticketCoreInput());
    $before = $ticket->fresh()->getAttributes();

    $this->actingAs($admin)->patchJson(route('tickets.update', $ticket), [...ticketCoreInput(), 'version' => 1, 'priority' => 'normal', $field => $value])
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    expect($ticket->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('ticket_activities', 0);
})->with([
    'short title' => ['title', 'abc'], 'long title' => ['title', str_repeat('a', 161)],
    'short description' => ['description', 'abc'], 'long description' => ['description', str_repeat('a', 10001)],
    'short contact' => ['requester_name', 'a'], 'long contact' => ['requester_name', str_repeat('a', 121)],
    'invalid email' => ['requester_email', 'invalid'], 'missing priority' => ['priority', null],
    'unknown priority' => ['priority', 'critical'], 'missing version' => ['version', null],
    'invalid version' => ['version', 0], 'forged status' => ['status', 'closed'],
    'forged assignee' => ['assigned_to', null], 'forged creator' => ['created_by', 1],
    'forged timestamp' => ['resolved_at', '2026-01-01 00:00:00'],
]);

test('agents cannot edit unassigned tickets and administrators cannot edit closed tickets', function (bool $administrator, TicketStatus $status) {
    $actor = $administrator ? User::factory()->administrator()->create() : User::factory()->create();
    $ticket = Ticket::factory()->inStatus($status)->create(ticketCoreInput());
    $before = $ticket->fresh()->getAttributes();

    $this->actingAs($actor)->patchJson(route('tickets.update', $ticket), [...ticketCoreInput(), 'version' => 1, 'priority' => 'urgent'])->assertForbidden();

    expect($ticket->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('ticket_activities', 0);
})->with(['unassigned agent' => [false, TicketStatus::New], 'closed admin' => [true, TicketStatus::Closed]]);

test('an obsolete edit returns 409 and cannot overwrite a successful edit', function () {
    $admin = User::factory()->administrator()->create();
    $ticket = Ticket::factory()->create(ticketCoreInput());
    app(UpdateTicket::class)->execute($admin, $ticket, [...ticketCoreInput(), 'version' => 1, 'priority' => 'urgent']);

    $this->actingAs($admin)->patchJson(route('tickets.update', $ticket), [...ticketCoreInput(), 'version' => 1, 'priority' => 'low'])->assertConflict();

    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'priority' => 'urgent', 'version' => 2]);
    $this->assertDatabaseCount('ticket_activities', 1);
});

test('failure to record the second edit category rolls back all edits and activities', function () {
    $admin = User::factory()->administrator()->create();
    $ticket = Ticket::factory()->create(ticketCoreInput());
    $before = $ticket->fresh()->getAttributes();
    DB::unprepared("CREATE TRIGGER fail_priority_activity BEFORE INSERT ON ticket_activities WHEN NEW.type = 'priority_changed' BEGIN SELECT RAISE(ABORT, 'simulated history failure'); END");

    try {
        expect(fn () => app(UpdateTicket::class)->execute($admin, $ticket, [...ticketCoreInput(), 'version' => 1, 'title' => 'Sujet fictif corrigé', 'priority' => 'urgent']))->toThrow(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER fail_priority_activity');
    }

    expect($ticket->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('ticket_activities', 0);
});
