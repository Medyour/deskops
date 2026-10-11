<?php

namespace App\Enums;

enum TicketStatus: string
{
    case New = 'new';
    case InProgress = 'in_progress';
    case WaitingCustomer = 'waiting_customer';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Nouveau', self::InProgress => 'En cours', self::WaitingCustomer => 'En attente du client', self::Resolved => 'Résolu', self::Closed => 'Fermé',
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, match ($this) {
            self::New => [self::InProgress],
            self::InProgress => [self::WaitingCustomer, self::Resolved],
            self::WaitingCustomer => [self::InProgress, self::Resolved],
            self::Resolved => [self::InProgress, self::Closed],
            self::Closed => [self::InProgress],
        }, true);
    }

    public function requiresReasonFor(self $target): bool
    {
        return in_array($target, [self::WaitingCustomer, self::Resolved], true)
            || ($target === self::InProgress && in_array($this, [self::Resolved, self::Closed], true));
    }
}
