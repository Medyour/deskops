<?php

use App\Actions\Tickets\AssignTicket;
use App\Actions\Tickets\ChangeTicketStatus;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;

test('ticket mutation endpoints require a session', function (string $operation) {
    $ticket = Ticket::factory()->create();
    $response = $operation === 'store'
        ? $this->post(route('tickets.store'), ticketCoreInput())
        : $this->patch(route($operation, $ticket), ['version' => 1, 'status' => 'in_progress', 'assigned_to' => null]);

    $response->assertRedirect(route('login'));
    $this->assertDatabaseCount('ticket_activities', 0);
})->with(['store', 'tickets.assign', 'tickets.change-status']);

test('session authenticated creation persists a ticket and redirects to its detail', function () {
    $actor = User::factory()->create();

    $this->actingAs($actor)->post(route('tickets.store'), ticketCoreInput())
        ->assertRedirect(route('tickets.show', 1))->assertSessionHas('status', 'Ticket DS-0001 créé.');

    $this->assertDatabaseHas('tickets', ['created_by' => $actor->id, 'status' => 'new', 'assigned_to' => null]);
    $this->assertDatabaseCount('ticket_activities', 1);
});

test('invalid browser input returns 422 without persisting a ticket', function () {
    $actor = User::factory()->create();

    $this->actingAs($actor)->postJson(route('tickets.store'), [...ticketCoreInput(), 'title' => 'bad'])
        ->assertUnprocessable()->assertJsonValidationErrors(['title']);

    $this->assertDatabaseCount('tickets', 0);
    $this->assertDatabaseCount('ticket_activities', 0);
});

test('missing creation fields return 422 without a ticket', function () {
    $actor = User::factory()->create();

    $this->actingAs($actor)->postJson(route('tickets.store'), [])
        ->assertUnprocessable()->assertJsonValidationErrors(['title', 'description', 'requester_name', 'requester_email']);

    $this->assertDatabaseCount('tickets', 0);
});

test('a creator who is not assigned receives 403 on status mutation', function () {
    $actor = User::factory()->create();
    $ticket = Ticket::factory()->inStatus(TicketStatus::InProgress)->create(['created_by' => $actor->id]);

    $this->actingAs($actor)->patchJson(route('tickets.change-status', $ticket), ['version' => 1, 'status' => 'waiting_customer', 'reason' => 'Information manquante'])
        ->assertForbidden();

    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => 'in_progress', 'version' => 1]);
    $this->assertDatabaseCount('ticket_activities', 0);
});

test('a second claim with an obsolete version receives 409 and does not steal the ticket', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    $ticket = Ticket::factory()->create();
    app(AssignTicket::class)->execute($first, $ticket, ['version' => 1, 'assigned_to' => $first->id]);

    $this->actingAs($second)->patchJson(route('tickets.assign', $ticket), ['version' => 1, 'assigned_to' => $second->id])
        ->assertConflict()->assertJsonPath('message', 'Ce ticket a changé ou est temporairement occupé. Rechargez-le avant de réessayer.');

    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'assigned_to' => $first->id, 'version' => 2]);
    $this->assertDatabaseCount('ticket_activities', 1);
});

test('the status endpoint invokes the business action and preserves its activity', function () {
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->inStatus(TicketStatus::InProgress)->assignedTo($agent)->create();

    $this->actingAs($agent)->patch(route('tickets.change-status', $ticket), ['version' => 1, 'status' => 'resolved', 'reason' => 'Solution fictive proposée'])
        ->assertRedirect(route('tickets.show', $ticket));

    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => 'resolved', 'version' => 2]);
    $this->assertDatabaseHas('ticket_activities', ['ticket_id' => $ticket->id, 'actor_id' => $agent->id, 'reason' => 'Solution fictive proposée']);
});

test('mutation endpoints reject invalid versions', function (mixed $version) {
    $admin = User::factory()->administrator()->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($admin)->patchJson(route('tickets.assign', $ticket), ['version' => $version, 'assigned_to' => null])
        ->assertUnprocessable()->assertJsonValidationErrors(['version']);

    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'version' => 1]);
    $this->assertDatabaseCount('ticket_activities', 0);
})->with(['missing' => null, 'zero' => 0, 'negative' => -1, 'decimal' => 1.5, 'text' => 'unknown']);

test('nonexistent tickets return 404', function () {
    $agent = User::factory()->create();

    $this->actingAs($agent)->patchJson(route('tickets.assign', 9999), ['version' => 1, 'assigned_to' => $agent->id])->assertNotFound();

    $this->assertDatabaseCount('ticket_activities', 0);
});

test('ticket mutations retain web CSRF protection', function () {
    $agent = User::factory()->create();
    $this->app->detectEnvironment(fn (): string => 'production');

    $this->actingAs($agent)->post(route('tickets.store'), ticketCoreInput())->assertStatus(419);

    $this->assertDatabaseCount('tickets', 0);
    $this->assertDatabaseCount('ticket_activities', 0);
});

test('the status endpoint requires a resolution summary', function () {
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->inStatus(TicketStatus::InProgress)->assignedTo($agent)->create();

    $this->actingAs($agent)->patchJson(route('tickets.change-status', $ticket), ['version' => 1, 'status' => 'resolved'])
        ->assertUnprocessable()->assertJsonValidationErrors(['reason']);

    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => 'in_progress', 'version' => 1, 'resolved_at' => null]);
    $this->assertDatabaseCount('ticket_activities', 0);
});

test('a stale status request returns 409 before evaluating newly required transition reasons', function () {
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->inStatus(TicketStatus::InProgress)->assignedTo($agent)->create();
    app(ChangeTicketStatus::class)->execute($agent, $ticket, ['version' => 1, 'status' => 'resolved', 'reason' => 'Solution concurrente fictive']);
    $before = $ticket->fresh()->getAttributes();

    $this->actingAs($agent)->patchJson(route('tickets.change-status', $ticket), ['version' => 1, 'status' => 'in_progress'])
        ->assertConflict();

    expect($ticket->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('ticket_activities', 1);
});

test('an agent cannot assign a new ticket to a colleague through the endpoint', function () {
    $agent = User::factory()->create();
    $colleague = User::factory()->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)->patchJson(route('tickets.assign', $ticket), ['version' => 1, 'assigned_to' => $colleague->id])->assertForbidden();

    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'assigned_to' => null, 'version' => 1]);
    $this->assertDatabaseCount('ticket_activities', 0);
});
