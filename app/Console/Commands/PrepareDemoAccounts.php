<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\DemoTicketSeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class PrepareDemoAccounts extends Command
{
    protected $signature = 'deskops:demo';

    protected $description = 'Préparer la fixture locale de tickets et renouveler les accès de démonstration';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Cette commande est réservée à l’environnement local.');

            return self::FAILURE;
        }

        try {
            $credentials = DB::transaction(function (): array {
                app(DemoUserSeeder::class)->run();
                app(DemoTicketSeeder::class)->run();
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
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Démonstration prête. Les anciennes sessions des comptes de démonstration ont été supprimées.');
        $this->table(['Adresse e-mail', 'Rôle', 'Nouveau mot de passe'], $credentials);
        $this->line('Mots de passe affichés uniquement ici ; aucun fichier de secrets n’est créé.');

        return self::SUCCESS;
    }
}
