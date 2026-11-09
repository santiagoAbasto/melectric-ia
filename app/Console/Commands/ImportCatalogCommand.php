<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Catalog\CatalogImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('melectric:import-catalog {path? : Excel de clasificación B2B} {--images= : Carpeta de imágenes de línea}')]
#[Description('Importa el catálogo B2B de MElectric (Excel) con procedencia, especificaciones, imágenes y relaciones')]
class ImportCatalogCommand extends Command
{
    public function handle(CatalogImporter $importer): int
    {
        $path = $this->argument('path') ?? storage_path('app/private/sources/excel/Clasificacion _ catalogo productos B2B_V2.xlsx');
        $images = $this->option('images') ?? storage_path('app/private/sources/images');
        $started = microtime(true);
        $stats = $importer->import($path, is_dir($images) ? $images : null);

        $this->table(['Métrica', 'Valor'], collect($stats)->except('errors')->map(fn ($v, $k) => [$k, $v])->values()->all());
        foreach ($stats['errors'] as $error) {
            $this->warn($error);
        }
        $this->info(sprintf('Listo en %.1f s', microtime(true) - $started));

        return $stats['failed'] > 0 && $stats['new'] + $stats['updated'] + $stats['unchanged'] === 0 ? self::FAILURE : self::SUCCESS;
    }
}
