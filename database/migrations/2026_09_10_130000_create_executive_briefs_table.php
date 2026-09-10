<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('executive_briefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('brief_type');
            $table->string('origin')->default('scheduled');
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->date('generated_for');
            $table->string('timezone');
            $table->string('locale', 8);
            $table->string('status');
            $table->unsignedSmallInteger('priority_score')->nullable();
            $table->string('summary')->nullable();
            $table->json('sections_json')->nullable();
            $table->json('source_snapshot_json')->nullable();
            $table->unsignedBigInteger('automation_run_id')->nullable();
            $table->unsignedBigInteger('regenerated_from_id')->nullable();
            $table->string('run_key');
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('delivery_status')->nullable();
            $table->string('safe_error')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'run_key']);
            $table->index(['user_id', 'brief_type', 'generated_for']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('executive_briefs');
    }
};
