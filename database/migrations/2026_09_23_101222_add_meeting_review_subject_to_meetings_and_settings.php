<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table): void {
            $table->foreignId('review_subject_person_id')->nullable()->after('organization_id')->constrained('people')->nullOnDelete();
            $table->string('leadership_review_status', 40)->nullable()->after('analysis_status');
        });

        Schema::table('user_productivity_settings', function (Blueprint $table): void {
            $table->foreignId('default_review_person_id')->nullable()->after('user_id')->constrained('people')->nullOnDelete();
            $table->boolean('auto_generate_leadership_review')->default(true)->after('default_review_person_id');
        });
    }

    public function down(): void
    {
        Schema::table('user_productivity_settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('default_review_person_id');
            $table->dropColumn('auto_generate_leadership_review');
        });

        Schema::table('meetings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('review_subject_person_id');
            $table->dropColumn('leadership_review_status');
        });
    }
};
