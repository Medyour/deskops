<?php

use App\Enums\DemoIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->enum('demo_identity', array_column(DemoIdentity::cases(), 'value'))->nullable()->unique();
        });
        Schema::table('tickets', function (Blueprint $table): void {
            $table->boolean('is_demo')->default(false);
            $table->index(['is_demo', 'created_at', 'id']);
        });
        Schema::create('demo_state', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->boolean('active')->default(false);
            $table->unsignedBigInteger('generation')->default(0);
            $table->json('fixture')->nullable();
        });
        DB::table('demo_state')->insert(['id' => 1, 'active' => false, 'generation' => 0]);
        if (DB::getDriverName() === 'pgsql') {
            $this->postgresGuards();
        } else {
            $this->sqliteGuards();
        }
        $this->historyDeleteGuards(true);
    }

    public function down(): void
    {
        $this->historyDeleteGuards(false);
        if (DB::getDriverName() === 'pgsql') {
            foreach (['users_demo_guard' => 'users', 'users_demo_truncate' => 'users', 'tickets_demo_guard' => 'tickets', 'demo_fixture_immutable' => 'demo_state'] as $trigger => $table) {
                DB::statement("DROP TRIGGER IF EXISTS {$trigger} ON {$table}");
            }
            foreach (['deskops_demo_user', 'deskops_demo_user_truncate', 'deskops_demo_ticket', 'deskops_demo_fixture'] as $function) {
                DB::statement("DROP FUNCTION IF EXISTS {$function}()");
            }
        } else {
            foreach (['users_demo_insert', 'users_demo_update', 'users_demo_delete', 'tickets_demo_insert', 'tickets_demo_update', 'tickets_demo_delete', 'demo_fixture_immutable'] as $trigger) {
                DB::statement("DROP TRIGGER IF EXISTS {$trigger}");
            }
        }
        Schema::dropIfExists('demo_state');
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex(['is_demo', 'created_at', 'id']);
            $table->dropColumn('is_demo');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['demo_identity']);
            $table->dropColumn('demo_identity');
        });
    }

    private function sqliteGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER users_demo_insert BEFORE INSERT ON users
            WHEN (NEW.demo_identity IS NOT NULL AND NOT (
                (NEW.demo_identity = 'administrator' AND NEW.role = 'admin' AND NEW.email = 'admin@deskops.example')
                OR (NEW.demo_identity = 'alex' AND NEW.role = 'agent' AND NEW.email = 'alex@deskops.example')
                OR (NEW.demo_identity = 'sam' AND NEW.role = 'agent' AND NEW.email = 'sam@deskops.example')
            )) OR EXISTS (SELECT 1 FROM users WHERE demo_identity IS NOT NULL AND
                (id = NEW.id OR email = NEW.email OR demo_identity = NEW.demo_identity))
            BEGIN SELECT RAISE(ABORT, 'Demo accounts cannot be replaced or forged'); END;
            CREATE TRIGGER users_demo_update BEFORE UPDATE ON users
            WHEN NEW.demo_identity IS NOT OLD.demo_identity OR
                (OLD.demo_identity IS NOT NULL AND (NEW.role <> OLD.role OR NEW.email <> OLD.email
                OR (NEW.password <> OLD.password AND NOT EXISTS (SELECT 1 FROM demo_state WHERE id = 1 AND active = 1))))
            BEGIN SELECT RAISE(ABORT, 'Demo account identity and credentials are protected'); END;
            CREATE TRIGGER users_demo_delete BEFORE DELETE ON users WHEN OLD.demo_identity IS NOT NULL
            BEGIN SELECT RAISE(ABORT, 'Demo accounts cannot be deleted'); END;
            CREATE TRIGGER tickets_demo_insert BEFORE INSERT ON tickets
            WHEN NEW.is_demo NOT IN (0, 1) OR NEW.is_demo <> EXISTS (SELECT 1 FROM users WHERE id = NEW.created_by AND demo_identity IS NOT NULL)
            BEGIN SELECT RAISE(ABORT, 'Invalid demo ticket ownership'); END;
            CREATE TRIGGER tickets_demo_update BEFORE UPDATE ON tickets
            WHEN NEW.is_demo <> OLD.is_demo OR NEW.created_by <> OLD.created_by
            BEGIN SELECT RAISE(ABORT, 'Ticket ownership cannot be changed'); END;
            CREATE TRIGGER tickets_demo_delete BEFORE DELETE ON tickets
            WHEN (OLD.is_demo = 1 AND NOT EXISTS (SELECT 1 FROM demo_state WHERE id = 1 AND active = 1))
                OR (OLD.is_demo = 0 AND EXISTS (SELECT 1 FROM demo_state WHERE id = 1 AND active = 1))
            BEGIN SELECT RAISE(ABORT, 'Only identified demo tickets can be reset'); END;
            CREATE TRIGGER demo_fixture_immutable BEFORE UPDATE OF fixture ON demo_state
            WHEN OLD.fixture IS NOT NULL AND NEW.fixture IS NOT OLD.fixture
            BEGIN SELECT RAISE(ABORT, 'The initial demo fixture is immutable'); END;
            SQL);
    }

    private function postgresGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION deskops_demo_user() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.demo_identity IS NOT NULL THEN
                        RAISE EXCEPTION 'Demo accounts cannot be deleted' USING ERRCODE = '23514';
                    END IF;
                    RETURN OLD;
                END IF;
                IF TG_OP = 'INSERT' THEN
                    IF (NEW.demo_identity IS NOT NULL AND NOT (
                        (NEW.demo_identity = 'administrator' AND NEW.role = 'admin' AND NEW.email = 'admin@deskops.example')
                        OR (NEW.demo_identity = 'alex' AND NEW.role = 'agent' AND NEW.email = 'alex@deskops.example')
                        OR (NEW.demo_identity = 'sam' AND NEW.role = 'agent' AND NEW.email = 'sam@deskops.example')
                    )) OR EXISTS (SELECT 1 FROM users WHERE demo_identity IS NOT NULL AND
                        (id = NEW.id OR email = NEW.email OR demo_identity = NEW.demo_identity)) THEN
                        RAISE EXCEPTION 'Demo accounts cannot be replaced or forged' USING ERRCODE = '23514';
                    END IF;
                ELSE
                    IF NEW.demo_identity IS DISTINCT FROM OLD.demo_identity OR
                        (OLD.demo_identity IS NOT NULL AND (NEW.role <> OLD.role OR NEW.email <> OLD.email
                        OR (NEW.password <> OLD.password AND NOT EXISTS (SELECT 1 FROM demo_state WHERE id = 1 AND active)))) THEN
                        RAISE EXCEPTION 'Demo account identity and credentials are protected' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER users_demo_guard BEFORE INSERT OR UPDATE OR DELETE ON users
                FOR EACH ROW EXECUTE FUNCTION deskops_demo_user();
            CREATE OR REPLACE FUNCTION deskops_demo_user_truncate() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM users WHERE demo_identity IS NOT NULL) THEN
                    RAISE EXCEPTION 'Demo accounts cannot be deleted' USING ERRCODE = '23514';
                END IF;
                RETURN NULL;
            END;
            $$;
            CREATE TRIGGER users_demo_truncate BEFORE TRUNCATE ON users
                FOR EACH STATEMENT EXECUTE FUNCTION deskops_demo_user_truncate();
            CREATE OR REPLACE FUNCTION deskops_demo_ticket() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.is_demo IS DISTINCT FROM EXISTS (SELECT 1 FROM demo_state WHERE id = 1 AND active) THEN
                        RAISE EXCEPTION 'Only identified demo tickets can be reset' USING ERRCODE = '23514';
                    END IF;
                    RETURN OLD;
                ELSIF TG_OP = 'INSERT' THEN
                    IF NEW.is_demo IS DISTINCT FROM EXISTS (SELECT 1 FROM users WHERE id = NEW.created_by AND demo_identity IS NOT NULL) THEN
                        RAISE EXCEPTION 'Invalid demo ticket ownership' USING ERRCODE = '23514';
                    END IF;
                ELSIF NEW.is_demo IS DISTINCT FROM OLD.is_demo OR NEW.created_by <> OLD.created_by THEN
                    RAISE EXCEPTION 'Ticket ownership cannot be changed' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER tickets_demo_guard BEFORE INSERT OR UPDATE OR DELETE ON tickets
                FOR EACH ROW EXECUTE FUNCTION deskops_demo_ticket();
            CREATE OR REPLACE FUNCTION deskops_demo_fixture() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.fixture IS NOT NULL AND NEW.fixture::text IS DISTINCT FROM OLD.fixture::text THEN
                    RAISE EXCEPTION 'The initial demo fixture is immutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER demo_fixture_immutable BEFORE UPDATE OF fixture ON demo_state
                FOR EACH ROW EXECUTE FUNCTION deskops_demo_fixture();
            SQL);
    }

    private function historyDeleteGuards(bool $demo): void
    {
        foreach (['comments' => ['comment', 'author_id', 'Comments are immutable'], 'ticket_activities' => ['activity', 'actor_id', 'Ticket activities are append-only']] as $table => [$name, $actor, $message]) {
            $allowed = $demo ? "EXISTS (SELECT 1 FROM demo_state WHERE id = 1 AND active) AND EXISTS (SELECT 1 FROM tickets t JOIN users u ON u.id = t.created_by JOIN users a ON a.id = OLD.{$actor} WHERE t.id = OLD.ticket_id AND t.is_demo = TRUE AND u.demo_identity IS NOT NULL AND a.demo_identity IS NOT NULL)" : 'FALSE';
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared("CREATE OR REPLACE FUNCTION deskops_{$name}_immutable() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF TG_OP = 'DELETE' THEN IF ({$allowed}) THEN RETURN OLD; END IF; END IF; RAISE EXCEPTION '{$message}' USING ERRCODE = '23514'; END; \$\$;");
            } else {
                DB::statement("DROP TRIGGER {$table}_no_delete");
                DB::statement("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} WHEN NOT ({$allowed}) BEGIN SELECT RAISE(ABORT, '{$message}'); END");
            }
        }
    }
};
