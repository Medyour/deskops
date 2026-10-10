<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('guests cannot access team pages', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['dashboard', 'admin']);

test('an agent can access the dashboard but cannot access administration', function () {
    $this->actingAs(User::factory()->create());
    $this->get(route('dashboard'))->assertOk()->assertSee('Agent')
        ->assertDontSee('Administration');
    $this->get(route('admin'))->assertForbidden();
});

test('an administrator can access administration', function () {
    $this->actingAs(User::factory()->administrator()->create());
    $this->get(route('dashboard'))->assertOk()->assertSee('Administration');
    $this->get(route('admin'))->assertOk()->assertSee('Accès administrateur');
});

test('role escalation through mass assignment is ignored', function () {
    $attributes = User::factory()->raw();
    unset($attributes['role']);

    $user = User::create([...$attributes, 'role' => 'admin'])->fresh();

    expect($user->role)->toBe(UserRole::Agent);
    expect($user->isAdministrator())->toBeFalse();
});

test('the database rejects unknown roles', function () {
    $user = User::factory()->create();

    expect(fn () => DB::table('users')->where('id', $user->id)->update(['role' => 'owner']))
        ->toThrow(QueryException::class);
});

test('user supplied names are escaped in the dashboard', function () {
    $this->actingAs(User::factory()->create(['name' => '<script>alert(1)</script>']));
    $this->get(route('dashboard'))->assertOk()
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false);
});
