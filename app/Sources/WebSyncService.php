<?php

declare(strict_types=1);

namespace App\Sources;

use App\Catalog\ProductReferenceNormalizer;
use App\Documents\DocumentIngestionService;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Product;
use App\Models\Source;
use App\Models\SourceRecord;
use App\Models\SyncRun;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Sincronización del sitio web con un clic: corre scraper/melectric_sync.py (Python, robots, lista blanca, SSRF,
 * sin login) y después incorpora la instantánea: páginas → documentos WEB_PAGE, PDF enlazados → pipeline documental,
 * productos JSON-LD → registros de fuente comparados contra el catálogo (no se pisa el catálogo: se informan los que
 * coinciden y los que faltan). Si algo falla, lo ya guardado no se toca.
 */
final class WebSyncService
{
    public function __construct(private readonly DocumentIngestionService $ingestion) {}

    public function start(?string $baseUrl, ?int $userId): SyncRun
    {
        $base = rtrim($baseUrl ?: config('melectric.sync.base_url'), '/');
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        if (! in_array($host, config('melectric.sync.allowed_hosts'), true)) {
            throw new RuntimeException("El dominio {$host} no está habilitado para sincronizar (SYNC_ALLOWED_HOSTS).");
        }
        if (SyncRun::query()->latest('id')->first()?->running()) {
            throw new RuntimeException('Ya hay una sincronización en curso.');
        }

        return SyncRun::query()->create(['base_url' => $base, 'status' => 'QUEUED', 'triggered_by' => $userId, 'log' => []]);
    }

    public function run(SyncRun $run): SyncRun
    {
        $cfg = config('melectric.sync');
        $run->update(['status' => 'RUNNING', 'started_at' => now()]);
        $this->log($run, 'crawl', 'ok', 'Rastreando '.$run->base_url.' con Python (robots, lista blanca, sin login)…');
        $out = Storage::disk('local')->path('sync/run-'.$run->id.'.json');
        @mkdir(dirname($out), 0775, true);
        $p = new Process([$cfg['python'], base_path('scraper/melectric_sync.py'), '--base', $run->base_url, '--out', $out,
            '--allow', ...$cfg['allowed_hosts'], '--max', (string) $cfg['max_pages'], '--delay-ms', (string) $cfg['delay_ms'],
            '--user-agent', (string) config('melectric.crawler.user_agent')]);
        $p->setTimeout(1800)->run();
        if (! $p->isSuccessful() || ! is_file($out)) {
            $this->log($run, 'crawl', 'failed', trim($p->getOutput().' '.$p->getErrorOutput()) ?: 'El script terminó con error');

            return tap($run)->update(['status' => 'FAILED', 'finished_at' => now()]);
        }

        return $this->ingestSnapshot($run, json_decode((string) file_get_contents($out), true) ?? []);
    }

    /** Incorpora una instantánea del script (separado para poder probarlo sin red). */
    public function ingestSnapshot(SyncRun $run, array $snap): SyncRun
    {
        $stats = ['pages' => 0, 'pages_new' => 0, 'pages_updated' => 0, 'pages_unchanged' => 0, 'pdfs' => 0, 'pdfs_new' => 0, 'pdfs_unchanged' => 0,
            'pdfs_failed' => 0, 'products_found' => 0, 'products_matched' => 0, 'products_unmatched' => 0, 'out_of_scope' => count($snap['out_of_scope'] ?? []),
            'crawl_errors' => count($snap['errors'] ?? [])];
        $this->log($run, 'crawl', 'ok', count($snap['pages'] ?? []).' páginas, '.count($snap['pdfs'] ?? []).' PDF, '.$stats['out_of_scope'].' fuera de alcance (login/robots)');

        foreach ($snap['pages'] ?? [] as $page) {
            if (mb_strlen((string) ($page['text'] ?? '')) < 40) {
                continue;
            }
            $stats['pages']++;
            $stats['pages_'.$this->ingestion->ingestWebText((string) $page['url'], (string) $page['text'])]++;
        }
        $this->log($run, 'pages', 'ok', "{$stats['pages_new']} nuevas · {$stats['pages_updated']} actualizadas · {$stats['pages_unchanged']} sin cambios");

        $allowed = config('melectric.sync.allowed_hosts');
        foreach ($snap['pdfs'] ?? [] as $pdf) {
            $stats['pdfs']++;
            try {
                UrlGuard::assertSafe($pdf['url'], $allowed);
                $res = Http::withUserAgent((string) config('melectric.crawler.user_agent'))->timeout(90)->withoutRedirecting()->get($pdf['url']);
                if (! $res->successful() || strlen($res->body()) > DocumentIngestionService::MAX_BYTES) {
                    throw new RuntimeException('HTTP '.$res->status());
                }
                $tmp = tempnam(sys_get_temp_dir(), 'melpdf');
                file_put_contents($tmp, $res->body());
                $name = rawurldecode(basename((string) parse_url($pdf['url'], PHP_URL_PATH)));
                $sha = hash_file('sha256', $tmp);
                if (Document::query()->where('sha256', $sha)->exists()) {
                    $stats['pdfs_unchanged']++;
                } else {
                    $renamed = dirname($tmp).'/'.preg_replace('/[^\pL\pN ._()-]/u', '_', $name);
                    rename($tmp, $renamed);
                    $doc = $this->ingestion->register($renamed, ['access_level' => 'PUBLIC', 'uri' => $pdf['url']]);
                    $this->ingestion->process($doc);
                    $stats['pdfs_new']++;
                    $tmp = $renamed;
                }
                @unlink($tmp);
            } catch (\Throwable $e) {
                $stats['pdfs_failed']++;
                $this->log($run, 'pdf', 'warning', basename((string) $pdf['url']).': '.mb_substr($e->getMessage(), 0, 160));
            }
        }
        $this->log($run, 'pdfs', $stats['pdfs_failed'] ? 'warning' : 'ok', "{$stats['pdfs_new']} nuevos · {$stats['pdfs_unchanged']} sin cambios · {$stats['pdfs_failed']} con error");

        $source = Source::query()->firstOrCreate(['kind' => 'WEB_SYNC', 'name' => 'Productos publicados en '.$run->base_url],
            ['access_level' => 'PUBLIC', 'uri' => $run->base_url, 'authority' => 40, 'retrieved_at' => now()]);
        $unmatched = [];
        foreach ($snap['pages'] ?? [] as $page) {
            foreach ($page['products'] ?? [] as $prod) {
                $stats['products_found']++;
                $refs = array_filter([$prod['mpn'] ?? '', $prod['sku'] ?? '', $prod['name'] ?? '']);
                $match = Product::query()->whereIn('normalized_reference', array_map([ProductReferenceNormalizer::class, 'normalize'], $refs))
                    ->orWhereIn('internal_code', $refs)->first();
                SourceRecord::query()->updateOrCreate(['source_id' => $source->id, 'locator' => mb_substr($page['url'].'#'.($prod['sku'] ?: $prod['mpn'] ?: md5(json_encode($prod))), 0, 250)],
                    ['hash' => hash('sha256', json_encode($prod)), 'payload' => $prod + ['matched_product_id' => $match?->id]]);
                $match ? $stats['products_matched']++ : ($unmatched[] = $prod['mpn'] ?: $prod['sku'] ?: $prod['name']);
            }
        }
        $stats['products_unmatched'] = count($unmatched);
        $this->log($run, 'products', 'ok', $stats['products_found']
            ? "{$stats['products_found']} productos publicados · {$stats['products_matched']} coinciden con el catálogo · {$stats['products_unmatched']} no están en el catálogo (para revisar)"
            : 'El sitio no publica productos con datos estructurados (JSON-LD). Cuando el sitio nuevo los publique, se comparan acá.');
        $stats['unmatched_sample'] = array_slice($unmatched, 0, 20);
        $stats['out_of_scope_sample'] = array_slice($snap['out_of_scope'] ?? [], 0, 10);

        $status = ($stats['pdfs_failed'] || $stats['crawl_errors']) ? 'PARTIAL' : 'SUCCEEDED';
        $run->update(['status' => $status, 'stats' => $stats, 'finished_at' => now()]);
        AuditLog::record('sync.executed', $run, null, collect($stats)->except(['unmatched_sample', 'out_of_scope_sample'])->all());

        return $run->refresh();
    }

    private function log(SyncRun $run, string $step, string $status, string $detail): void
    {
        $log = $run->log ?? [];
        $log[] = ['step' => $step, 'status' => $status, 'detail' => $detail, 'at' => now()->toIso8601String()];
        $run->update(['log' => $log]);
    }
}
