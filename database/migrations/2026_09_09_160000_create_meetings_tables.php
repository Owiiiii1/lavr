<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('current_analysis_id')->nullable();
            $table->string('title');
            $table->string('meeting_type')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('timezone')->nullable();
            $table->string('location')->nullable();
            $table->string('source_type');
            $table->string('source_external_id')->nullable();
            $table->string('status');
            $table->string('analysis_status');
            $table->string('source_language')->nullable();
            $table->text('summary')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'started_at']);
            $table->index(['user_id', 'analysis_status']);
            $table->index(['user_id', 'status']);
            $table->index(['project_id']);
            $table->index(['source_type', 'source_external_id']);
        });

        Schema::create('meeting_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->string('display_name');
            $table->string('email')->nullable();
            $table->string('role')->nullable();
            $table->string('attendance_status')->nullable();
            $table->string('speaker_key')->nullable();
            $table->string('source_identifier')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['meeting_id', 'person_id']);
            $table->index(['meeting_id', 'speaker_key']);
        });

        Schema::create('meeting_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->string('kind');
            $table->string('original_filename')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('extension')->nullable();
            $table->string('checksum_sha256', 64);
            $table->unsignedBigInteger('byte_size')->default(0);
            $table->string('disk');
            $table->string('storage_path');
            $table->longText('original_text')->nullable();
            $table->longText('normalized_text')->nullable();
            $table->string('source_language')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['meeting_id', 'checksum_sha256']);
        });

        Schema::create('meeting_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->string('status');
            $table->json('result_json')->nullable();
            $table->text('summary')->nullable();
            $table->string('error_class')->nullable();
            $table->string('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['meeting_id', 'version']);
        });

        Schema::table('meetings', function (Blueprint $table) {
            $table->foreign('current_analysis_id')
                ->references('id')
                ->on('meeting_analyses')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropForeign(['current_analysis_id']);
        });
        Schema::dropIfExists('meeting_analyses');
        Schema::dropIfExists('meeting_artifacts');
        Schema::dropIfExists('meeting_participants');
        Schema::dropIfExists('meetings');
    }
};
