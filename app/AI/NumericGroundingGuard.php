<?php

declare(strict_types=1);

namespace App\AI;

/**
 * Guardia contra datos inventados por el LLM redactor: cada número del texto final tiene que estar en la evidencia,
 * en el borrador determinista o en el mensaje del cliente. Tampoco puede afirmar compatibilidad, precio ni stock si
 * el borrador no lo hace. Si algo falla, se descarta el texto del LLM y se usa el borrador.
 */
final class NumericGroundingGuard
{
    /** @return array{ok:bool, violations:list<string>} */
    public function check(string $llmText, string $allowedCorpus, string $draft): array
    {
        $allowed = $this->numbers($allowedCorpus);
        $violations = [];
        foreach ($this->numbers($llmText) as $n) {
            if (! in_array($n, $allowed, true)) {
                $violations[] = "número sin evidencia: {$n}";
            }
        }
        // Cada cita [n] del borrador tiene que seguir en el texto final: sin cita, el dato pierde su fuente
        preg_match_all('/\[(\d+)\]/', $draft, $d);
        preg_match_all('/\[(\d+)\]/', $llmText, $l);
        $lost = array_diff(array_unique($d[1]), $l[1]);
        if ($lost) {
            $violations[] = 'perdió citas ['.implode('], [', $lost).']';
        }
        foreach (['/\bcompatible(s)?\b/iu' => 'afirma compatibilidad', '/(\$|USD|ARS)\s?\d/u' => 'menciona un precio', '/\b(en stock|hay stock|disponible para entrega)\b/iu' => 'afirma stock'] as $re => $why) {
            if (preg_match($re, $llmText) && ! preg_match($re, $draft)) {
                $violations[] = $why;
            }
        }

        return ['ok' => $violations === [], 'violations' => array_values(array_unique($violations))];
    }

    /** @return list<string> números normalizados ("5,5" y "5.5" → "5.5"; se ignoran los marcadores de cita [1]) */
    public function numbers(string $text): array
    {
        $text = preg_replace('/\[\d+\]/u', ' ', $text) ?? $text;
        preg_match_all('/\d+(?:[.,]\d+)?/u', $text, $m);

        // "5,50" → "5.5" y "40" sigue siendo "40": sólo se quitan ceros a la derecha de la parte decimal
        return array_values(array_unique(array_map(function ($n) {
            $n = str_replace(',', '.', $n);

            return str_contains($n, '.') ? (rtrim(rtrim($n, '0'), '.') ?: '0') : (ltrim($n, '0') ?: '0');
        }, $m[0])));
    }
}
