<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Enums\TicketQueue;
use App\Enums\TicketStatus;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'description', 'requester_name', 'requester_email', 'priority'])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => TicketStatus::class, 'priority' => TicketPriority::class, 'version' => 'integer', 'resolved_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'last_activity_at' => 'immutable_datetime'];
    }

    /**
     * @param  Builder<Ticket>  $query
     * @param  array{q?: string|null, status?: string|null, priority?: string|null, assignee?: string|null, queue?: string|null, page?: int|null}  $filters
     * @return Builder<Ticket>
     */
    public function scopeFiltered(Builder $query, array $filters, int $viewerId): Builder
    {
        if (($search = $filters['q'] ?? '') !== '') {
            $referenceId = null;
            if (preg_match('/^DS-([0-9]{4,})$/iD', $search, $matches) === 1) {
                $candidate = (int) $matches[1];
                if ('DS-'.str_pad((string) $candidate, 4, '0', STR_PAD_LEFT) === strtoupper($search)) {
                    $referenceId = $candidate;
                }
            }
            $literal = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(function (Builder $searchQuery) use ($referenceId, $literal): void {
                $searchQuery->where('id', $referenceId);
                foreach (['title', 'requester_name', 'requester_email'] as $field) {
                    $searchQuery->orWhereRaw('LOWER('.$field.") LIKE LOWER(?) ESCAPE '\\'", [$literal]);
                }
            });
        }
        foreach (['status', 'priority'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['assignee'])) {
            if ($filters['assignee'] === 'unassigned') {
                $query->whereNull('assigned_to');
            } else {
                $query->where('assigned_to', $filters['assignee'] === 'me' ? $viewerId : (int) $filters['assignee']);
            }
        }

        if (! empty($filters['queue'])) {
            $query->inQueue(TicketQueue::from($filters['queue']));
        }

        return $query;
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeInQueue(Builder $query, TicketQueue $queue): Builder
    {
        if ($queue === TicketQueue::Completed) {
            return $query->whereIn('status', TicketQueue::completedStatuses());
        }

        $query->whereIn('status', TicketQueue::activeStatuses());

        return match ($queue) {
            TicketQueue::Unassigned => $query->whereNull('assigned_to'),
            TicketQueue::HighPriority => $query->whereIn('priority', TicketQueue::attentionPriorities()),
            TicketQueue::Urgent => $query->where('priority', TicketPriority::Urgent),
            TicketQueue::Attention => $query->where(function (Builder $attention): void {
                $attention->whereNull('assigned_to')->orWhereIn('priority', TicketQueue::attentionPriorities());
            }),
            default => $query,
        };
    }

    /** @return Attribute<string, never> */
    protected function reference(): Attribute
    {
        return Attribute::make(get: fn (): string => 'DS-'.str_pad((string) $this->getKey(), 4, '0', STR_PAD_LEFT));
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return HasMany<TicketActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(TicketActivity::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->orderBy('created_at')->orderBy('id');
    }
}
