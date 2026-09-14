<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_productivity_settings', function (Blueprint $table) {
            $table->boolean('leadership_review_enabled')->default(true)->after('morning_brief_weekends');
            $table->unsignedTinyInteger('leadership_review_weekday')->default(1)->after('leadership_review_enabled');
            $table->string('leadership_review_local_time', 5)->default('09:00')->after('leadership_review_weekday');
            $table->boolean('leadership_review_telegram')->default(true)->after('leadership_review_local_time');
            $table->boolean('leadership_review_inbox')->default(true)->after('leadership_review_telegram');
            $table->timestamp('last_leadership_review_at')->nullable()->after('last_morning_brief_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_productivity_settings', function (Blueprint $table) {
            $table->dropColumn([
                'leadership_review_enabled',
                'leadership_review_weekday',
                'leadership_review_local_time',
                'leadership_review_telegram',
                'leadership_review_inbox',
                'last_leadership_review_at',
            ]);
        });
    }
};
