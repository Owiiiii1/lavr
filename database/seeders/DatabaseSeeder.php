<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Users\AccessCodeGenerator;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (User::query()->exists()) {
            return;
        }

        User::factory()->create([
            'name' => 'LAVR Client',
            'email' => 'client@example.com',
            'role' => UserRole::Owner,
            'access_code' => AccessCodeGenerator::OWNER_CODE,
        ]);
    }
}
