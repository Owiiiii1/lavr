<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_productivity_settings', function (Blueprint $table) {
            $table->boolean('morning_brief_enabled')->default(true)->after('proactive_enabled');
            $table->string('morning_brief_local_time', 5)->default('08:30')->after('morning_brief_enabled');
            $table->boolean('morning_brief_telegram')->default(true)->after('morning_brief_local_time');
            $table->boolean('morning_brief_inbox')->default(true)->after('morning_brief_telegram');
            $table->boolean('morning_brief_weekends')->default(false)->after('morning_brief_inbox');
            $table->timestamp('last_morning_brief_at')->nullable()->after('last_weekly_review_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_productivity_settings', function (Blueprint $table) {
            $table->dropColumn([
                'morning_brief_enabled',
                'morning_brief_local_time',
                'morning_brief_telegram',
                'morning_brief_inbox',
                'morning_brief_weekends',
                'last_morning_brief_at',
            ]);
        });
    }
};
