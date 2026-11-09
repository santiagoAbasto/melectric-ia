<?php

declare(strict_types=1);

namespace App\Search\Embeddings;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Embeddings locales con Ollama (nomic-embed-text). Nada sale de la máquina. */
final class OllamaEmbeddingProvider implements EmbeddingProviderInterface
{
    public function __construct(private readonly string $url, private readonly string $model = 'nomic-embed-text') {}

    public function model(): string
    {
        return 'ollama:'.$this->model;
    }

    public function available(): bool
    {
        try {
            return Http::timeout(2)->get($this->url.'/api/tags')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function embed(array $texts, bool $isQuery = false): array
    {
        $prefix = $isQuery ? 'search_query: ' : 'search_document: ';
        $res = Http::timeout(120)->post($this->url.'/api/embed', [
            'model' => $this->model,
            'input' => array_map(fn ($t) => $prefix.mb_substr($t, 0, 6000), $texts),
        ]);
        if (! $res->successful()) {
            throw new RuntimeException('Ollama embed falló: '.$res->status());
        }

        return array_map([Vector::class, 'normalize'], $res->json('embeddings'));
    }
}
