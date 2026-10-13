<?php

use App\Actions\Tickets\CreateTicket;
use App\Enums\DemoIdentity;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** @return array<string, string> */
function productionFixtureSnapshot(): array
{
    $snapshot = [];
    foreach (['users', 'tickets', 'comments', 'ticket_activities'] as $table) {
        $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }

    return $snapshot;
}

test('exclusive reset refuses a non-demo database atomically', function () {
    config(['app.demo_mode' => true]);
    $this->app->detectEnvironment(fn (): string => 'production');
    $user = User::factory()->create();
    app(CreateTicket::class)->execute($user, ticketCoreInput());
    $before = productionFixtureSnapshot();
    $this->artisan('deskops:demo-reset', ['--force' => true, '--exclusive' => true])->expectsOutput('Cette opération exige une base exclusivement dédiée à la démonstration.')->assertFailed();
    expect(productionFixtureSnapshot())->toBe($before);
    $this->assertDatabaseHas('demo_state', ['active' => false, 'generation' => 0]);
});

test('exclusive reset restores additions and remains deterministic on a dedicated demo database', function () {
    config(['app.demo_mode' => true]);
    $this->app->detectEnvironment(fn (): string => 'production');
    $this->artisan('deskops:demo-reset', ['--force' => true, '--exclusive' => true])->assertSuccessful();
    $before = productionFixtureSnapshot();
    $admin = User::where('demo_identity', DemoIdentity::Administrator)->firstOrFail();
    app(CreateTicket::class)->execute($admin, ticketCoreInput());
    foreach (range(1, 2) as $iteration) {
        $this->artisan('deskops:demo-reset', ['--force' => true, '--exclusive' => true])->assertSuccessful();
        expect(productionFixtureSnapshot())->toBe($before);
    }
    $this->assertDatabaseCount('tickets', 12);
    $this->assertDatabaseCount('comments', 24);
    $this->assertDatabaseCount('ticket_activities', 60);
});

test('production database controls refuse legacy or mixed data before migration or reset', function (bool $legacy) {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Le contrôle de production exige PostgreSQL ; SQLite reste le moteur local.');
    }
    $originalPgConfig = config('database.connections.pgsql');
    $restore = $this->isolateDatabase(['production_readiness']);
    $this->app->detectEnvironment(fn (): string => 'production');
    $configuration = DB::connection()->getConfig();
    $url = config('database.connections.pgsql.url');
    config([
        'app.demo_mode' => true, 'app.debug' => false, 'app.url' => 'https://deskops.example',
        'cache.default' => 'database', 'session.driver' => 'database', 'session.secure' => true,
        'session.http_only' => true, 'session.same_site' => 'lax', 'queue.default' => 'sync',
        'logging.default' => 'stderr', 'mail.default' => 'array', 'app.locale' => 'fr', 'trustedproxy.proxies' => 'REMOTE_ADDR',
        'database.connections.pgsql' => [...$configuration, 'url' => $url],
    ]);
    DB::purge('pgsql');
    DB::setDefaultConnection('pgsql');
    try {
        expect(Artisan::call('deskops:production-check', ['--database' => true]))->toBe(0);
        Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
        if ($legacy) {
            Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true, '--no-interaction' => true]);
        }
        User::factory()->create();
        config(['database.connections.pgsql.url' => $url]);
        expect(Artisan::call('deskops:production-check', ['--database' => true]))->toBe(1);
        expect(Artisan::output())->toContain('base exclusivement dédiée');
        $this->assertDatabaseCount('users', 1);
    } finally {
        DB::purge('pgsql');
        config(['database.connections.pgsql' => $originalPgConfig]);
        $restore();
    }
})->with([false, true]);
