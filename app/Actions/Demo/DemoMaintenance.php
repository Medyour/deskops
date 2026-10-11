<?php

namespace App\Actions\Demo;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;

class DemoMaintenance
{
    public const LOCK = 'deskops-demo-maintenance';

    public function run(Closure $operation): mixed
    {
        $lock = Cache::store('database')->lock(self::LOCK, 300);
        if (! $lock->get()) {
            throw new LogicException('Une opération de démonstration est déjà en cours.');
        }

        try {
            return DB::transaction(function () use ($operation): mixed {
                DB::table('demo_state')->where('id', 1)->update(['active' => true]);
                try {
                    return $operation();
                } finally {
                    DB::table('demo_state')->where('id', 1)->update(['active' => false]);
                }
            });
        } finally {
            $lock->release();
        }
    }

    public function active(): bool
    {
        return DB::transactionLevel() > 0 && (bool) DB::table('demo_state')->where('id', 1)->value('active');
    }
}
