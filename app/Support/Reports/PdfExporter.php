<?php

namespace App\Support\Reports;

use App\Models\MuseumInfo;
use Mpdf\Mpdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The printed report.
 *
 * Alone among the four exporters this one does not read a ReportDataset for
 * its body. It renders the same Blade view the browser prints, because that
 * layout is the one the museum already checks, signs and files - rebuilding
 * it from flat rows would produce a second document that looked almost but
 * not quite like the one on the wall, and the differences would be found by
 * whoever had to file it.
 *
 * What the dataset does supply is the title and the dateline, because the
 * letterhead is set on mPDF rather than written into the HTML. That is the
 * reason for mPDF over the lighter converters: SetHTMLHeader repeats the
 * banner on page two onwards, and a letterhead that only appears on the
 * first page of a twelve-page DTR is not a letterhead.
 */
class PdfExporter
{
    /** Room for the banner and the page number, in mm. */
    private const MARGIN_TOP    = 38;
    private const MARGIN_BOTTOM = 18;

    public function stream(ReportDataset $data, string $view, array $viewData): StreamedResponse
    {
        $pdf = $this->render($data, $view, $viewData);

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf;
        }, $data->filenameFor('pdf'), ['Content-Type' => 'application/pdf']);
    }

    public function toFile(ReportDataset $data, string $view, array $viewData, string $path): void
    {
        file_put_contents($path, $this->render($data, $view, $viewData));
    }

    /** The PDF bytes, for a caller that will not be sending them as a download. */
    public function raw(ReportDataset $data, string $view, array $viewData): string
    {
        return $this->render($data, $view, $viewData);
    }

    private function render(ReportDataset $data, string $view, array $viewData): string
    {
        $brand = MuseumInfo::branding();

        // mPDF writes font subsets and image caches while it works. Pointed
        // at storage rather than the system temp dir so a locked-down host
        // does not have to make %TEMP% writable for the web user.
        $temp = storage_path('framework/mpdf');
        if (!is_dir($temp)) {
            mkdir($temp, 0775, true);
        }

        $mpdf = new Mpdf([
            'tempDir'      => $temp,
            'format'       => 'A4',
            'margin_top'    => self::MARGIN_TOP,
            'margin_bottom' => self::MARGIN_BOTTOM,
            'margin_left'   => 12,
            'margin_right'  => 12,
            'margin_header' => 8,
            'margin_footer' => 8,
            'default_font'  => 'dejavusans',
        ]);

        $mpdf->SetTitle($data->title);
        $mpdf->SetAuthor($brand['name']);
        $mpdf->SetHTMLHeader($this->header($data, $brand));
        $mpdf->SetHTMLFooter($this->footer());

        // `pdf` tells layouts/print.blade.php to leave out the toolbar and
        // the letterhead: both are supplied above, and the letterhead has to
        // be, or it would print once instead of on every page.
        $mpdf->WriteHTML(view($view, $viewData + ['pdf' => true])->render());

        return $mpdf->Output('', 'S');
    }

    /**
     * @param array<string,mixed> $brand
     */
    private function header(ReportDataset $data, array $brand): string
    {
        $title = e($data->title);
        $meta  = e($data->meta);

        if ($brand['header_path']) {
            $src = $this->embed($brand['header_path']);

            return <<<HTML
            <div style="border-bottom:1.5px solid #1c1917;padding-bottom:5px;">
              <img src="{$src}" style="width:100%;">
              <table width="100%" style="margin-top:4px;"><tr>
                <td style="font-family:serif;font-size:13pt;">{$title}</td>
                <td align="right" style="font-size:8pt;color:#57534e;">{$meta}</td>
              </tr></table>
            </div>
            HTML;
        }

        $name = e($brand['name']);
        $logo = '';

        if ($brand['logo']) {
            $file = public_path(MuseumInfo::LOGO_DIR . '/' . basename(parse_url($brand['logo'], PHP_URL_PATH) ?: ''));
            if (is_file($file)) {
                $logo = '<td width="52"><img src="' . $this->embed($file) . '" style="width:44px;"></td>';
            }
        }

        return <<<HTML
        <table width="100%" style="border-bottom:1.5px solid #1c1917;padding-bottom:5px;"><tr>
          {$logo}
          <td>
            <div style="font-size:7.5pt;font-weight:bold;color:#57534e;letter-spacing:.5pt;">{$name}</div>
            <div style="font-family:serif;font-size:13pt;">{$title}</div>
          </td>
          <td align="right" valign="bottom" style="font-size:8pt;color:#57534e;">{$meta}</td>
        </tr></table>
        HTML;
    }

    /**
     * A branding image as a data URI.
     *
     * Not a path and not a file:// URL. mPDF resolves a bare relative path
     * against its own basepath rather than the filesystem, and a Windows
     * absolute path turns into the malformed "file://C:/..." - both of
     * which fail the way this one did: no error, no image, a letterhead
     * quietly missing from every PDF while the HTML page showed it fine.
     * Inlining the bytes removes the question. mPDF stores one copy of an
     * image however many pages repeat it, so the size cost is paid once.
     */
    private function embed(string $path): string
    {
        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png'          => 'image/png',
            'webp'         => 'image/webp',
            'jpg', 'jpeg'  => 'image/jpeg',
            default        => mime_content_type($path) ?: 'image/png',
        };

        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
    }

    /**
     * Page numbers only.
     *
     * The typed footer line is gone - an uploaded letterhead already
     * carries the address and the phone number. A page count is not
     * branding, though: a twenty-page trail that has been put down and
     * picked up again needs it.
     */
    private function footer(): string
    {
        return <<<HTML
        <table width="100%" style="padding-top:3px;font-size:7.5pt;color:#78716c;"><tr>
          <td align="right">Page {PAGENO} of {nbpg}</td>
        </tr></table>
        HTML;
    }
}
