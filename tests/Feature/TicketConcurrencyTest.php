<?php

use App\Actions\Tickets\AssignTicket;
use App\Exceptions\StaleTicket;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

test('independent connections cannot both claim the same version and lock contention returns 409', function () {
    $cleanup = $this->isolateDatabase(['ticket_race', 'ticket_race_other']);

    try {
        expect(Artisan::call('migrate', ['--database' => 'ticket_race', '--force' => true, '--no-interaction' => true]))->toBe(0);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET lock_timeout TO '20ms'");
        }
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
            $this->actingAs($firstAgent)->patchJson(route('tickets.assign', $ticket), ['version' => 2, 'assigned_to' => $firstAgent->id])->assertConflict();
        } finally {
            $other->rollBack();
        }
        $this->assertDatabaseCount('ticket_activities', 1, 'ticket_race');
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'version' => 2], 'ticket_race');
    } finally {
        $cleanup();
    }
});
