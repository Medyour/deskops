<?php

namespace Database\Seeders;

use App\Enums\DemoIdentity;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use LogicException;

class DemoUserSeeder extends Seeder
{
    /** @var list<array{name: string, email: string, role: UserRole}> */
    public const ACCOUNTS = [
        ['name' => 'Camille Martin', 'email' => 'admin@deskops.example', 'role' => UserRole::Admin],
        ['name' => 'Alex Bernard', 'email' => 'alex@deskops.example', 'role' => UserRole::Agent],
        ['name' => 'Sam Dubois', 'email' => 'sam@deskops.example', 'role' => UserRole::Agent],
    ];

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new LogicException('Les comptes de démonstration sont réservés à local et testing.');
        }

        foreach (self::ACCOUNTS as $account) {
            $user = User::firstOrNew(['email' => $account['email']]);
            $user->timestamps = false;
            $user->forceFill([
                ...$account,
                'email_verified_at' => '2026-01-15 09:00:00',
                'created_at' => '2026-01-15 09:00:00',
                'updated_at' => '2026-01-15 09:00:00',
            ]);

            if (! $user->exists) {
                $user->password = Str::password(24);
                $user->demo_identity = DemoIdentity::from($account['role'] === UserRole::Admin ? 'administrator' : explode('@', $account['email'])[0]);
            }

            $user->save();
        }
    }
}
