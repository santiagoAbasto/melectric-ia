<?php

declare(strict_types=1);

namespace App\AI;

use App\Search\ParsedQuery;

/**
 * Datos mínimos para seleccionar cada tipo de producto sin adivinar (spec §38). Pregunta sólo lo que falta y
 * como máximo dos cosas por vez: nada de interrogatorios.
 */
final class RequiredInputService
{
    /** tipo => lista de [claves aceptadas (una alcanza), etiqueta, pregunta] */
    public const REQUIRED = [
        'circuit_breaker' => [
            [['current'], 'Corriente nominal', '¿De cuántos amperes la necesitás?'],
            [['poles'], 'Polos', '¿Cuántos polos: 1, 2, 3 o 4?'],
            [['curve'], 'Curva', '¿Qué curva de disparo: B, C o D?'],
        ],
        'rcd' => [
            [['current'], 'Corriente nominal', '¿De cuántos amperes?'],
            [['poles'], 'Polos', '¿Bipolar o tetrapolar?'],
            [['sensitivity'], 'Sensibilidad', '¿Qué sensibilidad: 30 mA, 300 mA u otra?'],
        ],
        'contactor' => [
            [['power', 'current'], 'Potencia del motor o corriente AC-3', '¿Qué potencia tiene el motor (kW) o qué corriente AC-3 necesitás?'],
        ],
        'motor_protector' => [
            [['current', 'power'], 'Corriente nominal del motor', '¿Cuál es la corriente nominal del motor (la que figura en la placa)?'],
        ],
        'overload_relay' => [
            [['current'], 'Corriente nominal del motor', '¿Cuál es la corriente nominal del motor (placa)?'],
        ],
        'mccb' => [
            [['current'], 'Corriente nominal', '¿De qué corriente nominal?'],
            [['poles'], 'Polos', '¿Tripolar o tetrapolar?'],
        ],
        'disconnector' => [
            [['current'], 'Corriente nominal', '¿De qué corriente?'],
        ],
        'capacitor' => [
            [['reactive_power'], 'Potencia reactiva', '¿De cuántos kVAR?'],
        ],
        'vfd' => [
            [['power'], 'Potencia del motor', '¿Qué potencia tiene el motor (kW)?'],
            [['phases', 'voltage'], 'Alimentación', '¿La alimentación es monofásica 220 V o trifásica 380 V?'],
        ],
        'switch_generic' => [
            [['__type'], 'Tipo de interruptor', '¿Qué tipo de interruptor buscás: termomagnética, diferencial, compacto (caja moldeada) o seccionador?'],
            [['current'], 'Corriente nominal', '¿De cuántos amperes?'],
        ],
    ];

    /** Respuestas rápidas: formatos de respuesta que el parser entiende, no valores recomendados. */
    private const OPTIONS = [
        'poles' => [['1 polo', '1 polo'], ['2 polos', '2 polos'], ['3 polos', '3 polos'], ['4 polos', '4 polos']],
        'curve' => [['Curva B', 'curva B'], ['Curva C', 'curva C'], ['Curva D', 'curva D']],
        'sensitivity' => [['30 mA', '30 mA'], ['300 mA', '300 mA']],
        'phases' => [['Monofásica 220 V', 'monofásico 220 V'], ['Trifásica 380 V', 'trifásico 380 V']],
        '__type' => [['Termomagnética', 'termomagnética'], ['Diferencial', 'diferencial'], ['Compacto', 'interruptor compacto'], ['Seccionador', 'seccionador']],
    ];

    /** @return list<array{keys:list<string>, label:string, question:string, options?:list<array{label:string, send:string}>}> */
    public function missing(ParsedQuery $q, int $max = 2): array
    {
        $out = [];
        foreach (self::REQUIRED[$q->productType] ?? [] as [$keys, $label, $question]) {
            $present = collect($keys)->contains(fn ($k) => $k === '__type' ? false : $q->has($k));
            if (! $present) {
                $options = self::OPTIONS[$keys[0]] ?? null;
                if ($keys[0] === 'poles' && $q->productType === 'rcd') {
                    $options = [['Bipolar', '2 polos'], ['Tetrapolar', '4 polos']];
                }
                $out[] = ['keys' => $keys, 'label' => $label, 'question' => $question]
                    + ($options ? ['options' => array_map(fn ($o) => ['label' => $o[0], 'send' => $o[1]], $options)] : []);
            }
        }

        return array_slice($out, 0, $max);
    }
}
