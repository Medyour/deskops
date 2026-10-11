<?php

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

test('demo accounts are deterministic and repeated seeding preserves passwords', function () {
    $this->seed(DatabaseSeeder::class);
    $before = User::orderBy('email')->get()->toArray();
    $hash = User::where('email', 'admin@deskops.example')->firstOrFail()->password;

    $this->travel(10)->days();
    $this->seed(DatabaseSeeder::class);

    expect(User::orderBy('email')->get()->toArray())->toBe($before);
    expect(User::where('email', 'admin@deskops.example')->firstOrFail()->password)->toBe($hash);
    expect(User::where('role', UserRole::Admin)->count())->toBe(1);
    expect(User::where('role', UserRole::Agent)->count())->toBe(2);
    $this->assertDatabaseCount('users', 3);
});

test('demo seeding is refused in production', function () {
    $this->app->detectEnvironment(fn (): string => 'production');

    expect(fn () => $this->seed(DatabaseSeeder::class))->toThrow(LogicException::class);
    $this->assertDatabaseCount('users', 0);
});

test('the demo command is unavailable outside local', function (string $environment) {
    $this->app->detectEnvironment(fn (): string => $environment);

    $this->artisan('deskops:demo')
        ->expectsOutput('Cette commande est réservée à l’environnement local.')
        ->assertFailed();

    $this->assertDatabaseCount('users', 0);
})->with(['testing', 'staging', 'production']);

test('the demo command preserves credentials revokes sessions and tokens and never displays access details', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'admin@deskops.example')->firstOrFail();
    $oldHash = $user->password;
    DB::table('sessions')->insert([
        'id' => 'old-session',
        'user_id' => $user->id,
        'payload' => 'test',
        'last_activity' => 0,
    ]);
    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token' => 'obsolete',
    ]);

    expect(Artisan::call('deskops:demo'))->toBe(0);
    $output = Artisan::output();
    expect($output)->not->toContain($user->email)->not->toContain($oldHash);
    expect($user->fresh()->password)->toBe($oldHash);
    $this->assertDatabaseCount('users', 3);
    $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
    $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);

    expect(Artisan::call('deskops:demo'))->toBe(0);
    expect($user->fresh()->password)->toBe($oldHash);
    expect(Artisan::output())->not->toContain($user->email)->not->toContain($oldHash);
    $this->assertDatabaseCount('users', 3);
});
