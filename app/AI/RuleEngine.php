<?php

declare(strict_types=1);

namespace App\AI;

use App\Models\AiRule;

/**
 * Reglas deterministas (spec §76). La lógica vive en código; la tabla ai_rules guarda si está activa y su umbral.
 * Las reglas de seguridad están bloqueadas: se puede ajustar el umbral pero no apagarlas.
 */
final class RuleEngine
{
    public const RULES = [
        'EXACT_REFERENCE_LOOKUP' => ['Referencia exacta → búsqueda estructurada, sin LLM avanzado', 'structured_lookup', null],
        'ASK_FOR_MISSING_INPUT' => ['Selección técnica con datos faltantes → preguntar', 'ask_for_input', null],
        'SAFETY_LOW_CONFIDENCE_REVIEW' => ['Consulta crítica con confianza baja → revisión humana', 'human_review', 0.6],
        'CONFLICTING_SOURCES_NO_DEFINITIVE' => ['Fuentes en conflicto → no afirmar un dato definitivo', 'no_definitive_claim', null],
        'UNVERIFIED_RELATION_NO_CLAIM' => ['Relación no verificada → no afirmar compatibilidad', 'no_compatibility_claim', null],
        'PRICE_UNAVAILABLE' => ['Precio pedido sin proveedor autorizado → no disponible', 'price_unavailable', null],
        'STOCK_UNAVAILABLE' => ['Stock pedido sin proveedor autorizado → no disponible', 'stock_unavailable', null],
        'CALCULATION_REQUIRES_ENGINEERING' => ['Cálculo eléctrico sin metodología aprobada → ingeniería', 'engineering_handoff', null],
        'VOLTAGE_OUTSIDE_CATALOG_TABLE' => ['Tensión fuera de la tabla del catálogo → no seleccionar', 'no_selection', null],
        'AC3_TABLE_NEXT_SIZE' => ['Sin fila exacta en la tabla AC-3 → tamaño siguiente, avisado', 'next_size_notice', null],
        'NO_EVIDENCE_ABSTAIN' => ['Sin evidencia suficiente → abstenerse', 'abstain', null],
        'HUMAN_REQUESTED' => ['El cliente pide una persona → derivar', 'handoff', null],
        'PROMPT_INJECTION_FLAGGED' => ['Instrucciones dentro de un documento → se tratan como texto', 'strip_from_context', null],
    ];

    public const LOCKED = ['SAFETY_LOW_CONFIDENCE_REVIEW', 'CONFLICTING_SOURCES_NO_DEFINITIVE', 'UNVERIFIED_RELATION_NO_CLAIM',
        'PRICE_UNAVAILABLE', 'STOCK_UNAVAILABLE', 'CALCULATION_REQUIRES_ENGINEERING', 'VOLTAGE_OUTSIDE_CATALOG_TABLE', 'NO_EVIDENCE_ABSTAIN', 'PROMPT_INJECTION_FLAGGED'];

    /** @var array<string, array{enabled:bool, threshold:?float}>|null */
    private ?array $config = null;

    /** @var list<string> */
    private array $fired = [];

    public function enabled(string $key): bool
    {
        return in_array($key, self::LOCKED, true) || ($this->config()[$key]['enabled'] ?? true);
    }

    public function threshold(string $key): ?float
    {
        return $this->config()[$key]['threshold'] ?? self::RULES[$key][2];
    }

    public function fire(string $key): void
    {
        if (! in_array($key, $this->fired, true)) {
            $this->fired[] = $key;
        }
    }

    /** @return list<string> */
    public function fired(): array
    {
        return $this->fired;
    }

    public function reset(): void
    {
        $this->fired = [];
    }

    public static function seed(): void
    {
        foreach (self::RULES as $key => [$description, $action, $threshold]) {
            AiRule::query()->firstOrCreate(['key' => $key], ['description' => $description, 'action' => $action, 'threshold' => $threshold, 'enabled' => true]);
        }
    }

    private function config(): array
    {
        return $this->config ??= AiRule::query()->get()->mapWithKeys(fn (AiRule $r) => [$r->key => ['enabled' => $r->enabled, 'threshold' => $r->threshold]])->all();
    }
}
