<?php

namespace App\Console\Commands;

use App\Actions\Demo\ProductionReadiness;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

class CheckProduction extends Command
{
    protected $signature = 'deskops:production-check {--web : Exiger une clé de chiffrement valide pour le service web} {--database : Contrôler le périmètre de la base avant toute migration} {--ready : Contrôler également les tables et la fixture initialisée}';

    protected $description = 'Vérifier la configuration de la démonstration de production sans afficher de valeur sensible';

    public function handle(ProductionReadiness $readiness): int
    {
        try {
            $readiness->check(web: (bool) $this->option('web'), database: (bool) $this->option('database'), ready: (bool) $this->option('ready'));
        } catch (LogicException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Contrôle de production impossible. Aucun détail de connexion ou secret ne sera affiché.');

            return self::FAILURE;
        }
        $this->info('Contrôle de production réussi. Aucune valeur sensible affichée.');

        return self::SUCCESS;
    }
}
