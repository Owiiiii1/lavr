<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->timestamp('occurred_at');
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_external_id', 190)->nullable();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('meeting_id')->nullable()->constrained('meetings')->nullOnDelete();
            $table->foreignId('commitment_id')->nullable()->constrained('commitments')->nullOnDelete();
            $table->string('severity', 16);
            $table->string('fingerprint', 64);
            $table->json('payload_json')->nullable();
            $table->string('status', 24)->default('observed');
            $table->string('confidence', 16)->nullable();
            $table->string('evidence_pointer', 190)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'fingerprint']);
            $table->index(['user_id', 'status', 'severity']);
            $table->index(['user_id', 'event_type']);
        });

        Schema::create('proactive_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('operational_event_id')->nullable()->constrained('operational_events')->nullOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('commitment_id')->nullable()->constrained('commitments')->nullOnDelete();
            $table->foreignId('meeting_id')->nullable()->constrained('meetings')->nullOnDelete();
            $table->string('proposal_type', 64);
            $table->string('title');
            $table->text('rationale');
            $table->json('action_payload_json')->nullable();
            $table->string('status', 24)->default('pending');
            $table->boolean('requires_confirmation')->default(true);
            $table->string('policy_level', 16)->default('suggest');
            $table->string('fingerprint', 64);
            $table->string('severity', 16)->default('normal');
            $table->string('dismiss_reason', 64)->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'fingerprint']);
            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'person_id']);
            $table->index(['user_id', 'project_id']);
            $table->index(['user_id', 'commitment_id']);
        });

        Schema::create('proactive_proposal_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proactive_proposal_id')->constrained('proactive_proposals')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action', 32);
            $table->string('outcome', 32)->nullable();
            $table->string('target_canonical_type', 32)->nullable();
            $table->unsignedBigInteger('target_canonical_id')->nullable();
            $table->string('external_reference', 190)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proactive_proposal_audits');
        Schema::dropIfExists('proactive_proposals');
        Schema::dropIfExists('operational_events');
    }
};
