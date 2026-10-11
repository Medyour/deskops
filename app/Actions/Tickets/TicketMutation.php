<?php

namespace App\Actions\Tickets;

use App\Enums\TicketActivityType;
use App\Exceptions\StaleTicket;
use App\Models\Ticket;
use App\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class TicketMutation
{
    /** @param Closure(Ticket): Ticket $operation */
    public function run(Ticket $ticket, int $expectedVersion, Closure $operation): Ticket
    {
        try {
            return DB::transaction(function () use ($ticket, $expectedVersion, $operation): Ticket {
                /** The first statement claims the SQLite writer without altering timestamps or the version. */
                $matched = DB::table('tickets')->where('id', $ticket->id)->where('version', $expectedVersion)
                    ->update(['version' => DB::raw('version')]);
                if ($matched !== 1) {
                    throw new StaleTicket;
                }

                return $operation(Ticket::query()->findOrFail($ticket->id));
            }, attempts: 3);
        } catch (QueryException $exception) {
            if (in_array(((int) ($exception->errorInfo[1] ?? 0)) & 255, [5, 6], true)) {
                throw new StaleTicket;
            }
            throw $exception;
        }
    }

    /** @param array<string, int|string|null> $attributes */
    public function save(Ticket $ticket, array $attributes, User $actor, TicketActivityType $type, ?string $reason = null): Ticket
    {
        $changes = [];
        foreach ($attributes as $field => $value) {
            $before = $ticket->getRawOriginal($field);
            if ($before !== $value) {
                $changes[$field] = ['from' => $before, 'to' => $value];
            }
        }
        if ($changes === []) {
            return $ticket;
        }
        $ticket->forceFill([...$attributes, 'version' => $ticket->version + 1])->save();
        $ticket->activities()->create(['actor_id' => $actor->id, 'type' => $type, 'changes' => $changes, 'reason' => $reason]);

        return $ticket->refresh();
    }
}
