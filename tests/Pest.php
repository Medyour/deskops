<?php

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(env('DB_CONNECTION') === 'pgsql' ? DatabaseMigrations::class : RefreshDatabase::class)->in('Feature');

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

function generatedTestPassword(): string
{
    return Str::random(24).strtolower(chr(random_int(65, 90))).chr(random_int(65, 90)).random_int(0, 9);
}
