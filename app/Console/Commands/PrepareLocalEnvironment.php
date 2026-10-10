<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;

class PrepareLocalEnvironment extends Command
{
    protected $signature = 'deskops:prepare';

    protected $description = 'Préparer SQLite et la clé locale sans remplacer les données existantes';

    public function handle(): int
    {
        if (! app()->environment('local') || config('database.default') !== 'sqlite') {
            $this->error('La préparation exige un environnement local avec SQLite.');

            return self::FAILURE;
        }

        $database = config('database.connections.sqlite.database');

        if ($database !== ':memory:' && ! file_exists($database) && ! touch($database)) {
            throw new RuntimeException('Impossible de créer le fichier SQLite configuré.');
        }

        if (blank(config('app.key'))) {
            return $this->call('key:generate', ['--no-interaction' => true]);
        }

        $this->info('SQLite prêt ; clé applicative existante conservée.');

        return self::SUCCESS;
    }
}
