<?php

use App\Actions\Tickets\OperationsDashboard;
use App\Enums\TicketActivityType;
use App\Enums\TicketPriority;
use App\Enums\TicketQueue;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

test('the dashboard requires authentication even with a personal scope', function () {
    $this->get(route('dashboard', ['scope' => 'mine']))->assertRedirect(route('login'));
});

test('the demo dashboard has exact global metrics distributions loads and recent queues for both roles', function (string $email) {
    $this->seed(DatabaseSeeder::class);
    $viewer = User::where('email', $email)->firstOrFail();

    $response = $this->actingAs($viewer)->get(route('dashboard'));

    $response->assertOk()->assertSee('Tableau de bord')->assertViewHas('mine', false)
        ->assertViewHas('metrics', ['total' => 12, 'active' => 9, 'unassigned' => 3, 'high_priority' => 3, 'urgent' => 1, 'attention' => 5, 'completed' => 3])
        ->assertViewHas('statuses', ['new' => 3, 'in_progress' => 4, 'waiting_customer' => 2, 'resolved' => 2, 'closed' => 1])
        ->assertViewHas('priorities', ['low' => 3, 'normal' => 5, 'high' => 3, 'urgent' => 1]);
    expect($response->viewData('agents')->map(fn (User $agent): array => [$agent->name, $agent->active_tickets_count])->all())->toBe([['Alex Bernard', 3], ['Sam Dubois', 3]]);
    expect($response->viewData('attentionTickets')->pluck('id')->all())->toBe([3, 5, 7, 1, 2]);
    expect($response->viewData('recentTickets')->pluck('id')->all())->toBe([12, 11, 10, 9, 8]);
    expect($response->viewData('recentActivities')->pluck('id')->all())->toBe([60, 59, 58, 57, 56]);
    $this->assertDatabaseCount('tickets', 12);
    $this->assertDatabaseCount('ticket_activities', 60);
})->with(['administrator' => 'admin@deskops.example', 'agent' => 'alex@deskops.example']);

test('an empty ticket database renders zero counts all categories and useful empty states', function () {
    $agent = User::factory()->create();

    $response = $this->actingAs($agent)->get(route('dashboard'));

    $response->assertOk()->assertViewHas('metrics', ['total' => 0, 'active' => 0, 'unassigned' => 0, 'high_priority' => 0, 'urgent' => 0, 'attention' => 0, 'completed' => 0])
        ->assertViewHas('statuses', ['new' => 0, 'in_progress' => 0, 'waiting_customer' => 0, 'resolved' => 0, 'closed' => 0])
        ->assertViewHas('priorities', ['low' => 0, 'normal' => 0, 'high' => 0, 'urgent' => 0])
        ->assertSee('Aucune demande dans cette file.')->assertSee('Aucun ticket dans cette vue.')->assertSee('Aucune activité enregistrée dans cette vue.');
    expect($response->viewData('agents')->sole()->active_tickets_count)->toBe(0);
});

test('a team without agents still renders a dashboard', function () {
    $admin = User::factory()->administrator()->create();

    $this->actingAs($admin)->get(route('dashboard'))->assertOk()->assertSee('Aucun agent à afficher.');
});

test('only the three active statuses contribute to workload and high priority attention', function (TicketStatus $status, bool $active) {
    $agent = User::factory()->create();
    Ticket::factory()->inStatus($status)->assignedTo($agent)->create(['priority' => 'urgent', 'created_by' => $agent->id]);

    $response = $this->actingAs($agent)->get(route('dashboard'));

    $response->assertOk();
    $metrics = $response->viewData('metrics');
    expect($metrics['total'])->toBe(1);
    expect($metrics['completed'])->toBe($active ? 0 : 1);
    expect([$metrics['active'], $metrics['high_priority'], $metrics['urgent'], $metrics['attention']])->toBe($active ? [1, 1, 1, 1] : [0, 0, 0, 0]);
    expect($response->viewData('agents')->sole()->active_tickets_count)->toBe($active ? 1 : 0);
})->with([
    'new' => [TicketStatus::New, true], 'in progress' => [TicketStatus::InProgress, true], 'waiting customer' => [TicketStatus::WaitingCustomer, true],
    'resolved' => [TicketStatus::Resolved, false], 'closed' => [TicketStatus::Closed, false],
]);

test('attention combines unassigned and high priorities once while keeping ordinary assigned active tickets out', function () {
    $agent = User::factory()->create();
    $urgent = Ticket::factory()->create(['priority' => 'urgent', 'created_by' => $agent->id]);
    $normal = Ticket::factory()->create(['created_by' => $agent->id]);
    $high = Ticket::factory()->inStatus(TicketStatus::WaitingCustomer)->assignedTo($agent)->create(['priority' => 'high', 'created_by' => $agent->id]);
    Ticket::factory()->inStatus(TicketStatus::InProgress)->assignedTo($agent)->create(['created_by' => $agent->id]);

    $data = app(OperationsDashboard::class)->read($agent);

    expect($data['metrics'])->toBe(['total' => 4, 'active' => 4, 'unassigned' => 2, 'high_priority' => 2, 'urgent' => 1, 'attention' => 3, 'completed' => 0]);
    expect($data['attentionTickets']->pluck('id')->all())->toBe([$urgent->id, $high->id, $normal->id]);
});

test('personal scope follows current assignment for every count list activity and link', function () {
    $this->seed(DatabaseSeeder::class);
    $sam = User::where('email', 'sam@deskops.example')->firstOrFail();

    $response = $this->actingAs($sam)->get(route('dashboard', ['scope' => 'mine']));

    $response->assertOk()->assertViewHas('mine', true)
        ->assertViewHas('metrics', ['total' => 4, 'active' => 3, 'unassigned' => 0, 'high_priority' => 2, 'urgent' => 0, 'attention' => 2, 'completed' => 1])
        ->assertViewHas('statuses', ['new' => 0, 'in_progress' => 2, 'waiting_customer' => 1, 'resolved' => 1, 'closed' => 0])
        ->assertViewHas('priorities', ['low' => 0, 'normal' => 1, 'high' => 3, 'urgent' => 0])
        ->assertSee(route('tickets.index', ['assignee' => 'me', 'queue' => 'unassigned']))
        ->assertSee(route('tickets.index', ['assignee' => 'me', 'queue' => 'attention']))
        ->assertSee(route('tickets.index', ['assignee' => 'me', 'status' => 'resolved']));
    expect($response->viewData('agents')->sole()->id)->toBe($sam->id);
    expect($response->viewData('agents')->sole()->active_tickets_count)->toBe(3);
    expect($response->viewData('attentionTickets')->pluck('id')->all())->toBe([5, 7]);
    expect($response->viewData('recentTickets')->pluck('id')->all())->toBe([11, 9, 7, 5]);
    expect($response->viewData('recentActivities')->pluck('ticket_id')->all())->toBe([11, 11, 11, 11, 11]);
});

test('agent load includes zero workloads excludes administrators and sorts by load then name and ID', function () {
    $admin = User::factory()->administrator()->create();
    $zero = User::factory()->create(['name' => 'Agent Alpha']);
    $first = User::factory()->create(['name' => 'Agent Beta']);
    $tied = User::factory()->create(['name' => 'Agent Beta']);
    $busy = User::factory()->create(['name' => 'Agent Gamma']);
    Ticket::factory()->count(2)->assignedTo($busy)->create(['created_by' => $admin->id]);
    Ticket::factory()->inStatus(TicketStatus::WaitingCustomer)->assignedTo($first)->create(['created_by' => $admin->id]);
    Ticket::factory()->assignedTo($tied)->create(['created_by' => $admin->id]);
    Ticket::factory()->inStatus(TicketStatus::Closed)->assignedTo($zero)->create(['created_by' => $admin->id]);

    $data = app(OperationsDashboard::class)->read($admin);

    expect($data['agents']->map(fn (User $agent): array => [$agent->id, $agent->active_tickets_count])->all())
        ->toBe([[$busy->id, 2], [$first->id, 1], [$tied->id, 1], [$zero->id, 0]]);
});

test('invalid dashboard scopes are rejected without silently changing the view', function (array $parameters, bool $admin, string $message) {
    $viewer = $admin ? User::factory()->administrator()->create() : User::factory()->create();

    $this->actingAs($viewer)->get(route('dashboard', $parameters))->assertRedirect(route('dashboard'))->assertSessionHasErrors(['scope' => $message]);
})->with([
    'unknown scope' => [['scope' => 'everyone'], false, 'Choisissez la vue Équipe ou Mes tickets.'],
    'array scope' => [['scope' => ['mine']], false, 'Choisissez une seule vue du tableau de bord.'],
    'admin is not assignable' => [['scope' => 'mine'], true, 'Choisissez la vue Équipe ou Mes tickets.'],
]);

test('completed tickets depend only on current terminal status regardless of historical resolution dates', function () {
    $agent = User::factory()->create();
    $resolved = Ticket::factory()->inStatus(TicketStatus::Resolved)->assignedTo($agent)->create(['resolved_at' => '2020-01-15 12:00:00', 'created_at' => '2020-01-15 11:00:00']);
    $closed = Ticket::factory()->inStatus(TicketStatus::Closed)->assignedTo($agent)->create(['resolved_at' => '2021-01-15 12:00:00', 'closed_at' => '2021-01-16 12:00:00', 'created_at' => '2021-01-15 11:00:00']);
    Ticket::factory()->inStatus(TicketStatus::InProgress)->assignedTo($agent)->create();
    Ticket::factory()->inStatus(TicketStatus::WaitingCustomer)->assignedTo($agent)->create();
    Ticket::factory()->create();

    $data = app(OperationsDashboard::class)->read($agent);

    expect($data['metrics']['completed'])->toBe(2);
    expect($data['metrics']['active'])->toBe(3);
    $this->actingAs($agent)->get(route('tickets.index', ['queue' => 'completed']))->assertOk()
        ->assertViewHas('tickets', fn ($tickets): bool => $tickets->pluck('id')->all() === [$closed->id, $resolved->id]);
});

test('personal dashboard and filtered links cannot impersonate another agent through query parameters', function (string $email, int $total, int $completed, array $completedIds) {
    $this->seed(DatabaseSeeder::class);
    $viewer = User::where('email', $email)->firstOrFail();
    $other = User::where('role', 'agent')->whereKeyNot($viewer->id)->firstOrFail();
    $spoofed = ['assignee' => $other->id, 'user_id' => $other->id, 'viewer_id' => $other->id];

    $response = $this->actingAs($viewer)->get(route('dashboard', ['scope' => 'mine', ...$spoofed]));

    $response->assertOk()->assertSee('Terminés')->assertViewHas('mine', true)
        ->assertViewHas('metrics', fn (array $metrics): bool => $metrics['total'] === $total && $metrics['completed'] === $completed)
        ->assertViewHas('filters', ['assignee' => 'me'])
        ->assertSee(route('tickets.index', ['assignee' => 'me', 'queue' => 'completed']));
    expect($response->viewData('agents')->sole()->id)->toBe($viewer->id);
    expect($response->viewData('recentTickets')->pluck('assigned_to')->unique()->all())->toBe([$viewer->id]);
    $this->get(route('tickets.index', ['assignee' => 'me', 'queue' => 'completed', 'user_id' => $other->id, 'viewer_id' => $other->id]))->assertOk()
        ->assertViewHas('tickets', fn ($tickets): bool => $tickets->pluck('id')->all() === $completedIds)
        ->assertViewHas('filters', ['assignee' => 'me', 'queue' => 'completed']);
})->with(['Alex' => ['alex@deskops.example', 5, 2, [12, 10]], 'Sam' => ['sam@deskops.example', 4, 1, [11]]]);

test('personal scope without assignments stays empty even when the team has completed and active tickets', function () {
    $viewer = User::factory()->create();
    Ticket::factory()->inStatus(TicketStatus::Closed)->create();
    Ticket::factory()->create(['priority' => 'urgent']);

    $response = $this->actingAs($viewer)->get(route('dashboard', ['scope' => 'mine']));

    $response->assertOk()->assertViewHas('metrics', ['total' => 0, 'active' => 0, 'unassigned' => 0, 'high_priority' => 0, 'urgent' => 0, 'attention' => 0, 'completed' => 0]);
    expect($response->viewData('recentTickets'))->toBeEmpty();
    expect($response->viewData('recentActivities'))->toBeEmpty();
    expect($response->viewData('attentionTickets'))->toBeEmpty();
    expect($response->viewData('agents')->sole()->active_tickets_count)->toBe(0);
});

test('the dashboard links to each exact operational queue status priority and agent load', function () {
    $this->seed(DatabaseSeeder::class);
    $viewer = User::where('email', 'admin@deskops.example')->firstOrFail();
    $alex = User::where('email', 'alex@deskops.example')->firstOrFail();

    $response = $this->actingAs($viewer)->get(route('dashboard'));

    $response->assertOk();
    foreach (TicketQueue::cases() as $queue) {
        $response->assertSee(route('tickets.index', ['queue' => $queue->value]));
    }
    foreach (['status' => TicketStatus::cases(), 'priority' => TicketPriority::cases()] as $filter => $cases) {
        foreach ($cases as $case) {
            $link = route('tickets.index', [$filter => $case->value]);
            $response->assertSee($link);
            $expectedCount = $response->viewData($filter === 'status' ? 'statuses' : 'priorities')[$case->value];
            $this->get($link)->assertOk()->assertViewHas('tickets', fn ($tickets): bool => $tickets->total() === $expectedCount);
        }
    }
    $response->assertSee(route('tickets.index', ['queue' => 'active', 'assignee' => $alex->id]));
    foreach ($response->viewData('agents') as $agent) {
        $this->get(route('tickets.index', ['queue' => 'active', 'assignee' => $agent->id]))->assertOk()
            ->assertViewHas('tickets', fn ($tickets): bool => $tickets->total() === $agent->active_tickets_count);
    }
});

test('operational queue links return the exact demo members', function (string $queue, array $ids) {
    $this->seed(DatabaseSeeder::class);
    $viewer = User::where('email', 'admin@deskops.example')->firstOrFail();

    $this->actingAs($viewer)->get(route('tickets.index', ['queue' => $queue]))->assertOk()
        ->assertViewHas('tickets', fn ($tickets): bool => $tickets->pluck('id')->all() === $ids);
})->with([
    'active' => ['active', [9, 8, 7, 6, 5, 4, 3, 2, 1]], 'unassigned' => ['unassigned', [3, 2, 1]],
    'high priorities' => ['high_priority', [7, 5, 3]], 'urgent' => ['urgent', [3]],
    'attention' => ['attention', [7, 5, 3, 2, 1]], 'completed' => ['completed', [12, 11, 10]],
]);

test('operational filters combine with search status priority and assignment and persist through pagination', function () {
    $agent = User::factory()->create();
    $matching = Ticket::factory()->count(23)->inStatus(TicketStatus::InProgress)->assignedTo($agent)->create(['title' => 'Export fictif', 'priority' => 'high', 'created_by' => $agent->id]);
    Ticket::factory()->inStatus(TicketStatus::Resolved)->assignedTo($agent)->create(['title' => 'Export fictif', 'priority' => 'high']);
    Ticket::factory()->assignedTo($agent)->create(['title' => 'Export fictif', 'priority' => 'high']);
    Ticket::factory()->inStatus(TicketStatus::InProgress)->assignedTo($agent)->create(['title' => 'Export fictif', 'priority' => 'normal']);
    Ticket::factory()->inStatus(TicketStatus::InProgress)->create(['title' => 'Export fictif', 'priority' => 'high']);
    $filters = ['q' => 'Export', 'status' => 'in_progress', 'priority' => 'high', 'assignee' => 'me', 'queue' => 'attention'];

    $response = $this->actingAs($agent)->get(route('tickets.index', $filters));

    $response->assertOk()->assertViewHas('tickets', fn ($tickets): bool => $tickets->total() === 23 && $tickets->count() === 20);
    $nextPage = $response->viewData('tickets')->nextPageUrl();
    parse_str(parse_url($nextPage, PHP_URL_QUERY), $nextFilters);
    expect($nextFilters)->toMatchArray([...$filters, 'page' => '2']);
    $response->assertSee($nextPage);
    $this->get(route('tickets.index', [...$filters, 'page' => 2]))->assertOk()
        ->assertViewHas('tickets', fn ($tickets): bool => $tickets->count() === 3 && $tickets->last()->id === $matching->first()->id);
});

test('unknown or malformed operational queues are rejected', function (mixed $queue) {
    $agent = User::factory()->create();

    $this->actingAs($agent)->get(route('tickets.index', ['queue' => $queue]))->assertRedirect(route('tickets.index'))->assertSessionHasErrors('queue');
})->with(['unknown' => 'all', 'array' => [['active']], 'injection' => "active' OR 1=1 --"]);

test('recent queues use dates before IDs with stable tie ordering and limit each preview to five', function () {
    $agent = User::factory()->create();
    $older = Ticket::factory()->count(6)->create(['created_by' => $agent->id, 'created_at' => '2026-01-18 12:00:00']);
    $newer = Ticket::factory()->create(['created_by' => $agent->id, 'created_at' => '2026-01-19 12:00:00']);
    $oldHighId = Ticket::factory()->create(['created_by' => $agent->id, 'created_at' => '2026-01-17 12:00:00']);
    $first = TicketActivity::factory()->for($newer)->for($agent, 'actor')->create(['created_at' => '2026-01-19 12:00:00']);
    $second = TicketActivity::factory()->for($older->first())->for($agent, 'actor')->create(['created_at' => '2026-01-19 12:00:00']);
    $lastId = TicketActivity::factory()->for($oldHighId)->for($agent, 'actor')->create(['created_at' => '2026-01-18 12:00:00']);

    $data = app(OperationsDashboard::class)->read($agent);

    expect($data['recentTickets']->pluck('id')->all())->toBe([$newer->id, $older[5]->id, $older[4]->id, $older[3]->id, $older[2]->id]);
    expect($data['attentionTickets']->pluck('id')->all())->toBe([$oldHighId->id, $older[0]->id, $older[1]->id, $older[2]->id, $older[3]->id]);
    expect($data['recentActivities']->pluck('id')->all())->toBe([$second->id, $first->id, $lastId->id]);
});

test('tickets and actor names are escaped and activity metadata is not exposed', function () {
    $agent = User::factory()->create(['name' => '<script>agent()</script>']);
    $ticket = Ticket::factory()->assignedTo($agent)->create(['title' => '<img src=x onerror=alert(1)>', 'priority' => 'high', 'created_by' => $agent->id]);
    TicketActivity::factory()->for($ticket)->for($agent, 'actor')->create(['changes' => ['private' => 'metadata-unneeded-on-dashboard'], 'reason' => '<script>reason()</script>']);

    $this->actingAs($agent)->get(route('dashboard'))->assertOk()->assertSee($ticket->title)->assertSee($agent->name)
        ->assertDontSee($ticket->title, false)->assertDontSee($agent->name, false)
        ->assertDontSee('metadata-unneeded-on-dashboard')->assertDontSee('reason()');
});

test('dashboard query count stays bounded when ticket activity and agent counts grow', function (bool $mine) {
    $agent = User::factory()->create();
    $tickets = Ticket::factory()->count(5)->assignedTo($agent)->create(['priority' => 'high', 'created_by' => $agent->id]);
    foreach ($tickets as $ticket) {
        TicketActivity::factory()->for($ticket)->for($agent, 'actor')->create();
    }
    $this->actingAs($agent);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->get(route('dashboard', $mine ? ['scope' => 'mine'] : []))->assertOk();
    $baseline = count(DB::getQueryLog());
    DB::disableQueryLog();
    $additional = User::factory()->count(10)->create();
    foreach ($additional as $other) {
        $ticket = Ticket::factory()->assignedTo($other)->create(['priority' => 'high', 'created_by' => $agent->id]);
        TicketActivity::factory()->count(3)->for($ticket)->for($other, 'actor')->create(['type' => TicketActivityType::CommentAdded]);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();

    $response = $this->get(route('dashboard', $mine ? ['scope' => 'mine'] : []));
    $expanded = count(DB::getQueryLog());
    DB::disableQueryLog();

    $response->assertOk();
    expect($expanded)->toBe($baseline)->toBeLessThanOrEqual(11);
    expect($response->viewData('recentActivities'))->toHaveCount(5);
    expect($response->viewData('recentTickets'))->toHaveCount(5);
    expect($response->viewData('attentionTickets'))->toHaveCount(5);
})->with(['team' => false, 'personal scope' => true]);
