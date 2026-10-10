<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

test('authentication views render', function (string $path) {
    $this->get($path)->assertOk()->assertSee('DeskOps');
})->with(['/login', '/forgot-password', '/reset-password/test-token?email=alex%40deskops.example']);

test('both roles can sign in and the session is regenerated', function (bool $administrator) {
    $user = $administrator ? User::factory()->administrator()->create() : User::factory()->create();
    $this->withSession(['marker' => 'preserved']);
    $sessionId = session()->getId();

    $this->post(route('login.store'), [
        'email' => strtoupper($user->email),
        'password' => 'password',
        'role' => 'admin',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($sessionId);
    expect($user->fresh()->isAdministrator())->toBe($administrator);
})->with([false, true]);

test('invalid credentials cannot authenticate', function () {
    $user = User::factory()->create();

    $this->from(route('login'))->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'incorrect',
    ])->assertRedirect(route('login'))->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('login requires credentials', function () {
    $this->postJson(route('login.store'), [])
        ->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
});

test('the sixth login attempt is throttled for the same email and IP', function () {
    $user = User::factory()->create();

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson(route('login.store'), [
            'email' => $user->email,
            'password' => 'incorrect',
        ])->assertUnprocessable();
    }

    $this->postJson(route('login.store'), [
        'email' => strtoupper($user->email),
        'password' => 'incorrect',
    ])->assertTooManyRequests();
});

test('logout ends the session and renews its CSRF token', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->withSession(['private' => 'value']);
    $sessionId = session()->getId();
    $token = session()->token();

    $this->post(route('logout'))->assertRedirect(route('home'));

    $this->assertGuest();
    expect(session()->getId())->not->toBe($sessionId);
    expect(session()->token())->not->toBe($token);
    expect(session()->has('private'))->toBeFalse();
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('authentication POST requests require a CSRF token outside the test bypass', function () {
    $this->app->detectEnvironment(fn (): string => 'production');

    $this->post(route('login.store'), [
        'email' => 'nobody@deskops.example',
        'password' => 'incorrect',
    ])->assertStatus(419);
});

test('public registration is unavailable', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', ['role' => 'admin'])->assertNotFound();
    $this->assertDatabaseCount('users', 0);
});

test('authenticated users are redirected away from the login page', function () {
    $this->actingAs(User::factory()->create())->get(route('login'))
        ->assertRedirect(route('dashboard'));
});

test('password confirmation renders and validates the current password', function () {
    $this->actingAs(User::factory()->create());
    $this->get(route('password.confirm'))->assertOk();
    $this->post(route('password.confirm.store'), ['password' => 'incorrect'])
        ->assertSessionHasErrors('password');
    $this->post(route('password.confirm.store'), ['password' => 'password'])
        ->assertRedirect(route('dashboard'))->assertSessionHas('auth.password_confirmed_at');
});

test('a reset notification is delivered through the Laravel broker', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email])
        ->assertRedirect()->assertSessionHas('status', __('passwords.sent'));

    Notification::assertSentTo($user, ResetPassword::class);
});

test('a valid reset token changes the password and cannot be reused', function () {
    $user = User::factory()->create();
    $token = Password::createToken($user);
    $payload = [
        'token' => $token,
        'email' => $user->email,
        'password' => 'NewPassword123456',
        'password_confirmation' => 'NewPassword123456',
    ];

    $this->post(route('password.update'), $payload)
        ->assertRedirect(route('login'))->assertSessionHas('status', __('passwords.reset'));

    expect(Hash::check($payload['password'], $user->fresh()->password))->toBeTrue();
    expect($user->fresh()->role)->toBe($user->role);

    $this->post(route('password.update'), $payload)->assertSessionHasErrors('email');
});

test('an invalid reset token cannot change a password', function () {
    $user = User::factory()->create();
    $hash = $user->password;

    $this->post(route('password.update'), [
        'token' => 'invalid',
        'email' => $user->email,
        'password' => 'NewPassword123456',
        'password_confirmation' => 'NewPassword123456',
    ])->assertSessionHasErrors('email');

    expect($user->fresh()->password)->toBe($hash);
});

test('password resets enforce the documented policy', function (string $password, string $confirmation) {
    $user = User::factory()->create();
    $hash = $user->password;

    $this->post(route('password.update'), [
        'token' => Password::createToken($user),
        'email' => $user->email,
        'password' => $password,
        'password_confirmation' => $confirmation,
    ])->assertSessionHasErrors('password');

    expect($user->fresh()->password)->toBe($hash);
})->with([
    ['Short1', 'Short1'],
    ['lowercase123456', 'lowercase123456'],
    ['OnlyLettersLong', 'OnlyLettersLong'],
    ['ValidPassword123', 'DifferentPassword123'],
]);
