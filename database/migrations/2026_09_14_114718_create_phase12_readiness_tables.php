<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_map_progresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('current_step', 32)->default('owner_profile');
            $table->json('steps_json');
            $table->boolean('banner_dismissed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('validation_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('marker', 64)->unique();
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('validation_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('validation_batch_id')->constrained('validation_batches')->cascadeOnDelete();
            $table->string('entity_type', 64);
            $table->unsignedBigInteger('entity_id');
            $table->timestamps();
            $table->unique(['validation_batch_id', 'entity_type', 'entity_id'], 'validation_batch_items_unique');
        });

        Schema::create('handover_cleanup_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operation_id', 64)->unique();
            $table->json('selectors_json');
            $table->boolean('dry_run')->default(true);
            $table->boolean('executed')->default(false);
            $table->json('plan_json');
            $table->json('result_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('handover_cleanup_reports');
        Schema::dropIfExists('validation_batch_items');
        Schema::dropIfExists('validation_batches');
        Schema::dropIfExists('business_map_progresses');
    }
};
