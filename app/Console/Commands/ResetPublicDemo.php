<?php

namespace App\Console\Commands;

use App\Actions\Demo\ResetDemo;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

class ResetPublicDemo extends Command
{
    protected $signature = 'deskops:demo-reset {--force : Autoriser explicitement la réinitialisation en production} {--exclusive : Refuser toute base contenant des comptes ou tickets non-démo}';

    protected $description = 'Restaurer exclusivement la fixture identifiée de la démonstration publique';

    public function handle(ResetDemo $reset): int
    {
        if (! config('app.demo_mode')) {
            $this->error('Le mode démonstration est désactivé.');

            return self::FAILURE;
        }
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('La réinitialisation en production exige --force.');

            return self::FAILURE;
        }
        try {
            $reset->execute(exclusive: (bool) $this->option('exclusive'));
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Réinitialisation annulée sans modification. Aucun détail de connexion ou secret ne sera affiché.');

            return self::FAILURE;
        }
        $this->info('Démonstration restaurée : 12 tickets, 24 commentaires, 60 activités. Aucun accès généré ou affiché.');

        return self::SUCCESS;
    }
}
