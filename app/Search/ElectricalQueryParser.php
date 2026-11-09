<?php

declare(strict_types=1);

namespace App\Search;

use App\Catalog\Units\UnitService;

/**
 * Convierte una consulta en español rioplatense en requisitos estructurados (spec §61). Determinista: sin LLM.
 * "Diferencial 4 polos 40 amperes 30 mili" → type=rcd, poles=4, rated_current=40 A, sensitivity=0,03 A.
 */
final class ElectricalQueryParser
{
    public const VERSION = 'query_parser@1';

    /** [regex, tipo] — el orden importa: "relé térmico" antes que "térmica". */
    private const TYPES = [
        ['/\brel[eé]s?\s+t[eé]rmicos?|\brelevos?\s+t[eé]rmicos?/u', 'overload_relay'],
        ['/\bguarda\s?motor(es)?|\bprotector(es)? de motor|\bpkzm/u', 'motor_protector'],
        ['/\bdiferencial(es)?\b|\bdisyuntor(es)?\b|\brccb\b|\brcd\b/u', 'rcd'],
        ['/\bminicontactor|\bcontactor(es)?\b/u', 'contactor'],
        ['/\bcompactos?\b|\bcaja moldeada\b|\bmccb\b/u', 'mccb'],
        ['/\btermomagn[eé]tic|\bt[eé]rmicas?\b|\bllave t[eé]rmica|\bmcb\b|\binterruptor(es)?\s+(autom[aá]tico|curva)/u', 'circuit_breaker'],
        ['/\bseccionador(es)?\b/u', 'disconnector'],
        ['/\btelerruptor(es)?\b/u', 'impulse_relay'],
        ['/\bcapacitor(es)?\b|\bcondensador(es)?\b|\bbanco(s)? de capacitores/u', 'capacitor'],
        ['/\bcontrolador(es)? (de )?(factor|cos)|\bregulador(es)? de (factor|reactiva)|\bpfr\d*/u', 'pf_controller'],
        ['/\bvariador(es)?\b|\bvfd\b|\bpi150\b|\bpi500\b/u', 'vfd'],
        ['/\btemporizador|\brel[eé] (de )?falta de fase|\bmultimedidor/u', 'relay_measurement'],
        ['/\bpulsador|\bselectora|\bbotonera|\bpiloto|\bparada de emergencia/u', 'control_signaling'],
        ['/\binterruptor(es)?\b|\bprotecci[oó]n(es)?\b|\bllave(s)?\b/u', 'switch_generic'],
    ];

    private const INTENTS = [
        'HUMAN' => '/hablar con (una persona|un humano|un asesor|alguien|un vendedor|un ingeniero)|\boperador\b|atenci[oó]n humana/u',
        'PRICE' => '/\bprecio|cu[aá]nto (cuesta|sale|vale)|\bcosto\b|\bdescuento|lista de precios|cotiz/u',
        'STOCK' => '/\bstock\b|\bdisponib|\btienen en existencia|plazo de entrega|\bentrega\b/u',
        'CALCULATION' => '/cu[aá]ntos? kvar (necesito|me hace falta)|\bcalcul|\bdimension|ca[ií]da de tensi[oó]n|cortocircuito|selectividad|secci[oó]n de (cable|conductor)|puesta a tierra|arc flash/u',
        'PROJECT' => '/\bf[aá]brica\b|instalaci[oó]n completa|tablero completo|lista de (componentes|materiales)|\barm[aá]me\b|\bbom\b/u',
        'COMPARE' => '/\bcompar|\bdiferencia(s)? entre/u',
        'REPLACEMENT' => '/\breemplazo|\bequivalente|\bsustitut|\breemplaza/u',
        'COMPATIBILITY' => '/\bsirve (para|con)|\bcompatible|\bcorresponde|\bacepta\b|\baccesorios?\b|\bauxiliar(es)? (para|de)|\bva con\b|\bqu[eé] (rel[eé]|bobina|auxiliar|contacto)/u',
        'DOCUMENT' => '/\bdocumenta|\bcat[aá]logo|\bficha\b|\bmanual\b|\bp[aá]gina\b|\ben qu[eé] (documento|cat[aá]logo)|\bfuente\b/u',
        'PHOTO' => '/\b(te (env[ií]o|mando|paso) (una )?(foto|imagen)|foto (del|de la|de un)|fotograf[ií]a)\b/u',
        'GREETING' => '/^(hola|buen(os|as) (d[ií]as|tardes|noches)|buenas)\b[^?]{0,20}$/u',
    ];

    public function parse(string $raw): ParsedQuery
    {
        $q = mb_strtolower(trim($raw));
        $q = strtr($q, ['á' => 'á', 'é' => 'é']);
        $type = null;
        foreach (self::TYPES as [$re, $t]) {
            if (preg_match($re, $q)) {
                $type = $t;
                break;
            }
        }
        $intents = [];
        foreach (self::INTENTS as $name => $re) {
            if (preg_match($re, $q)) {
                $intents[] = $name;
            }
        }
        $v = [];
        $num = '(\d+(?:[.,]\d+)?)';

        if (preg_match("/{$num}\s*(?:ma|mili(?:amper(?:e|io)s?)?)\b/u", $q, $m)) {
            $v['sensitivity'] = UnitService::number($m[1]) / 1000;
        }
        if (preg_match("/{$num}\s*ka\b/u", $q, $m)) {
            $v['breaking_capacity'] = UnitService::number($m[1]) * 1000;
        }
        if (preg_match("/{$num}\s*(?:a|amp|amperes?|amperios?|amper)\b/u", $q, $m)) {
            $v['current'] = UnitService::number($m[1]);
        }
        if (preg_match("/{$num}\s*kvar/u", $q, $m)) {
            $v['reactive_power'] = UnitService::number($m[1]) * 1000;
        }
        if (preg_match("/{$num}\s*(kw|kilowatts?|hp|cv)\b/u", $q, $m)) {
            $q2 = UnitService::quantity(UnitService::number($m[1]), $m[1] === 'kilowatt' ? 'kW' : $m[2]);
            $v['power'] = $q2?->value;
            $v['power_unit_raw'] = $m[2];
        }
        if (preg_match('/bobina\s*(?:de\s*)?(\d+)\s*(?:v|volts?)?\s*(ac|dc|ca|cc|vca|vcc|vac|vdc)?/u', $q, $m)) {
            $v['coil_voltage'] = (float) $m[1];
            if (! empty($m[2])) {
                $v['coil_kind'] = in_array($m[2], ['ac', 'ca', 'vca', 'vac'], true) ? 'AC' : 'DC';
            }
            $q = str_replace($m[0], ' ', $q);
        }
        if (preg_match('/(?<![\d.,])(\d{2,3})\s*(?:v|volts?|voltios|vca|vac|vcc|vdc)\b/u', $q, $m)) {
            $v['voltage'] = (float) $m[1];
        } elseif (preg_match('/\b(?:trif[aá]sico|monof[aá]sico|a)\s+(220|230|380|400|415|440)\b(?!\s*(?:a|ka|ma|kw)\b)/u', $q, $m)) {
            $v['voltage'] = (float) $m[1];
        }
        if (preg_match('/\b(\d)\s*(?:p|polos?)\b/u', $q, $m)) {
            $v['poles'] = (int) $m[1];
        } elseif (preg_match('/\b(unipolar|bipolar|tripolar|tetrapolar)\b/u', $q, $m)) {
            $v['poles'] = ['unipolar' => 1, 'bipolar' => 2, 'tripolar' => 3, 'tetrapolar' => 4][$m[1]];
        } elseif (preg_match('/\b(un|uno|dos|tres|cuatro)\s+polos?\b/u', $q, $m)) {
            $v['poles'] = ['un' => 1, 'uno' => 1, 'dos' => 2, 'tres' => 3, 'cuatro' => 4][$m[1]];
        }
        if (preg_match('/\bcurva\s*(?:tipo\s*)?([bcd])\b/u', $q, $m)) {
            $v['curve'] = strtoupper($m[1]);
        }
        if (preg_match('/\btrif[aá]sic|\b3\s*fases|\btres fases/u', $q)) {
            $v['phases'] = 3;
        } elseif (preg_match('/\bmonof[aá]sic|\b1\s*fase|\buna fase/u', $q)) {
            $v['phases'] = 1;
        }
        if (preg_match('/\bac-?([13])\b/u', $q, $m)) {
            $v['utilization_category'] = 'AC-'.$m[1];
        }
        if (preg_match('/\bmotor/u', $q)) {
            $v['application'] = 'motor';
        }

        // Candidatos a referencia: tokens con letras y dígitos ("DILM25", "HL-C32/3-HX", "PKZM0-1,6")
        preg_match_all('/\b[a-z][a-z0-9]*(?:[-\/.,]?[a-z0-9]+)*(?:\([^)\s]{2,30}\))?/iu', $raw, $m);
        $refs = array_values(array_filter(array_unique($m[0]), fn ($t) => mb_strlen($t) >= 4
            && ! preg_match('/^\d+(?:[.,]\d+)?\s*(?:a|ka|ma|kw|v|hp|cv|kvar|p|polos?)$/iu', $t) && preg_match('/[a-z]/i', $t) && preg_match('/\d/', $t)));

        // Consulta corta sin tipo de producto: puede ser una referencia escrita con espacios ("hl c32 3 hx")
        $whole = trim($raw, " \t?¿!¡.");
        if ($type === null && preg_match('/\d/', $whole) && preg_match('/[a-z]/i', $whole) && str_word_count($whole) <= 5 && ! in_array($whole, $refs, true)) {
            array_unshift($refs, $whole);
        }

        return new ParsedQuery($raw, $type, $intents, $v, $refs);
    }
}
