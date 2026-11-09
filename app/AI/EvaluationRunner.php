<?php

declare(strict_types=1);

namespace App\AI;

use App\Models\EvalRun;

/**
 * Corre el dataset dorado (tests/AI/evals/golden.json) contra el orquestador real y mide (spec §114-120):
 * aciertos, respuestas falsas, abstenciones correctas e innecesarias y la métrica más peligrosa:
 * respuesta técnica falsa con confianza alta.
 */
final class EvaluationRunner
{
    public function __construct(private readonly AIOrchestrator $ai) {}

    public function run(?int $promptVersionId = null, ?int $userId = null, bool $useLlm = false): EvalRun
    {
        $cases = json_decode((string) file_get_contents(base_path('tests/AI/evals/golden.json')), true);
        $results = [];
        foreach ($cases as $case) {
            $a = $this->ai->answer($case['query'], null, 'EVAL', ['use_llm' => $useLlm, 'prompt_version_id' => $promptVersionId]);
            $refs = array_merge(array_column($a->products, 'reference'), [$a->text]);
            $fails = [];
            if (isset($case['expected_outcome']) && $a->outcome !== $case['expected_outcome']) {
                $fails[] = "outcome {$a->outcome} ≠ {$case['expected_outcome']}";
            }
            if (isset($case['expected_reference']) && ! collect($refs)->contains(fn ($r) => str_contains((string) $r, $case['expected_reference']))) {
                $fails[] = "falta la referencia {$case['expected_reference']}";
            }
            foreach ($case['required_facts'] ?? [] as $fact) {
                if (! str_contains($a->text, $fact)) {
                    $fails[] = "falta «{$fact}»";
                }
            }
            foreach ($case['forbidden_claims'] ?? [] as $claim) {
                if (str_contains($a->text, $claim)) {
                    $fails[] = "afirma «{$claim}»";
                }
            }
            foreach ($case['expected_rules'] ?? [] as $rule) {
                if (! in_array($rule, $a->rulesFired, true)) {
                    $fails[] = "no disparó {$rule}";
                }
            }
            if (isset($case['expected_tool']) && ! in_array($case['expected_tool'], array_column($a->toolCalls, 'tool'), true)) {
                $fails[] = "no usó {$case['expected_tool']}";
            }
            if (isset($case['expected_handoff']) && $a->handoff !== $case['expected_handoff']) {
                $fails[] = 'derivación '.($a->handoff ?? 'ninguna')." ≠ {$case['expected_handoff']}";
            }
            $results[] = ['id' => $case['id'], 'dataset' => $case['dataset'], 'query' => $case['query'], 'expected' => $case['expected_outcome'] ?? null,
                'outcome' => $a->outcome, 'confidence' => $a->confidence, 'passed' => $fails === [], 'failures' => $fails, 'trace_id' => $a->traceId];
        }

        $c = collect($results);
        $shouldAnswer = $c->where('expected', 'ANSWERED');
        $shouldNot = $c->filter(fn ($r) => in_array($r['expected'], ['ABSTAINED', 'ASKED_INPUT', 'HANDOFF'], true));
        $metrics = [
            'cases' => $c->count(), 'passed' => $c->where('passed', true)->count(),
            'pass_rate' => round($c->where('passed', true)->count() / max(1, $c->count()), 3),
            'correct_answer_rate' => round($shouldAnswer->where('passed', true)->count() / max(1, $shouldAnswer->count()), 3),
            'false_answer_rate' => round($shouldNot->where('outcome', 'ANSWERED')->count() / max(1, $shouldNot->count()), 3),
            'correct_abstention_rate' => round($shouldNot->filter(fn ($r) => $r['outcome'] !== 'ANSWERED')->count() / max(1, $shouldNot->count()), 3),
            'unnecessary_abstention_rate' => round($shouldAnswer->filter(fn ($r) => $r['outcome'] !== 'ANSWERED')->count() / max(1, $shouldAnswer->count()), 3),
            'false_confident_technical' => $c->filter(fn ($r) => ! $r['passed'] && $r['outcome'] === 'ANSWERED' && $r['confidence'] >= 0.8)->count(),
            'by_dataset' => $c->groupBy('dataset')->map(fn ($g) => ['cases' => $g->count(), 'passed' => $g->where('passed', true)->count()])->all(),
            'llm' => $useLlm,
        ];

        return EvalRun::query()->create(['dataset' => 'golden', 'prompt_version_id' => $promptVersionId, 'metrics' => $metrics, 'results' => $results, 'run_by' => $userId]);
    }
}
