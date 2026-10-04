<?php

namespace Tests\Feature;

use App\Models\MuseumInfo;
use App\Models\Staff;
use App\Support\Reports\ReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

/**
 * The two export tiers.
 *
 * What matters here is not that a file comes back - it is that the right
 * KIND of file comes back, that the machine tier stays a plain rectangle
 * nothing has decorated, and that the audit trail does not escape the
 * Tourism office through the new download route.
 */
class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    private const LAPTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

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

    private function tourism(): self
    {
        return $this->withHeader('User-Agent', self::LAPTOP)
            ->actingAs(Staff::factory()->tourismHead()->create());
    }

    private function download(string $report, string $format, array $query = []): string
    {
        $res = $this->admin()->get(route('reports.export', ['report' => $report, 'format' => $format] + $query));
        $res->assertOk();

        return $res->streamedContent();
    }

    // -- Tier two: what a person clicks ------------------------------------

    public function test_every_report_serves_every_format_it_advertises(): void
    {
        $staff = Staff::factory()->administrator()->create();

        foreach (ReportBuilder::FORMATS as $report => $formats) {
            if (in_array($report, ['audit', 'roster'], true)) {
                continue; // Tourism-only; covered separately below.
            }

            foreach ($formats as $format) {
                $query = $report === 'dtr' ? ['staff' => $staff->staff_id] : [];
                $body  = $this->download($report, $format, $query);

                $this->assertNotEmpty($body, "{$report}.{$format} came back empty");
                $this->assertSame(
                    $this->magicFor($format),
                    substr($body, 0, strlen($this->magicFor($format))),
                    "{$report}.{$format} is not actually a {$format} file"
                );
            }
        }
    }

    /** The first bytes each format must start with to be that format. */
    private function magicFor(string $format): string
    {
        return match ($format) {
            'pdf'  => '%PDF',
            // XLSX and DOCX are both ZIP containers - which is why ext-zip
            // has to be enabled for either of them to be written at all.
            'xlsx', 'docx' => "PK\x03\x04",
            'csv'  => "\xEF\xBB\xBF",
        };
    }

    public function test_a_format_a_report_does_not_offer_is_refused(): void
    {
        // Exhibit engagement is offered as DOCX, not XLSX; the logbook the
        // other way round. A link to the wrong one must not quietly serve
        // something else.
        $this->admin()->get('/reports/exhibits/export/xlsx')->assertNotFound();
        $this->admin()->get('/reports/logbook/export/docx')->assertNotFound();
        $this->admin()->get('/reports/logbook/export/txt')->assertNotFound();
    }

    public function test_the_spreadsheet_holds_numbers_not_text(): void
    {
        // The whole reason XLSX is offered: the office totals a column
        // itself. A figure written as a string cannot be summed.
        $path = $this->saveTemp($this->download('visitors', 'xlsx'), 'xlsx');

        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getSheet(0);

        $headcount = null;
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                if ($cell->getValue() === 'Headcount') {
                    $headcount = $cell->getColumn();
                    break 2;
                }
            }
        }

        $this->assertNotNull($headcount, 'no Headcount column in the sheet');

        $numeric = 0;
        foreach ($sheet->getColumnIterator($headcount, $headcount) as $col) {
            foreach ($col->getCellIterator() as $cell) {
                if (is_int($cell->getValue()) || is_float($cell->getValue())) {
                    $numeric++;
                }
            }
        }

        $this->assertGreaterThan(0, $numeric, 'every Headcount cell came out as text');
    }

    public function test_the_word_file_opens_as_a_document(): void
    {
        $path = $this->saveTemp($this->download('feedback', 'docx'), 'docx');

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'the docx is not a readable container');
        $this->assertNotFalse($zip->locateName('word/document.xml'));
        $this->assertNotFalse($zip->locateName('word/header1.xml'), 'no header part, so no letterhead to repeat');
        $zip->close();
    }

    // -- Tier one: what the pipeline reads ---------------------------------

    public function test_the_csv_is_a_plain_rectangle(): void
    {
        $body = $this->download('visitors', 'csv');

        $lines = array_values(array_filter(explode("\n", trim($body))));
        $this->assertGreaterThan(1, count($lines));

        $width = count(str_getcsv($lines[0]));
        foreach ($lines as $i => $line) {
            $this->assertCount($width, str_getcsv($line), "row {$i} has a different column count");
        }

        // No letterhead, no totals row, no summary strip: those belong in
        // the formats a person reads.
        $this->assertStringNotContainsString('Museo de Baler', $body);
        $this->assertStringNotContainsString('TOTAL', $body);
    }

    public function test_the_csv_keeps_its_byte_order_mark(): void
    {
        // Without it Excel reads a UTF-8 CSV as Windows-1252 and every
        // Filipino name with an enye in it comes out as mojibake.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $this->download('logbook', 'csv'));
    }

    public function test_the_nightly_batch_writes_one_csv_per_report(): void
    {
        $dir = storage_path('app/testing-exports');

        $this->artisan('reports:export', ['--path' => $dir])->assertSuccessful();

        foreach (['logbook', 'visitors', 'exhibits', 'feedback', 'audit'] as $report) {
            $this->assertFileExists($dir . DIRECTORY_SEPARATOR . $report . '.csv');
        }

        array_map('unlink', glob($dir . DIRECTORY_SEPARATOR . '*.csv') ?: []);
        @rmdir($dir);
    }

    public function test_the_batch_does_not_write_the_human_formats(): void
    {
        $dir = storage_path('app/testing-exports-2');

        $this->artisan('reports:export', ['--path' => $dir])->assertSuccessful();

        $this->assertEmpty(glob($dir . DIRECTORY_SEPARATOR . '*.xlsx') ?: []);
        $this->assertEmpty(glob($dir . DIRECTORY_SEPARATOR . '*.pdf') ?: []);
        $this->assertEmpty(glob($dir . DIRECTORY_SEPARATOR . '*.docx') ?: []);

        array_map('unlink', glob($dir . DIRECTORY_SEPARATOR . '*.csv') ?: []);
        @rmdir($dir);
    }

    // -- Save downloads straight away ---------------------------------------

    public function test_there_is_no_preview_step_between_the_page_and_the_file(): void
    {
        // The preview rendered a whole PDF on every page load and on every
        // change of the dropdown, then rendered it again when Save was
        // clicked. It was removed for that cost, so it must not come back
        // in any form: no pane, no frame, no route.
        $html = $this->admin()->get(route('reports.visitors'))->getContent();

        $this->assertStringNotContainsString('id="prevBody"', $html);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('/preview/', $html);
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('reports.preview'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('reports.audit.preview'));

        $this->admin()->get('/reports/visitors/preview/pdf')->assertNotFound();
        $this->tourism()->get('/reports/audit/preview/pdf')->assertNotFound();
    }

    public function test_save_points_at_the_real_file_before_any_script_runs(): void
    {
        // The href is rendered on the server, so Save downloads the first
        // format even if the toolbar script never runs.
        $html = $this->admin()->get(route('reports.visitors', ['from' => '2026-09-01', 'to' => '2026-09-30']))->getContent();

        $first = route('reports.export', ['report' => 'visitors', 'format' => 'pdf', 'from' => '2026-09-01', 'to' => '2026-09-30']);
        $this->assertMatchesRegularExpression(
            '/<a id="saveBtn" class="go" href="' . preg_quote(e($first), '/') . '" download>/',
            $html
        );
    }

    public function test_the_pdf_comes_back_as_a_download_not_inline(): void
    {
        $res = $this->admin()->get(route('reports.export', ['report' => 'visitors', 'format' => 'pdf']));

        $res->assertOk();
        $this->assertStringStartsWith('attachment', (string) $res->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $res->streamedContent());
    }

    public function test_nothing_in_the_panel_can_be_framed(): void
    {
        // The PDF preview was the one response allowed in a frame. With it
        // gone, a report download is as unframable as every other page.
        foreach ([
            route('reports.visitors'),
            route('reports.export', ['report' => 'visitors', 'format' => 'pdf']),
        ] as $url) {
            $res = $this->admin()->get($url);
            $res->assertHeader('X-Frame-Options', 'DENY');
            $this->assertStringContainsString("frame-ancestors 'none'", (string) $res->headers->get('Content-Security-Policy'));
        }
    }

    public function test_the_toolbar_is_save_print_and_one_dropdown(): void
    {
        $html = $this->admin()->get(route('reports.visitors'))->getContent();

        // A dropdown, not a row of pills, and no Preview button.
        $this->assertStringContainsString('id="fmtPick"', $html);
        $this->assertStringContainsString('id="saveBtn"', $html);
        $this->assertStringContainsString('class="print"', $html);
        $this->assertStringNotContainsString('id="previewBtn"', $html);
        $this->assertStringNotContainsString('name="fmt"', $html);

        // One option per format the report serves, and nothing else.
        $this->assertSame(
            count(ReportBuilder::FORMATS['visitors']),
            preg_match_all('/<option value="/', $html)
        );
    }

    public function test_the_poster_is_a_standalone_sheet_not_a_report(): void
    {
        // The poster is the one printable thing that is not a report: it
        // goes on a wall, so it carries no letterhead, no export formats and
        // no panel chrome - only the instruction, the code and the steps.
        $poster = $this->admin()->get('/desk/poster');

        $poster->assertOk();
        $poster->assertSee('Scan to sign in before you go inside');
        $poster->assertSee('data:image/', false);          // the QR itself
        $poster->assertDontSee('id="fmtPick"', false);     // no format picker
        $poster->assertDontSee('class="sheet"', false);    // not the report layout
    }

    // -- The wall the audit trail sits behind ------------------------------

    public function test_the_audit_trail_stays_behind_the_tourism_wall(): void
    {
        // SECURITY: the audit log is the record kept ON museum staff. The
        // shared export route must not reach it under any spelling.
        $this->admin()->get('/reports/audit/export/csv')->assertForbidden();
        $this->admin()->get('/reports/audit/export/pdf')->assertForbidden();
        $this->admin()->get('/reports/audit/csv')->assertForbidden();

        $this->tourism()->get('/reports/audit/export/csv')->assertOk();
        $this->tourism()->get('/reports/audit/export/pdf')->assertOk();
    }

    public function test_the_old_csv_links_still_serve_the_file(): void
    {
        // Half a dozen views point at these, and an office bookmarks an
        // export it uses every week. They must still hand back a CSV, not a
        // redirect to the new URL - anything fetching one on a schedule may
        // not follow one.
        foreach (['reports.logbook.csv', 'reports.feedback.csv'] as $name) {
            $res = $this->admin()->get(route($name));
            $res->assertOk();
            $this->assertStringContainsString('text/csv', (string) $res->headers->get('Content-Type'));
            $this->assertStringStartsWith("\xEF\xBB\xBF", $res->streamedContent());
        }
    }

    // -- Branding, across all three human formats --------------------------

    public function test_the_uploaded_letterhead_reaches_every_human_format(): void
    {
        $this->admin()->post('/museum', [
            'name'                => 'Museo de Baler',
            'admission_fee'       => 50,
            'report_header_image' => UploadedFile::fake()->image('letterhead.png', 1200, 200),
        ])->assertRedirect(route('museum.index'));

        $info = MuseumInfo::first();
        $this->assertNotNull($info->report_header_image, 'the banner was not saved');
        $this->written[] = public_path(MuseumInfo::LOGO_DIR . '/' . $info->report_header_image);
        $this->assertFileExists(end($this->written));

        // The browser page shows it.
        $this->admin()->get(route('reports.visitors'))
            ->assertOk()
            ->assertSee('class="banner"', false);

        // The spreadsheet embeds it.
        $path = $this->saveTemp($this->download('visitors', 'xlsx'), 'xlsx');
        $this->assertNotEmpty(
            \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getSheet(0)->getDrawingCollection(),
            'the banner is not in the spreadsheet'
        );

        // The Word file carries a header part holding an image.
        $docx = $this->saveTemp($this->download('exhibits', 'docx'), 'docx');
        $zip  = new ZipArchive();
        $zip->open($docx);
        $header = $zip->getFromName('word/header1.xml');
        $zip->close();
        // PHPWord writes header images as VML (<v:imagedata>) rather than
        // DrawingML. Word renders both; only the markup differs.
        $this->assertStringContainsString('v:imagedata', (string) $header, 'the banner is not in the Word header');

        // And the PDF actually embeds it.
        //
        // Asserting on %PDF alone is not enough: a PDF whose letterhead
        // silently failed to resolve is still a valid PDF, and that is
        // exactly how this was broken the first time.
        $pdf = $this->download('visitors', 'pdf');
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(
            0,
            preg_match_all('~/Subtype\s*/Image~', $pdf),
            'the PDF carries no image, so the letterhead did not resolve'
        );
    }

    public function test_the_letterhead_repeats_on_every_page_of_a_long_report(): void
    {
        // The reason mPDF was chosen over a lighter converter. A letterhead
        // that prints only on page one of a twenty-page trail is not a
        // letterhead, and nobody checks page two before filing it.
        // The letterhead is museum configuration, so an Administrator sets
        // it; the audit trail is Tourism's, so a Tourism head pulls it.
        $this->admin()->post('/museum', [
            'name'                => 'Museo de Baler',
            'admission_fee'       => 50,
            'report_header_image' => UploadedFile::fake()->image('letterhead.png', 1200, 200),
        ])->assertRedirect(route('museum.index'));

        $tourism = Staff::factory()->tourismHead()->create();

        $info = MuseumInfo::first();
        $this->assertNotNull($info?->report_header_image, 'the banner was not saved');
        $this->written[] = public_path(MuseumInfo::LOGO_DIR . '/' . $info->report_header_image);

        foreach (range(1, 400) as $i) {
            \App\Models\Log::create([
                'user_id'    => $tourism->staff_id,
                'user_name'  => 'Elena Bautista',
                'role'       => 'TourismHead',
                'action'     => 'viewed',
                'details'    => "Opened visitor record #{$i} from the records screen",
                'ip_address' => '127.0.0.1',
            ]);
        }

        $res = $this->withHeader('User-Agent', self::LAPTOP)->actingAs($tourism)
            ->get(route('reports.audit.export', ['format' => 'pdf', 'from' => today()->subDay()->toDateString(), 'to' => today()->toDateString()]));
        $res->assertOk();

        $pdf   = $res->streamedContent();
        $pages = preg_match_all('~/Type\s*/Page[^s]~', $pdf);

        $this->assertGreaterThan(1, $pages, 'expected the trail to run past one page');

        // mPDF stores the image once and shares one resource dictionary
        // across every page, so a reference count proves nothing. What
        // proves it is the draw operator: each page's content stream has to
        // paint the image itself. Streams are Flate-compressed, hence the
        // inflate before counting.
        $this->assertSame($pages, $this->countImageDraws($pdf), 'the letterhead is not drawn on every page');
    }

    public function test_a_banner_pointing_at_a_deleted_file_falls_back(): void
    {
        // A row can outlive its file - a restored database, a cleaned
        // public folder. That must print the composed header, not a
        // broken image or a crash inside the PDF writer.
        MuseumInfo::create([
            'name'                => 'Museo de Baler',
            'report_header_image' => 'gone-forever.png',
        ]);

        $this->admin()->get(route('reports.visitors'))
            ->assertOk()
            ->assertSee('Museo de Baler')
            ->assertDontSee('class="banner"', false);

        $this->assertStringStartsWith('%PDF', $this->download('visitors', 'pdf'));
    }

    /** How many times a PDF's page content actually paints an image. */
    private function countImageDraws(string $pdf): int
    {
        preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $streams);

        $draws = 0;
        foreach ($streams[1] as $stream) {
            $plain = @gzuncompress(ltrim($stream, "\r\n"));
            if ($plain !== false) {
                $draws += preg_match_all('~/I\d+\s+Do~', $plain);
            }
        }

        return $draws;
    }

    private function saveTemp(string $body, string $ext): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tst') . '.' . $ext;
        file_put_contents($path, $body);
        $this->written[] = $path;

        return $path;
    }
}
