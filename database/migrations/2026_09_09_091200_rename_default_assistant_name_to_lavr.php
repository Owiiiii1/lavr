<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_assistant_profiles')) {
            return;
        }

        DB::table('user_assistant_profiles')
            ->where('assistant_name', 'Jarvis')
            ->update(['assistant_name' => 'LAVR']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('user_assistant_profiles')) {
            return;
        }

        DB::table('user_assistant_profiles')
            ->where('assistant_name', 'LAVR')
            ->update(['assistant_name' => 'Jarvis']);
    }
};
