<?php

declare(strict_types=1);

namespace App\AI\DTOs;

/** Respuesta del orquestador: texto final + todo lo que la respalda, listo para la UI y la traza. */
final class AiAnswer
{
    /**
     * @param  list<array<string, mixed>>  $products  tarjetas de producto
     * @param  list<array<string, mixed>>  $citations
     * @param  list<array<string, mixed>>  $missing
     * @param  array<string, mixed>|null  $table  tabla técnica (TECHNICAL_TABLE) o comparación
     * @param  array<string, mixed>  $trace
     */
    public function __construct(
        public string $text,
        public string $outcome,              // ANSWERED | ASKED_INPUT | ABSTAINED | HANDOFF
        public array $products = [],
        public array $citations = [],
        public array $missing = [],
        public ?array $table = null,
        public float $confidence = 0.0,
        public array $confidenceBreakdown = [],
        public array $rulesFired = [],
        public array $toolCalls = [],
        public ?string $handoff = null,      // SALES | ENGINEERING | TECHNICAL | GENERAL
        public ?string $handoffReason = null,
        public string $traceId = '',
        public ?int $runId = null,
        public array $trace = [],
    ) {}

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return ['outcome' => $this->outcome, 'products' => $this->products, 'citations' => $this->citations, 'missing' => $this->missing,
            'table' => $this->table, 'confidence' => $this->confidence, 'handoff' => $this->handoff, 'handoff_reason' => $this->handoffReason,
            'rules' => $this->rulesFired, 'trace_id' => $this->traceId];
    }
}
