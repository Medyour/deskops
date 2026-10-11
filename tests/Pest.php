<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/** @return array{title: string, description: string, requester_name: string, requester_email: string} */
function ticketCoreInput(): array
{
    return [
        'title' => 'Accès au compte de démonstration',
        'description' => 'Merci de vérifier cet accès entièrement fictif.',
        'requester_name' => 'Contact fictif',
        'requester_email' => 'contact@deskops.example',
    ];
}
