<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Catalog\Units\UnitService;

/**
 * Extracción determinista de especificaciones desde la fila del Excel (referencia + descripción + columnas
 * estructuradas). Cada valor sale con su texto crudo, el origen y una confianza fija por regla: nunca se infiere un dato
 * que no esté escrito. Si dos orígenes discrepan, se devuelven ambos y el importador abre un conflicto.
 */
final class SpecExtractor
{
    public const VERSION = 'description_parser@1';

    /** Tipos para los que la corriente en A de la descripción es la corriente asignada (In). */
    private const IN_TYPES = ['circuit_breaker', 'rcd', 'disconnector', 'impulse_relay', 'motor_starter', 'mccb'];

    /**
     * @return list<ExtractedSpec>
     */
    public function extract(string $reference, string $description, ?string $productType, ?string $subfamily = null, ?int $sheetPoles = null): array
    {
        $out = [];
        $d = $description;
        $add = function (string $key, string $raw, float $conf, string $origin, ?float $value = null, ?string $unit = null, ?string $text = null, ?float $min = null, ?float $max = null, ?string $qualifier = null) use (&$out) {
            $out[] = new ExtractedSpec($key, $raw, $conf, $origin, $value, $unit, $text, $min, $max, $qualifier);
        };

        // Polos
        if ($sheetPoles) {
            $add('poles', (string) $sheetPoles, 0.95, 'sheet', (float) $sheetPoles);
        } elseif (preg_match('/\b(unipolar|bipolar|tripolar|tetrapolar)\b/iu', $d, $m)) {
            $n = ['unipolar' => 1, 'bipolar' => 2, 'tripolar' => 3, 'tetrapolar' => 4][mb_strtolower($m[1])];
            $add('poles', $m[0], 0.85, 'description', (float) $n);
        } elseif (preg_match('/\b([1-4])\s*(?:P|polos?)\b(?!\w)/u', $d, $m)) {
            $add('poles', $m[0], 0.8, 'description', (float) $m[1]);
        }

        // Curva (B/C/D): descripción, subfamilia de la hoja y la propia referencia (mMC6-C32/3, HL-C16/1-HX)
        if (preg_match('/curva\s*(?:tipo\s*)?([BCD])\b/iu', $d, $m)) {
            $add('curve', $m[0], 0.85, 'description', text: strtoupper($m[1]));
        }
        if ($subfamily && preg_match('/^Curva\s+([BCD])\b/iu', $subfamily, $m)) {
            $add('curve', $subfamily, 0.95, 'sheet', text: strtoupper($m[1]));
        }
        if (preg_match('/^(?:mMC6|mMCM|mMCMDC|mMCT|HL|PLN6|PLHT)-([BCD])(\d+)/u', $reference, $m)) {
            $add('curve', $m[0], 0.9, 'reference', text: $m[1]);
            if (in_array($productType, ['circuit_breaker'], true)) {
                $add('rated_current', $m[0], 0.9, 'reference', (float) $m[2], 'A');
            }
        }

        // Poder de corte
        if ($subfamily && preg_match('/(\d[\d.,]*)\s*kA/iu', $subfamily, $m)) {
            $add('breaking_capacity', $subfamily, 0.95, 'sheet', UnitService::number($m[1]) * 1000, 'A');
        }
        if (preg_match('/(Icu|F:)?\s*(\d[\d.,]*)\s*kA\b/iu', $d, $m)) {
            $add('breaking_capacity', trim($m[0]), 0.85, 'description', UnitService::number($m[2]) * 1000, 'A', qualifier: $m[1] === 'Icu' ? 'Icu' : null);
        }

        // Sensibilidad diferencial
        if (in_array($productType, ['rcd', 'relay_measurement', 'accessory'], true)) {
            if (preg_match('/(\d[\d.,]*)\s*mA\s*a\s*(\d[\d.,]*)\s*A\b/iu', $d, $m)) {
                $add('sensitivity', $m[0], 0.85, 'description', min: UnitService::number($m[1]) / 1000, max: UnitService::number($m[2]), unit: 'A', qualifier: 'regulable');
            } elseif (preg_match('/(?<![\d,])(\d+)\s*mA\b/u', $d, $m) && ! str_contains($d, ', 100, 300')) {
                $add('sensitivity', $m[0], 0.9, 'description', (float) $m[1] / 1000, 'A');
            }
            if (preg_match('/\b(selectivo|super\s*inmunizado|superinmunizado)\b/iu', $d.' '.$subfamily, $m)) {
                $add('rcd_variant', $m[0], 0.9, 'description', text: str_contains(mb_strtolower($m[1]), 'selec') ? 'Selectivo' : 'Superinmunizado');
            }
        }

        // Corriente asignada en protecciones y seccionadores
        if (in_array($productType, self::IN_TYPES, true) && ! preg_match('/Reg\./iu', $d)) {
            $clean = preg_replace('/\d[\d.,]*\s*[km]A\b/iu', ' ', $d) ?? $d;   // fuera kA y mA
            if (preg_match('/(?:In:?\s*)?(?<![\w.,])(\d+(?:[.,]\d+)?)\s*A\b(?!C)/u', $clean, $m)) {
                $add('rated_current', trim($m[0]), 0.85, 'description', UnitService::number($m[1]), 'A');
            }
        }

        // Rangos de regulación (guardamotores, relés térmicos, compactos)
        if (preg_match('/Reg\.?\s*(\d[\d.,]*)\s*-\s*(\d[\d.,]*)\s*A\b/iu', $d, $m)) {
            $add('current_setting_range', $m[0], 0.9, 'description', min: UnitService::number($m[1]), max: UnitService::number($m[2]), unit: 'A');
        }

        // Contactores: categoría, potencia, corriente AC-3/AC-1, tensión, bobina y auxiliares
        if (preg_match('/\bAC-?([13])\b/u', $d, $m)) {
            $add('utilization_category', $m[0], 0.85, 'description', text: 'AC-'.$m[1]);
        }
        if (preg_match('/AC1\s*=\s*(\d+)\s*A/iu', $d, $m)) {
            $add('rated_current_ac1', $m[0], 0.85, 'description', (float) $m[1], 'A');
        }
        if (preg_match('/AC3\s*=\s*(\d+)\s*A/iu', $d, $m)) {
            $add('rated_current_ac3', $m[0], 0.85, 'description', (float) $m[1], 'A');
        } elseif (preg_match('/kW\s*\/\s*(\d+(?:[.,]\d+)?)\s*A\b/iu', $d, $m)) {
            $add('rated_current_ac3', $m[0], 0.85, 'description', UnitService::number($m[1]), 'A');
        }
        if (in_array($productType, ['contactor', 'motor_protector', 'motor_starter'], true)
            && preg_match('/(\d+(?:[.,]\d+)?)\s*kW\b/iu', $d, $m)) {
            $qualifier = $productType === 'motor_protector' ? 'potencia de motor a 380 VAC' : 'AC-3 3x380 V';
            $add('motor_power', $m[0], 0.85, 'description', UnitService::number($m[1]) * 1000, 'W', qualifier: $qualifier);
        }
        if (preg_match('/3\s*x\s*(\d{3})\s*V/iu', $d, $m)) {
            $add('operating_voltage', $m[0], 0.85, 'description', (float) $m[1], 'V', qualifier: 'trifásica');
        } elseif ($productType === 'motor_protector' && preg_match('/(\d{3})\s*VAC/iu', $d, $m)) {
            $add('operating_voltage', $m[0], 0.8, 'description', (float) $m[1], 'V');
        }
        if (preg_match('/(?:bob\.?\s*|–\s*|-\s*)(\d+)\s*Vc([ac])\b/iu', $d, $m)) {
            $add('coil_voltage', $m[0], 0.85, 'description', (float) $m[1], 'V', qualifier: strtolower($m[2]) === 'a' ? 'AC' : 'DC');
        }
        if ($productType === 'contactor' && preg_match('/\(([^)]*(?:V|HZ|RDC|RAC|RA|RAW)[^)]*)\)\s*$/iu', ProductReferenceNormalizer::withoutNotes($reference), $m)) {
            $add('coil_designation', $m[1], 0.9, 'reference', text: $m[1]);
        }
        if (preg_match('/\b(\d\s*N[AOC](?:\s*\+\s*\d\s*N[AOC])*)\b/u', $d, $m)) {
            $add('auxiliary_contacts', $m[0], 0.85, 'description', text: str_replace(['NO', ' '], ['NA', ''], $m[1]));
        }

        // Compensación de energía reactiva
        if (preg_match('/(?:Qn:\s*)?(\d+(?:[.,]\d+)?)\s*kVAR/iu', $d, $m)) {
            $add('reactive_power', $m[0], 0.9, 'description', UnitService::number($m[1]) * 1000, 'VAR',
                qualifier: preg_match('/3\s*x\s*400\s*V/iu', $d) ? '3x400 V' : null);
        }
        if (preg_match('/Un:\s*(\d+)\s*V/iu', $d, $m)) {
            $add('rated_voltage', $m[0], 0.9, 'description', (float) $m[1], 'V');
        }
        if (preg_match('/(\d\s*x\s*)?(\d+(?:[.,]\d+)?)\s*[μµu]F/u', $d, $m)) {
            $add('capacitance', $m[0], 0.85, 'description', UnitService::number($m[2]) * 1e-6, 'F', qualifier: $m[1] !== '' ? 'por fase' : null);
        }
        if (preg_match('/(\d+)\s*pasos\b/iu', $d, $m)) {
            $add('steps', $m[0], 0.9, 'description', (float) $m[1]);
        }
        if (preg_match('/\bIP\s?(\d{2})\b/u', $d, $m)) {
            $add('ip_rating', $m[0], 0.9, 'description', text: 'IP'.$m[1]);
        }
        if (preg_match('/(\d+)\s*x\s*(\d+)\s*mm\b/iu', $d, $m)) {
            $add('dimensions', $m[0], 0.8, 'description', text: $m[1].' x '.$m[2].' mm');
        }

        return $out;
    }
}
