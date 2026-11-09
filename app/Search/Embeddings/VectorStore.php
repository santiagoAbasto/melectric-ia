<?php

declare(strict_types=1);

namespace App\Search\Embeddings;

use App\Models\ChunkEmbedding;
use App\Models\DocumentChunk;

/**
 * Índice vectorial sobre la tabla chunk_embeddings, reconstruible desde document_chunks (la fuente de verdad).
 * ponytail: coseno por fuerza bruta en PHP; alcanza para ~20k fragmentos. Pasar a Qdrant (misma interfaz pública)
 * cuando el corpus crezca o la latencia de searchKnowledge pase de ~300 ms.
 */
final class VectorStore
{
    public function __construct(private readonly EmbeddingProviderInterface $provider) {}

    public function provider(): EmbeddingProviderInterface
    {
        return $this->provider;
    }

    /** @param iterable<DocumentChunk> $chunks @return int vectores escritos */
    public function index(iterable $chunks): int
    {
        $pending = collect($chunks)->filter(fn (DocumentChunk $c) => ! ChunkEmbedding::query()
            ->where('chunk_id', $c->id)->where('model', $this->provider->model())->where('content_hash', $c->content_hash)->exists());
        $written = 0;
        foreach ($pending->chunk(16) as $batch) {
            $vectors = $this->provider->embed($batch->pluck('content')->values()->all());
            foreach ($batch->values() as $i => $chunk) {
                ChunkEmbedding::query()->updateOrCreate(['chunk_id' => $chunk->id, 'model' => $this->provider->model()], [
                    'dimensions' => count($vectors[$i]), 'vector' => json_encode($vectors[$i]), 'content_hash' => $chunk->content_hash,
                ]);
                $written++;
            }
        }

        return $written;
    }

    /** @return list<array{chunk_id:int, score:float}> */
    public function search(string $query, int $limit = 8, ?array $documentIds = null): array
    {
        $q = $this->provider->embed([$query], true)[0];
        $scores = [];
        ChunkEmbedding::query()->where('model', $this->provider->model())
            ->when($documentIds !== null, fn ($b) => $b->whereIn('chunk_id', DocumentChunk::query()->whereIn('document_id', $documentIds)->select('id')))
            ->select(['id', 'chunk_id', 'vector'])->chunkById(500, function ($rows) use ($q, &$scores) {
                foreach ($rows as $row) {
                    $scores[$row->chunk_id] = Vector::dot($q, json_decode($row->vector, true));
                }
            }, 'id');
        arsort($scores);

        return collect($scores)->take($limit)->map(fn ($s, $id) => ['chunk_id' => (int) $id, 'score' => round($s, 4)])->values()->all();
    }
}
