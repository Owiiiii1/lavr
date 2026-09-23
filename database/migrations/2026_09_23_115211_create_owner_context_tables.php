<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_context_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('source_type', 32);
            $table->date('source_date')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('storage_path')->nullable();
            $table->string('status', 32);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('owner_context_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->nullable()->constrained('owner_context_sources')->nullOnDelete();
            $table->string('category', 64);
            $table->string('scope_type', 32);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('fact_class', 32);
            $table->text('value');
            $table->json('structured_value')->nullable();
            $table->string('status', 32);
            $table->string('sensitivity', 32);
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('owner_context_items')->nullOnDelete();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->string('evidence_excerpt', 280)->nullable();
            $table->json('source_reference')->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'category']);
            $table->index(['user_id', 'fact_class']);
            $table->index(['user_id', 'fingerprint']);
            $table->index(['user_id', 'scope_type', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_context_items');
        Schema::dropIfExists('owner_context_sources');
    }
};
