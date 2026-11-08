<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repair_requests', function (Blueprint $table) {
            $table->id();
            // Correlative form number shown on the printed notice.
            $table->unsignedInteger('number')->unique();
            $table->string('status', 30)->default('reportado')->index();

            // 1. Driver notice.
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            // Snapshot of the unit description at report time, so history survives unit edits.
            $table->string('unit_label', 200);
            $table->foreignId('driver_id')->constrained()->restrictOnDelete();
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->string('problem_kind', 20);
            $table->text('problem_description');
            $table->date('detected_on');
            $table->string('signed_form_path')->nullable();

            // 2. Technical/administrative report.
            $table->text('inspection_notes')->nullable();
            $table->string('urgency', 10)->nullable();
            $table->text('estimated_parts_or_service')->nullable();
            $table->decimal('estimated_cost', 12, 2)->nullable();
            $table->foreignId('assessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assessed_at')->nullable();

            // 3. Superior decision.
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_notes')->nullable();

            // 4. Follow-up.
            $table->string('provider', 150)->nullable();
            $table->text('work_performed')->nullable();
            $table->decimal('final_cost', 12, 2)->nullable();
            $table->date('completed_on')->nullable();

            $table->timestamps();
        });

        Schema::create('repair_request_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repair_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repair_request_events');
        Schema::dropIfExists('repair_requests');
    }
};
