<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('a fresh SQLite installation supports the demo and cleans all triggers through populated rollbacks', function () {
    $path = tempnam(sys_get_temp_dir(), 'deskops-ticket-migrations-');
    $default = DB::getDefaultConnection();
    config(['database.connections.ticket_migrations' => [...config('database.connections.sqlite'), 'database' => $path, 'url' => null]]);
    DB::setDefaultConnection('ticket_migrations');
    $this->app->detectEnvironment(fn (): string => 'local');

    try {
        expect(Artisan::call('migrate', ['--database' => 'ticket_migrations', '--no-interaction' => true]))->toBe(0);
        expect(Artisan::call('deskops:demo', ['--no-interaction' => true]))->toBe(0);
        $snapshot = [];
        foreach (['tickets', 'comments', 'ticket_activities'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        expect(Artisan::call('deskops:demo', ['--no-interaction' => true]))->toBe(0);

        foreach ($snapshot as $table => $before) {
            expect(DB::table($table)->orderBy('id')->get()->toJson())->toBe($before);
        }
        $this->assertDatabaseCount('tickets', 12, 'ticket_migrations');
        $this->assertDatabaseCount('comments', 24, 'ticket_migrations');
        $this->assertDatabaseCount('ticket_activities', 60, 'ticket_migrations');

        expect(Artisan::call('migrate:rollback', ['--database' => 'ticket_migrations', '--step' => 3, '--no-interaction' => true]))->toBe(0);
        foreach (['tickets', 'comments', 'ticket_activities'] as $table) {
            expect(Schema::connection('ticket_migrations')->hasTable($table))->toBeFalse();
        }
        expect(DB::table('sqlite_master')->where('type', 'trigger')->count())->toBe(0);
        expect(DB::table('users')->where('email', 'alex@deskops.example')->update(['role' => 'admin']))->toBe(1);

        expect(Artisan::call('migrate', ['--database' => 'ticket_migrations', '--no-interaction' => true]))->toBe(0);
        expect(Artisan::call('deskops:demo', ['--no-interaction' => true]))->toBe(0);
        $this->assertDatabaseCount('tickets', 12, 'ticket_migrations');
        $this->assertDatabaseCount('comments', 24, 'ticket_migrations');
        $this->assertDatabaseCount('ticket_activities', 60, 'ticket_migrations');

        expect(Artisan::call('migrate:reset', ['--database' => 'ticket_migrations', '--no-interaction' => true]))->toBe(0);
        expect(Schema::connection('ticket_migrations')->hasTable('users'))->toBeFalse();
        expect(DB::table('sqlite_master')->where('type', 'trigger')->count())->toBe(0);
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('ticket_migrations');
        unlink($path);
    }
});
