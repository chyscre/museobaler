<?php

namespace Database\Factories;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Staff>
 */
class StaffFactory extends Factory
{
    protected $model = Staff::class;

    public function definition(): array
    {
        return [
            'name'     => fake()->name(),
            'email'    => fake()->unique()->safeEmail(),
            'password' => Hash::make('Password1!'),
            'role'     => 'Administrator',
            'status'   => true,
            // A settled account by default: one whose holder has already
            // replaced the password they were issued. Tests that are about
            // something else should not have to walk through the forced
            // password change to get there.
            'must_change_password' => false,
            'password_changed_at'  => now(),
        ];
    }

    /** Freshly created by the Tourism office and not yet signed into. */
    public function awaitingPasswordChange(): static
    {
        return $this->state(fn () => [
            'must_change_password' => true,
            'password_changed_at'  => null,
        ]);
    }

    /** Museum staff — the entrance desk, the guiding, the exhibits, all of it. */
    public function administrator(): static
    {
        return $this->state(fn () => ['role' => 'Administrator']);
    }

    /** The head of the Municipal Tourism Office — oversight, from outside the museum. */
    public function tourismHead(): static
    {
        return $this->state(fn () => ['role' => 'TourismHead']);
    }
}
