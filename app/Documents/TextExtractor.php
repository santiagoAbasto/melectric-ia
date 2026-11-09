<?php

declare(strict_types=1);

namespace App\Documents;

use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use ZipArchive;

/**
 * Texto de fuentes que no son PDF: TXT, Markdown, CSV, DOCX y XLSX. Valida la firma real del archivo (no la
 * extensión), limita el tamaño descomprimido de los formatos ZIP (bombas de compresión) y nunca ejecuta macros.
 */
final class TextExtractor
{
    public const EXTENSIONS = ['txt', 'md', 'csv', 'docx', 'xlsx'];

    private const MAX_UNZIPPED = 25 * 1024 * 1024;

    private const MAX_ROWS = 5000;

    public function extract(string $path, string $extension): string
    {
        $ext = strtolower($extension);
        if (! in_array($ext, self::EXTENSIONS, true)) {
            throw new RuntimeException("Formato no soportado: .{$ext}");
        }
        $head = (string) file_get_contents($path, false, null, 0, 8);
        $zip = str_starts_with($head, "PK\x03\x04");
        if (in_array($ext, ['docx', 'xlsx'], true) && ! $zip) {
            throw new RuntimeException("El contenido no es un .{$ext} válido (firma ZIP ausente).");
        }
        if (in_array($ext, ['txt', 'md', 'csv'], true) && ($zip || str_contains((string) file_get_contents($path, false, null, 0, 4096), "\0"))) {
            throw new RuntimeException('El archivo no es texto plano.');
        }

        return match ($ext) {
            'txt', 'md' => $this->utf8((string) file_get_contents($path)),
            'csv' => $this->csv($path),
            'docx' => $this->docx($path),
            'xlsx' => $this->xlsx($path),
        };
    }

    private function utf8(string $s): string
    {
        return trim(mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252'));
    }

    private function csv(string $path): string
    {
        $out = [];
        $h = fopen($path, 'r');
        $first = (string) fgets($h);
        rewind($h);
        $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        while (($row = fgetcsv($h, 0, $sep, '"', '\\')) !== false && count($out) < self::MAX_ROWS) {
            $out[] = '| '.implode(' | ', array_map(fn ($c) => trim((string) $c), $row)).' |';
        }
        fclose($h);

        return $this->utf8(implode("\n", $out));
    }

    private function docx(string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('No se pudo abrir el .docx.');
        }
        $stat = $zip->statName('word/document.xml');
        if (! $stat || $stat['size'] > self::MAX_UNZIPPED) {
            $zip->close();
            throw new RuntimeException('El .docx no tiene contenido legible o es demasiado grande descomprimido.');
        }
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        $xml = str_replace(['</w:p>', '<w:tab/>', '<w:br/>'], ["\n", "\t", "\n"], $xml);

        return $this->utf8(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }

    private function xlsx(string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) === true) {
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $total += (int) ($zip->statIndex($i)['size'] ?? 0);
            }
            $zip->close();
            if ($total > self::MAX_UNZIPPED * 4) {
                throw new RuntimeException('El .xlsx es demasiado grande descomprimido.');
            }
        }
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $out = [];
        foreach ($reader->load($path)->getAllSheets() as $sheet) {
            $out[] = '## Hoja: '.$sheet->getTitle().($sheet->getSheetState() !== 'visible' ? ' (oculta)' : '');
            $n = 0;
            foreach ($sheet->toArray(null, true, false, false) as $row) {
                if (++$n > self::MAX_ROWS) {
                    $out[] = '(… filas truncadas)';
                    break;
                }
                $cells = array_map(fn ($c) => trim((string) $c), $row);
                if (implode('', $cells) !== '') {
                    $out[] = '| '.implode(' | ', $cells).' |';
                }
            }
        }

        return $this->utf8(implode("\n", $out));
    }
}
