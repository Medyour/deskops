<?php

namespace App\Enums;

enum TicketPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Basse', self::Normal => 'Normale', self::High => 'Haute', self::Urgent => 'Urgente'
        };
    }
}
