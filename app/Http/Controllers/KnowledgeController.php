<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Catalog\Units\UnitService;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\KnowledgeConflict;
use App\Models\KnowledgeGap;
use App\Models\Product;
use App\Models\ProductRelationship;
use App\Models\ProductSpecification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Response;

class KnowledgeController extends Controller
{
    public function index(): Response
    {
        $spec = fn (ProductSpecification $s) => ['id' => $s->id, 'product_id' => $s->product_id, 'reference' => $s->product?->reference, 'field' => $s->definition?->label,
            'display' => UnitService::display($s), 'raw' => $s->raw_value, 'confidence' => $s->confidence, 'status' => $s->status, 'extractor' => $s->extractor,
            'source' => $s->document_id ? ($s->document?->title.' · pág. '.$s->page) : ('Excel · '.$s->sourceRecord?->locator),
            'source_url' => $s->document_id ? route('documents.file', $s->document_id, false).'#page='.$s->page : ($s->source_record_id ? route('sources.record', $s->source_record_id, false) : null)];

        return inertia('Knowledge/Index', [
            'quality' => [
                'products' => Product::query()->count(),
                'without_documents' => Product::query()->whereDoesntHave('documentLinks')->count(),
                'without_specs' => Product::query()->whereDoesntHave('specifications')->count(),
                'without_brand' => Product::query()->whereNull('brand_id')->count(),
                'relationships_unverified' => ProductRelationship::query()->where('status', 'AUTO_EXTRACTED')->count(),
                'relationships_verified' => ProductRelationship::query()->where('status', 'VERIFIED')->count(),
                'conflicts_open' => KnowledgeConflict::query()->where('status', 'OPEN')->count(),
                'low_confidence_specs' => ProductSpecification::query()->where('confidence', '<', 0.85)->whereIn('status', ['EXTRACTED', 'NEEDS_REVIEW'])->count(),
                'specs_needs_review' => ProductSpecification::query()->where('status', 'NEEDS_REVIEW')->count(),
                'documents_partial' => Document::query()->whereIn('processing_status', ['PARTIAL', 'NEEDS_REVIEW', 'FAILED'])->count(),
                'superseded_documents' => Document::query()->where('status', 'SUPERSEDED')->count(),
                'gaps' => KnowledgeGap::query()->where('status', 'OPEN')->count(),
            ],
            'conflicts' => KnowledgeConflict::query()->with('product')->orderByRaw("CASE status WHEN 'OPEN' THEN 0 ELSE 1 END")->latest()->limit(100)->get()
                ->map(fn ($c) => ['id' => $c->id, 'product_id' => $c->product_id, 'reference' => $c->product?->reference, 'field' => $c->field, 'value_a' => $c->value_a,
                    'source_a' => $c->source_a, 'value_b' => $c->value_b, 'source_b' => $c->source_b, 'status' => $c->status, 'resolution' => $c->resolution, 'note' => $c->resolution_note]),
            'review' => ProductSpecification::query()->with(['product', 'definition', 'document', 'sourceRecord'])->whereIn('status', ['EXTRACTED', 'NEEDS_REVIEW'])
                ->whereHas('definition', fn ($d) => $d->where('safety_critical', true))->orderBy('confidence')->limit(60)->get()->map($spec),
            'relationships' => ProductRelationship::query()->with(['product', 'relatedProduct'])->orderByRaw("CASE status WHEN 'AUTO_EXTRACTED' THEN 0 ELSE 1 END")->limit(60)->get()
                ->map(fn ($r) => ['id' => $r->id, 'type' => $r->type, 'status' => $r->status, 'from' => $r->product?->reference, 'to' => $r->relatedProduct?->reference,
                    'from_id' => $r->product_id, 'to_id' => $r->related_product_id, 'evidence' => $r->evidence, 'confidence' => $r->confidence]),
            'gaps' => KnowledgeGap::query()->orderByDesc('occurrences')->limit(50)->get(['id', 'example', 'intent', 'occurrences', 'status', 'updated_at']),
        ]);
    }

    public function resolveConflict(KnowledgeConflict $conflict, Request $request): RedirectResponse
    {
        $data = $request->validate(['resolution' => ['required', Rule::in(['A', 'B', 'NONE'])], 'note' => ['nullable', 'string', 'max:1000']]);
        $before = $conflict->only(['status', 'resolution']);
        $win = ['A' => $conflict->specA, 'B' => $conflict->specB][$data['resolution']] ?? null;
        $lose = ['A' => $conflict->specB, 'B' => $conflict->specA][$data['resolution']] ?? null;
        $win?->update(['status' => 'VERIFIED', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        $lose?->update(['status' => 'REJECTED', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        $conflict->update(['status' => $data['resolution'] === 'NONE' ? 'DISMISSED' : 'RESOLVED', 'resolution' => $data['resolution'],
            'resolution_note' => $data['note'] ?? null, 'resolved_by' => $request->user()->id, 'resolved_at' => now()]);
        AuditLog::record('knowledge.conflict_resolved', $conflict, $before, $conflict->only(['status', 'resolution', 'resolution_note']));

        return back()->with('message', 'Conflicto resuelto.');
    }

    public function reviewSpec(ProductSpecification $spec, Request $request): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['VERIFIED', 'REJECTED'])]]);
        $before = $spec->only(['status']);
        $spec->update(['status' => $data['status'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
        AuditLog::record('product.technical_field_reviewed', $spec, $before, $spec->only(['status']));

        return back();
    }

    public function reviewRelationship(ProductRelationship $relationship, Request $request): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['VERIFIED', 'REJECTED'])]]);
        $before = $relationship->only(['status']);
        $relationship->update(['status' => $data['status'], 'verified_by' => $request->user()->id, 'verified_at' => now()]);
        AuditLog::record('compatibility.changed', $relationship, $before, $relationship->only(['status']));

        return back();
    }
}
