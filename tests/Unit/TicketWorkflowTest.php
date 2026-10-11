<?php

use App\Enums\TicketStatus;

$allowedTransitions = [
    'new' => ['in_progress'],
    'in_progress' => ['waiting_customer', 'resolved'],
    'waiting_customer' => ['in_progress', 'resolved'],
    'resolved' => ['in_progress', 'closed'],
    'closed' => ['in_progress'],
];
$transitionCases = [];
foreach (TicketStatus::cases() as $from) {
    foreach (TicketStatus::cases() as $to) {
        $transitionCases[$from->value.' to '.$to->value] = [$from, $to, in_array($to->value, $allowedTransitions[$from->value], true)];
    }
}

test('the status graph accepts exactly the MVP transitions', function (TicketStatus $from, TicketStatus $to, bool $allowed) {
    expect($from->canTransitionTo($to))->toBe($allowed);
})->with($transitionCases);
