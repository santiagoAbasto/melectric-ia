<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * Empareja cada línea con su imagen. Los nombres de archivo vienen con la codificación rota (CP437 leído como CP1252:
 * "Rel‚ T‚rmico" = "Relé Térmico"), así que se compara una clave sólo con letras ASCII y dígitos: las vocales acentuadas
 * se pierden de ambos lados por igual.
 */
final class LineImageMatcher
{
    /** @var array<string, string> clave => ruta */
    private array $byKey = [];

    public function __construct(string $directory)
    {
        foreach (glob(rtrim($directory, '/').'/*.{jpg,jpeg,png,webp,JPG,PNG}', GLOB_BRACE) ?: [] as $path) {
            $this->byKey[self::key(pathinfo($path, PATHINFO_FILENAME))] ??= $path;
        }
    }

    public static function key(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($name)) ?? '';
    }

    public function find(string $lineName): ?string
    {
        return $this->byKey[self::key($lineName)] ?? null;
    }

    public function count(): int
    {
        return count($this->byKey);
    }
}
