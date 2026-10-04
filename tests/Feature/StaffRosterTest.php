<?php

namespace Tests\Feature;

use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The employee master list printed from the Staff page.
 */
class StaffRosterTest extends TestCase
{
    use RefreshDatabase;

    private const LAPTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private function tourism(): self
    {
        return $this->withHeader('User-Agent', self::LAPTOP)
            ->actingAs(Staff::factory()->tourismHead()->create(['name' => 'Tess Tourism']));
    }

    public function test_the_staff_page_links_to_the_roster(): void
    {
        $this->tourism()->get(route('staff.index'))
            ->assertOk()
            ->assertSee('Print All Employees')
            ->assertSee(route('staff.roster'), false);
    }

    public function test_the_roster_lists_every_account_active_or_not(): void
    {
        Staff::factory()->administrator()->create(['name' => 'Ana Active']);
        Staff::factory()->administrator()->create(['name' => 'Ivan Inactive', 'status' => false]);

        $page = $this->tourism()->get(route('staff.roster'));

        $page->assertOk();
        $page->assertSee('Employee Master List');
        $page->assertSee('Ana Active');
        $page->assertSee('Ivan Inactive');
        $page->assertSee('Tess Tourism');
        $page->assertSee('Inactive');

        // Nothing about the credentials, ever.
        $page->assertDontSee('password', false);
    }

    public function test_the_roster_downloads_in_each_format(): void
    {
        Staff::factory()->administrator()->create(['name' => 'Ana Active']);
        $as = $this->tourism();

        $csv = $as->get(route('staff.roster.export', ['format' => 'csv']));
        $csv->assertOk();
        $this->assertStringContainsString('Ana Active', $csv->streamedContent());

        $pdf = $as->get(route('staff.roster.export', ['format' => 'pdf']));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->streamedContent());

        $as->get(route('staff.roster.export', ['format' => 'xlsx']))->assertOk();
        $as->get('/staff/roster/export/docx')->assertNotFound();
    }

    public function test_museum_staff_cannot_read_the_roster(): void
    {
        // SECURITY: the accounts are Tourism's to manage, so is the list.
        $this->withHeader('User-Agent', self::LAPTOP)
            ->actingAs(Staff::factory()->administrator()->create());

        $this->get(route('staff.roster'))->assertForbidden();
        $this->get(route('staff.roster.export', ['format' => 'csv']))->assertForbidden();

        // And the shared report route does not reach it under another name.
        $this->get('/reports/roster/export/csv')->assertNotFound();
    }
}
