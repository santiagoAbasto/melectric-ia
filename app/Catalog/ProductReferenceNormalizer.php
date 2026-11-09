<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * Normalización de referencias (spec §22). Sólo quita lo que no cambia la identidad: mayúsculas, espacios, guiones,
 * barras, y puntos o comas que no estén entre dígitos. Nunca quita dígitos ni letras: "mMC6-C32/3" y "mMC6-C32/2" siguen siendo distintos.
 * La referencia cruda se conserva siempre en products.reference.
 */
final class ProductReferenceNormalizer
{
    /** Cambia cuando cambian las reglas: el importador re-deriva los productos. */
    public const VERSION = 'ref_normalizer@2';

    /** Notas comerciales pegadas a la referencia en el Excel: "(Disponible hasta agotar stock)". */
    private const NOTE = '/\s*\((?:disponib|hasta\s+ag|sin\s+stock|discontinu|reemplaz)[^)]*\)?\s*/iu';

    public static function normalize(string $reference): string
    {
        $s = mb_strtoupper(self::withoutNotes($reference));
        // La coma o el punto decimal entre dígitos es parte de la identidad: PKZM0-1,6 (1,6 A) ≠ PKZM0-16 (16 A).
        $s = preg_replace('/(?<=\d)[.,](?=\d)/u', '§', $s) ?? $s;
        $s = preg_replace('/[\s\-_\/.,·]+/u', '', $s) ?? $s;

        return str_replace('§', '.', $s);
    }

    public static function withoutNotes(string $reference): string
    {
        return trim(preg_replace(self::NOTE, ' ', $reference) ?? $reference);
    }

    public static function notes(string $reference): ?string
    {
        return preg_match(self::NOTE, $reference, $m) ? trim($m[0], " ()\t") : null;
    }

    /** Sin la variante de bobina/tensión entre paréntesis: "DILM32-10(230V50HZ,240V60HZ)" → "DILM32-10". */
    public static function base(string $reference): string
    {
        $s = self::withoutNotes($reference);
        $s = preg_replace('/\s*\([^)]*\)\s*$/u', '', $s) ?? $s;

        return trim($s);
    }

    /** Serie comercial: DILM, XTCG, PKZM0, NZMN2, mMC6, mRCM, HL… (null si no se reconoce). */
    public static function series(string $reference): ?string
    {
        $r = self::base($reference);
        if (preg_match('/^(DILMP|DILEM|DILK|DILM|DILH|DILA|DILE|XTCG|XTOG|XTCE)\d/i', $r, $m)) {
            return strtoupper($m[1]);
        }
        if (preg_match('/^(mMCMDC|mMCM|mMCT|mMC6|mRCM|PFDM|PLN6|PLHT|HL|IS|PN\d|ZB\d+|ZE|Z5|ZW7|PKZM\d|PKE|NZM[A-Z]?\d|LZM[A-Z]?\d|BZM\d|PDC\d|PDG\d|M22S?|EASY|EMR6|EMT6|ETR\d|PFR|CSADG|CSADP|C4400|APM\d+|P\d|T\d[A-Z]?|DMV)/', $r, $m)) {
            return $m[1];
        }

        return preg_match('/^([A-Za-z]{2,})/', $r, $m) ? $m[1] : null;
    }

    /**
     * Serie y tamaño de contactores/relés para leer rangos "para DILM17 a DILM32": "DILM32-10" → ['DILM', 32].
     *
     * @return array{0:string,1:int}|null
     */
    public static function sizeKey(string $reference): ?array
    {
        if (preg_match('/^(DILMP|DILEM|DILK|DILM|DILH|XTCG)\s*0*(\d+)/i', self::base($reference), $m)) {
            return [strtoupper($m[1]), (int) $m[2]];
        }

        return null;
    }
}
