<?php

declare(strict_types=1);

namespace App\Documents;

use App\Catalog\Units\UnitService;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentPage;
use App\Models\DocumentProductLink;
use App\Models\DocumentTable;
use App\Models\DocumentTableCell;
use App\Models\ProductSpecification;
use App\Models\Source;
use App\Models\TableFootnote;
use App\Search\Embeddings\VectorStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pipeline documental (spec §40): validación → hash → metadatos → páginas → tablas → referencias → vínculos →
 * especificaciones de tablas aprobadas → fragmentos → embeddings. Cada paso queda en processing_log con su resultado
 * real. Si un paso falla, el archivo queda guardado y el documento en NEEDS_REVIEW o FAILED: nunca se pierde.
 */
final class DocumentIngestionService
{
    public const MAX_BYTES = 50 * 1024 * 1024;

    public function __construct(
        private readonly PdfTextExtractor $pdf,
        private readonly LayoutTableExtractor $tables,
        private readonly ReferenceDetector $references,
        private readonly ContactorPowerTableMapper $contactorTable,
        private readonly VectorStore $vectors,
    ) {}

    /**
     * Valida y registra el archivo. Devuelve el documento existente si el hash ya estaba (idempotente).
     *
     * @param  array{title?:string, access_level?:string, uri?:?string, doc_type?:string, brand?:?string, document_date?:?string, supersedes_id?:?int, notes?:?string, uploaded_by?:?int}  $meta
     */
    public function register(string|UploadedFile $file, array $meta = []): Document
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $original = $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file);
        $this->validate($path, $original);

        $sha = hash_file('sha256', $path);
        if ($existing = Document::query()->where('sha256', $sha)->first()) {
            return $existing;
        }
        $stored = 'documents/'.$sha.'.pdf';
        Storage::disk('local')->put($stored, file_get_contents($path));

        $title = $meta['title'] ?? trim(preg_replace('/\s+/', ' ', pathinfo($original, PATHINFO_FILENAME)) ?? $original, ' .');
        $source = Source::query()->firstOrCreate(['sha256' => $sha], [
            'kind' => 'PDF', 'access_level' => $meta['access_level'] ?? 'ADMIN_UPLOAD', 'name' => $original,
            'uri' => $meta['uri'] ?? null, 'authority' => 70, 'retrieved_at' => now(),
        ]);
        $document = Document::query()->create([
            'source_id' => $source->id, 'title' => $title, 'family_key' => Str::slug($title),
            'doc_type' => $meta['doc_type'] ?? (str_contains(mb_strtolower($original), 'certificad') ? 'CERTIFICATE' : 'CATALOG'),
            'brand' => $meta['brand'] ?? null, 'document_date' => $meta['document_date'] ?? null,
            'supersedes_id' => $meta['supersedes_id'] ?? null, 'notes' => $meta['notes'] ?? null,
            'path' => $stored, 'original_name' => $original, 'mime' => 'application/pdf', 'size' => filesize($path),
            'sha256' => $sha, 'processing_status' => 'QUEUED', 'uploaded_by' => $meta['uploaded_by'] ?? null,
            'processing_log' => [['step' => 'validated', 'status' => 'ok', 'detail' => 'PDF válido, '.number_format(filesize($path) / 1024, 0, ',', '.').' KB', 'at' => now()->toIso8601String()]],
        ]);
        if ($document->supersedes_id) {
            app(DocumentPrecedenceService::class)->supersede($document, Document::query()->findOrFail($document->supersedes_id));
        }
        AuditLog::record('document.uploaded', $document, null, ['title' => $title, 'sha256' => $sha]);

        return $document;
    }

    public function process(Document $document): Document
    {
        $document->update(['processing_status' => 'PROCESSING']);
        $log = $document->processing_log ?? [];
        $stats = [];
        $step = function (string $name, string $status, string $detail) use (&$log, $document) {
            $log[] = ['step' => $name, 'status' => $status, 'detail' => $detail, 'at' => now()->toIso8601String()];
            $document->update(['processing_log' => $log]);
        };
        $path = Storage::disk('local')->path($document->path);

        try {
            // Reproceso limpio de lo derivado de este documento (las páginas y el archivo son la fuente)
            DB::transaction(function () use ($document) {
                $document->tables()->delete();
                $document->chunks()->delete();
                $document->links()->delete();
                $document->pagesText()->delete();
                ProductSpecification::query()->where('document_id', $document->id)->whereIn('status', ['EXTRACTED', 'NEEDS_REVIEW'])->delete();
            });

            $pages = $this->pdf->pageCount($path);
            $texts = [];
            for ($p = 1; $p <= $pages; $p++) {
                $texts[$p] = $this->pdf->page($path, $p);
                DocumentPage::query()->create(['document_id' => $document->id, 'page' => $p, 'text' => $texts[$p]]);
            }
            $document->update(['pages' => $pages]);
            $chars = array_sum(array_map('mb_strlen', $texts));
            $step('pages', $chars > 50 ? 'ok' : 'warning', "{$pages} páginas extraídas ({$chars} caracteres)".($chars <= 50 ? ' — sin texto: posible PDF escaneado, requiere OCR' : ''));

            $tableCount = 0;
            $needsReview = 0;
            $tableSpecs = ['specs' => 0, 'conflicts' => 0, 'rows' => 0];
            $tableModels = [];
            foreach ($texts as $p => $text) {
                foreach ($this->tables->extract($text) as $i => $t) {
                    $table = DocumentTable::query()->create([
                        'document_id' => $document->id, 'page' => $p, 'index_on_page' => $i, 'title' => $t['title'] ? mb_substr($t['title'], 0, 255) : null,
                        'header_lines' => $t['header_lines'], 'row_count' => count($t['rows']), 'col_count' => $t['col_count'],
                        'extractor' => LayoutTableExtractor::VERSION, 'confidence' => $t['confidence'],
                        'status' => $t['confidence'] >= 0.85 ? 'EXTRACTED' : 'NEEDS_REVIEW',
                    ]);
                    $cells = [];
                    foreach ($t['rows'] as $r => $row) {
                        foreach ($row as $c => $value) {
                            $cells[] = ['table_id' => $table->id, 'row' => $r, 'col' => $c, 'raw_value' => mb_substr($value, 0, 255),
                                'normalized_value' => preg_match('/^\d+(?:[.,]\d+)?$/', $value) ? UnitService::number($value) : null, 'unit' => null];
                        }
                    }
                    DocumentTableCell::query()->insert($cells);
                    foreach ($this->footnotes($text) as $note) {
                        TableFootnote::query()->create(['table_id' => $table->id, 'text' => $note]);
                    }
                    $tableModels[] = [$table, $t['rows']];
                    $tableCount++;
                    $needsReview += (int) ($table->status === 'NEEDS_REVIEW');
                    if ($this->contactorTable->applies($table, $t['rows'])) {
                        $r = $this->contactorTable->apply($document, $table, $t['rows']);
                        foreach ($r as $k => $v) {
                            $tableSpecs[$k] += $v;
                        }
                    }
                }
            }
            $step('tables', $needsReview > 0 ? 'warning' : 'ok', "{$tableCount} tablas detectadas".($needsReview ? ", {$needsReview} requieren revisión" : ''));
            if ($tableSpecs['rows'] > 0) {
                $step('table_specs', $tableSpecs['conflicts'] > 0 ? 'warning' : 'ok',
                    "Tabla de potencias de contactores: {$tableSpecs['rows']} filas → {$tableSpecs['specs']} especificaciones; {$tableSpecs['conflicts']} conflictos con el Excel");
            }

            $refs = 0;
            $linked = [];
            foreach ($texts as $p => $text) {
                foreach ($this->references->detect($text) as $hit) {
                    $refs++;
                    $tableId = $this->tableFor($tableModels, $p, $hit['raw']);
                    foreach ($hit['product_ids'] as $pid) {
                        DocumentProductLink::query()->create([
                            'document_id' => $document->id, 'product_id' => $pid, 'reference_raw' => mb_substr($hit['raw'], 0, 255), 'page' => $p,
                            'table_id' => $tableId, 'link_method' => $hit['method'], 'confidence' => $hit['confidence'],
                        ]);
                        $linked[$pid] = true;
                    }
                }
            }
            $step('references', 'ok', "{$refs} referencias detectadas · ".count($linked).' productos vinculados');

            $overrides = [];
            foreach ($texts as $p => $text) {
                if (preg_match('/(reemplaza|sustituye|modifica|anula)\s+(a\s+)?(la\s+)?(versi[oó]n|edici[oó]n|tabla)\s+anterior/iu', $text)) {
                    $overrides[] = $p;
                }
            }
            if ($overrides) {
                $step('override_notice', 'warning', 'El documento declara reemplazar información anterior (págs. '.implode(', ', $overrides).'). Indicar qué documento reemplaza.');
            }

            $chunks = $this->chunk($document, $texts, $tableModels);
            $step('chunks', 'ok', count($chunks).' fragmentos (texto + tablas)');

            $embedded = 0;
            if ($this->vectors->provider()->available()) {
                $embedded = $this->vectors->index($chunks);
                $step('embeddings', 'ok', "{$embedded} vectores ({$this->vectors->provider()->model()})");
            } else {
                $step('embeddings', 'warning', 'Proveedor de embeddings no disponible: la búsqueda semántica no cubre este documento (exacta, estructurada y texto completo sí).');
            }

            $stats = ['pages' => $pages, 'tables' => $tableCount, 'tables_needs_review' => $needsReview, 'references' => $refs,
                'linked_products' => count($linked), 'table_specs' => $tableSpecs['specs'], 'conflicts' => $tableSpecs['conflicts'],
                'chunks' => count($chunks), 'embeddings' => $embedded];
            $status = $chars <= 50 ? 'NEEDS_REVIEW' : (($needsReview > 0 || $tableSpecs['conflicts'] > 0 || $embedded === 0) ? 'PARTIAL' : 'SUCCEEDED');
            $document->update(['processing_status' => $status, 'stats' => $stats]);
        } catch (\Throwable $e) {
            $step('error', 'failed', $e->getMessage());
            $document->update(['processing_status' => 'FAILED']);
        }

        return $document->refresh();
    }

    /**
     * Página pública del sitio como documento WEB_PAGE (texto + fragmentos + embeddings), deduplicada por hash del
     * texto visible. Devuelve new | updated | unchanged.
     */
    public function ingestWebPage(string $url, string $html): string
    {
        $text = self::htmlToText($html);
        $title = preg_match('/<title>(.*?)<\/title>/is', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]))) : $url;
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $title = 'Sitio MElectric — '.($path === '/' ? 'Inicio' : trim(str_replace(['/Home/', '/'], ['', ' '], $path)));
        $sha = hash('sha256', $text);
        $family = 'web-'.Str::slug($path) ?: 'web-home';
        $current = Document::query()->where('family_key', $family)->where('status', 'CURRENT')->first();
        if ($current && $current->sha256 === $sha) {
            return 'unchanged';
        }
        $stored = 'documents/web/'.$sha.'.html';
        Storage::disk('local')->put($stored, $html);
        $source = Source::query()->firstOrCreate(['sha256' => $sha], ['kind' => 'WEB_PAGE', 'access_level' => 'PUBLIC', 'name' => $url, 'uri' => $url, 'authority' => 40, 'retrieved_at' => now()]);
        $doc = Document::query()->create(['source_id' => $source->id, 'title' => $title, 'family_key' => $family, 'doc_type' => 'WEB_PAGE', 'brand' => 'MElectric',
            'path' => $stored, 'original_name' => $url, 'mime' => 'text/html', 'size' => strlen($html), 'sha256' => $sha, 'pages' => 1,
            'processing_status' => 'PROCESSING', 'processing_log' => [['step' => 'fetched', 'status' => 'ok', 'detail' => $url, 'at' => now()->toIso8601String()]]]);
        if ($current) {
            app(DocumentPrecedenceService::class)->supersede($doc, $current);
        }
        DocumentPage::query()->create(['document_id' => $doc->id, 'page' => 1, 'text' => $text]);
        $chunks = $this->chunk($doc, [1 => $text], []);
        $embedded = $this->vectors->provider()->available() ? $this->vectors->index($chunks) : 0;
        $doc->update(['processing_status' => $embedded ? 'SUCCEEDED' : 'PARTIAL', 'stats' => ['pages' => 1, 'chunks' => count($chunks), 'embeddings' => $embedded, 'tables' => 0, 'references' => 0, 'linked_products' => 0]]);

        return $current ? 'updated' : 'new';
    }

    /** Texto visible de una página: sin scripts, estilos, menú ni pie repetido. */
    public static function htmlToText(string $html): string
    {
        $html = preg_replace('/<(script|style|head|nav|footer|noscript)[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        $html = preg_replace('/<(br|\/p|\/div|\/h\d|\/li)[^>]*>/i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}\x{200B}]+/u', ' ', $text) ?? $text;
        $lines = array_filter(array_map('trim', explode("\n", $text)), fn ($l) => mb_strlen($l) > 2);
        $text = implode("\n", $lines);
        // El pie del sitio se repite en todas las páginas: se corta en «Redes /Melectric»
        $cut = mb_strpos($text, 'Redes /Melectric');

        return trim($cut !== false ? mb_substr($text, 0, $cut) : $text);
    }

    private function validate(string $path, string $name): void
    {
        $size = @filesize($path);
        if (! $size) {
            throw new RuntimeException('Archivo vacío o ilegible.');
        }
        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('El archivo supera 50 MB.');
        }
        $head = (string) file_get_contents($path, false, null, 0, 1024);
        if (! str_starts_with($head, '%PDF-')) {
            throw new RuntimeException('El contenido no es un PDF (firma %PDF- ausente), aunque la extensión diga otra cosa.');
        }
        if (! preg_match('/\.pdf$/i', $name)) {
            throw new RuntimeException('La extensión no coincide con el contenido (se esperaba .pdf).');
        }
    }

    /** @return list<string> */
    private function footnotes(string $text): array
    {
        preg_match_all('/^\s*(?:\*{1,3}|\(\d\)|Nota:)\s*(.{10,200})$/mu', $text, $m);

        return array_slice(array_map('trim', $m[1]), 0, 10);
    }

    private function tableFor(array $tableModels, int $page, string $raw): ?int
    {
        foreach ($tableModels as [$table, $rows]) {
            if ($table->page === $page && str_contains(json_encode($rows, JSON_UNESCAPED_UNICODE), $raw)) {
                return $table->id;
            }
        }

        return null;
    }

    /** @return list<DocumentChunk> */
    private function chunk(Document $document, array $texts, array $tableModels): array
    {
        $out = [];
        foreach ($texts as $p => $text) {
            $clean = trim(preg_replace('/[ \t]{2,}/u', '  ', $text) ?? $text);
            foreach ($this->split($clean, 1400) as $piece) {
                $content = "[{$document->title} — pág. {$p}]\n".$piece;
                $out[] = DocumentChunk::query()->create(['document_id' => $document->id, 'page' => $p, 'kind' => 'TEXT', 'content' => $content, 'content_hash' => hash('sha256', $content)]);
            }
        }
        foreach ($tableModels as [$table, $rows]) {
            $md = "[{$document->title} — pág. {$table->page} — tabla".($table->title ? ": {$table->title}" : '')."]\n";
            $md .= implode("\n", array_map(fn ($h) => trim(preg_replace('/\s{2,}/', ' · ', $h) ?? $h), array_slice($table->header_lines, -4)))."\n";
            foreach ($rows as $row) {
                $md .= '| '.implode(' | ', $row)." |\n";
            }
            $out[] = DocumentChunk::query()->create(['document_id' => $document->id, 'page' => $table->page, 'kind' => 'TABLE', 'table_id' => $table->id,
                'content' => mb_substr($md, 0, 6000), 'content_hash' => hash('sha256', $md)]);
        }

        return $out;
    }

    /** @return list<string> */
    private function split(string $text, int $max): array
    {
        $parts = preg_split('/\n\s*\n/u', $text) ?: [$text];
        $out = [];
        $buf = '';
        foreach ($parts as $part) {
            if (mb_strlen($buf) + mb_strlen($part) > $max && $buf !== '') {
                $out[] = trim($buf);
                $buf = '';
            }
            $buf .= "\n\n".$part;
        }
        if (trim($buf) !== '') {
            $out[] = trim($buf);
        }

        return array_values(array_filter($out, fn ($c) => mb_strlen($c) > 40));
    }
}
