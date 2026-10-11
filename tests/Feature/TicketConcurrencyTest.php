<?php

use App\Actions\Tickets\AssignTicket;
use App\Exceptions\StaleTicket;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

test('independent SQLite file connections cannot both claim the same observed version', function () {
    $path = tempnam(sys_get_temp_dir(), 'deskops-ticket-race-');
    $default = DB::getDefaultConnection();
    $configuration = [...config('database.connections.sqlite'), 'database' => $path, 'url' => null, 'busy_timeout' => 1];
    config(['database.connections.ticket_race' => $configuration, 'database.connections.ticket_race_other' => $configuration]);
    DB::setDefaultConnection('ticket_race');

    try {
        expect(Artisan::call('migrate', ['--database' => 'ticket_race', '--force' => true, '--no-interaction' => true]))->toBe(0);
        $firstAgent = User::factory()->create();
        $secondAgent = User::factory()->create();
        $ticket = Ticket::factory()->create();
        $firstSnapshot = Ticket::on('ticket_race')->findOrFail($ticket->id);
        $secondSnapshot = Ticket::on('ticket_race_other')->findOrFail($ticket->id);

        app(AssignTicket::class)->execute($firstAgent, $firstSnapshot, ['version' => 1, 'assigned_to' => $firstAgent->id]);
        expect(fn () => app(AssignTicket::class)->execute($secondAgent, $secondSnapshot, ['version' => 1, 'assigned_to' => $secondAgent->id]))
            ->toThrow(StaleTicket::class);

        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'assigned_to' => $firstAgent->id, 'version' => 2], 'ticket_race');
        $this->assertDatabaseCount('ticket_activities', 1, 'ticket_race');
        expect(DB::connection('ticket_race_other')->table('tickets')->where('id', $ticket->id)->value('assigned_to'))->toBe($firstAgent->id);

        $other = DB::connection('ticket_race_other');
        $other->beginTransaction();
        $other->table('tickets')->where('id', $ticket->id)->update(['version' => DB::raw('version')]);
        try {
            expect(fn () => app(AssignTicket::class)->execute($firstAgent, $ticket, ['version' => 2, 'assigned_to' => $firstAgent->id]))
                ->toThrow(StaleTicket::class);
        } finally {
            $other->rollBack();
        }
        $this->assertDatabaseCount('ticket_activities', 1, 'ticket_race');
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'version' => 2], 'ticket_race');
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('ticket_race');
        DB::purge('ticket_race_other');
        unlink($path);
    }
});
