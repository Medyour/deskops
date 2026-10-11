<?php

test('local preparation preserves an existing application key', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    config(['app.key' => 'existing-local-key']);
    $originalDriver = config('database.default');
    config(['database.default' => 'sqlite']);

    try {
        $this->artisan('deskops:prepare')->assertSuccessful();
    } finally {
        config(['database.default' => $originalDriver]);
    }

    expect(config('app.key'))->toBe('existing-local-key');
});

test('local preparation refuses other environments and database drivers', function (string $environment, string $driver) {
    $this->app->detectEnvironment(fn (): string => $environment);
    $originalDriver = config('database.default');
    config(['database.default' => $driver]);

    try {
        $this->artisan('deskops:prepare')
            ->expectsOutput('La préparation exige un environnement local avec SQLite.')
            ->assertFailed();
    } finally {
        config(['database.default' => $originalDriver]);
    }
})->with([
    ['production', 'sqlite'],
    ['testing', 'sqlite'],
    ['local', 'mysql'],
]);
