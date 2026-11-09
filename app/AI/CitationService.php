<?php

declare(strict_types=1);

namespace App\AI;

use App\Models\Document;
use App\Models\ProductSpecification;
use App\Models\SourceRecord;

/**
 * Citas numeradas [n] para cada afirmación técnica. Una cita apunta a una fila exacta del Excel (hoja!fila) o a una
 * página de un PDF; la URL abre esa fila o el PDF en esa página.
 */
final class CitationService
{
    /** @var array<string, array{n:int, kind:string, label:string, url:?string, locator:string}> */
    private array $items = [];

    public function forSpec(ProductSpecification $spec): int
    {
        return $spec->document_id
            ? $this->forPage((int) $spec->document_id, (int) $spec->page)
            : $this->forRecord((int) $spec->source_record_id);
    }

    public function forRecord(int $recordId): int
    {
        $key = 'record:'.$recordId;
        if (! isset($this->items[$key])) {
            $r = SourceRecord::query()->with('source')->find($recordId);
            $this->items[$key] = ['n' => count($this->items) + 1, 'kind' => 'excel',
                'label' => 'Excel «'.($r?->source?->name ?? 'catálogo').'» · '.($r?->locator ?? '?'),
                'url' => $r ? route('sources.record', $r->id, false) : null, 'locator' => $r?->locator ?? ''];
        }

        return $this->items[$key]['n'];
    }

    public function forPage(int $documentId, int $page): int
    {
        $key = "document:{$documentId}:{$page}";
        if (! isset($this->items[$key])) {
            $d = Document::query()->find($documentId);
            $this->items[$key] = ['n' => count($this->items) + 1, 'kind' => 'document',
                'label' => ($d?->title ?? 'Documento').' · pág. '.$page.($d?->document_date ? ' · '.$d->document_date->format('m/Y') : ''),
                'url' => $d ? route('documents.file', $d->id, false).'#page='.$page : null, 'locator' => 'page:'.$page];
        }

        return $this->items[$key]['n'];
    }

    /** @return list<array{n:int, kind:string, label:string, url:?string, locator:string}> */
    public function all(): array
    {
        return array_values($this->items);
    }
}
