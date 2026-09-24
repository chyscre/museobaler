<?php

namespace Tests\Feature;

use App\Models\MuseumInfo;
use App\Models\Staff;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admission fee is set once, on the Museum Info page, and every screen
 * that mentions money reads it from there. It used to be a number typed
 * into the code in six places while Museum Info carried a free-text line that
 * could say - and did say - "Free for all visitors" on a museum charging 50.
 */
class AdmissionFeeSettingTest extends TestCase
{
    use RefreshDatabase;

    private const LAPTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private function staff()
    {
        return $this->withHeader('User-Agent', self::LAPTOP)
            ->actingAs(Staff::factory()->administrator()->create());
    }

    private function setFee(float $fee): void
    {
        $this->staff()->post('/museum', ['name' => 'Museo de Baler', 'admission_fee' => $fee])
            ->assertRedirect(route('museum.index'))
            ->assertSessionHasNoErrors();
    }

    public function test_the_fee_defaults_to_what_the_museum_has_always_charged(): void
    {
        $this->assertSame(50.0, MuseumInfo::admissionFee());
        $this->assertSame(50.0, Visitor::feeFor('Tourist'));
        $this->assertSame(0.0, Visitor::feeFor('Local'));
    }

    public function test_museum_staff_set_the_fee_on_the_museum_info_page(): void
    {
        $this->setFee(80);

        $this->assertSame(80.0, MuseumInfo::admissionFee());
        $this->assertSame(80.0, Visitor::feeFor('Foreign'));
        $this->assertSame(0.0, Visitor::feeFor('Local'));
    }

    public function test_the_visitor_facing_sentence_is_generated_from_the_fee(): void
    {
        $this->setFee(80);
        $this->assertSame(
            'Baler residents enter free with a valid ID · Visitors ₱80.00',
            MuseumInfo::first()->admission
        );

        $this->setFee(0);
        $this->assertSame('Free for all visitors', MuseumInfo::first()->admission);
    }

    public function test_a_walk_in_registered_at_the_desk_owes_the_current_fee(): void
    {
        $this->setFee(75.5);

        $this->staff()->post('/desk/visitors', [
            'first_name' => 'Ramon', 'last_name' => 'Cruz', 'visitor_type' => 'Tourist',
            'city' => 'Quezon City',
        ])->assertRedirect();

        $this->assertDatabaseHas('visitors', [
            'first_name'     => 'Ramon',
            'admission_fee'  => 75.5,
            'payment_status' => 'Unpaid',
        ]);
    }

    public function test_the_desk_and_museum_info_show_the_current_fee(): void
    {
        $this->setFee(80);

        $this->staff()->get('/desk')->assertOk()->assertSee('PHP 80.00')->assertDontSee('PHP 50');
        // Not the poster: it says only how to sign in. The fee is settled at
        // the desk, by a person, so a printed figure there would be one more
        // thing to reprint every time the fee changes.
        $this->staff()->get('/museum')->assertOk()
            ->assertSee('value="80.00"', false)
            ->assertSee('Visitors ₱80.00');
    }

    public function test_a_negative_or_missing_fee_is_refused(): void
    {
        $this->staff()->post('/museum', ['name' => 'Museo de Baler', 'admission_fee' => -5])
            ->assertSessionHasErrors('admission_fee');

        $this->staff()->post('/museum', ['name' => 'Museo de Baler'])
            ->assertSessionHasErrors('admission_fee');

        $this->assertSame(50.0, MuseumInfo::admissionFee());
    }
}
