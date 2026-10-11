<?php

namespace App\Actions\Tickets;

use App\Enums\TicketActivityType;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class AddComment
{
    public function __construct(private TicketMutation $mutation) {}

    /** @param array{version: int, body: string} $input */
    public function execute(User $actor, Ticket $ticket, array $input): Ticket
    {
        $data = TicketInput::validate($input, [
            'version' => ['required', 'integer', 'min:1'],
            'body' => ['required', 'string', 'min:1', 'max:5000', 'regex:/[^\s\p{Z}]/u'],
        ]);

        return $this->mutation->run($ticket, (int) $data['version'], function (Ticket $current) use ($actor, $data): Ticket {
            $actor->refresh();
            Gate::forUser($actor)->authorize('comment', $current);
            $current->forceFill(['version' => $current->version + 1])->save();
            $comment = $current->comments()->create(['author_id' => $actor->id, 'body' => $data['body']]);
            $current->activities()->create([
                'actor_id' => $actor->id, 'type' => TicketActivityType::CommentAdded,
                'changes' => ['comment_id' => ['from' => null, 'to' => $comment->id]],
            ]);

            return $current->refresh();
        });
    }
}
