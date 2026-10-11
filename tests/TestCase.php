<?php

namespace Tests;

use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $application = parent::createApplication();
        if ($application['config']->get('database.default') === 'pgsql') {
            $database = $application['db']->connection()->getConfig('database');
            if (! $application->environment('testing') || ! str_ends_with($database, '_test')) {
                throw new LogicException('Les tests PostgreSQL exigent une base jetable dont le nom termine par _test, en environnement testing.');
            }
        }

        return $application;
    }

    protected function tearDown(): void
    {
        $this->app?->detectEnvironment(fn (): string => 'testing');
        parent::tearDown();
    }

    /** @param list<string> $names */
    protected function isolateDatabase(array $names): Closure
    {
        $default = DB::getDefaultConnection();
        $postgres = DB::getDriverName() === 'pgsql';
        $schema = 'deskops_test_'.bin2hex(random_bytes(8));
        $path = $postgres ? null : tempnam(sys_get_temp_dir(), 'deskops-database-');
        if ($postgres) {
            DB::statement('CREATE SCHEMA "'.$schema.'"');
        }
        $configuration = $postgres
            ? [...DB::connection()->getConfig(), 'url' => null, 'search_path' => $schema]
            : [...config('database.connections.sqlite'), 'database' => $path, 'url' => null, 'busy_timeout' => 1];
        foreach ($names as $name) {
            config(['database.connections.'.$name => $configuration]);
        }
        DB::setDefaultConnection($names[0]);

        return function () use ($default, $names, $postgres, $schema, $path): void {
            DB::setDefaultConnection($default);
            foreach ($names as $name) {
                DB::purge($name);
            }
            if ($postgres) {
                DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
            } else {
                unlink($path);
            }
        };
    }

    /** @return array{triggers: int, functions: int} */
    protected function remainingDatabaseProtections(): array
    {
        if (DB::getDriverName() === 'sqlite') {
            return ['triggers' => DB::table('sqlite_master')->where('type', 'trigger')->count(), 'functions' => 0];
        }

        return [
            'triggers' => DB::table('pg_trigger as t')->join('pg_class as c', 'c.oid', '=', 't.tgrelid')->join('pg_namespace as n', 'n.oid', '=', 'c.relnamespace')
                ->whereRaw('n.nspname = current_schema()')->where('t.tgisinternal', false)->count(),
            'functions' => DB::table('pg_proc as p')->join('pg_namespace as n', 'n.oid', '=', 'p.pronamespace')
                ->whereRaw('n.nspname = current_schema()')->where('p.proname', 'like', 'deskops_%')->count(),
        ];
    }

    protected function preventActivityWrites(bool $priorityOnly = false): Closure
    {
        $condition = $priorityOnly ? "NEW.type = 'priority_changed'" : 'TRUE';
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION test_unavailable_history() RETURNS trigger LANGUAGE plpgsql AS $$
                BEGIN
                    RAISE EXCEPTION 'Test history write failed' USING ERRCODE = '23514';
                END;
                $$;
                SQL);
            DB::statement("CREATE TRIGGER test_unavailable_history BEFORE INSERT ON ticket_activities FOR EACH ROW WHEN ({$condition}) EXECUTE FUNCTION test_unavailable_history()");
        } else {
            DB::statement("CREATE TRIGGER test_unavailable_history BEFORE INSERT ON ticket_activities WHEN {$condition} BEGIN SELECT RAISE(ABORT, 'Test history write failed'); END");
        }

        return function (): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('DROP TRIGGER test_unavailable_history ON ticket_activities');
                DB::statement('DROP FUNCTION test_unavailable_history()');
            } else {
                DB::statement('DROP TRIGGER test_unavailable_history');
            }
        };
    }
}
