<?php

declare(strict_types=1);

namespace App\Documents;

use App\Catalog\ProductReferenceNormalizer;
use App\Catalog\SpecCatalog;
use App\Catalog\Units\UnitService;
use App\Models\Document;
use App\Models\DocumentTable;
use App\Models\KnowledgeConflict;
use App\Models\Product;
use App\Models\ProductSpecification;

/**
 * Mapeo aprobado de la «Tabla de Potencias y Corrientes de Contactores DILM - E-Line» (catálogo Contactores y Relevos
 * Térmicos, MElectric). Filas: P [kW] AC-3 380-400 V | In [A] AC-3 | Ith [A] AC-1 | referencias por familia.
 * Sólo escribe especificaciones para referencias de tamaño de serie (XTCG032, DILM32, DILMP45) y las aplica a todas
 * las variantes de bobina de ese tamaño. Si la descripción del Excel dice otra cosa, abre un conflicto: no elige.
 */
final class ContactorPowerTableMapper
{
    public const VERSION = 'contactor_table@1';

    public function applies(DocumentTable $table, array $rows): bool
    {
        $titled = str_contains(mb_strtolower((string) $table->title.' '.implode(' ', $table->header_lines)), 'potencias y corrientes');

        return $titled || collect($rows)->filter(fn ($r) => $this->parseRow($r) !== null)->count() >= 4;
    }

    /** @return array{kw:float, ac3:float, ac1:float, refs:list<string>}|null */
    public function parseRow(array $row): ?array
    {
        $nums = [];
        $refs = [];
        foreach ($row as $cell) {
            foreach (preg_split('/\s+/', trim($cell)) ?: [] as $tok) {
                if (preg_match('/^(XTCG|DILMP|DILM)\d+$/', $tok)) {
                    $refs[] = $tok;
                } elseif (count($refs) === 0 && preg_match('/^\d+(?:,\d+)?$/', $tok) && count($nums) < 3) {
                    $nums[] = UnitService::number($tok);
                }
            }
        }

        return count($nums) === 3 && $refs !== [] ? ['kw' => $nums[0], 'ac3' => $nums[1], 'ac1' => $nums[2], 'refs' => $refs] : null;
    }

    /** @return array{specs:int, conflicts:int, rows:int} */
    public function apply(Document $document, DocumentTable $table, array $rows): array
    {
        $defs = SpecCatalog::ensure();
        $stats = ['specs' => 0, 'conflicts' => 0, 'rows' => 0];
        foreach ($rows as $row) {
            if (! $parsed = $this->parseRow($row)) {
                continue;
            }
            $stats['rows']++;
            foreach ($parsed['refs'] as $ref) {
                [$series, $size] = ProductReferenceNormalizer::sizeKey($ref) ?? [null, null];
                $products = Product::query()->where('series', $series)->get()
                    ->filter(fn (Product $p) => (ProductReferenceNormalizer::sizeKey($p->reference)[1] ?? null) === $size);
                foreach ($products as $product) {
                    foreach ([
                        ['motor_power', $parsed['kw'] * 1000, 'W', 'AC-3 380-400 V', 'P [kW]'],
                        ['rated_current_ac3', $parsed['ac3'], 'A', 'AC-3 380-400 V', 'In [A]'],
                        ['rated_current_ac1', $parsed['ac1'], 'A', 'AC-1 Ith @ 40 °C', 'Ith [A]'],
                    ] as [$key, $value, $unit, $qualifier, $col]) {
                        $spec = ProductSpecification::query()->updateOrCreate(
                            ['product_id' => $product->id, 'definition_id' => $defs[$key], 'extractor' => self::VERSION, 'document_id' => $document->id],
                            ['raw_value' => "{$ref}: {$col} = ".UnitService::format($value, $unit), 'value' => $value, 'unit' => $unit,
                                'qualifier' => $qualifier, 'page' => $table->page, 'confidence' => round(min(0.95, $table->confidence + 0.05), 3), 'status' => 'EXTRACTED'],
                        );
                        $stats['specs']++;
                        $stats['conflicts'] += $this->checkConflict($product, $defs[$key], $key, $spec, $document);
                    }
                }
            }
        }

        return $stats;
    }

    private function checkConflict(Product $product, int $definitionId, string $key, ProductSpecification $tableSpec, Document $document): int
    {
        $other = ProductSpecification::query()->where('product_id', $product->id)->where('definition_id', $definitionId)
            ->where('id', '!=', $tableSpec->id)->where('extractor', '!=', self::VERSION)->whereNotNull('value')->first();
        if (! $other || abs($other->value - $tableSpec->value) < 1e-6) {
            return 0;
        }
        $exists = KnowledgeConflict::query()->where('product_id', $product->id)->where('field', $key)
            ->whereIn('status', ['OPEN', 'RESOLVED'])->where('spec_b_id', $tableSpec->id)->exists();
        if ($exists) {
            return 0;
        }
        $other->update(['status' => 'NEEDS_REVIEW']);
        $tableSpec->update(['status' => 'NEEDS_REVIEW']);
        KnowledgeConflict::query()->create([
            'product_id' => $product->id, 'definition_id' => $definitionId, 'field' => $key,
            'spec_a_id' => $other->id, 'spec_b_id' => $tableSpec->id,
            'value_a' => UnitService::format($other->value, $other->unit), 'source_a' => 'Excel B2B — «'.mb_substr($other->raw_value, 0, 120).'»',
            'value_b' => UnitService::format($tableSpec->value, $tableSpec->unit), 'source_b' => $document->title.' · pág. '.$tableSpec->page,
        ]);

        return 1;
    }
}
