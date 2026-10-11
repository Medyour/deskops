<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestamp('created_at');
            $table->index(['ticket_id', 'created_at', 'id']);
            $table->index('author_id');
        });
        if (DB::getDriverName() === 'pgsql') {
            $this->createPostgresProtections();

            return;
        }
        DB::statement(<<<'SQL'
            CREATE TRIGGER comments_validate BEFORE INSERT ON comments
            WHEN length(trim(NEW.body)) NOT BETWEEN 1 AND 5000
            BEGIN SELECT RAISE(ABORT, 'Invalid comment body'); END
            SQL);
        foreach (['UPDATE', 'DELETE'] as $operation) {
            $name = 'comments_no_'.strtolower($operation);
            DB::statement("CREATE TRIGGER {$name} BEFORE {$operation} ON comments BEGIN SELECT RAISE(ABORT, 'Comments are immutable'); END");
        }
        DB::statement(<<<'SQL'
            CREATE TRIGGER comments_no_replace BEFORE INSERT ON comments
            WHEN EXISTS (SELECT 1 FROM comments WHERE id = NEW.id)
            BEGIN SELECT RAISE(ABORT, 'Comments cannot be replaced'); END
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS deskops_comment_insert()');
            DB::statement('DROP FUNCTION IF EXISTS deskops_comment_immutable()');
        }
    }

    private function createPostgresProtections(): void
    {
        DB::statement('ALTER TABLE comments ADD CONSTRAINT comments_valid_body CHECK (length(trim(body)) BETWEEN 1 AND 5000)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION deskops_comment_insert() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM comments WHERE id = NEW.id) THEN
                    RAISE EXCEPTION 'Comments cannot be replaced' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER comments_no_replace BEFORE INSERT ON comments
                FOR EACH ROW EXECUTE FUNCTION deskops_comment_insert();
            CREATE OR REPLACE FUNCTION deskops_comment_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Comments are immutable' USING ERRCODE = '23514';
            END;
            $$;
            CREATE TRIGGER comments_no_update_delete BEFORE UPDATE OR DELETE ON comments
                FOR EACH ROW EXECUTE FUNCTION deskops_comment_immutable();
            CREATE TRIGGER comments_no_truncate BEFORE TRUNCATE ON comments
                FOR EACH STATEMENT EXECUTE FUNCTION deskops_comment_immutable();
            SQL);
    }
};
