<?php

declare(strict_types=1);

namespace App\Search;

use App\Documents\DocumentPrecedenceService;
use App\Models\DocumentChunk;
use App\Search\Embeddings\VectorStore;
use Illuminate\Support\Facades\DB;

/**
 * Búsqueda en la documentación: texto completo + semántica sobre fragmentos de documentos vigentes, combinadas con
 * pesos explícitos. Si el proveedor de embeddings cae, sigue funcionando con texto completo (spec §224).
 */
final class KnowledgeSearchService
{
    private const INJECTION = '/ignor(e|á|a)\s+(all\s+|the\s+|las\s+|todas\s+las\s+)?(previous|prior|instrucciones|instructions)|system prompt|you are now|olvid(a|á) (tus|las) (reglas|instrucciones)/iu';

    public function __construct(private readonly VectorStore $vectors) {}

    /** @return list<array<string, mixed>> */
    public function search(string $query, int $limit = 5, ?array $documentIds = null): array
    {
        $documentIds ??= DocumentPrecedenceService::currentIds();
        $scores = [];
        $semanticAvailable = false;

        // Texto completo
        $q = DocumentChunk::query()->whereIn('document_id', $documentIds);
        if (DB::getDriverName() === 'mysql') {
            $terms = trim(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $query) ?? '');
            if ($terms !== '') {
                $rows = $q->selectRaw('id, MATCH(content) AGAINST (? IN NATURAL LANGUAGE MODE) AS s', [$terms])
                    ->whereRaw('MATCH(content) AGAINST (? IN NATURAL LANGUAGE MODE)', [$terms])->orderByDesc('s')->limit(20)->get();
                $max = max(1e-9, (float) ($rows->max('s') ?? 0));
                foreach ($rows as $r) {
                    $scores[$r->id]['text'] = round((float) $r->s / $max, 4);
                }
            }
        } else {
            foreach (array_filter(preg_split('/\s+/u', mb_strtolower($query)) ?: [], fn ($w) => mb_strlen($w) > 3) as $w) {
                foreach ((clone $q)->whereRaw('LOWER(content) LIKE ?', ['%'.$w.'%'])->limit(20)->pluck('id') as $id) {
                    $scores[$id]['text'] = min(1.0, ($scores[$id]['text'] ?? 0) + 0.25);
                }
            }
        }

        // Semántica (si el proveedor está disponible)
        try {
            if ($this->vectors->provider()->available()) {
                $semanticAvailable = true;
                foreach ($this->vectors->search($query, 20, $documentIds) as $hit) {
                    $scores[$hit['chunk_id']]['semantic'] = $hit['score'];
                }
            }
        } catch (\Throwable) {
            $semanticAvailable = false;
        }

        $ranked = [];
        foreach ($scores as $id => $s) {
            $final = 0.45 * ($s['text'] ?? 0) + 0.55 * max(0, (($s['semantic'] ?? 0) - 0.3) / 0.7);
            $ranked[$id] = ['text' => $s['text'] ?? 0, 'semantic' => $s['semantic'] ?? null, 'score' => round($final, 4)];
        }
        uasort($ranked, fn ($a, $b) => $b['score'] <=> $a['score']);
        $ranked = array_slice($ranked, 0, $limit, true);
        $chunks = DocumentChunk::query()->with('document')->whereIn('id', array_keys($ranked))->get()->keyBy('id');

        return collect($ranked)->map(function ($s, $id) use ($chunks, $semanticAvailable) {
            $c = $chunks[$id];

            return ['chunk_id' => $id, 'document_id' => $c->document_id, 'document' => $c->document->title, 'page' => $c->page,
                'kind' => $c->kind, 'table_id' => $c->table_id, 'score' => $s['score'], 'text_score' => $s['text'], 'semantic_score' => $s['semantic'],
                'semantic_available' => $semanticAvailable, 'injection_flag' => (bool) preg_match(self::INJECTION, $c->content),
                'content' => $c->content];
        })->values()->all();
    }
}
