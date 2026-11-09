<?php

declare(strict_types=1);

namespace App\Search\Embeddings;

/**
 * Sólo para pruebas: vector por bolsa de palabras con hashing (64 dimensiones). Determinista y sin red.
 * Nunca se registra en producción (ver AppServiceProvider).
 */
final class FakeEmbeddingProvider implements EmbeddingProviderInterface
{
    public function model(): string
    {
        return 'fake:hash64';
    }

    public function available(): bool
    {
        return true;
    }

    public function embed(array $texts, bool $isQuery = false): array
    {
        return array_map(function (string $t) {
            $v = array_fill(0, 64, 0.0);
            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($t)) ?: [] as $w) {
                if ($w !== '') {
                    $v[crc32($w) % 64] += 1.0;
                }
            }

            return Vector::normalize($v);
        }, $texts);
    }
}
