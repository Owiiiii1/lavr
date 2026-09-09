<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_assistant_profiles', function (Blueprint $table): void {
            $table->string('interface_locale', 8)->nullable()->after('about_user');
            $table->string('assistant_locale', 8)->nullable()->after('interface_locale');
        });
    }

    public function down(): void
    {
        Schema::table('user_assistant_profiles', function (Blueprint $table): void {
            $table->dropColumn(['interface_locale', 'assistant_locale']);
        });
    }
};
