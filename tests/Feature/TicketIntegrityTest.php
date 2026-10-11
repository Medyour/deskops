<?php

use App\Actions\Tickets\AddComment;
use App\Actions\Tickets\AssignTicket;
use App\Actions\Tickets\ChangeTicketStatus;
use App\Actions\Tickets\CreateTicket;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('the database rejects invalid ticket invariants independently of validation', function (array $attributes) {
    $ticket = Ticket::factory()->create();

    expect(fn () => DB::table('tickets')->where('id', $ticket->id)->update($attributes))
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => 'new', 'priority' => 'normal', 'version' => 1, 'assigned_to' => null]);
})->with([
    'unknown status' => [['status' => 'invalid']], 'unknown priority' => [['priority' => 'critical']],
    'zero version' => [['version' => 0]], 'fractional version' => [['version' => 1.5]],
    'short title' => [['title' => 'bad']], 'short description' => [['description' => 'short']],
    'missing creator' => [['created_by' => 999999]], 'missing assignee' => [['assigned_to' => 999999]],
    'active without assignee' => [['status' => 'in_progress']],
    'new with resolution date' => [['resolved_at' => '2026-01-01 00:00:00']],
    'new with closure date' => [['closed_at' => '2026-01-01 00:00:00']],
]);

test('the database rejects administrative assignees and later role escalation of assigned agents', function () {
    $admin = User::factory()->administrator()->create();
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->assignedTo($agent)->create();

    expect(fn () => DB::table('tickets')->where('id', $ticket->id)->update(['assigned_to' => $admin->id]))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('users')->where('id', $agent->id)->update(['role' => 'admin']))
        ->toThrow(QueryException::class);

    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'assigned_to' => $agent->id]);
    $this->assertDatabaseHas('users', ['id' => $agent->id, 'role' => 'agent']);
});

test('resolution and closure require consistent timestamps at database level', function (array $attributes) {
    $ticket = Ticket::factory()->inStatus(TicketStatus::InProgress)->create();

    expect(fn () => DB::table('tickets')->where('id', $ticket->id)->update($attributes))
        ->toThrow(QueryException::class);
    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'status' => 'in_progress', 'resolved_at' => null, 'closed_at' => null]);
})->with([
    'resolved without date' => [['status' => 'resolved']],
    'closed without dates' => [['status' => 'closed']],
    'closed before resolution' => [['status' => 'closed', 'resolved_at' => '2026-02-01 12:00:00', 'closed_at' => '2026-02-01 11:00:00']],
]);

test('activities and comments resist update delete replacement and clearing their tables', function (string $table, string $operation) {
    $actor = User::factory()->administrator()->create();
    $ticket = app(CreateTicket::class)->execute($actor, ticketCoreInput());
    app(AddComment::class)->execute($actor, $ticket, ['version' => 1, 'body' => 'Commentaire fictif immuable']);
    $record = (array) DB::table($table)->orderBy('id')->first();

    expect(function () use ($table, $operation, $record): void {
        if ($operation === 'update') {
            DB::table($table)->where('id', $record['id'])->update(['created_at' => '2030-01-01 00:00:00']);
        } elseif ($operation === 'delete') {
            DB::table($table)->where('id', $record['id'])->delete();
        } elseif ($operation === 'truncate') {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("TRUNCATE TABLE {$table}");
            } else {
                DB::table($table)->delete();
            }
        } else {
            $columns = implode(', ', array_keys($record));
            $placeholders = implode(', ', array_fill(0, count($record), '?'));
            $sql = DB::getDriverName() === 'pgsql'
                ? "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders}) ON CONFLICT (id) DO UPDATE SET created_at = EXCLUDED.created_at"
                : "INSERT OR REPLACE INTO {$table} ({$columns}) VALUES ({$placeholders})";
            DB::statement($sql, array_values($record));
        }
    })->toThrow(QueryException::class, match ([$table, $operation === 'replace']) {
        ['comments', true] => 'Comments cannot be replaced',
        ['ticket_activities', true] => 'Ticket activities cannot be replaced',
        ['comments', false] => 'Comments are immutable',
        default => 'Ticket activities are append-only',
    });

    expect((array) DB::table($table)->where('id', $record['id'])->first())->toBe($record);
    $this->assertDatabaseCount('ticket_activities', 2);
    $this->assertDatabaseCount('comments', 1);
})->with([
    ['ticket_activities', 'update'], ['ticket_activities', 'delete'], ['ticket_activities', 'replace'],
    ['comments', 'update'], ['comments', 'delete'], ['comments', 'replace'],
    ['ticket_activities', 'truncate'], ['comments', 'truncate'],
]);

test('an activity write failure rolls back every associated mutation', function (string $operation) {
    $actor = User::factory()->administrator()->create();
    $agent = User::factory()->create();
    $ticket = Ticket::factory()->inStatus(TicketStatus::InProgress)->create();
    $before = $ticket->fresh()->getAttributes();
    $restoreHistory = $this->preventActivityWrites();

    try {
        expect(fn () => match ($operation) {
            'create' => app(CreateTicket::class)->execute($actor, ticketCoreInput()),
            'assign' => app(AssignTicket::class)->execute($actor, $ticket, ['version' => 1, 'assigned_to' => $agent->id]),
            'status' => app(ChangeTicketStatus::class)->execute($actor, $ticket, ['version' => 1, 'status' => 'resolved', 'reason' => 'Solution fictive']),
            'comment' => app(AddComment::class)->execute($actor, $ticket, ['version' => 1, 'body' => 'Suivi fictif']),
        })->toThrow(QueryException::class);
    } finally {
        $restoreHistory();
    }

    expect($ticket->fresh()->getAttributes())->toBe($before);
    $this->assertDatabaseCount('tickets', 1);
    $this->assertDatabaseCount('comments', 0);
    $this->assertDatabaseCount('ticket_activities', 0);
})->with(['create', 'assign', 'status', 'comment']);

test('SQL replacement cannot erase the creation activity through its secondary unique index', function () {
    $actor = User::factory()->create();
    $ticket = app(CreateTicket::class)->execute($actor, ticketCoreInput());
    $original = (array) DB::table('ticket_activities')->first();
    $replacement = [...$original, 'id' => $original['id'] + 100];
    $columns = implode(', ', array_keys($replacement));
    $placeholders = implode(', ', array_fill(0, count($replacement), '?'));

    $sql = DB::getDriverName() === 'pgsql'
        ? "INSERT INTO ticket_activities ({$columns}) VALUES ({$placeholders}) ON CONFLICT (ticket_id) WHERE type = 'ticket_created' DO UPDATE SET changes = EXCLUDED.changes"
        : "INSERT OR REPLACE INTO ticket_activities ({$columns}) VALUES ({$placeholders})";
    expect(fn () => DB::statement($sql, array_values($replacement)))
        ->toThrow(QueryException::class, 'Ticket activities cannot be replaced');

    expect((array) DB::table('ticket_activities')->first())->toBe($original);
    $this->assertDatabaseCount('ticket_activities', 1);
    $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'version' => 1]);
});

test('foreign keys preserve tickets users and their historical actors', function () {
    $admin = User::factory()->administrator()->create();
    $agent = User::factory()->create();
    $ticket = app(CreateTicket::class)->execute($admin, ticketCoreInput());
    app(AssignTicket::class)->execute($admin, $ticket, ['version' => 1, 'assigned_to' => $agent->id]);

    expect(fn () => $ticket->delete())->toThrow(QueryException::class);
    expect(fn () => $admin->delete())->toThrow(QueryException::class);
    expect(fn () => $agent->delete())->toThrow(QueryException::class);

    $this->assertDatabaseCount('tickets', 1);
    $this->assertDatabaseCount('users', 2);
    $this->assertDatabaseCount('ticket_activities', 2);
});

test('history rejects unknown actors malformed metadata and duplicate creation', function (string $kind) {
    $actor = User::factory()->create();
    $ticket = app(CreateTicket::class)->execute($actor, ticketCoreInput());
    $row = (array) DB::table('ticket_activities')->first();
    unset($row['id']);
    $attributes = match ($kind) {
        'actor' => ['actor_id' => 999999, 'type' => 'assignment_changed'],
        'json' => ['changes' => 'invalid-json', 'type' => 'assignment_changed'],
        'array' => ['changes' => '[]', 'type' => 'assignment_changed'],
        'type' => ['type' => 'session_changed'],
        default => [],
    };

    expect(fn () => DB::table('ticket_activities')->insert([...$row, ...$attributes]))->toThrow(QueryException::class);
    $this->assertDatabaseCount('ticket_activities', 1);
})->with(['actor', 'json', 'array', 'type', 'duplicate creation']);

test('ticket references stay unique and stable above four digits', function () {
    $agent = User::factory()->create();
    if (DB::getDriverName() === 'pgsql') {
        DB::statement('ALTER SEQUENCE tickets_id_seq RESTART WITH 10000');
        $ticket = Ticket::factory()->create();
    } else {
        $ticket = Ticket::factory()->create(['id' => 10000]);
    }
    $next = app(CreateTicket::class)->execute($agent, ticketCoreInput());

    app(AssignTicket::class)->execute($agent, $ticket, ['version' => 1, 'assigned_to' => $agent->id]);

    expect($ticket->fresh()->reference)->toBe('DS-10000');
    expect($next->reference)->toBe('DS-10001');
});

test('history is ordered by timestamp then id even when events share a timestamp', function () {
    $this->travelTo('2026-02-01 12:00:00');
    $actor = User::factory()->create();
    $ticket = app(CreateTicket::class)->execute($actor, ticketCoreInput());
    $ticket = app(AssignTicket::class)->execute($actor, $ticket, ['version' => 1, 'assigned_to' => $actor->id]);

    $ticket = app(ChangeTicketStatus::class)->execute($actor, $ticket, ['version' => 2, 'status' => 'in_progress']);

    expect($ticket->activities->pluck('type')->map(fn ($type): string => $type->value)->all())
        ->toBe(['ticket_created', 'assignment_changed', 'status_changed']);
    expect($ticket->activities->pluck('id')->all())->toBe([1, 2, 3]);
});
