<?php

namespace App\Console\Commands;

use App\Actions\Demo\DemoMaintenance;
use App\Models\User;
use Database\Seeders\DemoTicketSeeder;
use Database\Seeders\DemoUserSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

class PrepareDemoAccounts extends Command
{
    protected $signature = 'deskops:demo';

    protected $description = 'Préparer la fixture locale sans publier ni renouveler les accès';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Cette commande est réservée à l’environnement local.');

            return self::FAILURE;
        }

        try {
            if (config('app.demo_mode')) {
                $this->error('Utilisez deskops:demo-reset en mode démonstration public. Aucun mot de passe ne sera affiché.');

                return self::FAILURE;
            }
            app(DemoMaintenance::class)->run(function (): void {
                app(DemoUserSeeder::class)->run();
                app(DemoTicketSeeder::class)->run();

                foreach (DemoUserSeeder::ACCOUNTS as $account) {
                    $user = User::where('email', $account['email'])->firstOrFail();
                    DB::table('users')->where('id', $user->id)->update(['remember_token' => null]);

                    DB::table('sessions')->where('user_id', $user->id)->delete();
                    DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                }
            });
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Préparation annulée sans modification. Aucun détail de connexion ou secret ne sera affiché.');

            return self::FAILURE;
        }

        $this->info('Démonstration prête. Les anciennes sessions des comptes de démonstration ont été supprimées.');
        $this->line('Aucun accès affiché ou renouvelé. Pour explorer les rôles, utilisez le mode démonstration sur une base dédiée.');

        return self::SUCCESS;
    }
}
