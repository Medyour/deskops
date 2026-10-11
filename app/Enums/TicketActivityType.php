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
}
