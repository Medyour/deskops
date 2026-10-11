<?php

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 160);
            $table->text('description');
            $table->string('requester_name', 120);
            $table->string('requester_email', 254);
            $table->enum('status', array_column(TicketStatus::cases(), 'value'))->default('new');
            $table->enum('priority', array_column(TicketPriority::cases(), 'value'))->default('normal');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['priority', 'created_at']);
            $table->index(['assigned_to', 'status']);
            $table->index(['created_at', 'id']);
            $table->index('created_by');
        });

        $invalid = <<<'SQL'
            length(trim(NEW.title)) NOT BETWEEN 5 AND 160
            OR length(trim(NEW.description)) NOT BETWEEN 10 AND 10000
            OR length(trim(NEW.requester_name)) NOT BETWEEN 2 AND 120
            OR length(NEW.requester_email) NOT BETWEEN 3 AND 254
            OR typeof(NEW.version) <> 'integer' OR NEW.version < 1
            OR (NEW.assigned_to IS NOT NULL AND NOT EXISTS (SELECT 1 FROM users WHERE id = NEW.assigned_to AND role = 'agent'))
            OR (NEW.status <> 'new' AND NEW.assigned_to IS NULL)
            OR (NEW.status IN ('new', 'in_progress', 'waiting_customer') AND (NEW.resolved_at IS NOT NULL OR NEW.closed_at IS NOT NULL))
            OR (NEW.status = 'resolved' AND (NEW.resolved_at IS NULL OR NEW.closed_at IS NOT NULL))
            OR (NEW.status = 'closed' AND (NEW.resolved_at IS NULL OR NEW.closed_at IS NULL OR NEW.closed_at < NEW.resolved_at))
            SQL;

        foreach (['INSERT', 'UPDATE'] as $operation) {
            $name = 'tickets_validate_'.strtolower($operation);
            DB::statement("CREATE TRIGGER {$name} BEFORE {$operation} ON tickets WHEN {$invalid} BEGIN SELECT RAISE(ABORT, 'Invalid ticket invariants'); END");
        }

        DB::statement(<<<'SQL'
            CREATE TRIGGER users_preserve_assignee_role BEFORE UPDATE OF role ON users
            WHEN NEW.role <> 'agent' AND EXISTS (SELECT 1 FROM tickets WHERE assigned_to = OLD.id)
            BEGIN SELECT RAISE(ABORT, 'Assigned users must remain agents'); END
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS users_preserve_assignee_role');
        Schema::dropIfExists('tickets');
    }
};
