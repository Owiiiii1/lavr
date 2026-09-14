<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_productivity_settings', function (Blueprint $table) {
            $table->boolean('operational_alerts_enabled')->default(true)->after('proactive_enabled');
            $table->string('operational_min_severity', 16)->default('high')->after('operational_alerts_enabled');
            $table->unsignedTinyInteger('operational_max_alerts_per_day')->default(6)->after('operational_min_severity');
            $table->string('quiet_hours_start', 5)->nullable()->after('operational_max_alerts_per_day');
            $table->string('quiet_hours_end', 5)->nullable()->after('quiet_hours_start');
            $table->boolean('critical_bypass_quiet_hours')->default(true)->after('quiet_hours_end');
            $table->boolean('auto_create_reminders')->default(false)->after('critical_bypass_quiet_hours');
            $table->boolean('auto_draft_messages')->default(false)->after('auto_create_reminders');
            $table->boolean('third_party_execute')->default(false)->after('auto_draft_messages');
            $table->json('disabled_operational_rules')->nullable()->after('third_party_execute');
            $table->json('operational_rule_prefs')->nullable()->after('disabled_operational_rules');
        });
    }

    public function down(): void
    {
        Schema::table('user_productivity_settings', function (Blueprint $table) {
            $table->dropColumn([
                'operational_alerts_enabled',
                'operational_min_severity',
                'operational_max_alerts_per_day',
                'quiet_hours_start',
                'quiet_hours_end',
                'critical_bypass_quiet_hours',
                'auto_create_reminders',
                'auto_draft_messages',
                'third_party_execute',
                'disabled_operational_rules',
                'operational_rule_prefs',
            ]);
        });
    }
};
