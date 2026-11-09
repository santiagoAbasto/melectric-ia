<?php

declare(strict_types=1);

namespace App\Documents;

use App\Catalog\ProductReferenceNormalizer;
use App\Models\Product;

/**
 * Encuentra referencias de producto del catálogo dentro de un texto. Compara tokens normalizados contra la referencia
 * completa, la referencia base (sin bobina) y el tamaño de serie (DILM32 → todas las variantes DILM32-xx). Cada
 * coincidencia sale con su método y confianza: exacta 0,95 · base 0,9 · tamaño de serie 0,7.
 */
final class ReferenceDetector
{
    /** @var array<string, list<int>>|null */
    private ?array $full = null;

    /** @var array<string, list<int>> */
    private array $base = [];

    /** @var array<string, list<int>> */
    private array $size = [];

    /**
     * @return list<array{raw:string, product_ids:list<int>, method:string, confidence:float}>
     */
    public function detect(string $text): array
    {
        $this->load();
        $found = [];
        preg_match_all('/[A-Za-z][A-Za-z0-9]*(?:[-\/.,][A-Za-z0-9]+)*(?:\([^)\s]{2,30}\))?/u', $text, $m);
        foreach (array_unique($m[0]) as $token) {
            if (mb_strlen($token) < 4 || ! preg_match('/\d/', $token)) {
                continue;
            }
            $n = ProductReferenceNormalizer::normalize($token);
            if (isset($this->full[$n])) {
                $found[$n] = ['raw' => $token, 'product_ids' => $this->full[$n], 'method' => 'exact_reference', 'confidence' => 0.95];
            } elseif (isset($this->base[$n])) {
                $found[$n] = ['raw' => $token, 'product_ids' => $this->base[$n], 'method' => 'base_reference', 'confidence' => 0.9];
            } elseif (($key = ProductReferenceNormalizer::sizeKey($token)) && preg_match('/^[A-Za-z]+\d+$/', $token) && isset($this->size[$key[0].$key[1]])) {
                $found[$n] ??= ['raw' => $token, 'product_ids' => $this->size[$key[0].$key[1]], 'method' => 'series_size', 'confidence' => 0.7];
            }
        }

        return array_values($found);
    }

    private function load(): void
    {
        if ($this->full !== null) {
            return;
        }
        $this->full = [];
        foreach (Product::query()->get(['id', 'reference', 'normalized_reference', 'base_reference']) as $p) {
            $this->full[$p->normalized_reference][] = $p->id;
            if ($p->base_reference) {
                $this->base[ProductReferenceNormalizer::normalize($p->base_reference)][] = $p->id;
            }
            if ($k = ProductReferenceNormalizer::sizeKey($p->reference)) {
                $this->size[$k[0].$k[1]][] = $p->id;
            }
        }
    }
}
