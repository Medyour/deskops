<?php

namespace Database\Factories;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Ticket> */
class TicketFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(5), 'description' => fake()->paragraph(),
            'requester_name' => fake()->name(), 'requester_email' => fake()->safeEmail(),
            'priority' => TicketPriority::Normal, 'status' => TicketStatus::New,
            'created_by' => User::factory(), 'assigned_to' => null, 'version' => 1,
            'is_demo' => fn (array $attributes): bool => User::findOrFail($attributes['created_by'])->isDemo(),
            'resolved_at' => null, 'closed_at' => null,
        ];
    }

    public function assignedTo(User $agent): static
    {
        return $this->state(fn (array $attributes): array => ['assigned_to' => $agent->id]);
    }

    public function inStatus(TicketStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status, 'assigned_to' => $status === TicketStatus::New ? null : User::factory(),
            'resolved_at' => in_array($status, [TicketStatus::Resolved, TicketStatus::Closed], true) ? now() : null,
            'closed_at' => $status === TicketStatus::Closed ? now() : null,
        ]);
    }
}
