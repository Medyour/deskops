<?php

namespace App\Enums;

enum TicketActivityType: string
{
    case TicketCreated = 'ticket_created';
    case TicketUpdated = 'ticket_updated';
    case PriorityChanged = 'priority_changed';
    case AssignmentChanged = 'assignment_changed';
    case StatusChanged = 'status_changed';
    case CommentAdded = 'comment_added';

    public function label(): string
    {
        return match ($this) {
            self::TicketCreated => 'Ticket créé', self::TicketUpdated => 'Informations modifiées',
            self::PriorityChanged => 'Priorité modifiée', self::AssignmentChanged => 'Assignation modifiée',
            self::StatusChanged => 'Statut modifié', self::CommentAdded => 'Commentaire ajouté',
        };
    }
}
