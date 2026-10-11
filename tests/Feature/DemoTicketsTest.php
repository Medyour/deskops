<?php

use App\Actions\Tickets\AssignTicket;
use App\Actions\Tickets\CreateTicket;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoTicketSeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

test('the complete demo fixture matches the MVP without random business data', function () {
    $this->travelTo('2030-01-01 12:00:00');

    $this->seed(DatabaseSeeder::class);

    $this->assertDatabaseCount('tickets', 12);
    $this->assertDatabaseCount('comments', 24);
    $this->assertDatabaseCount('ticket_activities', 60);
    expect(DB::table('ticket_activities')->selectRaw('type, count(*) as total')->groupBy('type')->orderBy('type')->pluck('total', 'type')->all())
        ->toBe(['assignment_changed' => 9, 'comment_added' => 24, 'status_changed' => 15, 'ticket_created' => 12]);
    expect(Ticket::orderBy('id')->get()->map(fn (Ticket $ticket): array => [$ticket->reference, $ticket->title, $ticket->status->value, $ticket->priority->value, $ticket->assignee?->email])->all())
        ->toBe([
            ['DS-0001', 'Accès au compte client', 'new', 'normal', null],
            ['DS-0002', 'Demande de facture', 'new', 'low', null],
            ['DS-0003', 'Service indisponible', 'new', 'urgent', null],
            ['DS-0004', 'Export des contacts', 'in_progress', 'normal', 'alex@deskops.example'],
            ['DS-0005', 'Paiement refusé', 'in_progress', 'high', 'sam@deskops.example'],
            ['DS-0006', 'Adresse de livraison', 'in_progress', 'low', 'alex@deskops.example'],
            ['DS-0007', 'Import incomplet', 'in_progress', 'high', 'sam@deskops.example'],
            ['DS-0008', 'Justificatif manquant', 'waiting_customer', 'normal', 'alex@deskops.example'],
            ['DS-0009', 'Informations de commande', 'waiting_customer', 'normal', 'sam@deskops.example'],
            ['DS-0010', 'Double notification', 'resolved', 'normal', 'alex@deskops.example'],
            ['DS-0011', 'Erreur de connexion', 'resolved', 'high', 'sam@deskops.example'],
            ['DS-0012', 'Mise à jour du profil', 'closed', 'low', 'alex@deskops.example'],
        ]);
    $last = Ticket::findOrFail(12);
    expect($last->created_at->toDateTimeString())->toBe('2026-01-15 14:30:00');
    expect($last->resolved_at->toDateTimeString())->toBe('2026-01-15 14:33:00');
    expect($last->closed_at->toDateTimeString())->toBe('2026-01-15 14:37:00');
    expect($last->comments->pluck('created_at')->map(fn ($date): string => $date->toDateTimeString())->all())
        ->toBe(['2026-01-15 14:35:00', '2026-01-15 14:36:00']);
    expect($last->activities->pluck('reason')->filter()->values()->all())->toBe(['Scénario de démonstration']);
    expect(Ticket::findOrFail(1)->requester_email)->toBe('client1@deskops.example');
    expect(now()->toDateTimeString())->toBe('2030-01-01 12:00:00');
});

test('repeated seeding preserves fixture mutations custom tickets and immutable history', function () {
    $this->seed(DatabaseSeeder::class);
    $admin = User::where('email', 'admin@deskops.example')->firstOrFail();
    $sam = User::where('email', 'sam@deskops.example')->firstOrFail();
    app(AssignTicket::class)->execute($admin, Ticket::findOrFail(1), ['version' => 3, 'assigned_to' => $sam->id]);
    app(CreateTicket::class)->execute($admin, [...ticketCoreInput(), 'title' => 'Autre ticket entièrement fictif']);
    $snapshot = [];
    foreach (['tickets', 'comments', 'ticket_activities'] as $table) {
        $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }

    $this->seed(DatabaseSeeder::class);

    foreach ($snapshot as $table => $before) {
        expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($before);
    }
    $this->assertDatabaseCount('tickets', 13);
    $this->assertDatabaseCount('ticket_activities', 62);
});

test('demo initialization refuses unrelated tickets without changing existing records or accounts', function () {
    $actor = User::factory()->create();
    $ticket = app(CreateTicket::class)->execute($actor, ticketCoreInput());
    $before = $ticket->getAttributes();

    expect(fn () => $this->seed(DatabaseSeeder::class))->toThrow(LogicException::class);

    expect($ticket->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('tickets', 1);
    $this->assertDatabaseCount('ticket_activities', 1);
    $this->assertDatabaseCount('users', 1);
});

test('the local demo command seeds once while renewing credentials on every run', function () {
    $this->app->detectEnvironment(fn (): string => 'local');

    expect(Artisan::call('deskops:demo'))->toBe(0);
    $oldHash = User::where('email', 'admin@deskops.example')->firstOrFail()->password;
    $before = DB::table('ticket_activities')->orderBy('id')->get()->toJson();

    expect(Artisan::call('deskops:demo'))->toBe(0);

    expect(User::where('email', 'admin@deskops.example')->firstOrFail()->password)->not->toBe($oldHash);
    expect(DB::table('ticket_activities')->orderBy('id')->get()->toJson())->toBe($before);
    $this->assertDatabaseCount('tickets', 12);
    $this->assertDatabaseCount('comments', 24);
    $this->assertDatabaseCount('ticket_activities', 60);
});

test('the business seeder independently refuses production', function () {
    $this->app->detectEnvironment(fn (): string => 'production');

    expect(fn () => app(DemoTicketSeeder::class)->run())->toThrow(LogicException::class);

    $this->assertDatabaseCount('tickets', 0);
});

test('the demo command refuses unrelated tickets without rotating credentials or sessions', function () {
    $this->seed(DemoUserSeeder::class);
    $admin = User::where('email', 'admin@deskops.example')->firstOrFail();
    app(CreateTicket::class)->execute($admin, [...ticketCoreInput(), 'title' => 'Demande locale hors fixture']);
    DB::table('sessions')->insert([
        'id' => 'fictitious-session', 'user_id' => $admin->id, 'ip_address' => null,
        'user_agent' => null, 'payload' => 'fictitious', 'last_activity' => 1,
    ]);
    $before = $admin->getAttributes();
    $this->app->detectEnvironment(fn (): string => 'local');

    expect(Artisan::call('deskops:demo'))->toBe(1);

    expect(Artisan::output())->toContain('Aucune donnée existante ne sera écrasée.');
    expect($admin->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseHas('sessions', ['id' => 'fictitious-session', 'user_id' => $admin->id]);
    $this->assertDatabaseCount('tickets', 1);
    $this->assertDatabaseCount('ticket_activities', 1);
});

test('demo initialization refuses a previously used sequence even when no tickets remain', function () {
    $ticket = Ticket::factory()->create();
    $ticket->delete();
    $accountsBefore = User::count();

    expect(fn () => $this->seed(DatabaseSeeder::class))->toThrow(LogicException::class, 'les identifiants de cette base ont déjà été utilisés');

    $this->assertDatabaseCount('tickets', 0);
    $this->assertDatabaseCount('ticket_activities', 0);
    $this->assertDatabaseCount('users', $accountsBefore);
});
