<?php

namespace Database\Factories;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use App\Services\Projects\ProjectNameNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'user_id' => User::factory(),
            'name' => $name,
            'normalized_name' => ProjectNameNormalizer::normalize($name),
            'type' => 'company',
            'website' => null,
            'email' => fake()->unique()->companyEmail(),
            'phone' => null,
            'notes' => null,
            'status' => OrganizationStatus::Active,
        ];
    }
}
