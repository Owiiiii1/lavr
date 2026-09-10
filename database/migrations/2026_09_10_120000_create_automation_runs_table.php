<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('automation_type');
            $table->unsignedBigInteger('automation_id');
            $table->string('run_key');
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('status');
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('outcome_code')->nullable();
            $table->string('delivery_status')->nullable();
            $table->string('delivery_key')->nullable();
            $table->json('metrics')->nullable();
            $table->string('safe_error')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'run_key']);
            $table->index(['automation_type', 'automation_id']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
    }
};
