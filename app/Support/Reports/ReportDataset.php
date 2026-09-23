<?php

namespace App\Support\Reports;

/**
 * A report reduced to plain rows, ready for any exporter.
 *
 * The printed PDF still renders the Blade view, because that is the layout
 * the museum already checks and signs. This is the other half: the flat
 * shape that CSV, XLSX and DOCX all need, built once so those three cannot
 * drift apart from each other.
 */
final class ReportDataset
{
    /**
     * @param list<ReportSection>   $sections
     * @param array<string,string>  $summary headline figures, shown above the tables
     * @param int                   $primary index of the section that IS the data
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $meta,
        public readonly string $filename,
        public readonly array $sections,
        public readonly array $summary = [],
        public readonly int $primary = 0,
    ) {}

    /**
     * The one section a machine wants.
     *
     * CSV is the pipeline format, and a pipeline wants a rectangle: one
     * header row, then rows, nothing else. Stacking a report's summary
     * tables into the same file would give it three different column counts
     * and break every reader that opens it. So CSV exports this section
     * alone, and the rest of the tables are for the human formats, which can
     * lay several out on a page. It is also what keeps the CSVs the museum
     * already downloads byte-identical to what they were.
     */
    public function primarySection(): ReportSection
    {
        return $this->sections[$this->primary];
    }

    /** The filename the browser is offered, extension included. */
    public function filenameFor(string $format): string
    {
        return $this->filename . '.' . $format;
    }
}
