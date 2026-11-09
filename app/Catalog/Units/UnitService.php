<?php

declare(strict_types=1);

namespace App\Catalog\Units;

use App\Models\ProductSpecification;

/**
 * Unidades de primera clase (spec §26). Todo valor se guarda en su unidad canónica (A, V, W, VA, VAR, Hz, F, mm, s, °C, %)
 * junto con el texto original. Dos cantidades sólo se comparan si tienen la misma dimensión: 30 mA nunca equivale a 30 A.
 */
final class UnitService
{
    /** símbolo => [dimensión, unidad canónica, factor] */
    public const UNITS = [
        'mA' => ['current', 'A', 0.001], 'A' => ['current', 'A', 1.0], 'kA' => ['current', 'A', 1000.0],
        'mV' => ['voltage', 'V', 0.001], 'V' => ['voltage', 'V', 1.0], 'kV' => ['voltage', 'V', 1000.0],
        'W' => ['power', 'W', 1.0], 'kW' => ['power', 'W', 1000.0], 'MW' => ['power', 'W', 1_000_000.0],
        'HP' => ['power', 'W', 745.699872], 'CV' => ['power', 'W', 735.49875],
        'VA' => ['apparent_power', 'VA', 1.0], 'kVA' => ['apparent_power', 'VA', 1000.0],
        'VAR' => ['reactive_power', 'VAR', 1.0], 'kVAR' => ['reactive_power', 'VAR', 1000.0],
        'Hz' => ['frequency', 'Hz', 1.0],
        'µF' => ['capacitance', 'F', 1e-6], 'nF' => ['capacitance', 'F', 1e-9], 'F' => ['capacitance', 'F', 1.0],
        'Ω' => ['resistance', 'Ω', 1.0], 'kΩ' => ['resistance', 'Ω', 1000.0],
        'mm' => ['length', 'mm', 1.0], 'cm' => ['length', 'mm', 10.0], 'm' => ['length', 'mm', 1000.0],
        'ms' => ['time', 's', 0.001], 's' => ['time', 's', 1.0], 'min' => ['time', 's', 60.0], 'h' => ['time', 's', 3600.0],
        '°C' => ['temperature', '°C', 1.0],
        '%' => ['percentage', '%', 1.0],
    ];

    /** Variantes escritas en catálogos y por clientes → símbolo canónico. */
    private const ALIASES = [
        'ma' => 'mA', 'mili' => 'mA', 'miliamperes' => 'mA', 'miliamper' => 'mA',
        'a' => 'A', 'amp' => 'A', 'amper' => 'A', 'amperes' => 'A', 'amperios' => 'A', 'ka' => 'kA',
        'v' => 'V', 'vac' => 'V', 'vca' => 'V', 'vdc' => 'V', 'vcc' => 'V', 'volt' => 'V', 'volts' => 'V', 'voltios' => 'V', 'kv' => 'kV', 'mv' => 'mV',
        'w' => 'W', 'kw' => 'kW', 'mw' => 'MW', 'hp' => 'HP', 'cv' => 'CV',
        'va' => 'VA', 'kva' => 'kVA', 'var' => 'VAR', 'kvar' => 'kVAR', 'kvars' => 'kVAR',
        'hz' => 'Hz', 'µf' => 'µF', 'μf' => 'µF', 'uf' => 'µF', 'nf' => 'nF',
        'ω' => 'Ω', 'ohm' => 'Ω', 'kω' => 'kΩ',
        'mm' => 'mm', 'cm' => 'cm', 'ms' => 'ms', 'seg' => 's', 's' => 's', 'min' => 'min', 'hs' => 'h', 'hrs' => 'h', 'h' => 'h',
        '°c' => '°C', 'ºc' => '°C', '%' => '%',
    ];

    public static function canonicalSymbol(string $unit): ?string
    {
        $u = trim($unit);
        if (isset(self::UNITS[$u])) {
            return $u;
        }

        return self::ALIASES[mb_strtolower($u)] ?? null;
    }

    /** "0,16" → 0.16 ; "2.600" → 2600 ; "4.5" → 4.5 */
    public static function number(string $raw): ?float
    {
        $s = str_replace([' ', "\u{00A0}"], '', trim($raw));
        if ($s === '' || ! preg_match('/^-?[\d.,]+$/', $s)) {
            return null;
        }
        if (str_contains($s, ',')) {
            $s = str_replace('.', '', $s);   // 1.234,5
            $s = str_replace(',', '.', $s);
        } elseif (preg_match('/^[1-9]\d{0,2}(\.\d{3})+$/', $s)) {
            $s = str_replace('.', '', $s);   // separador de miles: 2.600
        }

        return is_numeric($s) ? (float) $s : null;
    }

    /** Convierte a la unidad canónica. */
    public static function quantity(float $value, string $unit, string $raw = ''): ?Quantity
    {
        $symbol = self::canonicalSymbol($unit);
        if ($symbol === null) {
            return null;
        }
        [$dimension, $canonical, $factor] = self::UNITS[$symbol];

        return new Quantity(round($value * $factor, 9), $canonical, $dimension, $raw !== '' ? $raw : $value.' '.$unit);
    }

    /** Primer valor con unidad dentro de un texto: "bob 220 Vca" → 220 V. */
    public static function parse(string $text): ?Quantity
    {
        if (! preg_match('/(-?\d[\d.,]*)\s*('.self::unitPattern().')(?![a-zA-Z])/iu', $text, $m)) {
            return null;
        }
        $n = self::number($m[1]);

        return $n === null ? null : self::quantity($n, $m[2], trim($m[0]));
    }

    /** "0,16 a 63 A", "Reg. 25-32 A", "0.1-0.16 A" → [min, max] en la misma dimensión. */
    public static function parseRange(string $text): ?Range
    {
        if (! preg_match('/(\d[\d.,]*)\s*(?:-|–|a|hasta)\s*(\d[\d.,]*)\s*('.self::unitPattern().')(?![a-zA-Z])/iu', $text, $m)) {
            return null;
        }
        $min = self::number($m[1]);
        $max = self::number($m[2]);
        if ($min === null || $max === null || $min > $max) {
            return null;
        }
        $a = self::quantity($min, $m[3]);
        $b = self::quantity($max, $m[3]);

        return $a && $b ? new Range($a->value, $b->value, $a->unit, $a->dimension, trim($m[0])) : null;
    }

    /** Texto legible en la unidad más natural: 0.03 A → "30 mA"; 6000 A → "6 kA"; 15000 W → "15 kW". */
    public static function format(float $value, ?string $unit): string
    {
        $prefixed = match ($unit) {
            'A' => $value < 1 && $value > 0 ? [$value * 1000, 'mA'] : ($value >= 1000 ? [$value / 1000, 'kA'] : [$value, 'A']),
            'W' => $value >= 1000 ? [$value / 1000, 'kW'] : [$value, 'W'],
            'VAR' => $value >= 1000 ? [$value / 1000, 'kVAR'] : [$value, 'VAR'],
            'VA' => $value >= 1000 ? [$value / 1000, 'kVA'] : [$value, 'VA'],
            'V' => $value >= 1000 ? [$value / 1000, 'kV'] : [$value, 'V'],
            'F' => [$value * 1e6, 'µF'],
            null, '' => [$value, ''],
            default => [$value, $unit],
        };
        $n = rtrim(rtrim(number_format($prefixed[0], 3, ',', '.'), '0'), ',');

        return trim($n.' '.$prefixed[1]);
    }

    /** Rango con un solo prefijo, como en el catálogo: 0,1 – 0,16 A (no 100 mA – 160 mA). */
    public static function formatRange(float $min, float $max, ?string $unit): string
    {
        $full = self::format($max, $unit);
        $suffix = $unit === 'A' && $max >= 0.1 && $max < 1000 ? 'A' : trim((string) preg_replace('/^[\d.,]+\s*/u', '', $full));
        $factor = self::UNITS[$suffix][2] ?? 1.0;
        $n = fn (float $v) => rtrim(rtrim(number_format($v / $factor, 3, ',', '.'), '0'), ',');

        return $n($min).' – '.$n($max).' '.$suffix;
    }

    public static function display(ProductSpecification $s): string
    {
        if ($s->value_text !== null && $s->value === null && $s->value_min === null) {
            return $s->value_text;
        }
        if ($s->value_min !== null && $s->value_max !== null) {
            return self::formatRange($s->value_min, $s->value_max, $s->unit);
        }

        return $s->value !== null ? self::format($s->value, $s->unit) : $s->raw_value;
    }

    private static function unitPattern(): string
    {
        static $p = null;

        return $p ??= implode('|', array_map(fn ($u) => preg_quote($u, '/'),
            collect(array_merge(array_keys(self::UNITS), array_keys(self::ALIASES)))->unique()->sortByDesc(fn ($u) => mb_strlen($u))->values()->all()));
    }
}
