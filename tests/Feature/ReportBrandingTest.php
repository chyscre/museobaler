<?php

namespace Tests\Feature;

use App\Models\MuseumInfo;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The letterhead on printed reports, set once from Museum Info.
 *
 * Uploading a logo and typing the organisation and footer lines is the
 * whole of it; every report picks them up through the print layout. What
 * is checked here: the file lands and is served, the lines print, the
 * defaults hold when nothing is set, and removing the logo removes the
 * file.
 */
class ReportBrandingTest extends TestCase
{
    use RefreshDatabase;

    private const LAPTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    /** Files this test writes under public/, removed afterwards. */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $f) {
            if (is_file($f)) @unlink($f);
        }
        parent::tearDown();
    }

    private function admin(): self
    {
        return $this->withHeader('User-Agent', self::LAPTOP)
            ->actingAs(Staff::factory()->administrator()->create());
    }

    private function save(array $extra = [], array $files = []): \Illuminate\Testing\TestResponse
    {
        $res = $this->admin()->post('/museum', array_merge([
            'name'          => 'Museo de Baler',
            'admission_fee' => 50,
        ], $extra, $files));

        $info = MuseumInfo::first();
        foreach (['report_logo', 'report_header_image'] as $field) {
            if ($info && $info->{$field}) {
                $this->written[] = public_path(MuseumInfo::LOGO_DIR . '/' . $info->{$field});
            }
        }

        return $res;
    }

    public function test_reports_print_the_museum_name_when_nothing_is_set(): void
    {
        $this->admin()->get(route('reports.visitors'))
            ->assertOk()
            ->assertSee('Museo de Baler')
            ->assertDontSee('class="logo"', false)
            ->assertDontSee('class="banner"', false);
    }

    public function test_the_logo_shows_on_every_report(): void
    {
        $this->save([], [
            'report_logo' => UploadedFile::fake()->image('seal.png', 400, 400),
        ])->assertRedirect(route('museum.index'));

        $info = MuseumInfo::first();
        $this->assertNotNull($info->report_logo);
        $this->assertFileExists(public_path(MuseumInfo::LOGO_DIR . '/' . $info->report_logo));

        foreach (['reports.visitors', 'reports.feedback', 'reports.logbook'] as $route) {
            $this->admin()->get(route($route))
                ->assertOk()
                ->assertSee('class="logo"', false)
                ->assertSee(MuseumInfo::LOGO_DIR . '/' . $info->report_logo);
        }
    }

    public function test_an_uploaded_letterhead_replaces_the_composed_header(): void
    {
        $this->save([], [
            'report_logo'         => UploadedFile::fake()->image('seal.png', 400, 400),
            'report_header_image' => UploadedFile::fake()->image('letterhead.png', 1200, 200),
        ])->assertRedirect(route('museum.index'));

        $info = MuseumInfo::first();
        $this->written[] = public_path(MuseumInfo::LOGO_DIR . '/' . $info->report_header_image);

        // Both files exist, but only the banner prints: a letterhead already
        // carries the seal and the office name.
        $this->admin()->get(route('reports.visitors'))
            ->assertOk()
            ->assertSee('class="banner"', false)
            ->assertDontSee('class="logo"', false);
    }

    public function test_a_new_logo_replaces_the_old_file(): void
    {
        $this->save([], ['report_logo' => UploadedFile::fake()->image('one.png', 100, 100)]);
        $first = MuseumInfo::first()->report_logo;
        $firstPath = public_path(MuseumInfo::LOGO_DIR . '/' . $first);
        $this->assertFileExists($firstPath);

        sleep(1); // the filename carries the second it was saved
        $this->save([], ['report_logo' => UploadedFile::fake()->image('two.jpg', 100, 100)]);
        $second = MuseumInfo::first()->report_logo;

        $this->assertNotSame($first, $second);
        $this->assertFileDoesNotExist($firstPath);
        $this->assertFileExists(public_path(MuseumInfo::LOGO_DIR . '/' . $second));
    }

    public function test_the_logo_can_be_removed(): void
    {
        $this->save([], ['report_logo' => UploadedFile::fake()->image('seal.png', 100, 100)]);
        $path = public_path(MuseumInfo::LOGO_DIR . '/' . MuseumInfo::first()->report_logo);

        $this->save(['remove_logo' => 1]);

        $this->assertNull(MuseumInfo::first()->report_logo);
        $this->assertFileDoesNotExist($path);
        $this->admin()->get(route('reports.visitors'))->assertDontSee('class="logo"', false);
    }

    public function test_only_raster_images_are_accepted_as_a_logo(): void
    {
        $this->save([], ['report_logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')])
            ->assertSessionHasErrors('report_logo');
        $this->assertNull(MuseumInfo::first()?->report_logo);
    }

    public function test_saving_branding_leaves_the_rest_of_the_row_alone(): void
    {
        $this->save(['latitude' => 15.76, 'longitude' => 121.56, 'geofence_radius_m' => 150]);
        $this->save(
            ['latitude' => 15.76, 'longitude' => 121.56, 'geofence_radius_m' => 150],
            ['report_logo' => UploadedFile::fake()->image('seal.png', 100, 100)]
        );

        $info = MuseumInfo::first();
        $this->assertNotNull($info->report_logo);
        $this->assertSame(150, $info->geofence_radius_m);
        $this->assertEquals(50, (float) $info->admission_fee);
    }
}
