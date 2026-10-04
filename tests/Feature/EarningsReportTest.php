<?php

namespace Tests\Feature;

use App\Models\AdmissionPayment;
use App\Models\MuseumInfo;
use App\Models\Staff;
use App\Models\Visitor;
use App\Models\VisitGroup;
use App\Support\Reports\Earnings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The payments ledger and the earnings report built on it.
 *
 * The reason the ledger exists is the first test: a visitor's own row is
 * reset when they come back, so revenue read from that row loses every
 * earlier visit. The rest pin that the group arithmetic (the difference
 * after an upward correction, refunds after a downward one) lands in the
 * ledger the way the cash drawer saw it.
 */
class EarningsReportTest extends TestCase
{
    use RefreshDatabase;

    private const LAPTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private float $fee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fee = MuseumInfo::admissionFee();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function desk(): Staff
    {
        return Staff::factory()->administrator()->create();
    }

    private function registerGroup(Staff $desk, int $headcount, int $locals = 0): VisitGroup
    {
        $this->actingAs($desk)->post('/desk/groups', [
            'contact_name' => 'Santos family', 'group_type' => 'Family',
            'visitor_type' => 'Tourist', 'headcount' => $headcount, 'local_count' => $locals,
        ]);

        return VisitGroup::latest('group_id')->firstOrFail();
    }

    // -- The ledger keeps what the visitor row forgets ----------------------

    public function test_a_returning_visitors_earlier_payment_still_counts(): void
    {
        $desk    = $this->desk();
        $visitor = Visitor::factory()->create(['admission_fee' => $this->fee]);

        Carbon::setTestNow('2026-09-14 10:00');
        $this->actingAs($desk)->post(route('visitors.mark-paid', $visitor))->assertRedirect();

        // Back a week later: the row resets to Unpaid for the new visit...
        Carbon::setTestNow('2026-09-21 10:00');
        $visitor->refresh()->touchReturning();
        $this->assertSame('Unpaid', $visitor->payment_status);

        $this->actingAs($desk)->post(route('visitors.mark-paid', $visitor))->assertRedirect();

        // ...but both payments are in the ledger, on the days they were made.
        $e = Earnings::build(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

        $this->assertSame(2, $e['byPayer']['individual']['transactions']);
        $this->assertEquals(2 * $this->fee, $e['byPayer']['individual']['net']);

        $days = collect($e['periods'])->keyBy(fn ($p) => $p['date']->toDateString());
        $this->assertEquals($this->fee, $days['2026-09-14']['individual']['net']);
        $this->assertEquals($this->fee, $days['2026-09-21']['individual']['net']);
        $this->assertEquals(0, $days['2026-09-15']['all']['net']);
    }

    public function test_paying_twice_on_one_day_is_refused_and_not_recorded(): void
    {
        $desk    = $this->desk();
        $visitor = Visitor::factory()->create(['admission_fee' => $this->fee]);

        $this->actingAs($desk)->post(route('visitors.mark-paid', $visitor));
        $this->actingAs($desk)->post(route('visitors.mark-paid', $visitor))->assertSessionHas('error');

        $this->assertSame(1, AdmissionPayment::count());
        $this->assertSame($desk->staff_id, AdmissionPayment::first()->recorded_by);
    }

    // -- Groups --------------------------------------------------------------

    public function test_a_group_payment_is_one_transaction_for_the_party(): void
    {
        $desk  = $this->desk();
        $group = $this->registerGroup($desk, 5, 1);

        $this->actingAs($desk)->post(route('desk.groups.paid', $group));

        $payment = AdmissionPayment::sole();
        $this->assertSame('group', $payment->payer);
        $this->assertSame(5, $payment->headcount);
        $this->assertEquals(4 * $this->fee, (float) $payment->amount);
    }

    public function test_a_refund_after_a_downward_correction_is_its_own_row(): void
    {
        $desk  = $this->desk();
        $group = $this->registerGroup($desk, 5);
        $this->actingAs($desk)->post(route('desk.groups.paid', $group));

        // Two of them turn out to be locals: two fees go back.
        $this->actingAs($desk)->post(route('desk.groups.correct', $group), ['local_count' => 2]);

        $refund = AdmissionPayment::where('kind', 'refund')->sole();
        $this->assertEquals(2 * $this->fee, (float) $refund->amount);

        $t = Earnings::build(today(), today())['totals'];
        $this->assertEquals(5 * $this->fee, $t['collected']);
        $this->assertEquals(2 * $this->fee, $t['refunded']);
        $this->assertEquals(3 * $this->fee, $t['net']);
        $this->assertSame(1, $t['transactions']);
        $this->assertSame(1, $t['refunds']);
    }

    public function test_paying_again_after_an_upward_correction_records_only_the_difference(): void
    {
        $desk  = $this->desk();
        $group = $this->registerGroup($desk, 5, 2);
        $this->actingAs($desk)->post(route('desk.groups.paid', $group));   // 3 fees

        // One "local" was not: one more fee is owed, and collected.
        $this->actingAs($desk)->post(route('desk.groups.correct', $group), ['local_count' => 1]);
        $this->actingAs($desk)->post(route('desk.groups.paid', $group->refresh()));

        $amounts = AdmissionPayment::orderBy('payment_id')->pluck('amount')->map(fn ($a) => (float) $a)->all();
        $this->assertEquals([3 * $this->fee, 1 * $this->fee], $amounts);

        $this->assertEquals(4 * $this->fee, Earnings::build(today(), today())['totals']['net']);
    }

    // -- The report ----------------------------------------------------------

    public function test_the_report_splits_individual_and_group(): void
    {
        $desk = $this->desk();

        $visitor = Visitor::factory()->create(['admission_fee' => $this->fee]);
        $this->actingAs($desk)->post(route('visitors.mark-paid', $visitor));

        $group = $this->registerGroup($desk, 3);
        $this->actingAs($desk)->post(route('desk.groups.paid', $group));

        $page = $this->withHeader('User-Agent', self::LAPTOP)->actingAs($desk)->get(route('reports.earnings'));

        $page->assertOk();
        $page->assertSee('By payment type');
        $page->assertSee('MDB-' . today()->format('Ymd') . '-0001');
        $page->assertSee('MDB-' . today()->format('Ymd') . '-0002');
        $page->assertSee('PHP ' . number_format(4 * $this->fee, 2));

        $e = Earnings::build(today(), today());
        $this->assertEquals($this->fee, $e['byPayer']['individual']['net']);
        $this->assertEquals(3 * $this->fee, $e['byPayer']['group']['net']);
    }

    public function test_a_long_range_is_broken_down_by_month(): void
    {
        $e = Earnings::build(Carbon::parse('2026-01-01'), Carbon::parse('2026-06-30'));

        $this->assertTrue($e['monthly']);
        $this->assertCount(6, $e['periods']);
        $this->assertSame('January 2026', $e['periods'][0]['label']);

        $this->assertFalse(Earnings::build(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'))['monthly']);
    }

    public function test_the_downloads_carry_the_same_figures(): void
    {
        $desk    = $this->desk();
        $visitor = Visitor::factory()->create(['admission_fee' => $this->fee]);
        $this->actingAs($desk)->post(route('visitors.mark-paid', $visitor));

        $as = $this->withHeader('User-Agent', self::LAPTOP)->actingAs($desk);

        // The CSV is the transaction list: one row per peso movement.
        $csv  = ltrim($as->get(route('reports.export', ['report' => 'earnings', 'format' => 'csv']))->streamedContent(), "\xEF\xBB\xBF");
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));

        $this->assertSame('Txn No.', $rows[0][0]);
        $this->assertCount(2, $rows);
        $this->assertSame('MDB-' . today()->format('Ymd') . '-0001', $rows[1][0]);
        $this->assertSame('Individual', $rows[1][3]);

        foreach (['xlsx', 'pdf'] as $format) {
            $as->get(route('reports.export', ['report' => 'earnings', 'format' => $format]))->assertOk();
        }
    }

    // -- The backfill --------------------------------------------------------

    public function test_the_migration_rebuilds_payments_made_before_the_ledger(): void
    {
        // Rows paid under the old scheme, then the ledger emptied as if the
        // migration were about to run for the first time.
        Visitor::factory()->paid()->create(['admission_fee' => $this->fee]);
        VisitGroup::create([
            'contact_name' => 'Cruz', 'visitor_type' => 'Tourist', 'headcount' => 4,
            'paying_count' => 4, 'total_fee' => 3 * $this->fee, 'refunded_amount' => $this->fee,
            'refunded_at' => now(), 'payment_status' => 'Paid', 'paid_at' => now(),
            'visit_date' => today(),
        ]);
        AdmissionPayment::query()->delete();

        $migration = require database_path('migrations/2026_10_02_000001_create_admission_payments_table.php');
        $migration->down();
        $migration->up();

        $t = Earnings::build(today(), today())['totals'];
        $this->assertSame(2, $t['transactions']);
        $this->assertEquals(5 * $this->fee, $t['collected']);   // 1 + the group's 4
        $this->assertEquals($this->fee, $t['refunded']);
        $this->assertTrue(AdmissionPayment::where('backfilled', false)->doesntExist());
    }
}
