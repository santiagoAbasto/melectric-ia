<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Catalog\Units\UnitService;
use App\Models\Brand;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\DocumentProductLink;
use App\Models\KnowledgeConflict;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductRelationship;
use App\Search\KnowledgeSearchService;
use App\Search\ProductSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class CatalogController extends Controller
{
    public function index(Request $request, ProductSearchService $search): Response
    {
        $q = trim((string) $request->query('q'));
        $category = $request->integer('category') ?: null;
        $brand = $request->integer('brand') ?: null;
        $strategy = null;
        $query = Product::query()->with(['brand', 'family.category', 'specifications.definition']);
        if ($q !== '') {
            $result = $search->search($q, 200);
            $strategy = $result->strategy;
            $ids = array_column($result->candidates, 'product_id');
            $query->whereIn('id', $ids ?: [0]);
            if ($ids) {
                $query->orderByRaw('CASE id '.collect($ids)->map(fn ($id, $i) => 'WHEN '.(int) $id.' THEN '.$i)->join(' ').' END');
            }
        } else {
            $query->orderBy('family_id')->orderBy('reference');
        }
        $query->when($category, fn ($b) => $b->where('category_id', $category))->when($brand, fn ($b) => $b->where('brand_id', $brand));
        $page = $query->paginate(40)->withQueryString();

        return inertia('Products/Index', [
            'products' => $page->through(fn (Product $p) => $p->card()),
            'filters' => ['q' => $q, 'category' => $category, 'brand' => $brand],
            'strategy' => $strategy,
            'categories' => ProductCategory::query()->withCount('families')->orderBy('name')->get(['id', 'name', 'rubro'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'rubro' => $c->rubro, 'count' => Product::query()->where('category_id', $c->id)->count()]),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name']),
            'totals' => ['products' => Product::query()->count(), 'without_brand' => Product::query()->whereNull('brand_id')->count()],
        ]);
    }

    public function show(Product $product): Response
    {
        $product->load(['brand', 'family.category', 'aliases', 'specifications.definition', 'specifications.document', 'specifications.sourceRecord.source', 'sourceRecord.source']);

        return inertia('Products/Show', [
            'product' => $product->card() + ['base_reference' => $product->base_reference, 'normalized_reference' => $product->normalized_reference, 'series' => $product->series,
                'status' => $product->status, 'source' => ['label' => ($product->sourceRecord?->source?->name ?? '').' · '.$product->sourceRecord?->locator,
                    'url' => $product->source_record_id ? route('sources.record', $product->source_record_id, false) : null, 'payload' => $product->sourceRecord?->payload]],
            'aliases' => $product->aliases->map->only(['alias', 'normalized', 'kind']),
            'specifications' => $product->specifications->sortBy(fn ($s) => $s->definition->key)->values()->map(fn ($s) => [
                'id' => $s->id, 'key' => $s->definition->key, 'label' => $s->definition->label, 'display' => UnitService::display($s), 'raw' => $s->raw_value,
                'qualifier' => $s->qualifier, 'confidence' => $s->confidence, 'status' => $s->status, 'extractor' => $s->extractor, 'safety_critical' => $s->definition->safety_critical,
                'source' => $s->document_id ? ['label' => $s->document?->title.' · pág. '.$s->page, 'url' => route('documents.file', $s->document_id, false).'#page='.$s->page]
                    : ['label' => ($s->sourceRecord?->source?->name ?? 'Excel').' · '.$s->sourceRecord?->locator, 'url' => $s->source_record_id ? route('sources.record', $s->source_record_id, false) : null],
            ]),
            'relationships' => ProductRelationship::query()->with(['product', 'relatedProduct'])->where(fn ($b) => $b->where('product_id', $product->id)->orWhere('related_product_id', $product->id))
                ->limit(80)->get()->map(fn ($r) => ['id' => $r->id, 'type' => $r->type, 'status' => $r->status, 'evidence' => $r->evidence, 'confidence' => $r->confidence,
                    'direction' => $r->product_id === $product->id ? 'out' : 'in',
                    'other' => $r->product_id === $product->id ? $r->relatedProduct?->only(['id', 'reference', 'description']) : $r->product?->only(['id', 'reference', 'description'])]),
            'documents' => DocumentProductLink::query()->with('document')->where('product_id', $product->id)->get()->unique(fn ($l) => $l->document_id.'-'.$l->page)->values()
                ->map(fn ($l) => ['document_id' => $l->document_id, 'title' => $l->document->title, 'page' => $l->page, 'method' => $l->link_method, 'confidence' => $l->confidence,
                    'url' => route('documents.file', $l->document_id, false).'#page='.$l->page, 'status' => $l->document->status]),
            'conflicts' => KnowledgeConflict::query()->where('product_id', $product->id)->latest()->get(['id', 'field', 'value_a', 'source_a', 'value_b', 'source_b', 'status', 'resolution']),
        ]);
    }

    /** Depurador de búsqueda (spec §111): etapas, filtros, candidatos con señales y conocimiento. */
    public function debug(Request $request, ProductSearchService $search, KnowledgeSearchService $knowledge): Response
    {
        $q = trim((string) $request->query('q'));
        $data = null;
        if ($q !== '') {
            $t = microtime(true);
            $r = $search->search($q, 60);
            $products = $r->products(30)->keyBy('id');
            $data = $r->toArray() + ['ms' => (int) round((microtime(true) - $t) * 1000)];
            $data['candidates'] = array_map(fn ($c) => $c + ['reference' => $products[$c['product_id']]->reference ?? null, 'description' => $products[$c['product_id']]->description ?? null],
                array_slice($data['candidates'], 0, 30));
            $data['knowledge'] = array_map(fn ($h) => collect($h)->except('content')->all() + ['excerpt' => mb_substr((string) $h['content'], 0, 400)], $knowledge->search($q, 6));
        }

        return inertia('Search/Debug', ['q' => $q, 'result' => $data]);
    }

    /** Paleta Cmd/Ctrl+K: referencias, productos, documentos y conversaciones. */
    public function command(Request $request, ProductSearchService $search): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        if (mb_strlen($q) < 2) {
            return response()->json(['items' => []]);
        }
        $items = [];
        foreach ($search->search($q, 8)->products(8) as $p) {
            $items[] = ['kind' => 'Producto', 'label' => $p->reference, 'hint' => mb_substr((string) $p->description, 0, 70), 'url' => route('products.show', $p, false)];
        }
        foreach (Document::query()->where('title', 'like', "%{$q}%")->limit(4)->get() as $d) {
            $items[] = ['kind' => 'Documento', 'label' => $d->title, 'hint' => $d->pages.' págs.', 'url' => route('documents.show', $d, false)];
        }
        foreach (Conversation::query()->with('customer')->whereHas('customer', fn ($b) => $b->where('name', 'like', "%{$q}%")->orWhere('company', 'like', "%{$q}%"))->limit(4)->get() as $c) {
            $items[] = ['kind' => 'Conversación', 'label' => $c->customer->name, 'hint' => $c->customer->company, 'url' => route('inbox.show', $c, false)];
        }

        return response()->json(['items' => $items]);
    }
}
