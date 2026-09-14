<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leadership_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('review_type', 32);
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->foreignId('meeting_id')->nullable()->constrained('meetings')->nullOnDelete();
            $table->string('status', 32);
            $table->text('summary')->nullable();
            $table->json('metrics_json')->nullable();
            $table->json('findings_json')->nullable();
            $table->json('source_snapshot_json')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->string('generated_by', 32)->nullable();
            $table->string('origin', 32)->default('manual');
            $table->string('run_key', 190);
            $table->unsignedBigInteger('automation_run_id')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('locale', 8)->nullable();
            $table->string('delivery_status', 32)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('safe_error', 190)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'run_key']);
            $table->index(['user_id', 'review_type', 'period_start']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leadership_reviews');
    }
};
