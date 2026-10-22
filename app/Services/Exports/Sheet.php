<?php

namespace App\Services\Exports;

use DateTimeInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * One worksheet written row by row, like the ExcelJS `addRow` calls of the original exports.
 * Every string is written as an explicit string, so nothing a user typed becomes a formula.
 */
final class Sheet
{
    public readonly Worksheet $ws;

    private int $row = 0;

    public function __construct(Spreadsheet $wb, string $title)
    {
        $this->ws = new Worksheet($wb, $title);
        $wb->addSheet($this->ws);
    }

    /** @param  list<int|float>  $widths  column widths in Excel units, from column A. */
    public function widths(array $widths): self
    {
        foreach (array_values($widths) as $i => $w) {
            $this->ws->getColumnDimension(self::col($i + 1))->setWidth($w);
        }

        return $this;
    }

    /** Appends a row (empty values leave the cell blank) and returns its number. */
    public function add(array $values = []): int
    {
        $this->row++;
        foreach (array_values($values) as $i => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $cell = $this->ws->getCell(self::col($i + 1).$this->row);
            if ($v instanceof DateTimeInterface) {
                $cell->setValueExplicit(ExcelDate::PHPToExcel($v), DataType::TYPE_NUMERIC);
            } elseif (is_int($v) || is_float($v)) {
                $cell->setValueExplicit($v, DataType::TYPE_NUMERIC);
            } else {
                $cell->setValueExplicit((string) $v, DataType::TYPE_STRING);
            }
        }

        return $this->row;
    }

    public function rowCount(): int
    {
        return $this->row;
    }

    /** Bold, wrapped header row with the grey fill. */
    public function header(int $row, int $columns): void
    {
        $style = $this->ws->getStyle(self::range($row, $columns));
        $style->getFont()->setBold(true);
        $style->getAlignment()->setVertical('center')->setWrapText(true);
        $this->fill(self::range($row, $columns), Sheets::HEADER_FILL);
    }

    public function fill(string $range, string $argb): void
    {
        $this->ws->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($argb);
    }

    public function numFmt(string $cell, string $format): void
    {
        $this->ws->getStyle($cell)->getNumberFormat()->setFormatCode($format);
    }

    /** Number format on columns (1-based) of a row. */
    public function numFmtCols(int $row, array $cols, string $format): void
    {
        foreach ($cols as $c) {
            $this->numFmt(self::col($c).$row, $format);
        }
    }

    public function bold(string $range, ?float $size = null): void
    {
        $font = $this->ws->getStyle($range)->getFont()->setBold(true);
        if ($size) {
            $font->setSize($size);
        }
    }

    public function alignLeft(string $range): void
    {
        $this->ws->getStyle($range)->getAlignment()->setHorizontal('left');
    }

    public function wrapTop(string $range): void
    {
        $this->ws->getStyle($range)->getAlignment()->setVertical('top')->setWrapText(true);
    }

    public function freeze(string $cell): void
    {
        $this->ws->freezePane($cell);
    }

    public static function col(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index);
    }

    /** "A3:F3" (from column A to the given column count). */
    public static function range(int $row, int $columns): string
    {
        return 'A'.$row.':'.self::col(max(1, $columns)).$row;
    }
}
