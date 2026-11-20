<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

final class FormattedProjectExport
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';


    public static function create(iterable $records, string $templatePath): string
    {
        if (!is_file($templatePath)) {
            throw new RuntimeException('The formatted export template is missing.');
        }

        $path = tempnam(sys_get_temp_dir(), 'project-export-');
        if ($path === false || !copy($templatePath, $path)) {
            throw new RuntimeException('Could not prepare the export workbook.');
        }

        $zip = new ZipArchive();
        try {
            if ($zip->open($path) !== true) {
                throw new RuntimeException('Could not open the export workbook.');
            }

            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if ($xml === false) {
                throw new RuntimeException('The export worksheet is missing.');
            }

            $document = new DOMDocument('1.0', 'UTF-8');
            if (!$document->loadXML($xml)) {
                throw new RuntimeException('The export worksheet is invalid.');
            }
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('x', self::NS);
            $sheetData = $xpath->query('/x:worksheet/x:sheetData')->item(0);
            if (!$sheetData instanceof DOMElement) {
                throw new RuntimeException('The export worksheet has no rows.');
            }

            $templateRows = [];
            foreach ($xpath->query('./x:row', $sheetData) as $row) {
                $templateRows[(int) $row->getAttribute('r')] = $row;
            }
            if (!isset($templateRows[1], $templateRows[2])) {
                throw new RuntimeException('The export template has no formatted rows.');
            }
            $columnWidths = [];
            foreach ($xpath->query('/x:worksheet/x:cols/x:col') as $column) {
                for ($index = (int) $column->getAttribute('min'); $index <= (int) $column->getAttribute('max'); $index++) {
                    $columnWidths[$index] = (float) $column->getAttribute('width');
                }
            }

            $count = 0;
            foreach ($records as $record) {
                $record = TrackerProjectRevision::exportRecord($record);
                $count++;
                $rowNumber = $count + 1;
                $row = $templateRows[$rowNumber] ?? null;
                if (!$row) {
                    $source = $templateRows[$rowNumber % 2 === 0 ? 200 : 201] ?? $templateRows[2];
                    $row = $source->cloneNode(true);
                    $sheetData->appendChild($row);
                }
                $row->setAttribute('r', (string) $rowNumber);
                $cells = [];
                foreach ($xpath->query('./x:c', $row) as $cell) {
                    $cells[] = $cell;
                }
                $wrappedLines = 1;
                foreach (array_keys(TrackerProjectFields::columns()) as $index => $key) {
                    if (in_array($key, ['materialTestingDate','noaDueDate'], true) && !isset($cells[$index])) {
                        $cells[$index] = $cells[20]->cloneNode(true);
                        $row->appendChild($cells[$index]);
                    }
                    if (!isset($cells[$index])) {
                        throw new RuntimeException('The export template columns do not match the project data.');
                    }
                    $cell = $cells[$index];
                    $cell->setAttribute('r', self::columnLetter($index + 1).$rowNumber);
                    self::setCell($document, $cell, $key, TrackerProjectFields::isNotApplicable($record) && str_starts_with($key,'ld') ? TrackerProjectFields::display($record,$key) : ($record[$key] ?? ''));
                    $text = is_scalar($record[$key] ?? null) ? trim((string) $record[$key]) : '';
                    $usableWidth = max(8, (int) floor(($columnWidths[$index + 1] ?? 20) - 2));
                    $lines = 0;
                    foreach (explode("\n", str_replace("\r", '', $text)) as $part) {
                        $length = function_exists('mb_strlen') ? mb_strlen($part, 'UTF-8') : strlen($part);
                        $lines += max(1, (int) ceil($length / $usableWidth));
                    }
                    $wrappedLines = max($wrappedLines, $lines);
                }
                $row->setAttribute('ht', (string) min(409, max(22, $wrappedLines * 15 + 7)));
                $row->setAttribute('customHeight', '1');
            }

            foreach ($templateRows as $rowNumber => $row) {
                if ($rowNumber > $count + 1) {
                    $sheetData->removeChild($row);
                }
            }

            $lastRow = max(1, $count + 1);
            $range = 'A1:'.self::columnLetter(count(TrackerProjectFields::columns())).$lastRow;
            $dimension = $xpath->query('/x:worksheet/x:dimension')->item(0);
            $dimension?->setAttribute('ref', $range);
            $filter = $xpath->query('/x:worksheet/x:autoFilter')->item(0);
            $filter?->setAttribute('ref', $range);

            if (!$zip->addFromString('xl/worksheets/sheet1.xml', $document->saveXML())) {
                throw new RuntimeException('Could not write the export worksheet.');
            }
            if (!$zip->close()) {
                throw new RuntimeException('Could not finish the export workbook.');
            }

            return $path;
        } catch (\Throwable $error) {
            $zip->close();
            @unlink($path);
            throw $error;
        }
    }

    private static function setCell(DOMDocument $document, DOMElement $cell, string $key, mixed $value): void
    {
        while ($cell->firstChild) {
            $cell->removeChild($cell->firstChild);
        }
        $cell->removeAttribute('t');
        $value = is_scalar($value) ? trim((string) $value) : '';
        if ($value === '') {
            return;
        }

        if (in_array($key, TrackerProjectFields::dates(), true)) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
            if ($date && $date->format('Y-m-d') === $value) {
                $base = new \DateTimeImmutable('1899-12-30', new \DateTimeZone('UTC'));
                self::setNumber($document, $cell, (string) $base->diff($date)->format('%r%a'));
                return;
            }
        } elseif (in_array($key, ['approvedBudget','bidAmount','materialTestingAmount','ldAmount','losingBidAmount1','losingBidAmount2'], true)) {
            $amount = preg_replace('/[₱,$\s]/u', '', $value);
            if (preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
                self::setNumber($document, $cell, $amount);
                return;
            }
        } elseif ($key === 'physicalAccomplishment' && is_numeric($value) && (float)$value >= 0 && (float)$value <= 100) {
            self::setNumber($document, $cell, (string)((float)$value / 100));
            return;
        } elseif (in_array($key,['contractDuration','ldActualDuration'],true) && is_numeric($value)) {
            self::setNumber($document, $cell, $value);
            return;
        }

        $cell->setAttribute('t', 'inlineStr');
        $inline = $document->createElementNS(self::NS, 'is');
        $text = $document->createElementNS(self::NS, 't');
        $text->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
        $text->appendChild($document->createTextNode($value));
        $inline->appendChild($text);
        $cell->appendChild($inline);
    }

    private static function setNumber(DOMDocument $document, DOMElement $cell, string $value): void
    {
        $cell->setAttribute('t', 'n');
        $cell->appendChild($document->createElementNS(self::NS, 'v', $value));
    }

    private static function columnLetter(int $number): string
    {
        $letters = '';
        while ($number > 0) {
            $number--;
            $letters = chr(65 + $number % 26).$letters;
            $number = intdiv($number, 26);
        }
        return $letters;
    }
}
