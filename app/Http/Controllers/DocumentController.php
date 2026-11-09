<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Documents\DocumentIngestionService;
use App\Models\Document;
use App\Models\DocumentProductLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function index(): Response
    {
        return inertia('Documents/Index', [
            'documents' => Document::query()->with('source')->orderByRaw("CASE doc_type WHEN 'CATALOG' THEN 0 WHEN 'CERTIFICATE' THEN 1 ELSE 2 END")->orderBy('title')->get()->map(fn (Document $d) => [
                'id' => $d->id, 'title' => $d->title, 'doc_type' => $d->doc_type, 'brand' => $d->brand, 'status' => $d->status, 'processing_status' => $d->processing_status,
                'pages' => $d->pages, 'stats' => $d->stats, 'document_date' => $d->document_date?->toDateString(), 'uploaded_at' => $d->created_at?->toIso8601String(),
                'access_level' => $d->source?->access_level, 'uri' => $d->source?->uri, 'sha256' => substr($d->sha256, 0, 16), 'supersedes_id' => $d->supersedes_id,
            ]),
        ]);
    }

    public function store(Request $request, DocumentIngestionService $service): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:51200'], 'title' => ['nullable', 'string', 'max:200'], 'doc_type' => ['nullable', 'in:CATALOG,DATASHEET,MANUAL,CERTIFICATE'],
            'brand' => ['nullable', 'string', 'max:60'], 'document_date' => ['nullable', 'date'], 'supersedes_id' => ['nullable', 'integer', 'exists:documents,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            $doc = $service->register($request->file('file'), array_filter($data, fn ($v) => $v !== null && ! is_object($v)) + ['uploaded_by' => $request->user()->id]);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }
        if ($doc->processing_status === 'QUEUED') {
            $service->process($doc); // ponytail: sincrónico (~1-5 s por catálogo); pasar a ProcessDocumentJob con worker si llegan PDF grandes
        }

        return redirect()->route('documents.show', $doc)->with('message', 'Documento procesado: '.$doc->refresh()->processing_status);
    }

    public function show(Document $document): Response
    {
        $document->load(['tables.footnotes', 'pagesText', 'source']);

        return inertia('Documents/Show', [
            'document' => $document->only(['id', 'title', 'doc_type', 'brand', 'status', 'processing_status', 'processing_log', 'stats', 'pages', 'original_name', 'sha256', 'mime', 'notes', 'supersedes_id'])
                + ['file_url' => route('documents.file', $document, false), 'access_level' => $document->source?->access_level, 'uri' => $document->source?->uri,
                    'document_date' => $document->document_date?->toDateString()],
            'tables' => $document->tables->sortBy(['page', 'index_on_page'])->values()->map(fn ($t) => ['id' => $t->id, 'page' => $t->page, 'title' => $t->title, 'header_lines' => $t->header_lines,
                'rows' => $t->grid(), 'confidence' => $t->confidence, 'status' => $t->status, 'extractor' => $t->extractor, 'footnotes' => $t->footnotes->pluck('text')]),
            'pages' => $document->pagesText->sortBy('page')->values()->map(fn ($p) => ['page' => $p->page, 'text' => $p->text]),
            'links' => DocumentProductLink::query()->with('product')->where('document_id', $document->id)->get()->groupBy('page')
                ->map(fn ($g, $page) => ['page' => $page, 'products' => $g->unique('product_id')->take(40)->map(fn ($l) => ['id' => $l->product_id, 'reference' => $l->product?->reference,
                    'method' => $l->link_method, 'confidence' => $l->confidence])->values()])->values(),
        ]);
    }

    public function file(Document $document): StreamedResponse
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($document->path), 404);
        $mime = $document->mime === 'application/pdf' ? 'application/pdf' : 'text/plain; charset=utf-8';

        // Las páginas web guardadas se sirven como texto plano: nunca se ejecuta su HTML ni sus scripts
        return $disk->response($document->path, null, ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'", 'Content-Disposition' => 'inline']);
    }

    public function reprocess(Document $document, DocumentIngestionService $service): RedirectResponse
    {
        abort_if($document->doc_type === 'WEB_PAGE', 422, 'Las páginas web se actualizan con el crawler.');
        $service->process($document);

        return back()->with('message', 'Reprocesado: '.$document->refresh()->processing_status);
    }
}
