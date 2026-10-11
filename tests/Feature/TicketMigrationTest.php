<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('a fresh installation supports the demo and cleans all triggers and functions through populated rollbacks', function () {
    $cleanup = $this->isolateDatabase(['ticket_migrations']);
    $this->app->detectEnvironment(fn (): string => 'local');

    try {
        expect(Artisan::call('migrate', ['--database' => 'ticket_migrations', '--no-interaction' => true]))->toBe(0);
        expect(Artisan::call('migrate:fresh', ['--database' => 'ticket_migrations', '--force' => true, '--no-interaction' => true]))->toBe(0);
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

        expect(Artisan::call('migrate:rollback', ['--database' => 'ticket_migrations', '--step' => 4, '--no-interaction' => true]))->toBe(0);
        foreach (['tickets', 'comments', 'ticket_activities'] as $table) {
            expect(Schema::connection('ticket_migrations')->hasTable($table))->toBeFalse();
        }
        expect($this->remainingDatabaseProtections())->toBe(['triggers' => 0, 'functions' => 0]);
        expect(DB::table('users')->where('email', 'alex@deskops.example')->update(['role' => 'admin']))->toBe(1);

        expect(Artisan::call('migrate', ['--database' => 'ticket_migrations', '--no-interaction' => true]))->toBe(0);
        expect(Artisan::call('deskops:demo', ['--no-interaction' => true]))->toBe(0);
        $this->assertDatabaseCount('tickets', 12, 'ticket_migrations');
        $this->assertDatabaseCount('comments', 24, 'ticket_migrations');
        $this->assertDatabaseCount('ticket_activities', 60, 'ticket_migrations');

        expect(Artisan::call('migrate:reset', ['--database' => 'ticket_migrations', '--no-interaction' => true]))->toBe(0);
        expect(Schema::connection('ticket_migrations')->hasTable('users'))->toBeFalse();
        expect($this->remainingDatabaseProtections())->toBe(['triggers' => 0, 'functions' => 0]);
    } finally {
        $cleanup();
    }
});
