<?php

namespace App\Models;

use App\Enums\TicketActivityType;
use Database\Factories\TicketActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['actor_id', 'type', 'changes', 'reason'])]
class TicketActivity extends Model
{
    /** @use HasFactory<TicketActivityFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => TicketActivityType::class, 'changes' => 'array', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
