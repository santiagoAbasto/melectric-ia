<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Models\AuditLog;
use App\Models\Brand;
use App\Models\KnowledgeConflict;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Models\ProductCategory;
use App\Models\ProductFamily;
use App\Models\ProductSpecification;
use App\Models\Source;
use App\Models\SourceRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Importa el Excel «Clasificación catálogo productos B2B» (hoja B2B + hoja MCBs & RCCBs). Idempotente por código
 * interno: cuenta nuevos / actualizados / sin cambios / fallidos y nunca borra productos que dejan de venir.
 * Las especificaciones revisadas por una persona (VERIFIED/REJECTED) no se tocan al reimportar.
 */
final class CatalogImporter
{
    /** @var array<string, int> */
    private array $defs = [];

    public function __construct(private readonly SpecExtractor $extractor = new SpecExtractor) {}

    /** @return array<string, int|float|list<string>> */
    public function import(string $xlsxPath, ?string $imagesDir = null): array
    {
        if (! is_file($xlsxPath)) {
            throw new RuntimeException("No existe el archivo: {$xlsxPath}");
        }
        $stats = ['parsed' => 0, 'new' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'missing_reference' => 0,
            'specs' => 0, 'conflicts' => 0, 'relationships' => 0, 'families' => 0, 'families_with_image' => 0, 'errors' => []];
        $this->defs = SpecCatalog::ensure();

        $reader = IOFactory::createReaderForFile($xlsxPath);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly(['B2B', 'MCBs & RCCBs']);
        $book = $reader->load($xlsxPath);
        if (! $book->sheetNameExists('B2B')) {
            throw new RuntimeException('El Excel no tiene la hoja «B2B» (¿es la versión anterior sin la hoja de clasificación?).');
        }

        $sha = hash_file('sha256', $xlsxPath);
        $source = Source::query()->updateOrCreate(['sha256' => $sha], [
            'kind' => 'EXCEL', 'access_level' => 'ADMIN_UPLOAD', 'name' => basename($xlsxPath), 'uri' => $xlsxPath,
            'authority' => 60, 'retrieved_at' => now(),
        ]);

        // Hoja MCBs & RCCBs: familia, subfamilia, marca y polos por código
        $mcb = [];
        if ($book->sheetNameExists('MCBs & RCCBs')) {
            foreach ($book->getSheetByName('MCBs & RCCBs')->toArray(null, true, false, true) as $n => $r) {
                if (is_numeric($r['C'] ?? null) && $n >= 6) {
                    $mcb[(string) $r['C']] = ['familia' => $r['D'], 'subfamilia' => $r['E'], 'marca' => $r['F'], 'polos' => $r['H'] ? (int) $r['H'] : null, 'row' => $n];
                }
            }
        }

        $images = $imagesDir ? new LineImageMatcher($imagesDir) : null;
        $families = [];
        $accessories = [];

        foreach ($book->getSheetByName('B2B')->toArray(null, true, false, true) as $n => $r) {
            if ($n === 1 || trim((string) ($r['A'] ?? '')) === '') {
                continue;
            }
            $stats['parsed']++;
            $code = trim((string) $r['A']);
            $reference = trim((string) $r['B']);
            $description = trim((string) $r['C']);
            $line = trim((string) $r['D']);
            if ($reference === '') {
                $stats['missing_reference']++;
                $stats['failed']++;
                $stats['errors'][] = "B2B!A{$n}: código {$code} sin referencia";

                continue;
            }
            try {
                DB::transaction(function () use ($source, $n, $r, $code, $reference, $description, $line, $mcb, $images, &$families, &$accessories, &$stats) {
                    $sheet = $mcb[$code] ?? null;
                    $payload = ['Código' => $r['A'], 'Desc Breve' => $r['B'], 'Desc Detallada' => $r['C'], 'Agrupador' => $r['D']]
                        + ($sheet ? ['MCB.Familia' => $sheet['familia'], 'MCB.Subfamilia' => $sheet['subfamilia'], 'MCB.Marca' => $sheet['marca'], 'MCB.Polos' => $sheet['polos'], 'MCB.fila' => $sheet['row']] : []);
                    $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));
                    $record = SourceRecord::query()->updateOrCreate(['source_id' => $source->id, 'locator' => "B2B!A{$n}"], ['hash' => $hash, 'payload' => $payload]);

                    $family = $families[$line] ??= $this->family($line, $images, $stats);
                    $class = CatalogClassifier::classify($line);
                    $brand = CatalogClassifier::brand($reference, $line, $sheet['marca'] ?? null);

                    $product = Product::query()->firstOrNew(['internal_code' => $code]);
                    $productHash = hash('sha256', $hash.'|'.SpecExtractor::VERSION.'|'.ProductReferenceNormalizer::VERSION);
                    if ($product->exists && $product->content_hash === $productHash) {
                        $stats['unchanged']++;
                        if (RelationshipExtractor::typeFor($line)) {
                            $accessories[] = [$product, $line];
                        }

                        return;
                    }
                    $before = $product->exists ? $product->only(['reference', 'description', 'family_id']) : null;
                    $product->fill([
                        'reference' => $reference,
                        'normalized_reference' => ProductReferenceNormalizer::normalize($reference),
                        'base_reference' => ProductReferenceNormalizer::base($reference),
                        'series' => ProductReferenceNormalizer::series($reference),
                        'description' => $description,
                        'brand_id' => $brand ? Brand::query()->firstOrCreate(['name' => $brand['brand']], ['slug' => Str::slug($brand['brand'])])->id : null,
                        'category_id' => $family->category_id,
                        'family_id' => $family->id,
                        'subfamily' => $sheet['subfamilia'] ?? null,
                        'source_record_id' => $record->id,
                        'content_hash' => $productHash,
                    ])->save();
                    $stats[$before ? 'updated' : 'new']++;
                    if ($before && $before['reference'] !== $reference) {
                        AuditLog::record('product.reference_changed', $product, $before, $product->only(['reference', 'description', 'family_id']));
                    }

                    $this->aliases($product, $reference, $record->id);
                    [$specs, $conflicts] = $this->specs($product, $reference, $description, $class['product_type'] ?? null, $sheet, $record->id);
                    $stats['specs'] += $specs;
                    $stats['conflicts'] += $conflicts;
                    if (RelationshipExtractor::typeFor($line)) {
                        $accessories[] = [$product, $line];
                    }
                });
            } catch (\Throwable $e) {
                $stats['failed']++;
                $stats['errors'][] = "B2B!A{$n}: ".$e->getMessage();
            }
        }

        $rel = new RelationshipExtractor;
        foreach ($accessories as [$product, $line]) {
            $stats['relationships'] += $rel->extractFor($product, $line);
        }

        $stats['errors'] = array_slice($stats['errors'], 0, 50);
        $source->update(['meta' => ['last_import' => $stats + ['at' => now()->toIso8601String()]]]);
        AuditLog::record('catalog.import', $source, null, collect($stats)->except('errors')->all());

        return $stats;
    }

    private function family(string $line, ?LineImageMatcher $images, array &$stats): ProductFamily
    {
        $class = CatalogClassifier::classify($line);
        $category = $class ? ProductCategory::query()->firstOrCreate(['name' => $class['category']], [
            'slug' => Str::slug($class['category']), 'product_type' => $class['product_type'], 'rubro' => $class['rubro'],
        ]) : null;
        $family = ProductFamily::query()->firstOrNew(['name' => $line]);
        $family->fill(['slug' => Str::slug($line) ?: md5($line), 'category_id' => $category?->id]);
        if ($images && ($src = $images->find($line))) {
            $target = 'catalog/lines/'.$family->slug.'.'.strtolower(pathinfo($src, PATHINFO_EXTENSION));
            Storage::disk('public')->put($target, file_get_contents($src));
            $family->fill(['image_path' => $target, 'image_source' => basename($src)]);
            $stats['families_with_image']++;
        }
        $family->save();
        $stats['families']++;

        return $family;
    }

    private function aliases(Product $product, string $reference, int $recordId): void
    {
        $base = ProductReferenceNormalizer::base($reference);
        foreach (array_filter(['base_reference' => $base !== $reference ? $base : null, 'catalog_reference' => $reference]) as $kind => $alias) {
            ProductAlias::query()->updateOrCreate(
                ['product_id' => $product->id, 'normalized' => ProductReferenceNormalizer::normalize($alias), 'kind' => $kind],
                ['alias' => $alias, 'source_record_id' => $recordId],
            );
        }
    }

    /** @return array{0:int,1:int} [especificaciones guardadas, conflictos abiertos] */
    private function specs(Product $product, string $reference, string $description, ?string $type, ?array $sheet, int $recordId): array
    {
        KnowledgeConflict::query()->where('product_id', $product->id)->where('status', 'OPEN')->delete();
        ProductSpecification::query()->where('product_id', $product->id)->where('extractor', SpecExtractor::VERSION)
            ->whereIn('status', ['EXTRACTED', 'NEEDS_REVIEW'])->delete();
        $reviewed = ProductSpecification::query()->where('product_id', $product->id)->whereIn('status', ['VERIFIED', 'REJECTED'])
            ->pluck('definition_id')->all();

        $byKey = [];
        foreach ($this->extractor->extract($reference, $description, $type, $sheet['subfamilia'] ?? null, $sheet['polos'] ?? null) as $s) {
            $byKey[$s->key][] = $s;
        }
        $saved = 0;
        $conflicts = 0;
        foreach ($byKey as $key => $candidates) {
            if (in_array($this->defs[$key], $reviewed, true)) {
                continue;
            }
            $groups = collect($candidates)->groupBy(fn (ExtractedSpec $s) => $s->comparable());
            $status = $groups->count() > 1 ? 'NEEDS_REVIEW' : 'EXTRACTED';
            $rows = [];
            foreach ($groups as $group) {
                $best = $group->sortByDesc('confidence')->first();
                // Dos orígenes independientes que coinciden suben la confianza (tope 0,98)
                $conf = min(0.98, $best->confidence + 0.03 * ($group->pluck('origin')->unique()->count() - 1));
                $rows[] = ProductSpecification::query()->create([
                    'product_id' => $product->id, 'definition_id' => $this->defs[$key],
                    'raw_value' => mb_substr($group->pluck('raw')->unique()->join(' | '), 0, 255),
                    'value' => $best->value, 'value_min' => $best->min, 'value_max' => $best->max, 'value_text' => $best->text,
                    'unit' => $best->unit, 'qualifier' => $best->qualifier, 'source_record_id' => $recordId,
                    'extractor' => SpecExtractor::VERSION, 'confidence' => round($conf, 3), 'status' => $status,
                ]);
                $saved++;
            }
            if (count($rows) === 2) {
                KnowledgeConflict::query()->create([
                    'product_id' => $product->id, 'definition_id' => $this->defs[$key], 'field' => $key,
                    'spec_a_id' => $rows[0]->id, 'spec_b_id' => $rows[1]->id,
                    'value_a' => $groups->values()[0]->first()->display(), 'source_a' => 'Excel B2B ('.$groups->values()[0]->pluck('origin')->join(', ').')',
                    'value_b' => $groups->values()[1]->first()->display(), 'source_b' => 'Excel B2B ('.$groups->values()[1]->pluck('origin')->join(', ').')',
                ]);
                $conflicts++;
            }
        }

        return [$saved, $conflicts];
    }
}
