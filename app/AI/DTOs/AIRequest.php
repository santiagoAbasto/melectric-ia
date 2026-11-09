<?php

declare(strict_types=1);

namespace App\AI\DTOs;

/**
 * Petición neutral al proveedor de IA. El PromptBuilder ya separó SYSTEM /
 * BUSINESS RULES / RETRIEVED DATA / USER INPUT antes de llegar acá.
 * Sin `temperature`: la API de Claude la rechaza desde 2026-10-21; la
 * profundidad se controla con `effort` (low|medium|high).
 */
final readonly class AIRequest
{
    /**
     * @param  list<array{role:string, content:string|list<array<string, mixed>>}>  $messages  el contenido puede llevar bloques (imagen + texto)
     * @param  array<string, mixed>|null  $responseSchema  JSON Schema: el modelo responde un JSON que lo cumple
     * @param  array<string, mixed>  $metadata
     * @param  string  $systemData  parte del system que cambia en cada consulta (datos del catálogo): va después de
     *                              $system y fuera de la caché, para que lo fijo se reutilice entre consultas
     */
    public function __construct(
        public string $system,
        public array $messages,
        public string $model,
        public int $maxTokens = 1500,
        public ?string $effort = null,
        public array $metadata = [],
        public ?array $responseSchema = null,
        public string $systemData = '',
    ) {}

    /** System completo, para proveedores sin caché y para la traza. */
    public function fullSystem(): string
    {
        return trim($this->system."\n\n".$this->systemData);
    }
}
