<?php

use App\Actions\Tickets\AddComment;
use App\Actions\Tickets\AssignTicket;
use App\Actions\Tickets\ChangeTicketStatus;
use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\UpdateTicket;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

test('ticket pages require authentication', function (string $page) {
    $ticket = Ticket::factory()->create();

    $this->get(route($page, in_array($page, ['tickets.show', 'tickets.edit'], true) ? $ticket : []))->assertRedirect(route('login'));
})->with(['tickets.index', 'tickets.create', 'tickets.show', 'tickets.edit']);

test('both roles can browse all tickets and open creation', function (bool $administrator) {
    $actor = $administrator ? User::factory()->administrator()->create() : User::factory()->create();
    $ticket = Ticket::factory()->create(['title' => 'Demande fictive lisible']);

    $this->actingAs($actor)->get(route('tickets.index'))->assertOk()->assertSee($ticket->reference)->assertSee('Demande fictive lisible');
    $this->get(route('tickets.create'))->assertOk()->assertSee('Créer un ticket');
    $this->get(route('tickets.show', $ticket))->assertOk()->assertSee($ticket->description)->assertSee($ticket->requester_name);
})->with(['administrator' => true, 'agent' => false]);

test('search combines with all filters using AND and paginates with persistent parameters', function () {
    $this->freezeTime();
    $agent = User::factory()->create();
    $other = User::factory()->create();
    $matching = Ticket::factory()->count(23)->inStatus(TicketStatus::InProgress)->assignedTo($agent)->create(['title' => 'Export fictif', 'priority' => 'high']);
    Ticket::factory()->inStatus(TicketStatus::InProgress)->assignedTo($other)->create(['title' => 'Export fictif', 'priority' => 'high']);
    Ticket::factory()->assignedTo($agent)->create(['title' => 'Export fictif', 'priority' => 'high']);
    Ticket::factory()->inStatus(TicketStatus::InProgress)->assignedTo($agent)->create(['title' => 'Export fictif', 'priority' => 'low']);
    Ticket::factory()->inStatus(TicketStatus::InProgress)->assignedTo($agent)->create(['title' => 'Autre demande fictive', 'priority' => 'high']);
    $filters = ['q' => '  Export  ', 'status' => 'in_progress', 'priority' => 'high', 'assignee' => 'me'];

    $response = $this->actingAs($agent)->get(route('tickets.index', $filters));

    $response->assertOk()->assertSee('23 tickets trouvés')->assertViewHas('tickets', fn ($tickets): bool => $tickets->total() === 23 && $tickets->count() === 20 && $tickets->first()->id === $matching->last()->id);
    $response->assertSee(route('tickets.index', ['q' => 'Export', 'status' => 'in_progress', 'priority' => 'high', 'assignee' => 'me', 'page' => 2]));
    $this->get(route('tickets.index', [...$filters, 'page' => 2]))->assertOk()->assertViewHas('tickets', fn ($tickets): bool => $tickets->count() === 3 && $tickets->last()->id === $matching->first()->id);
});

test('search finds each contracted field without matching descriptions', function (string $search) {
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->create(['title' => 'Sujet Needle fictif', 'requester_name' => 'Contact Needle', 'requester_email' => 'needle@deskops.example']);
    Ticket::factory()->create(['title' => 'Autre demande fictive', 'requester_name' => 'Autre contact', 'requester_email' => 'autre@deskops.example', 'description' => 'Description Needle uniquement.']);

    $this->actingAs($agent)->get(route('tickets.index', ['q' => $search]))->assertOk()
        ->assertViewHas('tickets', fn ($tickets): bool => $tickets->total() === 1 && $tickets->first()->id === $ticket->id);
})->with(['title' => 'Sujet Needle', 'contact' => 'Contact Needle', 'email' => 'needle@', 'reference case insensitive' => 'ds-0001']);

test('reference search stays exact beyond DS-9999', function (string $search, int $count) {
    $agent = User::factory()->create();
    Ticket::factory()->create(['id' => 10000]);

    $this->actingAs($agent)->get(route('tickets.index', ['q' => $search]))->assertOk()
        ->assertViewHas('tickets', fn ($tickets): bool => $tickets->total() === $count);
})->with(['large id' => ['ds-10000', 1], 'extra leading zero' => ['DS-010000', 0], 'partial reference' => ['DS-1000', 0]]);

test('SQL wildcard and escape characters are searched literally', function (string $literal) {
    $agent = User::factory()->create();
    $match = Ticket::factory()->create(['title' => 'Demande '.$literal.' fictive']);
    Ticket::factory()->create(['title' => 'Demande ordinaire fictive']);

    $this->actingAs($agent)->get(route('tickets.index', ['q' => $literal]))->assertOk()
        ->assertViewHas('tickets', fn ($tickets): bool => $tickets->total() === 1 && $tickets->first()->id === $match->id);
})->with(['percent' => '%', 'underscore' => '_', 'backslash' => '\\', 'SQL text' => "' OR 1=1 --"]);

test('assignment filters select an agent the current user or unassigned tickets', function (string $filter) {
    $agent = User::factory()->create();
    $other = User::factory()->create();
    $mine = Ticket::factory()->assignedTo($agent)->create();
    $theirs = Ticket::factory()->assignedTo($other)->create();
    $unassigned = Ticket::factory()->create();
    $assignee = $filter === 'id' ? (string) $other->id : $filter;
    $expected = match ($filter) {
        'me' => $mine->id, 'id' => $theirs->id, default => $unassigned->id
    };

    $this->actingAs($agent)->get(route('tickets.index', ['assignee' => $assignee]))->assertOk()
        ->assertViewHas('tickets', fn ($tickets): bool => $tickets->total() === 1 && $tickets->first()->id === $expected);
})->with(['me', 'id', 'unassigned']);

test('invalid search and filter values return 422 instead of being discarded', function (array $filters, string $field) {
    $agent = User::factory()->create();

    $this->actingAs($agent)->getJson(route('tickets.index', $filters))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'unknown status' => [['status' => 'invalid'], 'status'],
    'unknown priority' => [['priority' => 'critical'], 'priority'],
    'unknown assignee' => [['assignee' => 'someone'], 'assignee'],
    'missing agent' => [['assignee' => '9999'], 'assignee'],
    'array search' => [['q' => ['invalid']], 'q'],
    'long search' => [['q' => str_repeat('a', 101)], 'q'],
    'negative page' => [['page' => -1], 'page'],
]);

test('an administrator id cannot be used as an assignee filter', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)->getJson(route('tickets.index', ['assignee' => $admin->id]))->assertUnprocessable()->assertJsonValidationErrors('assignee');
});

test('invalid browser filters show validation and retain the search input', function () {
    $agent = User::factory()->create();

    $this->actingAs($agent)->get(route('tickets.index', ['status' => 'invalid', 'q' => 'Saisie fictive']))->assertRedirect(route('tickets.index'));
    $this->get(route('tickets.index'))->assertOk()->assertSee('La valeur du champ statut est inconnue.')->assertSee('Saisie fictive');
});

test('an empty search offers reset and creation navigation', function () {
    $agent = User::factory()->create();

    $this->actingAs($agent)->get(route('tickets.index', ['q' => 'Introuvable']))->assertOk()
        ->assertSee('0 tickets trouvés')->assertSee('Aucun ticket à afficher')->assertSee('Réinitialiser les filtres')->assertSee(route('tickets.create'));
});

test('the list loads relations in a bounded number of queries', function (int $count) {
    $agent = User::factory()->create();
    Ticket::factory()->count($count)->assignedTo($agent)->recycle($agent)->create();
    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->actingAs($agent)->get(route('tickets.index'))->assertOk();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(count($queries))->toBeLessThanOrEqual(9);
})->with(['two tickets' => 2, 'full page' => 20]);

test('detail escapes every user text and presents ordered comments and history', function () {
    $this->freezeTime();
    $markup = '<script>alert("fictif")</script>';
    $admin = User::factory()->administrator()->create(['name' => $markup]);
    $agent = User::factory()->create(['name' => '<svg onload="alert(1)">']);
    $ticket = app(CreateTicket::class)->execute($admin, [...ticketCoreInput(), 'title' => $markup, 'description' => 'Description '.$markup, 'requester_name' => $markup]);
    $ticket = app(AssignTicket::class)->execute($admin, $ticket, ['version' => 1, 'assigned_to' => $agent->id]);
    $ticket = app(ChangeTicketStatus::class)->execute($admin, $ticket, ['version' => 2, 'status' => 'in_progress']);
    $ticket = app(ChangeTicketStatus::class)->execute($admin, $ticket, ['version' => 3, 'status' => 'waiting_customer', 'reason' => $markup]);
    $ticket = app(AddComment::class)->execute($agent, $ticket, ['version' => 4, 'body' => 'Premier commentaire '.$markup]);
    app(AddComment::class)->execute($admin, $ticket, ['version' => 5, 'body' => 'Second commentaire fictif']);

    $response = $this->actingAs($agent)->get(route('tickets.show', $ticket));

    $response->assertOk()->assertSee($markup)->assertSee($agent->name)->assertDontSee($markup, false)->assertDontSee($agent->name, false)
        ->assertSeeInOrder(['Premier commentaire', 'Second commentaire', 'Ticket créé', 'Assignation modifiée', 'Statut modifié', 'Commentaire ajouté'])
        ->assertSee('Voir le commentaire')->assertSee('Heures de Paris');
});

test('detail uses a bounded number of queries with many actors and activities', function () {
    $admin = User::factory()->administrator()->create();
    $ticket = app(CreateTicket::class)->execute($admin, ticketCoreInput());
    for ($number = 0; $number < 10; $number++) {
        app(AddComment::class)->execute($admin, $ticket, ['version' => $ticket->fresh()->version, 'body' => 'Commentaire fictif '.$number]);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();

    $this->actingAs($admin)->get(route('tickets.show', $ticket))->assertOk();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect(count($queries))->toBeLessThanOrEqual(12);
});

test('creation validation is French and retains submitted values', function () {
    $agent = User::factory()->create();

    $this->actingAs($agent)->from(route('tickets.create'))->post(route('tickets.store'), [...ticketCoreInput(), 'title' => 'abc'])
        ->assertRedirect(route('tickets.create'));
    $this->get(route('tickets.create'))->assertOk()->assertSee('Le champ sujet doit contenir au moins 5 caractères.')
        ->assertSee('value="abc"', false)->assertSee(ticketCoreInput()['description'])->assertSee('aria-invalid="true"', false);
    $this->assertDatabaseCount('tickets', 0);
});

test('agents cannot open edit pages for tickets assigned to a colleague', function () {
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->inStatus(TicketStatus::InProgress)->create();

    $this->actingAs($agent)->get(route('tickets.edit', $ticket))->assertForbidden()
        ->assertSee('Accès refusé')->assertSee('Cette action n’est pas autorisée.')->assertSee(route('tickets.index'));
});

test('an assigned agent can edit fields through the workspace and returns to the detail', function () {
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->assignedTo($agent)->create(ticketCoreInput());

    $this->actingAs($agent)->get(route('tickets.edit', $ticket))->assertOk()->assertSee(ticketCoreInput()['title']);
    $this->patch(route('tickets.update', $ticket), [...ticketCoreInput(), 'version' => 1, 'priority' => 'high'])
        ->assertRedirect(route('tickets.show', $ticket))->assertSessionHas('status', 'Informations enregistrées.');

    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'priority' => 'high', 'version' => 2]);
    $this->assertDatabaseCount('ticket_activities', 1);
});

test('a browser comment is recorded once and redirects to its ticket', function () {
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->assignedTo($agent)->create();

    $this->actingAs($agent)->post(route('tickets.comment', $ticket), ['version' => 1, 'body' => 'Commentaire interne fictif'])
        ->assertRedirect(route('tickets.show', $ticket))->assertSessionHas('status', 'Commentaire ajouté.');

    $this->assertDatabaseHas('comments', ['ticket_id' => $ticket->id, 'author_id' => $agent->id, 'body' => 'Commentaire interne fictif']);
    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'version' => 2]);
    $this->assertDatabaseCount('comments', 1);
    $this->assertDatabaseCount('ticket_activities', 1);
});

test('invalid comments return 422 without writing history', function (mixed $body) {
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->assignedTo($agent)->create();

    $this->actingAs($agent)->postJson(route('tickets.comment', $ticket), ['version' => 1, 'body' => $body])
        ->assertUnprocessable()->assertJsonValidationErrors('body');

    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'version' => 1]);
    $this->assertDatabaseCount('comments', 0);
    $this->assertDatabaseCount('ticket_activities', 0);
})->with(['missing' => null, 'blank' => '   ', 'too long' => str_repeat('a', 5001)]);

test('an unassigned agent cannot comment by posting directly', function () {
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)->postJson(route('tickets.comment', $ticket), ['version' => 1, 'body' => 'Commentaire interdit'])->assertForbidden();

    $this->assertDatabaseCount('comments', 0);
    $this->assertDatabaseCount('ticket_activities', 0);
});

test('new mutation routes retain authentication and CSRF protection', function (string $operation) {
    $ticket = Ticket::factory()->create();

    $response = $operation === 'tickets.update'
        ? $this->patch(route($operation, $ticket), [...ticketCoreInput(), 'version' => 1, 'priority' => 'normal'])
        : $this->post(route($operation, $ticket), ['version' => 1, 'body' => 'Commentaire fictif']);
    $response->assertRedirect(route('login'));

    $this->app->detectEnvironment(fn (): string => 'production');
    $this->actingAs($ticket->creator);
    $response = $operation === 'tickets.update'
        ? $this->patch(route($operation, $ticket), [...ticketCoreInput(), 'version' => 1, 'priority' => 'normal'])
        : $this->post(route($operation, $ticket), ['version' => 1, 'body' => 'Commentaire fictif']);
    $response->assertStatus(419);
    $this->assertDatabaseCount('ticket_activities', 0);
})->with(['tickets.update', 'tickets.comment']);

test('a browser conflict preserves input shows current data and offers an explicit retry', function (string $operation, array $draft, string $text) {
    $admin = User::factory()->administrator()->create();
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->inStatus(TicketStatus::InProgress)->assignedTo($agent)->create(ticketCoreInput());
    app(UpdateTicket::class)->execute($admin, $ticket, [...ticketCoreInput(), 'version' => 1, 'title' => 'Sujet concurrent fictif', 'priority' => 'urgent']);
    $input = [...$draft, 'version' => 1];

    $this->actingAs($admin);
    $response = $operation === 'tickets.comment' ? $this->post(route($operation, $ticket), $input) : $this->patch(route($operation, $ticket), $input);

    $response->assertConflict()->assertSee('Votre saisie est conservée.')->assertSee('Sujet concurrent fictif')
        ->assertSee($text)->assertSee('Version observée : 1')->assertSee('Version actuelle : 2')->assertSee('après vérification')
        ->assertSee('name="version" value="2"', false);
    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'title' => 'Sujet concurrent fictif', 'priority' => 'urgent', 'version' => 2]);
    $this->assertDatabaseCount('ticket_activities', 2);
    $this->assertDatabaseCount('comments', 0);
})->with([
    'edit' => ['tickets.update', [...ticketCoreInput(), 'title' => 'Ma saisie fictive', 'priority' => 'low'], 'Ma saisie fictive'],
    'comment' => ['tickets.comment', ['body' => '<script>commentaire fictif</script>'], '<script>commentaire fictif</script>'],
    'status' => ['tickets.change-status', ['status' => 'resolved', 'reason' => 'Mon résumé fictif'], 'Mon résumé fictif'],
    'assignment' => ['tickets.assign', ['assigned_to' => null], 'Non assigné'],
]);

test('a conflict keeps the draft visible when reassignment removes edit permission', function () {
    $admin = User::factory()->administrator()->create();
    $agent = User::factory()->create();
    $other = User::factory()->create();
    $ticket = Ticket::factory()->inStatus(TicketStatus::InProgress)->assignedTo($agent)->create(ticketCoreInput());
    app(AssignTicket::class)->execute($admin, $ticket, ['version' => 1, 'assigned_to' => $other->id]);

    $this->actingAs($agent)->patch(route('tickets.update', $ticket), [...ticketCoreInput(), 'version' => 1, 'title' => 'Saisie conservée fictive', 'priority' => 'normal'])
        ->assertConflict()->assertSee('Saisie conservée fictive')->assertSee('Cette action n’est plus disponible')
        ->assertDontSee('Enregistrer après vérification');

    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'assigned_to' => $other->id, 'version' => 2]);
    $this->assertDatabaseCount('ticket_activities', 1);
});

test('a 409 preserves an explicit release selection and retries it with the current version', function () {
    $admin = User::factory()->administrator()->create();
    $agent = User::factory()->create();
    $other = User::factory()->create();
    $ticket = Ticket::factory()->assignedTo($agent)->create();
    app(AssignTicket::class)->execute($admin, $ticket, ['version' => 1, 'assigned_to' => $other->id]);

    $this->actingAs($admin)->patch(route('tickets.assign', $ticket), ['version' => 1, 'assigned_to' => null])
        ->assertConflict()->assertSee('<option value="" selected>Non assigné</option>', false)
        ->assertSee('name="version" value="2"', false);

    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'assigned_to' => $other->id, 'version' => 2]);
    $this->assertDatabaseCount('ticket_activities', 1);
    $this->patch(route('tickets.assign', $ticket), ['version' => 2, 'assigned_to' => null])->assertRedirect(route('tickets.show', $ticket));
    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'assigned_to' => null, 'version' => 3]);
    $this->assertDatabaseCount('ticket_activities', 2);
});

test('nonexistent detail pages return 404', function () {
    $agent = User::factory()->create();

    $this->actingAs($agent)->get(route('tickets.show', 99999))->assertNotFound();
});

test('workspace dates are displayed in Paris while stored timestamps remain UTC', function () {
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->create(['created_at' => '2026-01-15 09:00:00', 'updated_at' => '2026-01-15 09:00:00']);

    $this->actingAs($agent)->get(route('tickets.show', $ticket))->assertOk()->assertSee('15/01/2026 · 10:00');

    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'created_at' => '2026-01-15 09:00:00']);
});

test('status forms present the complete role matrix without inventing transitions', function (TicketStatus $status, string $role, array $expected) {
    $admin = User::factory()->administrator()->create();
    $assigned = User::factory()->create();
    $other = User::factory()->create();
    $ticket = Ticket::factory()->inStatus($status)->assignedTo($assigned)->create();
    $viewer = match ($role) {
        'admin' => $admin, 'assigned' => $assigned, default => $other
    };

    $response = $this->actingAs($viewer)->get(route('tickets.show', $ticket));

    $response->assertOk()->assertViewHas('transitions', fn (array $transitions): bool => array_map(fn (TicketStatus $target): string => $target->value, $transitions) === $expected);
    foreach ($expected as $target) {
        $response->assertSee('value="'.$target.'"', false);
    }
    if ($role === 'other' || $status === TicketStatus::Closed) {
        $response->assertDontSee(route('tickets.edit', $ticket));
        $response->assertDontSee('name="body"', false);
    } else {
        $response->assertSee(route('tickets.edit', $ticket))->assertSee('name="body"', false);
    }
})->with([
    'new admin' => [TicketStatus::New, 'admin', ['in_progress']],
    'new assigned' => [TicketStatus::New, 'assigned', ['in_progress']],
    'new colleague' => [TicketStatus::New, 'other', []],
    'progress admin' => [TicketStatus::InProgress, 'admin', ['waiting_customer', 'resolved']],
    'progress assigned' => [TicketStatus::InProgress, 'assigned', ['waiting_customer', 'resolved']],
    'progress colleague' => [TicketStatus::InProgress, 'other', []],
    'waiting admin' => [TicketStatus::WaitingCustomer, 'admin', ['in_progress', 'resolved']],
    'waiting assigned' => [TicketStatus::WaitingCustomer, 'assigned', ['in_progress', 'resolved']],
    'waiting colleague' => [TicketStatus::WaitingCustomer, 'other', []],
    'resolved admin' => [TicketStatus::Resolved, 'admin', ['in_progress', 'closed']],
    'resolved assigned' => [TicketStatus::Resolved, 'assigned', ['in_progress']],
    'resolved colleague' => [TicketStatus::Resolved, 'other', []],
    'closed admin' => [TicketStatus::Closed, 'admin', ['in_progress']],
    'closed assigned' => [TicketStatus::Closed, 'assigned', []],
    'closed colleague' => [TicketStatus::Closed, 'other', []],
]);

test('an unassigned new ticket offers claim but no status transition to an agent', function () {
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->create();

    $this->actingAs($agent)->get(route('tickets.show', $ticket))->assertOk()->assertSee('Prendre ce ticket')
        ->assertViewHas('transitions', [])->assertDontSee('name="status"', false)->assertDontSee('Modifier les informations');
});

test('validation after a conflict redirects to a readable page and keeps the draft', function (string $operation, array $input, string $destination, string $text) {
    $admin = User::factory()->administrator()->create();
    $ticket = Ticket::factory()->inStatus(TicketStatus::InProgress)->create(ticketCoreInput());

    $this->actingAs($admin)->from(route($operation, $ticket));
    $response = $operation === 'tickets.comment' ? $this->post(route($operation, $ticket), $input) : $this->patch(route($operation, $ticket), $input);

    $response->assertRedirect(route($destination, $ticket));
    $this->get(route($destination, $ticket))->assertOk()->assertSee($text)->assertSee('aria-invalid="true"', false);
    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'version' => 1, 'status' => 'in_progress']);
    $this->assertDatabaseCount('ticket_activities', 0);
    $this->assertDatabaseCount('comments', 0);
})->with([
    'edit' => ['tickets.update', [...ticketCoreInput(), 'version' => 1, 'title' => 'abc', 'priority' => 'normal'], 'tickets.edit', 'Le champ sujet doit contenir au moins 5 caractères.'],
    'comment' => ['tickets.comment', ['version' => 1, 'body' => ''], 'tickets.show', 'Le champ commentaire est obligatoire.'],
    'status' => ['tickets.change-status', ['version' => 1, 'status' => 'resolved', 'reason' => 'abc'], 'tickets.show', 'Le champ motif doit contenir au moins 5 caractères.'],
    'assignment' => ['tickets.assign', ['version' => 1, 'assigned_to' => null], 'tickets.show', 'Seul un ticket nouveau peut être libéré.'],
]);

test('search text is escaped when reflected into the filter form', function () {
    $agent = User::factory()->create();
    $search = '"><script>alert("fictif")</script>';

    $this->actingAs($agent)->get(route('tickets.index', ['q' => $search]))->assertOk()->assertSee($search)->assertDontSee($search, false);
});

test('list ordering uses creation time before id and last activity follows comments', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-20 12:00:00', 'UTC'));
    $admin = User::factory()->administrator()->create();
    $newer = Ticket::factory()->create(['created_at' => '2026-01-19 12:00:00']);
    $older = Ticket::factory()->create(['created_at' => '2026-01-18 12:00:00']);
    app(AddComment::class)->execute($admin, $older, ['version' => 1, 'body' => 'Activité fictive récente']);

    $this->actingAs($admin)->get(route('tickets.index'))->assertOk()
        ->assertViewHas('tickets', fn ($tickets): bool => $tickets->pluck('id')->all() === [$newer->id, $older->id])
        ->assertSee('20/01/2026 · 13:00');
});
