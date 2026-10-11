<?php

namespace App\Enums;

enum TicketQueue: string
{
    case Active = 'active';
    case Unassigned = 'unassigned';
    case HighPriority = 'high_priority';
    case Urgent = 'urgent';
    case Attention = 'attention';
    case ResolvedRecently = 'resolved_recently';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Tickets actifs',
            self::Unassigned => 'Actifs non assignés',
            self::HighPriority => 'Actifs de priorité Haute ou Urgente',
            self::Urgent => 'Urgents actifs',
            self::Attention => 'À traiter : non assignés ou prioritaires actifs',
            self::ResolvedRecently => 'Résolus sur les 7 derniers jours',
        };
    }

    /** @return list<string> */
    public static function activeStatuses(): array
    {
        return [TicketStatus::New->value, TicketStatus::InProgress->value, TicketStatus::WaitingCustomer->value];
    }

    /** @return list<string> */
    public static function attentionPriorities(): array
    {
        return [TicketPriority::High->value, TicketPriority::Urgent->value];
    }
}
