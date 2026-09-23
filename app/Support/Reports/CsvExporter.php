<?php

namespace App\Support\Reports;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The pipeline format.
 *
 * Deliberately the plainest of the four: the primary section's header row,
 * then its rows, and nothing else. No letterhead, no totals row, no summary
 * strip - a CSV that carries those is a CSV that no longer parses cleanly,
 * and the whole point of this tier is that something downstream reads it
 * without a human in the way.
 *
 * Numbers are written unformatted for the same reason. "1,250.00" with a
 * thousands separator is a string to every parser on earth; 1250.00 is a
 * number. The formatted version belongs in XLSX and PDF, where a person is
 * reading it.
 */
class CsvExporter
{
    /** A download for the browser. */
    public function stream(ReportDataset $data): StreamedResponse
    {
        $section = $data->primarySection();

        return response()->streamDownload(function () use ($section) {
            $handle = fopen('php://output', 'w');
            $this->write($handle, $section);
            fclose($handle);
        }, $data->filenameFor('csv'), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** The same bytes, written to disk for the nightly batch. */
    public function toFile(ReportDataset $data, string $path): void
    {
        $handle = fopen($path, 'w');
        $this->write($handle, $data->primarySection());
        fclose($handle);
    }

    /**
     * @param resource $handle
     */
    private function write($handle, ReportSection $section): void
    {
        // Excel reads a UTF-8 CSV as Windows-1252 unless the BOM is there,
        // which turns every Filipino name with an enye in it into mojibake.
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, $section->columns);

        foreach ($section->rows as $row) {
            fputcsv($handle, array_map(
                fn ($cell) => $cell === null ? '' : $cell,
                $row
            ));
        }
    }
}
