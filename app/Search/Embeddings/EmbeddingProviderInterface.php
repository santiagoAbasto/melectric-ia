<?php

declare(strict_types=1);

namespace App\Search\Embeddings;

interface EmbeddingProviderInterface
{
    public function model(): string;

    public function available(): bool;

    /**
     * @param  list<string>  $texts
     * @return list<list<float>> vectores normalizados (norma 1)
     */
    public function embed(array $texts, bool $isQuery = false): array;
}
