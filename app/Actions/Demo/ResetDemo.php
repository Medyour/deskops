<?php

namespace App\Actions\Demo;

use App\Enums\DemoIdentity;
use App\Models\User;
use Database\Seeders\DemoTicketSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class ResetDemo
{
    public function __construct(private DemoMaintenance $maintenance) {}

    public function execute(): void
    {
        if (! config('app.demo_mode')) {
            throw new LogicException('Le mode démonstration est désactivé.');
        }
        $this->maintenance->run(function (): void {
            $this->ensureAccounts();
            $fixture = DB::table('demo_state')->where('id', 1)->value('fixture');
            if ($fixture === null) {
                if (DB::table('tickets')->where('is_demo', true)->exists()) {
                    throw new LogicException('La fixture initiale de démonstration est absente. Aucune donnée ne sera supprimée.');
                }
                app(DemoTicketSeeder::class)->initializePublicFixture();
                $this->captureFixture();
            } else {
                $snapshot = json_decode($fixture, true, flags: JSON_THROW_ON_ERROR);
                $this->assertOwnership($snapshot);
                $ids = DB::table('tickets')->where('is_demo', true)->select('id');
                DB::table('comments')->whereIn('ticket_id', $ids)->delete();
                DB::table('ticket_activities')->whereIn('ticket_id', $ids)->delete();
                DB::table('tickets')->where('is_demo', true)->delete();
                foreach (['tickets', 'comments', 'ticket_activities'] as $table) {
                    foreach ($snapshot[$table] as $record) {
                        DB::table($table)->insert($record);
                    }
                }
            }
            $users = User::query()->whereNotNull('demo_identity')->get();
            DB::table('sessions')->whereIn('user_id', $users->modelKeys())->delete();
            DB::table('password_reset_tokens')->whereIn('email', $users->pluck('email'))->delete();
            DB::table('users')->whereIn('id', $users->modelKeys())->update(['remember_token' => null]);
            DB::table('demo_state')->where('id', 1)->increment('generation');
        });
    }

    private function ensureAccounts(): void
    {
        foreach (DemoIdentity::cases() as $identity) {
            $user = User::query()->where('demo_identity', $identity)->first();
            if ($user !== null) {
                if ($user->email !== $identity->email() || $user->role !== $identity->role()) {
                    throw new LogicException('Une identité de démonstration est incompatible.');
                }

                DB::table('users')->where('id', $user->id)->where('demo_identity', $identity->value)->update([
                    'name' => $identity->name(), 'email_verified_at' => '2026-01-15 09:00:00',
                    'created_at' => '2026-01-15 09:00:00', 'updated_at' => '2026-01-15 09:00:00',
                ]);

                continue;
            }
            if (User::query()->where('email', $identity->email())->exists()) {
                throw new LogicException('Une adresse réservée est utilisée par un compte non-démo. Aucune adoption automatique.');
            }
            $user = new User;
            $user->timestamps = false;
            $user->forceFill([
                'demo_identity' => $identity, 'name' => $identity->name(), 'email' => $identity->email(),
                'role' => $identity->role(), 'password' => Str::password(32),
                'email_verified_at' => '2026-01-15 09:00:00', 'created_at' => '2026-01-15 09:00:00', 'updated_at' => '2026-01-15 09:00:00',
            ])->save();
        }
    }

    public function captureFixture(): void
    {
        $snapshot = [];
        $ids = DB::table('tickets')->where('is_demo', true)->orderBy('id')->pluck('id');
        if ($ids->count() !== 12) {
            throw new LogicException('La fixture de démonstration doit contenir exactement 12 tickets.');
        }
        $snapshot['tickets'] = DB::table('tickets')->whereIn('id', $ids)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        foreach (['comments', 'ticket_activities'] as $table) {
            $snapshot[$table] = DB::table($table)->whereIn('ticket_id', $ids)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }
        if (count($snapshot['comments']) !== 24 || count($snapshot['ticket_activities']) !== 60) {
            throw new LogicException('La fixture de démonstration est incomplète.');
        }
        DB::table('demo_state')->where('id', 1)->whereNull('fixture')->update(['fixture' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
    }

    /** @param array{tickets: list<array<string, mixed>>, comments: list<array<string, mixed>>, ticket_activities: list<array<string, mixed>>} $snapshot */
    private function assertOwnership(array $snapshot): void
    {
        $demoUsers = User::query()->whereNotNull('demo_identity')->pluck('id')->all();
        if (DB::table('tickets')->where('is_demo', true)->whereNotNull('assigned_to')->whereNotIn('assigned_to', $demoUsers)->exists()) {
            throw new LogicException('Un ticket démo est assigné à un compte non-démo. La réinitialisation est refusée.');
        }
        $fixtureTickets = array_column($snapshot['tickets'], 'id');
        foreach ($snapshot['tickets'] as $record) {
            if (! $record['is_demo'] || ! in_array($record['created_by'], $demoUsers, true)
                || ($record['assigned_to'] !== null && ! in_array($record['assigned_to'], $demoUsers, true))
                || DB::table('tickets')->where('id', $record['id'])->where('is_demo', false)->exists()) {
                throw new LogicException('La fixture ne peut pas remplacer un ticket non-démo.');
            }
        }
        foreach (['comments' => 'author_id', 'ticket_activities' => 'actor_id'] as $table => $actor) {
            foreach ($snapshot[$table] as $record) {
                if (! in_array($record['ticket_id'], $fixtureTickets, true) || ! in_array($record[$actor], $demoUsers, true)
                    || DB::table($table)->where('id', $record['id'])->whereNotIn('ticket_id', $fixtureTickets)->exists()) {
                    throw new LogicException('La fixture contient une référence étrangère à la démonstration.');
                }
            }
            if (DB::table($table)->whereIn('ticket_id', DB::table('tickets')->where('is_demo', true)->select('id'))->whereNotIn($actor, $demoUsers)->exists()) {
                throw new LogicException('Un ticket démo contient une contribution non-démo. La réinitialisation est refusée.');
            }
        }
    }
}
