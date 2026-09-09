<?php

namespace Database\Factories;

use App\Enums\EmploymentStatus;
use App\Models\EmployeeProfile;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeProfile>
 */
class EmployeeProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'person_id' => Person::factory(),
            'position' => 'Producer',
            'department' => 'Operations',
            'manager_person_id' => null,
            'employment_status' => EmploymentStatus::Active,
            'responsibilities' => ['Show production'],
            'areas_of_ownership' => ['Chicago'],
            'notes' => null,
        ];
    }
}
