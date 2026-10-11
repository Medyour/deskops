<?php

use App\Actions\Demo\ResetDemo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('demo ownership migration rolls back populated data restores immutability and reapplies cleanly', function () {
    $cleanup = $this->isolateDatabase(['demo_migrations']);
    config(['app.demo_mode' => true]);
    try {
        expect(Artisan::call('migrate', ['--database' => 'demo_migrations', '--no-interaction' => true]))->toBe(0);
        app(ResetDemo::class)->execute();
        expect(Artisan::call('migrate:rollback', ['--database' => 'demo_migrations', '--step' => 1, '--no-interaction' => true]))->toBe(0);
        expect(Schema::hasColumn('users', 'demo_identity'))->toBeFalse();
        expect(Schema::hasColumn('tickets', 'is_demo'))->toBeFalse();
        expect(Schema::hasTable('demo_state'))->toBeFalse();
        expect(fn () => DB::table('comments')->delete())->toThrow(QueryException::class);
        expect(Artisan::call('migrate', ['--database' => 'demo_migrations', '--no-interaction' => true]))->toBe(0);
        expect(DB::table('users')->whereNotNull('demo_identity')->count())->toBe(0);
        expect(DB::table('tickets')->where('is_demo', true)->count())->toBe(0);
        expect(Artisan::call('migrate:reset', ['--database' => 'demo_migrations', '--no-interaction' => true]))->toBe(0);
        expect($this->remainingDatabaseProtections())->toBe(['triggers' => 0, 'functions' => 0]);
    } finally {
        $cleanup();
    }
});
