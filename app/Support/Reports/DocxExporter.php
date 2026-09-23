<?php

namespace App\Support\Reports;

use App\Models\MuseumInfo;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Writer\Word2007;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The editable report.
 *
 * Offered on the two narrative reports - exhibit engagement and feedback -
 * because those are the ones somebody rewrites before sending: a paragraph
 * of interpretation added above the numbers, a sentence about why a month
 * was quiet. A PDF cannot take that and a spreadsheet is the wrong shape
 * for it.
 *
 * The letterhead goes in the section header, which is what makes Word repeat
 * it on page two onwards and keeps it there after the text has been edited
 * and reflowed.
 */
class DocxExporter
{
    public function stream(ReportDataset $data): StreamedResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'rpt') . '.docx';
        $this->toFile($data, $path);

        return response()->streamDownload(function () use ($path) {
            readfile($path);
            @unlink($path);
        }, $data->filenameFor('docx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    public function toFile(ReportDataset $data, string $path): void
    {
        $brand = MuseumInfo::branding();

        $word = new PhpWord();
        $word->getSettings()->setUpdateFields(true);
        $word->setDefaultFontName('Calibri');
        $word->setDefaultFontSize(10);

        $word->getDocInfo()->setTitle($data->title)->setSubject($data->meta);

        $section = $word->addSection([
            'marginTop'    => Converter::cmToTwip(1.6),
            'marginBottom' => Converter::cmToTwip(1.6),
            'marginLeft'   => Converter::cmToTwip(1.8),
            'marginRight'  => Converter::cmToTwip(1.8),
        ]);

        $this->letterhead($section, $brand);
        $this->title($section, $data);
        $this->summary($section, $data);

        foreach ($data->sections as $s) {
            $this->table($section, $s);
        }

        $this->footer($section);

        (new Word2007($word))->save($path);
    }

    /**
     * @param array<string,mixed> $brand
     */
    private function letterhead(Section $section, array $brand): void
    {
        $header = $section->addHeader();

        if ($brand['header_path']) {
            // Full text width, height left to scale: a banner that is forced
            // to a fixed height on a page it was not drawn for comes out
            // stretched, and this is the museum's own artwork.
            $header->addImage($brand['header_path'], [
                'width'     => Converter::cmToPoint(17.4),
                'height'    => null,
                'alignment' => Jc::CENTER,
            ]);

            return;
        }

        if ($brand['logo']) {
            $logo = public_path(MuseumInfo::LOGO_DIR . '/' . basename(parse_url($brand['logo'], PHP_URL_PATH) ?: ''));
            if (is_file($logo)) {
                $header->addImage($logo, ['height' => 40, 'alignment' => Jc::CENTER]);
            }
        }

        $header->addText(
            htmlspecialchars($brand['name'], ENT_QUOTES),
            ['bold' => true, 'size' => 9, 'color' => '57534E'],
            ['alignment' => Jc::CENTER, 'spaceAfter' => 0]
        );
    }

    private function title(Section $section, ReportDataset $data): void
    {
        $section->addText(
            htmlspecialchars($data->title, ENT_QUOTES),
            ['bold' => true, 'size' => 17],
            ['spaceAfter' => 40]
        );
        $section->addText(
            htmlspecialchars($data->meta, ENT_QUOTES),
            ['size' => 9, 'color' => '78716C'],
            ['spaceAfter' => 200]
        );
    }

    private function summary(Section $section, ReportDataset $data): void
    {
        if ($data->summary === []) {
            return;
        }

        foreach ($data->summary as $label => $value) {
            $run = $section->addTextRun(['spaceAfter' => 20]);
            $run->addText(htmlspecialchars($label, ENT_QUOTES) . ':  ', ['bold' => true, 'size' => 9]);
            $run->addText(htmlspecialchars((string) $value, ENT_QUOTES), ['size' => 9]);
        }

        $section->addTextBreak(1);
    }

    private function table(Section $section, ReportSection $data): void
    {
        if ($data->heading) {
            $section->addText(
                htmlspecialchars($data->heading, ENT_QUOTES),
                ['bold' => true, 'size' => 12],
                ['spaceBefore' => 200, 'spaceAfter' => 80]
            );
        }

        if ($data->isEmpty()) {
            $section->addText('No entries for this period.', ['italic' => true, 'size' => 9, 'color' => '78716C']);

            return;
        }

        $table = $section->addTable([
            'borderSize'  => 6,
            'borderColor' => 'E7E5E4',
            'cellMargin'  => 60,
            'width'       => 100 * 50,
            'unit'        => 'pct',
        ]);

        // repeatAsHeaderRow is what puts the column names back at the top of
        // every page once the table runs past one.
        $table->addRow(null, ['tblHeader' => true]);
        foreach ($data->columns as $label) {
            $cell = $table->addCell(null, ['bgColor' => '1C1917']);
            $cell->addText(
                htmlspecialchars($label, ENT_QUOTES),
                ['bold' => true, 'size' => 8, 'color' => 'FFFFFF'],
                ['spaceAfter' => 0]
            );
        }

        foreach ($data->rows as $line) {
            $table->addRow();
            foreach ($line as $cell) {
                $table->addCell()->addText(
                    htmlspecialchars($this->text($cell), ENT_QUOTES),
                    ['size' => 8],
                    ['spaceAfter' => 0]
                );
            }
        }

        if ($data->footer !== []) {
            $table->addRow();
            foreach ($data->footer as $cell) {
                $table->addCell()->addText(
                    htmlspecialchars($this->text($cell), ENT_QUOTES),
                    ['size' => 8, 'bold' => true],
                    ['spaceAfter' => 0]
                );
            }
        }
    }

    /**
     * Page numbers only - the typed footer line is gone, because an
     * uploaded letterhead already carries the address and phone number.
     */
    private function footer(Section $section): void
    {
        $footer = $section->addFooter();

        $footer->addPreserveText(
            'Page {PAGE} of {NUMPAGES}',
            ['size' => 8, 'color' => '78716C'],
            ['alignment' => Jc::CENTER]
        );
    }

    private function text(mixed $cell): string
    {
        if ($cell === null) {
            return '';
        }

        return is_float($cell) ? rtrim(rtrim(number_format($cell, 2, '.', ''), '0'), '.') : (string) $cell;
    }
}
