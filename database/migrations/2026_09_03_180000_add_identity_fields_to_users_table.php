<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 16)->default('user')->after('password');
            $table->string('access_code', 32)->nullable()->after('role');
            $table->string('status', 16)->default('active')->after('access_code');
            $table->string('timezone', 64)->default('Europe/Rome')->after('status');
        });

        $userCount = (int) DB::table('users')->count();
        $ownerCodeTaken = DB::table('users')->where('access_code', '2000')->exists();

        if ($userCount === 1 && ! $ownerCodeTaken) {
            $existingUser = DB::table('users')->first();

            if ($existingUser !== null) {
                DB::table('users')
                    ->where('id', $existingUser->id)
                    ->update([
                        'role' => 'owner',
                        'access_code' => '2000',
                        'status' => 'active',
                        'timezone' => 'Europe/Rome',
                    ]);
            }
        } elseif ($userCount > 1) {
            throw new RuntimeException(
                'Unexpected users count during identity migration. Manual review required before promoting owner.'
            );
        }

        $usersWithoutCode = (int) DB::table('users')->whereNull('access_code')->count();

        if ($usersWithoutCode > 0) {
            throw new RuntimeException(
                'Users without access_code remain after owner backfill. Aborting migration.'
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('access_code', 32)->nullable(false)->change();
            $table->unique('access_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['access_code']);
            $table->dropColumn(['role', 'access_code', 'status', 'timezone']);
        });
    }
};
