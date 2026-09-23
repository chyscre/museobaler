<?php

namespace App\Support\Reports;

use App\Models\MuseumInfo;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The spreadsheet.
 *
 * The reason this format exists at all is that the office wants to total a
 * column itself, so the one thing it must get right is cell types: a figure
 * declared numeric in the dataset is written as a number, never as a string
 * that merely looks like one. Everything else here - the letterhead, the
 * banding, the frozen header - is presentation.
 *
 * Each section becomes its own worksheet rather than being stacked on one,
 * because a sheet with three different column counts on it cannot be sorted
 * or filtered, which is the other half of why anyone opens a spreadsheet.
 */
class XlsxExporter
{
    /** Peso amounts, and the columns that get it. */
    private const MONEY = '#,##0.00';

    public function stream(ReportDataset $data): StreamedResponse
    {
        $path = $this->toTempFile($data);

        return response()->streamDownload(function () use ($path) {
            readfile($path);
            @unlink($path);
        }, $data->filenameFor('xlsx'), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function toFile(ReportDataset $data, string $path): void
    {
        $book = $this->build($data);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
    }

    private function toTempFile(ReportDataset $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rpt') . '.xlsx';
        $this->toFile($data, $path);

        return $path;
    }

    private function build(ReportDataset $data): Spreadsheet
    {
        $brand = MuseumInfo::branding();
        $book  = new Spreadsheet();
        $book->getProperties()->setTitle($data->title)->setSubject($data->meta);

        foreach ($data->sections as $i => $section) {
            $sheet = $i === 0 ? $book->getActiveSheet() : $book->createSheet();
            $sheet->setTitle($this->sheetName($section->heading ?? $data->title, $i));
            $this->fill($sheet, $data, $section, $brand, $i === 0);
        }

        $book->setActiveSheetIndex(0);

        return $book;
    }

    /**
     * @param array<string,mixed> $brand
     */
    private function fill(Worksheet $sheet, ReportDataset $data, ReportSection $section, array $brand, bool $first): void
    {
        $width = max(count($section->columns), 1);
        $last  = $sheet->getCell([$width, 1])->getColumn();
        $row   = 1;

        // The uploaded letterhead, anchored at A1. Rows are heightened to
        // clear it rather than the image being shrunk to fit them, so a wide
        // banner keeps its proportions.
        if ($brand['header_path']) {
            $drawing = new Drawing();
            $drawing->setPath($brand['header_path']);
            $drawing->setHeight(64);
            $drawing->setCoordinates('A1');
            $drawing->setWorksheet($sheet);

            $sheet->getRowDimension(1)->setRowHeight(54);
            $row = 2;
        } else {
            $sheet->setCellValue([1, $row], $brand['name']);
            $sheet->getStyle([1, $row])->getFont()->setBold(true)->setSize(9);
            $sheet->getStyle([1, $row])->getFont()->getColor()->setARGB('FF57534E');
            $row++;
        }

        $sheet->setCellValue([1, $row], $data->title);
        $sheet->mergeCells("A{$row}:{$last}{$row}");
        $sheet->getStyle([1, $row])->getFont()->setBold(true)->setSize(15);
        $row++;

        $sheet->setCellValue([1, $row], $data->meta);
        $sheet->mergeCells("A{$row}:{$last}{$row}");
        $sheet->getStyle([1, $row])->getFont()->setSize(9)->getColor()->setARGB('FF78716C');
        $row += 2;

        // The headline figures, once, on the first sheet only - repeating
        // them above every table would just be noise to scroll past.
        if ($first && $data->summary !== []) {
            foreach ($data->summary as $label => $value) {
                $sheet->setCellValue([1, $row], $label);
                $sheet->getStyle([1, $row])->getFont()->setBold(true)->setSize(9);
                $sheet->setCellValueExplicit([2, $row], $value, DataType::TYPE_STRING);
                $row++;
            }
            $row++;
        }

        if ($section->heading) {
            $sheet->setCellValue([1, $row], $section->heading);
            $sheet->getStyle([1, $row])->getFont()->setBold(true)->setSize(11);
            $row++;
        }

        $headerRow = $row;
        foreach ($section->columns as $c => $label) {
            $sheet->setCellValue([$c + 1, $row], $label);
        }
        $sheet->getStyle("A{$row}:{$last}{$row}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1C1917']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(20);
        $row++;

        $firstDataRow = $row;
        foreach ($section->rows as $line) {
            foreach ($line as $c => $cell) {
                $this->put($sheet, $c + 1, $row, $cell, in_array($c, $section->numeric, true));
            }
            $row++;
        }
        $lastDataRow = $row - 1;

        if ($section->footer !== []) {
            foreach ($section->footer as $c => $cell) {
                $this->put($sheet, $c + 1, $row, $cell, in_array($c, $section->numeric, true));
            }
            $sheet->getStyle("A{$row}:{$last}{$row}")->applyFromArray([
                'font'    => ['bold' => true],
                'borders' => ['top' => ['borderStyle' => Border::BORDER_THIN]],
            ]);
            $row++;
        }

        if ($lastDataRow >= $firstDataRow) {
            $sheet->getStyle("A{$firstDataRow}:{$last}{$lastDataRow}")
                ->getBorders()->getBottom()
                ->setBorderStyle(Border::BORDER_HAIR)
                ->getColor()->setARGB('FFE7E5E4');

            // Money columns read as money. Detected by header rather than
            // configured per report: every report that has one calls it the
            // same thing, and a missed format is cosmetic, not wrong.
            foreach ($section->numeric as $c) {
                $name = strtolower($section->columns[$c] ?? '');
                if (str_contains($name, 'fee') || str_contains($name, 'collect') || str_contains($name, 'refund')) {
                    $col = $sheet->getCell([$c + 1, $firstDataRow])->getColumn();
                    $sheet->getStyle("{$col}{$firstDataRow}:{$col}{$row}")
                        ->getNumberFormat()->setFormatCode(self::MONEY);
                }
            }

            $sheet->setAutoFilter("A{$headerRow}:{$last}{$lastDataRow}");
        }

        // Freeze everything above the first data row so the letterhead and
        // the column names stay put on a long scroll.
        $sheet->freezePane("A{$firstDataRow}");

        foreach (range(1, $width) as $c) {
            $sheet->getColumnDimension($sheet->getCell([$c, $headerRow])->getColumn())->setAutoSize(true);
        }

        $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);

        // Page numbers only. The typed footer line is gone; an uploaded
        // letterhead already carries the address.
        $sheet->getHeaderFooter()->setOddFooter('&R&9Page &P of &N');
    }

    private function put(Worksheet $sheet, int $col, int $row, mixed $value, bool $numeric): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if ($numeric && is_numeric($value)) {
            $sheet->setCellValue([$col, $row], $value + 0);

            return;
        }

        // Explicitly a string, so a control number like "0012" keeps its
        // leading zeros and a code like "1-2" is not read as a date.
        $sheet->setCellValueExplicit([$col, $row], (string) $value, DataType::TYPE_STRING);
    }

    /** Excel rejects some characters in a tab name and caps it at 31. */
    private function sheetName(string $raw, int $i): string
    {
        $clean = trim(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $raw));

        return mb_substr($clean !== '' ? $clean : 'Sheet ' . ($i + 1), 0, 31);
    }
}
