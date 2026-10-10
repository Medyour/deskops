<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PrepareDemoAccounts extends Command
{
    protected $signature = 'deskops:demo';

    protected $description = 'Préparer les trois comptes locaux et renouveler leurs mots de passe';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Cette commande est réservée à l’environnement local.');

            return self::FAILURE;
        }

        $credentials = DB::transaction(function (): array {
            app(DemoUserSeeder::class)->run();
            $credentials = [];

            foreach (DemoUserSeeder::ACCOUNTS as $account) {
                $password = Str::password(24, symbols: false);
                $user = User::where('email', $account['email'])->firstOrFail();
                $user->timestamps = false;
                $user->forceFill(['password' => $password, 'remember_token' => null])->save();
                $credentials[] = [$account['email'], $account['role']->label(), $password];

                DB::table('sessions')->where('user_id', $user->id)->delete();
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            }

            return $credentials;
        });

        $this->info('Comptes de démonstration prêts. Les anciennes sessions ont été supprimées.');
        $this->table(['Adresse e-mail', 'Rôle', 'Nouveau mot de passe'], $credentials);
        $this->line('Mots de passe affichés uniquement ici ; aucun fichier de secrets n’est créé.');

        return self::SUCCESS;
    }
}
