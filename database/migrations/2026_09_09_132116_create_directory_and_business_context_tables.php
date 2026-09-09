<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('display_name');
            $table->string('normalized_name');
            $table->string('primary_email')->nullable();
            $table->string('primary_phone')->nullable();
            $table->string('telegram_username')->nullable();
            $table->text('notes')->nullable();
            $table->string('preferred_language', 8)->nullable();
            $table->string('status', 32);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'normalized_name']);
            $table->index(['user_id', 'display_name']);
            $table->index(['user_id', 'primary_email']);
        });

        Schema::create('person_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('role', 32);
            $table->timestamps();

            $table->unique(['person_id', 'role']);
            $table->index('role');
        });

        Schema::create('person_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('value');
            $table->string('normalized_value');
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['type', 'normalized_value']);
            $table->index(['person_id', 'type']);
        });

        Schema::create('employee_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('position')->nullable();
            $table->string('department')->nullable();
            $table->foreignId('manager_person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('employment_status', 32);
            $table->json('responsibilities')->nullable();
            $table->json('areas_of_ownership')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('person_id');
        });

        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('normalized_name');
            $table->string('type')->nullable();
            $table->string('website')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 32);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'normalized_name']);
        });

        Schema::create('directory_relationships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id');
            $table->string('relation_type', 32);
            $table->string('object_type', 32);
            $table->unsignedBigInteger('object_id');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['user_id', 'subject_type', 'subject_id', 'relation_type', 'object_type', 'object_id'],
                'directory_rel_unique',
            );
            $table->index(['subject_type', 'subject_id'], 'directory_rel_subject_idx');
            $table->index(['object_type', 'object_id'], 'directory_rel_object_idx');
            $table->index('relation_type');
        });

        Schema::create('project_people', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('role')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'person_id']);
            $table->index('person_id');
        });

        Schema::create('project_organizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('role')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'organization_id']);
            $table->index('organization_id');
        });

        Schema::create('project_source_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id');
            $table->string('purpose')->nullable();
            $table->string('importance')->nullable();
            $table->string('monitoring_policy')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'source_type', 'source_id'], 'project_source_unique');
            $table->index(['source_type', 'source_id']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->string('category')->nullable()->after('description');
            $table->date('start_date')->nullable()->after('category');
            $table->date('end_date')->nullable()->after('start_date');
            $table->foreignId('owner_person_id')->nullable()->after('end_date')->constrained('people')->nullOnDelete();
        });

        Schema::table('knowledge_entities', function (Blueprint $table): void {
            $table->string('canonical_type', 32)->nullable()->after('project_id');
            $table->unsignedBigInteger('canonical_id')->nullable()->after('canonical_type');
            $table->index(['canonical_type', 'canonical_id']);
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_entities', function (Blueprint $table): void {
            $table->dropIndex(['canonical_type', 'canonical_id']);
            $table->dropColumn(['canonical_type', 'canonical_id']);
        });

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('owner_person_id');
            $table->dropColumn(['category', 'start_date', 'end_date']);
        });

        Schema::dropIfExists('project_source_bindings');
        Schema::dropIfExists('project_organizations');
        Schema::dropIfExists('project_people');
        Schema::dropIfExists('directory_relationships');
        Schema::dropIfExists('organizations');
        Schema::dropIfExists('employee_profiles');
        Schema::dropIfExists('person_identities');
        Schema::dropIfExists('person_roles');
        Schema::dropIfExists('people');
    }
};
