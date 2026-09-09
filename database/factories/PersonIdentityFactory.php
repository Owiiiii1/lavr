<?php

namespace Database\Factories;

use App\Enums\PersonIdentityType;
use App\Models\Person;
use App\Models\PersonIdentity;
use App\Services\Directory\IdentityNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PersonIdentity>
 */
class PersonIdentityFactory extends Factory
{
    public function definition(): array
    {
        $email = fake()->unique()->safeEmail();

        return [
            'person_id' => Person::factory(),
            'type' => PersonIdentityType::Email,
            'value' => $email,
            'normalized_value' => IdentityNormalizer::normalize(PersonIdentityType::Email, $email),
            'is_primary' => true,
        ];
    }
}
