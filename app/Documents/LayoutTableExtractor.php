<?php

declare(strict_types=1);

namespace App\Documents;

/**
 * Detecta tablas en el texto con disposición (`pdftotext -layout`). Una tabla es un bloque de líneas consecutivas con
 * 3 o más celdas separadas por 2+ espacios. Las columnas se reconstruyen agrupando la posición de inicio de cada celda,
 * así una celda vacía no corre las demás. Conserva las líneas de encabezado de arriba tal cual y calcula una confianza
 * por consistencia de columnas: lo dudoso queda NEEDS_REVIEW, nunca se descarta.
 */
final class LayoutTableExtractor
{
    public const VERSION = 'layout_table@1';

    /**
     * @return list<array{title:?string, header_lines:list<string>, rows:list<list<string>>, col_count:int, confidence:float, first_line:int}>
     */
    public function extract(string $pageText): array
    {
        $lines = preg_split('/\R/u', $pageText) ?: [];
        $tables = [];
        $block = [];
        $gap = 0;
        foreach ($lines as $i => $line) {
            $cells = $this->cells($line);
            if (count($cells) >= 3 && $this->dataLike($cells)) {
                $block[] = [$i, $cells];
                $gap = 0;
            } elseif (trim($line) === '' && $block !== [] && $gap < 1) {
                $gap++;
            } else {
                $this->flush($block, $lines, $tables);
                $block = [];
                $gap = 0;
            }
        }
        $this->flush($block, $lines, $tables);

        return $tables;
    }

    /** @return list<array{0:int,1:string}> [posición, texto] */
    private function cells(string $line): array
    {
        $out = [];
        if (preg_match_all('/\S+(?: \S+)*/u', $line, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$text, $byteOffset]) {
                $out[] = [mb_strlen(substr($line, 0, $byteOffset)), $text];
            }
        }

        return $out;
    }

    /** Al menos dos celdas con dígitos: descarta párrafos a dos columnas. */
    private function dataLike(array $cells): bool
    {
        $numeric = 0;
        foreach ($cells as [, $t]) {
            $numeric += (int) (bool) preg_match('/\d/u', $t);
        }

        return $numeric >= 2 && $numeric / count($cells) >= 0.4;
    }

    private function flush(array $block, array $lines, array &$tables): void
    {
        if (count($block) < 3) {
            return;
        }
        // Columnas: posiciones de inicio agrupadas (tolerancia 3 caracteres)
        $starts = [];
        foreach ($block as [, $cells]) {
            foreach ($cells as [$pos]) {
                $starts[] = $pos;
            }
        }
        sort($starts);
        $cols = [];
        foreach ($starts as $p) {
            if ($cols === [] || $p - end($cols)['max'] > 3) {
                $cols[] = ['min' => $p, 'max' => $p, 'n' => 1];
            } else {
                $cols[array_key_last($cols)]['max'] = $p;
                $cols[array_key_last($cols)]['n']++;
            }
        }
        $minHits = max(2, (int) ceil(count($block) * 0.15));
        $cols = array_values(array_filter($cols, fn ($c) => $c['n'] >= $minHits));
        if (count($cols) < 3) {
            return;
        }
        $rows = [];
        $filled = 0;
        foreach ($block as [, $cells]) {
            $row = array_fill(0, count($cols), '');
            foreach ($cells as [$pos, $text]) {
                $idx = 0;
                foreach ($cols as $k => $c) {
                    if ($pos >= $c['min'] - 3) {
                        $idx = $k;
                    }
                }
                $row[$idx] = trim($row[$idx].' '.$text);
            }
            $filled += count(array_filter($row, fn ($c) => $c !== '')) / count($cols);
            $rows[] = $row;
        }
        $firstLine = $block[0][0];
        $header = [];
        for ($j = $firstLine - 1; $j >= max(0, $firstLine - 12) && count($header) < 8; $j--) {
            if (trim($lines[$j]) !== '') {
                array_unshift($header, rtrim($lines[$j]));
            }
        }
        $title = null;
        foreach ($header as $h) {
            if (preg_match('/\b(Tabla|Table|Cuadro|Datos t[eé]cnicos|Referencias?)\b/iu', $h)) {
                $title = trim(preg_replace('/\s{2,}/', ' · ', trim($h)) ?? $h);
                break;
            }
        }
        $consistency = $filled / count($rows);
        $confidence = round(min(0.95, 0.35 + 0.5 * $consistency + min(0.1, count($rows) / 100)), 3);
        $tables[] = ['title' => $title, 'header_lines' => $header, 'rows' => $rows, 'col_count' => count($cols), 'confidence' => $confidence, 'first_line' => $firstLine];
    }
}
