<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Models\Product;
use App\Models\ProductRelationship;

/**
 * Lee relaciones escritas en la descripción del catálogo de MElectric: "Relé térmico para DILM17 hasta DILM32",
 * "Bobina para contactor de potencia DILM17 a DILM32, DILMP32 a DILMP45", "para DILM185 ; DILM225 y DILM250".
 * Toda relación nace AUTO_EXTRACTED con la frase como evidencia: el asistente no la afirma como compatibilidad
 * verificada hasta que un ingeniero la verifica.
 */
final class RelationshipExtractor
{
    public const VERSION = 'relationship_parser@1';

    private const SERIES = 'DILMP|DILEM|DILK|DILM|DILH|XTCG';

    /** Tipo de relación según la línea del accesorio. */
    public static function typeFor(string $familyName): ?string
    {
        return match (true) {
            (bool) preg_match('/^Rel[eé]s? T[eé]rmic/iu', $familyName) => 'PAIRS_WITH',
            (bool) preg_match('/^(Bloque|Contactos Aux|Contactos para)/iu', $familyName) => 'AUXILIARY_FOR',
            (bool) preg_match('/^(Bobinas|Conexion|Uniones|Enclavamiento|Mod\. Protec|Tempoizador|Accesorios DILM|Juego de Cableado)/iu', $familyName) => 'ACCESSORY_OF',
            default => null,
        };
    }

    /**
     * Rangos y listas de tamaños mencionados después de "para".
     *
     * @return list<array{series:string, from:int, to:int, evidence:string}>
     */
    public static function targets(string $description): array
    {
        if (! preg_match('/\bpara\b(.*)$/iu', $description, $m)) {
            return [];
        }
        $tail = $m[1];
        $out = [];
        $re = '/('.self::SERIES.')\s*(\d+)(?:\s*(?:a|hasta)\s*(?:('.self::SERIES.')\s*)?(\d+))?/iu';
        preg_match_all($re, $tail, $all, PREG_SET_ORDER);
        foreach ($all as $t) {
            $series = strtoupper($t[1]);
            $to = isset($t[4]) && $t[4] !== '' ? (int) $t[4] : (int) $t[2];
            if (isset($t[3]) && $t[3] !== '' && strtoupper($t[3]) !== $series) {
                continue; // rango entre series distintas: no se interpreta
            }
            $out[] = ['series' => $series, 'from' => (int) $t[2], 'to' => $to, 'evidence' => trim($t[0])];
        }
        // "Relé térmico para DILEM" / "para XTCG": toda la serie
        if ($out === [] && preg_match('/\bpara\s+('.self::SERIES.')\b(?!\s*\d)/iu', $description, $s)) {
            $out[] = ['series' => strtoupper($s[1]), 'from' => 0, 'to' => PHP_INT_MAX, 'evidence' => trim($s[0])];
        }

        return $out;
    }

    /** @return int relaciones creadas */
    public function extractFor(Product $accessory, string $familyName): int
    {
        $type = self::typeFor($familyName);
        if ($type === null) {
            return 0;
        }
        $created = 0;
        foreach (self::targets((string) $accessory->description) as $t) {
            $candidates = Product::query()->where('series', $t['series'])->where('id', '!=', $accessory->id)
                ->whereHas('category', fn ($q) => $q->where('product_type', 'contactor'))->get(['id', 'reference']);
            foreach ($candidates as $c) {
                $size = ProductReferenceNormalizer::sizeKey($c->reference);
                if (! $size || $size[1] < $t['from'] || $size[1] > $t['to']) {
                    continue;
                }
                $rel = ProductRelationship::query()->firstOrCreate(
                    ['product_id' => $accessory->id, 'related_product_id' => $c->id, 'type' => $type],
                    ['status' => 'AUTO_EXTRACTED', 'evidence' => mb_substr('«'.$accessory->description.'»', 0, 500),
                        'source_record_id' => $accessory->source_record_id, 'confidence' => 0.75],
                );
                $created += (int) $rel->wasRecentlyCreated;
            }
        }

        return $created;
    }
}
