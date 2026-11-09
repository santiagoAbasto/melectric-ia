<?php

declare(strict_types=1);

namespace App\Vision;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * OCR local con el framework Vision de macOS (bin/ocr-macos, compilado de bin/ocr-macos.swift). Sólo para desarrollo en
 * Mac: en un servidor Linux hay que enchufar otro OcrProviderInterface (tesseract o un modelo de visión con credenciales).
 */
final class MacVisionOcrProvider implements OcrProviderInterface
{
    public function name(): string
    {
        return 'macos-vision';
    }

    public function available(): bool
    {
        return PHP_OS_FAMILY === 'Darwin' && is_executable(base_path('bin/ocr-macos'));
    }

    public function read(string $imagePath): array
    {
        $p = new Process([base_path('bin/ocr-macos'), $imagePath]);
        $p->setTimeout(120)->run();
        if (! $p->isSuccessful()) {
            throw new RuntimeException('OCR falló: '.trim($p->getErrorOutput()));
        }

        return array_map(fn ($l) => ['text' => (string) $l['text'], 'confidence' => round((float) $l['confidence'], 3)], json_decode($p->getOutput(), true) ?? []);
    }
}
