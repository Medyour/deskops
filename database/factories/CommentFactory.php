<?php

namespace Database\Factories;

use App\Models\Comment;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Comment> */
class CommentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['ticket_id' => Ticket::factory(), 'author_id' => User::factory()->administrator(), 'body' => fake()->sentence(), 'created_at' => now()];
    }
}
