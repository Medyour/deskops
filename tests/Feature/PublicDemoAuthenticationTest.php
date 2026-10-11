<?php

use App\Actions\Demo\ResetDemo;
use App\Actions\Tickets\CreateTicket;
use App\Actions\Tickets\UpdateTicket;
use App\Enums\DemoIdentity;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

test('public demonstration is disabled by default including its POST endpoint', function () {
    expect(config('app.demo_mode'))->toBeFalse();
    $this->get(route('login'))->assertOk()->assertDontSee('Explorer comme')->assertSee('Mot de passe oublié');
    $this->post(route('demo.login'), ['role' => 'administrator'])->assertNotFound();
    $this->assertGuest();
});

test('disabled demo entry returns 404 before method authentication CSRF and rate checks', function (string $method, bool $authenticated) {
    if ($authenticated) {
        $this->actingAs(User::factory()->create());
    }
    $this->app->detectEnvironment(fn (): string => 'production');
    for ($attempt = 0; $attempt < 7; $attempt++) {
        $this->call($method, '/demo/login', ['role' => 'agent'])->assertNotFound();
    }
})->with(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'])->with([false, true]);

test('logging out a demo visitor invalidates the session and regenerates CSRF', function () {
    config(['app.demo_mode' => true]);
    $this->seed(DemoUserSeeder::class);
    $this->post(route('demo.login'), ['role' => 'agent'])->assertRedirect(route('dashboard'));
    $sessionId = session()->getId();
    $csrf = session()->token();
    $this->post(route('logout'))->assertRedirect('/');
    $this->assertGuest();
    expect(session()->getId())->not->toBe($sessionId);
    expect(session()->token())->not->toBe($csrf);
    expect(session()->has('demo_login'))->toBeFalse();
    expect(session()->has('demo_generation'))->toBeFalse();
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('public demo entry selects only the known account regenerates the session and ignores intended redirects', function (string $role, DemoIdentity $identity) {
    config(['app.demo_mode' => true]);
    $this->seed(DemoUserSeeder::class);
    $user = User::where('demo_identity', $identity)->firstOrFail();
    $before = $user->getAttributes();
    $this->withSession(['url.intended' => 'https://external.invalid/private', 'private' => 'old-data']);
    $sessionId = session()->getId();
    $csrf = session()->token();

    $this->post(route('demo.login'), ['role' => $role])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect(session()->getId())->not->toBe($sessionId);
    expect(session()->token())->not->toBe($csrf);
    expect(session()->has('private'))->toBeFalse();
    expect(session()->has('url.intended'))->toBeFalse();
    expect($user->fresh()->getAttributes())->toBe($before);
    expect(session()->get('demo_login'))->toBeTrue();
    $this->get(route('dashboard'))->assertOk()->assertSee('Les données sont fictives, partagées');
})->with([['administrator', DemoIdentity::Administrator], ['agent', DemoIdentity::Alex]]);

test('demo entry refuses unknown roles and arbitrary parameters', function (array $payload) {
    config(['app.demo_mode' => true]);
    $this->postJson(route('demo.login'), $payload)->assertUnprocessable()->assertJsonValidationErrors('role');
    $this->assertGuest();
})->with([
    'missing role' => [[]], 'unknown role' => [['role' => 'owner']], 'array role' => [['role' => ['agent']]],
    'user id' => [['role' => 'agent', 'user_id' => 999]],
    'email' => [['role' => 'agent', 'email' => 'someone@deskops.example']],
    'redirect' => [['role' => 'agent', 'redirect' => 'https://external.invalid']],
    'password' => [fn (): array => ['role' => 'agent', 'password' => generatedTestPassword()]],
]);

test('demo entry cannot use an unmarked account or a missing demo identity', function () {
    config(['app.demo_mode' => true]);
    User::factory()->administrator()->create(['email' => DemoIdentity::Administrator->email()]);
    $this->post(route('demo.login'), ['role' => 'administrator'])->assertServiceUnavailable();
    $this->assertGuest();
});

test('demo entry requires POST and CSRF outside the test bypass', function () {
    config(['app.demo_mode' => true]);
    $this->seed(DemoUserSeeder::class);
    $this->app->detectEnvironment(fn (): string => 'production');
    $this->get('/demo/login')->assertStatus(405);
    $this->post(route('demo.login'), ['role' => 'agent'])->assertStatus(419);
    $this->assertGuest();
    $this->withSession(['_token' => 'csrf-fixture-token']);
    $this->post(route('demo.login'), ['role' => 'agent', '_token' => 'csrf-fixture-token'])->assertRedirect(route('dashboard'));
});

test('demo entry is limited across roles for the same visitor IP', function () {
    config(['app.demo_mode' => true]);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->postJson(route('demo.login'), ['role' => $attempt % 2 === 0 ? 'agent' : 'administrator'])->assertServiceUnavailable();
    }
    $this->postJson(route('demo.login'), ['role' => 'agent'])->assertTooManyRequests();
    $this->assertGuest();
});

test('password recovery and confirmation are unavailable in public demo mode', function (string $method, string $path) {
    Notification::fake();
    config(['app.demo_mode' => true]);
    if (str_contains($path, 'confirm-password')) {
        $this->actingAs(User::factory()->create());
    }
    $this->{$method}($path)->assertNotFound();
    Notification::assertNothingSent();
    $this->assertDatabaseCount('password_reset_tokens', 0);
})->with([
    ['get', '/forgot-password'], ['post', '/forgot-password'], ['get', '/reset-password/fixture-token'],
    ['post', '/reset-password'], ['get', '/user/confirm-password'], ['post', '/user/confirm-password'],
]);

test('password recovery remains functional outside public demo mode for regular accounts', function () {
    Notification::fake();
    $user = User::factory()->create();
    $this->post(route('password.email'), ['email' => $user->email])->assertRedirect()->assertSessionHas('status', __('passwords.sent'));
    Notification::assertSentTo($user, ResetPassword::class);
});

test('a demo account cannot reset its password even outside public mode', function () {
    $this->seed(DemoUserSeeder::class);
    $user = User::where('demo_identity', DemoIdentity::Alex)->firstOrFail();
    $before = $user->password;
    $password = generatedTestPassword();
    $this->post(route('password.update'), [
        'email' => $user->email, 'token' => Password::createToken($user),
        'password' => $password, 'password_confirmation' => $password,
    ])->assertSessionHasErrors('email');
    expect($user->fresh()->password)->toBe($before);
});

test('public demo sessions expire when the mode is disabled or the data is reset', function (bool $reset) {
    config(['app.demo_mode' => true]);
    app(ResetDemo::class)->execute();
    $this->post(route('demo.login'), ['role' => 'agent'])->assertRedirect(route('dashboard'));
    if ($reset) {
        app(ResetDemo::class)->execute();
    } else {
        config(['app.demo_mode' => false]);
    }
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->assertGuest();
})->with([false, true]);

test('public demo accounts cannot use password login but existing local access remains possible', function () {
    $password = generatedTestPassword();
    $user = User::factory()->create(['demo_identity' => DemoIdentity::Alex, 'email' => DemoIdentity::Alex->email(), 'password' => $password]);
    config(['app.demo_mode' => true]);
    $this->post(route('login.store'), ['email' => $user->email, 'password' => $password])->assertSessionHasErrors('email');
    $this->assertGuest();
    config(['app.demo_mode' => false]);
    $this->post(route('login.store'), ['email' => $user->email, 'password' => $password])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
});

test('demo visitors see only demo tickets agents and dashboard activities and cannot mutate a regular ticket', function () {
    config(['app.demo_mode' => true]);
    $outsider = User::factory()->create(['name' => 'PRIVATE AGENT']);
    $ticket = app(CreateTicket::class)->execute($outsider, [...ticketCoreInput(), 'title' => 'PRIVATE TICKET']);
    app(ResetDemo::class)->execute();
    $this->post(route('demo.login'), ['role' => 'administrator'])->assertRedirect(route('dashboard'));
    $this->get(route('tickets.index'))->assertOk()->assertDontSee('PRIVATE TICKET')->assertDontSee('PRIVATE AGENT');
    $this->get(route('dashboard'))->assertOk()->assertDontSee('PRIVATE TICKET')->assertDontSee('PRIVATE AGENT');
    $this->get(route('tickets.show', $ticket))->assertForbidden();
    $this->patch(route('tickets.assign', $ticket), ['version' => 1, 'assigned_to' => $outsider->id])->assertForbidden();
    $this->get(route('tickets.index', ['assignee' => $outsider->id]))->assertSessionHasErrors('assignee');
    expect($ticket->fresh()->version)->toBe(1);
});

test('demo ownership is server controlled on ticket creation and an outsider cannot be assigned', function () {
    config(['app.demo_mode' => true]);
    app(ResetDemo::class)->execute();
    $outsider = User::factory()->create();
    $this->post(route('demo.login'), ['role' => 'administrator']);
    $this->postJson(route('tickets.store'), [...ticketCoreInput(), 'is_demo' => false])->assertUnprocessable()->assertJsonValidationErrors('is_demo');
    $this->post(route('tickets.store'), ticketCoreInput())->assertRedirect();
    $ticket = Ticket::orderByDesc('id')->firstOrFail();
    expect($ticket->is_demo)->toBeTrue();
    $this->patch(route('tickets.assign', $ticket), ['version' => 1, 'assigned_to' => $outsider->id])->assertForbidden();
});

test('business actions reject a pre-reset demo session even when the restored ticket version matches', function (bool $create) {
    config(['app.demo_mode' => true]);
    app(ResetDemo::class)->execute();
    $this->post(route('demo.login'), ['role' => 'administrator'])->assertRedirect(route('dashboard'));
    $actor = auth()->user();
    $ticket = Ticket::findOrFail(1);
    app(ResetDemo::class)->execute();
    expect(request()->hasSession())->toBeTrue();
    expect($ticket->fresh()->version)->toBe($ticket->version);
    $before = DB::table('tickets')->orderBy('id')->get()->toJson();

    expect(fn () => $create
        ? app(CreateTicket::class)->execute($actor, ticketCoreInput())
        : app(UpdateTicket::class)->execute($actor, $ticket, [...ticketCoreInput(), 'title' => 'Modification devenue obsolète', 'version' => $ticket->version])
    )->toThrow(AuthenticationException::class);

    expect(DB::table('tickets')->orderBy('id')->get()->toJson())->toBe($before);
    $this->assertDatabaseCount('ticket_activities', 60);
    $this->assertGuest();
})->with([false, true]);
