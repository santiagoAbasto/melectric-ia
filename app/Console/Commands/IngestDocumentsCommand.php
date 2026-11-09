<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Documents\DocumentIngestionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('melectric:ingest-documents {dir? : Carpeta con PDF} {--access=PUBLIC : Nivel de acceso de la fuente} {--reprocess : Reprocesar documentos ya registrados}')]
#[Description('Registra y procesa los catálogos PDF (páginas, tablas, referencias, vínculos, fragmentos y embeddings)')]
class IngestDocumentsCommand extends Command
{
    public function handle(DocumentIngestionService $service): int
    {
        $dir = $this->argument('dir') ?? storage_path('app/private/sources/pdfs');
        $rows = [];
        foreach (glob(rtrim($dir, '/').'/*.pdf') ?: [] as $file) {
            try {
                $doc = $service->register($file, [
                    'access_level' => $this->option('access'),
                    'uri' => $this->option('access') === 'PUBLIC' ? 'https://www.melectric.com.ar/Content/Catalogos/'.rawurlencode(basename($file)) : null,
                    'brand' => preg_match('/Variadores|compensaci|Folleto|Productos General/iu', $file) ? 'MElectric' : 'Eaton',
                ]);
                if ($doc->processing_status === 'QUEUED' || $this->option('reprocess')) {
                    $doc = $service->process($doc);
                }
                $s = $doc->stats ?? [];
                $rows[] = [$doc->id, mb_strimwidth($doc->title, 0, 48, '…'), $doc->processing_status, $s['pages'] ?? 0, $s['tables'] ?? 0,
                    $s['references'] ?? 0, $s['linked_products'] ?? 0, $s['table_specs'] ?? 0, $s['conflicts'] ?? 0, $s['chunks'] ?? 0, $s['embeddings'] ?? 0];
            } catch (\Throwable $e) {
                $this->error(basename($file).': '.$e->getMessage());
            }
        }
        $this->table(['id', 'Documento', 'Estado', 'Págs', 'Tablas', 'Refs', 'Productos', 'Specs tabla', 'Conflictos', 'Fragmentos', 'Vectores'], $rows);

        return self::SUCCESS;
    }
}
