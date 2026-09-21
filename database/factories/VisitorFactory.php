<?php

namespace Database\Factories;

use App\Models\Visitor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Visitor>
 */
class VisitorFactory extends Factory
{
    protected $model = Visitor::class;

    /** A tourist who has registered in the app and not yet paid. */
    public function definition(): array
    {
        return [
            'first_name'     => fake()->firstName(),
            'last_name'      => fake()->lastName(),
            'middle_name'    => null,
            'age'            => fake()->numberBetween(18, 70),
            'sex'            => 'Prefer not to say',
            'visitor_type'   => 'Tourist',
            'visit_type'     => 'Solo',
            'city'           => 'Quezon City',
            'province'       => 'Metro Manila',
            'country'        => 'Philippines',
            'email'          => fake()->unique()->safeEmail(),
            'password'       => 'correct horse battery',
            'auth_provider'  => 'manual',
            'explore_mode'   => 'Storyline',
            'source'         => 'app',
            'admission_fee'  => 50.00,
            'payment_status' => 'Unpaid',
            'id_verified'    => false,
            'last_visit'     => now(),
        ];
    }

    /** The desk has taken their fee: cleared to enter. */
    public function paid(): static
    {
        return $this->state(fn () => ['payment_status' => 'Paid', 'paid_at' => now()]);
    }

    /** A Baler resident, free admission once an ID has been sighted. */
    public function local(bool $verified = false): static
    {
        return $this->state(fn () => [
            'visitor_type'   => 'Local',
            'city'           => 'Baler',
            'barangay'       => 'Sabang',
            'province'       => 'Aurora',
            'admission_fee'  => 0,
            'payment_status' => 'Free',
            'id_verified'    => $verified,
        ]);
    }
}
