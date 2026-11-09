<?php

declare(strict_types=1);

namespace App\Documents;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Texto por página con disposición conservada, vía poppler (`pdftotext -layout`, `pdfinfo`). Requisito de sistema
 * documentado en docs/deployment.md. Nunca ejecuta contenido del PDF.
 */
final class PdfTextExtractor
{
    public function pageCount(string $path): int
    {
        $p = new Process(['pdfinfo', $path]);
        $p->setTimeout(30)->run();
        if (! $p->isSuccessful() || ! preg_match('/^Pages:\s+(\d+)/m', $p->getOutput(), $m)) {
            throw new RuntimeException('pdfinfo no pudo leer el PDF: '.trim($p->getErrorOutput()));
        }

        return (int) $m[1];
    }

    public function page(string $path, int $page): string
    {
        $p = new Process(['pdftotext', '-layout', '-enc', 'UTF-8', '-f', (string) $page, '-l', (string) $page, $path, '-']);
        $p->setTimeout(60)->run();
        if (! $p->isSuccessful()) {
            throw new RuntimeException("pdftotext falló en la página {$page}: ".trim($p->getErrorOutput()));
        }

        return rtrim($p->getOutput());
    }
}
