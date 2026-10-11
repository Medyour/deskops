<?php

use App\Actions\Demo\DemoMaintenance;
use App\Actions\Demo\ResetDemo;
use App\Actions\Tickets\AddComment;
use App\Actions\Tickets\AssignTicket;
use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\UpdateTicket;
use App\Enums\DemoIdentity;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** @return array<string, string> */
function publicDemoSnapshot(): array
{
    $snapshot = [];
    foreach (['users', 'tickets', 'comments', 'ticket_activities'] as $table) {
        $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }

    return $snapshot;
}

test('demo reset refuses a disabled mode without changing any records', function () {
    $user = User::factory()->create();
    app(CreateTicket::class)->execute($user, ticketCoreInput());
    $before = publicDemoSnapshot();
    $this->artisan('deskops:demo-reset')->expectsOutput('Le mode démonstration est désactivé.')->assertFailed();
    expect(publicDemoSnapshot())->toBe($before);
});

test('production demo reset requires force and succeeds without disclosing access details', function () {
    config(['app.demo_mode' => true]);
    $this->app->detectEnvironment(fn (): string => 'production');
    $this->artisan('deskops:demo-reset')->expectsOutput('La réinitialisation en production exige --force.')->assertFailed();
    $this->assertDatabaseCount('users', 0);
    expect(Artisan::call('deskops:demo-reset', ['--force' => true]))->toBe(0);
    expect(Artisan::output())->toContain('12 tickets, 24 commentaires, 60 activités')->not->toContain('deskops.example')->not->toContain('mot de passe');
    foreach (User::all() as $user) {
        expect(Artisan::output())->not->toContain($user->password);
    }
    $this->assertDatabaseCount('users', 3);
    $this->assertDatabaseCount('tickets', 12);
    $this->assertDatabaseCount('comments', 24);
    $this->assertDatabaseCount('ticket_activities', 60);
});

test('the maintenance lock prevents concurrent resets', function () {
    config(['app.demo_mode' => true]);
    $lock = Cache::store('database')->lock(DemoMaintenance::LOCK, 300);
    expect($lock->get())->toBeTrue();
    try {
        $this->artisan('deskops:demo-reset')->expectsOutput('Une opération de démonstration est déjà en cours.')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    } finally {
        $lock->release();
    }
    $this->artisan('deskops:demo-reset')->assertSuccessful();
});

test('reset restores exact records and IDs after mutations extra comments and tickets and is idempotent', function () {
    config(['app.demo_mode' => true]);
    app(ResetDemo::class)->execute();
    $before = publicDemoSnapshot();
    $admin = User::where('demo_identity', DemoIdentity::Administrator)->firstOrFail();
    $alex = User::where('demo_identity', DemoIdentity::Alex)->firstOrFail();
    $alex->update(['name' => 'Nom fictif temporaire']);
    $ticket = Ticket::findOrFail(1);
    app(UpdateTicket::class)->execute($admin, $ticket, [...ticketCoreInput(), 'title' => 'Modification de démonstration', 'priority' => 'high', 'version' => $ticket->version]);
    app(AssignTicket::class)->execute($admin, $ticket->fresh(), ['version' => $ticket->fresh()->version, 'assigned_to' => $alex->id]);
    app(AddComment::class)->execute($admin, $ticket->fresh(), ['version' => $ticket->fresh()->version, 'body' => 'Autre commentaire fictif']);
    app(CreateTicket::class)->execute($alex, [...ticketCoreInput(), 'title' => 'Ticket supplémentaire fictif']);

    $this->artisan('deskops:demo-reset')->assertSuccessful();
    expect(publicDemoSnapshot())->toBe($before);
    $this->artisan('deskops:demo-reset')->assertSuccessful();
    expect(publicDemoSnapshot())->toBe($before);
    expect(Ticket::orderBy('id')->get()->pluck('reference')->all())->toBe(array_map(fn (int $number): string => 'DS-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT), range(1, 12)));
    $this->assertDatabaseHas('demo_state', ['id' => 1, 'active' => false, 'generation' => 3]);
});

test('reset preserves every non-demo record including accounts sessions tickets and history in a mixed database', function () {
    config(['app.demo_mode' => true]);
    $normal = User::factory()->administrator()->create();
    $ticket = app(CreateTicket::class)->execute($normal, [...ticketCoreInput(), 'title' => 'Ticket privé à conserver']);
    app(AddComment::class)->execute($normal, $ticket, ['version' => 1, 'body' => 'Commentaire privé à conserver']);
    DB::table('sessions')->insert(['id' => 'normal-session', 'user_id' => $normal->id, 'payload' => 'private-fixture', 'last_activity' => 1]);
    DB::table('password_reset_tokens')->insert(['email' => $normal->email, 'token' => 'private-fixture-token']);
    $before = publicDemoSnapshot();
    app(ResetDemo::class)->execute();
    $demoIds = Ticket::where('is_demo', true)->orderBy('id')->pluck('id')->all();
    $admin = User::where('demo_identity', DemoIdentity::Administrator)->firstOrFail();
    $extra = app(CreateTicket::class)->execute($admin, ticketCoreInput());
    app(ResetDemo::class)->execute();
    app(ResetDemo::class)->execute();

    foreach (['users', 'tickets', 'comments', 'ticket_activities'] as $table) {
        $query = match ($table) {
            'users' => DB::table($table)->whereNull('demo_identity'),
            'tickets' => DB::table($table)->where('is_demo', false),
            default => DB::table($table)->where('ticket_id', $ticket->id),
        };
        expect($query->orderBy('id')->get()->toJson())->toBe($before[$table]);
    }
    expect(Ticket::where('is_demo', true)->orderBy('id')->pluck('id')->all())->toBe($demoIds);
    $this->assertDatabaseHas('sessions', ['id' => 'normal-session', 'user_id' => $normal->id, 'payload' => 'private-fixture']);
    $this->assertDatabaseHas('password_reset_tokens', ['email' => $normal->email, 'token' => 'private-fixture-token']);
    $next = app(CreateTicket::class)->execute($normal, ticketCoreInput());
    expect($next->id)->toBeGreaterThan($extra->id);
});

test('reset never adopts unmarked legacy accounts even with reserved demo addresses', function () {
    config(['app.demo_mode' => true]);
    User::factory()->create(['email' => DemoIdentity::Alex->email()]);
    $before = publicDemoSnapshot();
    $this->artisan('deskops:demo-reset')->assertFailed();
    expect(publicDemoSnapshot())->toBe($before);
    $this->assertDatabaseHas('demo_state', ['active' => false, 'fixture' => null]);
});

test('reset refuses to erase contributions authored by a non-demo user', function () {
    config(['app.demo_mode' => true]);
    app(ResetDemo::class)->execute();
    $normal = User::factory()->administrator()->create();
    app(AddComment::class)->execute($normal, Ticket::findOrFail(1), ['version' => 3, 'body' => 'Contribution non-démo à conserver']);
    $before = publicDemoSnapshot();
    $this->artisan('deskops:demo-reset')->assertFailed();
    expect(publicDemoSnapshot())->toBe($before);
});

test('a failed restore rolls back its deletions and releases the maintenance lock without secrets', function () {
    config(['app.demo_mode' => true]);
    app(ResetDemo::class)->execute();
    $admin = User::where('demo_identity', DemoIdentity::Administrator)->firstOrFail();
    app(CreateTicket::class)->execute($admin, ticketCoreInput());
    $before = publicDemoSnapshot();
    $restoreWrites = $this->preventActivityWrites();
    try {
        $this->artisan('deskops:demo-reset')->expectsOutput('Réinitialisation annulée sans modification. Aucun détail de connexion ou secret ne sera affiché.')->assertFailed();
        expect(publicDemoSnapshot())->toBe($before);
        $this->assertDatabaseHas('demo_state', ['active' => false]);
    } finally {
        $restoreWrites();
    }
    $this->artisan('deskops:demo-reset')->assertSuccessful();
    $this->assertDatabaseCount('tickets', 12);
});

test('demo accounts reject protected changes and deletion including direct query builder writes', function (string $field) {
    $this->seed(DemoUserSeeder::class);
    $user = User::where('demo_identity', DemoIdentity::Alex)->firstOrFail();
    $before = $user->getAttributes();
    $attributes = match ($field) {
        'role' => ['role' => 'admin'], 'email' => ['email' => 'changed@deskops.example'],
        'password' => ['password' => 'ineligible-fixture-value'], 'identity' => ['demo_identity' => null],
        default => [],
    };
    expect(fn () => $field === 'delete' ? $user->delete() : $user->forceFill($attributes)->save())->toThrow(QueryException::class);
    expect(fn () => $field === 'delete' ? DB::table('users')->where('id', $user->id)->delete() : DB::table('users')->where('id', $user->id)->update($attributes))->toThrow(QueryException::class);
    expect($user->fresh()->getAttributes())->toBe($before);
})->with(['role', 'email', 'password', 'identity', 'delete']);

test('maintenance cannot delete or change the history of a non-demo ticket', function () {
    $normal = User::factory()->administrator()->create();
    $ticket = app(CreateTicket::class)->execute($normal, ticketCoreInput());
    app(AddComment::class)->execute($normal, $ticket, ['version' => 1, 'body' => 'Historique non-démo']);
    $before = publicDemoSnapshot();
    foreach (['comments', 'ticket_activities', 'tickets'] as $table) {
        expect(fn () => app(DemoMaintenance::class)->run(fn () => DB::table($table)->delete()))->toThrow(QueryException::class);
    }
    expect(publicDemoSnapshot())->toBe($before);
});

test('demo ownership cannot be forged converted or mass assigned', function () {
    $normal = User::factory()->create();
    $ticket = app(CreateTicket::class)->execute($normal, ticketCoreInput());
    expect(fn () => DB::table('tickets')->where('id', $ticket->id)->update(['is_demo' => true]))->toThrow(QueryException::class);
    expect(fn () => DB::table('users')->where('id', $normal->id)->update(['demo_identity' => 'alex']))->toThrow(QueryException::class);
    $normal->fill(['demo_identity' => 'administrator', 'role' => 'admin'])->save();
    expect($normal->fresh()->isDemo())->toBeFalse();
    expect($normal->fresh()->isAdministrator())->toBeFalse();
    expect($ticket->fresh()->is_demo)->toBeFalse();
});

test('public mode cannot use the local password rotation command', function () {
    config(['app.demo_mode' => true]);
    $this->app->detectEnvironment(fn (): string => 'local');
    $this->artisan('deskops:demo')->expectsOutput('Utilisez deskops:demo-reset en mode démonstration public. Aucun mot de passe ne sera affiché.')->assertFailed();
    $this->assertDatabaseCount('users', 0);
});

test('the initial fixture is immutable and contains no credential metadata', function () {
    config(['app.demo_mode' => true]);
    app(ResetDemo::class)->execute();
    $fixture = DB::table('demo_state')->value('fixture');
    expect($fixture)->not->toContain('password')->not->toContain('remember_token');
    expect(fn () => DB::table('demo_state')->update(['fixture' => null]))->toThrow(QueryException::class);
    expect(DB::table('demo_state')->value('fixture'))->toBe($fixture);
});

test('demo accounts cannot be replaced or cleared by SQL', function (bool $replace) {
    $this->seed(DemoUserSeeder::class);
    $user = (array) DB::table('users')->where('demo_identity', 'alex')->first();
    expect(function () use ($replace, $user): void {
        if (! $replace) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('TRUNCATE TABLE users CASCADE');
            } else {
                DB::table('users')->delete();
            }

            return;
        }
        $columns = implode(', ', array_keys($user));
        $bindings = implode(', ', array_fill(0, count($user), '?'));
        $sql = DB::getDriverName() === 'pgsql'
            ? "INSERT INTO users ({$columns}) VALUES ({$bindings}) ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name"
            : "INSERT OR REPLACE INTO users ({$columns}) VALUES ({$bindings})";
        DB::statement($sql, array_values($user));
    })->toThrow(QueryException::class);
    expect((array) DB::table('users')->where('id', $user['id'])->first())->toBe($user);
    $this->assertDatabaseCount('users', 3);
})->with([false, true]);

test('maintenance never enables update replacement or truncate of demo history', function (string $table, string $operation) {
    config(['app.demo_mode' => true]);
    app(ResetDemo::class)->execute();
    $before = publicDemoSnapshot();
    $row = (array) DB::table($table)->orderBy('id')->first();
    expect(fn () => app(DemoMaintenance::class)->run(function () use ($table, $operation, $row): void {
        if ($operation === 'update') {
            DB::table($table)->where('id', $row['id'])->update(['created_at' => '2030-01-01 00:00:00']);
        } else {
            $columns = implode(', ', array_keys($row));
            $bindings = implode(', ', array_fill(0, count($row), '?'));
            $sql = DB::getDriverName() === 'pgsql'
                ? "INSERT INTO {$table} ({$columns}) VALUES ({$bindings}) ON CONFLICT (id) DO UPDATE SET created_at = EXCLUDED.created_at"
                : "INSERT OR REPLACE INTO {$table} ({$columns}) VALUES ({$bindings})";
            DB::statement($sql, array_values($row));
        }
    }))->toThrow(QueryException::class);
    expect(publicDemoSnapshot())->toBe($before);
})->with([
    ['comments', 'update'], ['ticket_activities', 'update'],
    ['comments', 'replace'], ['ticket_activities', 'replace'],
]);

test('PostgreSQL rejects truncating demo history even during maintenance', function (string $table) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('SQLite ne possède pas de commande TRUNCATE ; sa suppression ligne par ligne est couverte par les tests de reset.');
    }
    config(['app.demo_mode' => true]);
    app(ResetDemo::class)->execute();
    $before = publicDemoSnapshot();
    expect(fn () => app(DemoMaintenance::class)->run(fn () => DB::statement("TRUNCATE TABLE {$table}")))->toThrow(QueryException::class);
    expect(publicDemoSnapshot())->toBe($before);
})->with(['comments', 'ticket_activities']);
