<?php

namespace App\Support\Reports;

/**
 * One table inside a report.
 *
 * A report is rarely a single grid - the feedback report alone carries the
 * CSM figures, the star breakdown and the per-guide averages - so an export
 * is a list of these rather than one set of columns.
 *
 * `numeric` is the part that earns XLSX its place. Without it every cell
 * lands in Excel as text and the museum cannot sum a column, which is the
 * only reason anyone asked for a spreadsheet instead of a PDF.
 */
final class ReportSection
{
    /**
     * @param list<string>                        $columns
     * @param list<list<string|int|float|null>>   $rows
     * @param list<string|int|float|null>         $footer  totals row, or [] for none
     * @param list<int>                           $numeric zero-based indexes of numeric columns
     */
    public function __construct(
        public readonly ?string $heading,
        public readonly array $columns,
        public readonly array $rows,
        public readonly array $footer = [],
        public readonly array $numeric = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
