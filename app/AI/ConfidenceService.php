<?php

declare(strict_types=1);

namespace App\AI;

/**
 * Confianza calculada por el sistema, nunca declarada por el LLM (spec §77). Combina señales objetivas y aplica
 * topes por riesgo: conflicto de fuentes ≤ 0,4; relación no verificada ≤ 0,6; sólo evidencia documental ≤ 0,6.
 * No está calibrada todavía: 0,9 no significa 90 % de aciertos hasta tener evaluaciones con revisión humana (§78).
 */
final class ConfidenceService
{
    private const STRATEGY = ['exact_reference' => 0.97, 'normalized_reference' => 0.92, 'series_size' => 0.82,
        'structured' => 0.88, 'full_text' => 0.5, 'knowledge' => 0.6, 'none' => 0.0];

    /**
     * @param  array{strategy?:string, attribute_match?:float, spec_confidence?:float, conflict?:bool, unverified_relation?:bool,
     *     document_only?:bool, next_size?:bool, retrieval_score?:?float, sources?:int, ocr?:?float}  $s
     * @return array{score:float, breakdown:array<string, mixed>}
     */
    public function score(array $s): array
    {
        $b = ['strategy' => $s['strategy'] ?? 'none'];
        $score = self::STRATEGY[$b['strategy']] ?? 0.0;
        foreach (['attribute_match', 'spec_confidence', 'ocr'] as $k) {
            if (isset($s[$k])) {
                $score *= max(0.0, min(1.0, (float) $s[$k]));
                $b[$k] = round((float) $s[$k], 3);
            }
        }
        if (isset($s['retrieval_score'])) {
            $score = min($score, 0.4 + (float) $s['retrieval_score'] * 0.4);
            $b['retrieval_score'] = $s['retrieval_score'];
        }
        if (! empty($s['next_size'])) {
            $score -= 0.1;
            $b['next_size_penalty'] = -0.1;
        }
        $sources = (int) ($s['sources'] ?? 0);
        if ($sources >= 2) {
            $score = min(0.99, $score + 0.03);
            $b['independent_sources'] = $sources;
        }
        foreach (['conflict' => 0.4, 'unverified_relation' => 0.6, 'document_only' => 0.6] as $flag => $cap) {
            if (! empty($s[$flag])) {
                $score = min($score, $cap);
                $b['cap_'.$flag] = $cap;
            }
        }
        $b['calibrated'] = false;

        return ['score' => round(max(0.0, $score), 3), 'breakdown' => $b];
    }
}
