<?php

use App\Enums\TicketActivityType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->enum('type', array_column(TicketActivityType::cases(), 'value'));
            $table->json('changes');
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at');
            $table->index(['ticket_id', 'created_at', 'id']);
            $table->index(['created_at', 'id']);
            $table->index('actor_id');
        });
        DB::statement("CREATE UNIQUE INDEX ticket_single_creation ON ticket_activities (ticket_id) WHERE type = 'ticket_created'");
        if (DB::getDriverName() === 'pgsql') {
            $this->createPostgresProtections();

            return;
        }
        DB::statement(<<<'SQL'
            CREATE TRIGGER ticket_activities_validate BEFORE INSERT ON ticket_activities
            WHEN CASE WHEN json_valid(NEW.changes) THEN json_type(NEW.changes) <> 'object' ELSE 1 END
                OR length(NEW.reason) > 500
            BEGIN SELECT RAISE(ABORT, 'Invalid activity metadata'); END
            SQL);
        foreach (['UPDATE', 'DELETE'] as $operation) {
            $name = 'ticket_activities_no_'.strtolower($operation);
            DB::statement("CREATE TRIGGER {$name} BEFORE {$operation} ON ticket_activities BEGIN SELECT RAISE(ABORT, 'Ticket activities are append-only'); END");
        }
        DB::statement(<<<'SQL'
            CREATE TRIGGER ticket_activities_no_replace BEFORE INSERT ON ticket_activities
            WHEN EXISTS (SELECT 1 FROM ticket_activities WHERE id = NEW.id)
                OR (NEW.type = 'ticket_created' AND EXISTS (
                    SELECT 1 FROM ticket_activities WHERE ticket_id = NEW.ticket_id AND type = 'ticket_created'
                ))
            BEGIN SELECT RAISE(ABORT, 'Ticket activities cannot be replaced'); END
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_activities');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS deskops_activity_insert()');
            DB::statement('DROP FUNCTION IF EXISTS deskops_activity_immutable()');
        }
    }

    private function createPostgresProtections(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE ticket_activities ADD CONSTRAINT activities_valid_metadata CHECK (
                json_typeof(changes) = 'object' AND (reason IS NULL OR length(reason) <= 500)
            )
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION deskops_activity_insert() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM ticket_activities WHERE id = NEW.id)
                    OR (NEW.type = 'ticket_created' AND EXISTS (
                        SELECT 1 FROM ticket_activities WHERE ticket_id = NEW.ticket_id AND type = 'ticket_created'
                    )) THEN
                    RAISE EXCEPTION 'Ticket activities cannot be replaced' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER ticket_activities_no_replace BEFORE INSERT ON ticket_activities
                FOR EACH ROW EXECUTE FUNCTION deskops_activity_insert();
            CREATE OR REPLACE FUNCTION deskops_activity_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Ticket activities are append-only' USING ERRCODE = '23514';
            END;
            $$;
            CREATE TRIGGER ticket_activities_no_update_delete BEFORE UPDATE OR DELETE ON ticket_activities
                FOR EACH ROW EXECUTE FUNCTION deskops_activity_immutable();
            CREATE TRIGGER ticket_activities_no_truncate BEFORE TRUNCATE ON ticket_activities
                FOR EACH STATEMENT EXECUTE FUNCTION deskops_activity_immutable();
            SQL);
    }
};
