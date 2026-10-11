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
    }
};
