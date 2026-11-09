<?php

declare(strict_types=1);

namespace App\Vision;

interface OcrProviderInterface
{
    public function name(): string;

    public function available(): bool;

    /** @return list<array{text:string, confidence:float}> líneas de arriba hacia abajo */
    public function read(string $imagePath): array;
}
