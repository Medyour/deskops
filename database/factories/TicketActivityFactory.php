<?php

namespace Database\Factories;

use App\Enums\TicketActivityType;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketActivity> */
class TicketActivityFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['ticket_id' => Ticket::factory(), 'actor_id' => User::factory(), 'type' => TicketActivityType::TicketCreated,
            'changes' => ['status' => ['from' => null, 'to' => 'new']], 'reason' => null, 'created_at' => now()];
    }
}
