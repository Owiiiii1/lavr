<?php

namespace Database\Factories;

use App\Enums\PersonStatus;
use App\Models\Person;
use App\Models\User;
use App\Services\Projects\ProjectNameNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    public function definition(): array
    {
        $first = fake()->firstName();
        $last = fake()->lastName();
        $display = $first.' '.$last;

        return [
            'user_id' => User::factory(),
            'first_name' => $first,
            'last_name' => $last,
            'display_name' => $display,
            'normalized_name' => ProjectNameNormalizer::normalize($display),
            'primary_email' => fake()->unique()->safeEmail(),
            'primary_phone' => null,
            'telegram_username' => 'tg_'.Str::lower(Str::random(8)),
            'notes' => null,
            'preferred_language' => 'uk',
            'status' => PersonStatus::Active,
        ];
    }
}
