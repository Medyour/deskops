<?php

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('runtime PostgreSQL URLs resolve through Laravel without a connection or credentials in source', function (string $scheme) {
    config(['database.connections.runtime_postgresql' => [
        ...config('database.connections.pgsql'),
        'url' => $scheme.'://runtime_user@database.invalid:5432/deskops_test?sslmode=verify-full',
    ]]);

    try {
        $configuration = DB::connection('runtime_postgresql')->getConfig();
        expect($configuration)->toMatchArray([
            'driver' => 'pgsql', 'host' => 'database.invalid', 'port' => 5432,
            'database' => 'deskops_test', 'username' => 'runtime_user', 'password' => '',
            'sslmode' => 'verify-full', 'timezone' => 'UTC', 'isolation_level' => 'READ COMMITTED',
        ]);
    } finally {
        DB::purge('runtime_postgresql');
    }
})->with(['postgres', 'postgresql']);

test('database constraints reject invalid comments before any history is recorded', function (array $invalid) {
    $actor = User::factory()->create();
    $ticket = Ticket::factory()->create(['created_by' => $actor->id]);

    expect(fn () => DB::table('comments')->insert([
        'ticket_id' => $ticket->id, 'author_id' => $actor->id, 'body' => 'Commentaire fictif valide',
        'created_at' => '2026-01-15 12:00:00', ...$invalid,
    ]))->toThrow(QueryException::class);

    $this->assertDatabaseCount('comments', 0);
    $this->assertDatabaseCount('ticket_activities', 0);
})->with([
    'empty' => [['body' => '']], 'blank' => [['body' => '   ']],
    'too long' => [['body' => str_repeat('a', 5001)]],
    'missing author' => [['author_id' => 999999]], 'missing ticket' => [['ticket_id' => 999999]],
]);
