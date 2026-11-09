<?php

declare(strict_types=1);

namespace App\Search\Embeddings;

final class Vector
{
    /** @param list<float> $v @return list<float> */
    public static function normalize(array $v): array
    {
        $norm = sqrt(array_sum(array_map(fn ($x) => $x * $x, $v)));

        return $norm > 0 ? array_map(fn ($x) => $x / $norm, $v) : $v;
    }

    /** Producto punto de vectores ya normalizados = coseno. */
    public static function dot(array $a, array $b): float
    {
        $s = 0.0;
        foreach ($a as $i => $x) {
            $s += $x * ($b[$i] ?? 0.0);
        }

        return $s;
    }
}
