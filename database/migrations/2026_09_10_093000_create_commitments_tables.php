<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('person_name_raw')->nullable();
            $table->boolean('unresolved_person')->default(false);
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('meeting_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('meeting_analysis_id')->nullable()->constrained('meeting_analyses')->nullOnDelete();
            $table->unsignedBigInteger('merged_into_id')->nullable();
            $table->string('title');
            $table->text('expected_result')->nullable();
            $table->text('description')->nullable();
            $table->string('deadline_raw')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->string('deadline_precision')->nullable();
            $table->string('status');
            $table->string('lifecycle_status');
            $table->string('confidence');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('source_reference')->nullable();
            $table->string('fingerprint', 64);
            $table->text('completion_note')->nullable();
            $table->string('last_notified_status')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamp('owner_edited_at')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'fingerprint']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'lifecycle_status']);
            $table->index(['person_id']);
            $table->index(['project_id']);
            $table->index(['meeting_id']);
            $table->index(['deadline_at']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::table('commitments', function (Blueprint $table) {
            $table->foreign('merged_into_id')
                ->references('id')
                ->on('commitments')
                ->nullOnDelete();
        });

        Schema::create('commitment_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commitment_id')->constrained('commitments')->cascadeOnDelete();
            $table->string('evidence_type');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('excerpt', 280)->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->string('confidence');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['commitment_id', 'evidence_type']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('commitment_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commitment_id')->constrained('commitments')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['commitment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commitment_status_history');
        Schema::dropIfExists('commitment_evidence');
        Schema::table('commitments', function (Blueprint $table) {
            $table->dropForeign(['merged_into_id']);
        });
        Schema::dropIfExists('commitments');
    }
};
