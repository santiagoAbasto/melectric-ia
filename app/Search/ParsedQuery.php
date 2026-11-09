<?php

declare(strict_types=1);

namespace App\Search;

/** Requisitos estructurados de una consulta. Los valores van en unidad canónica (A, V, W, VAR). */
final readonly class ParsedQuery
{
    /**
     * @param  list<string>  $intents
     * @param  array<string, mixed>  $values
     * @param  list<string>  $referenceCandidates
     */
    public function __construct(
        public string $raw,
        public ?string $productType,
        public array $intents,
        public array $values,
        public array $referenceCandidates,
    ) {}

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function hasIntent(string $intent): bool
    {
        return in_array($intent, $this->intents, true);
    }

    public function withValues(array $values, ?string $type = null): self
    {
        return new self($this->raw, $type ?? $this->productType, $this->intents, $values + $this->values, $this->referenceCandidates);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['raw' => $this->raw, 'product_type' => $this->productType, 'intents' => $this->intents, 'values' => $this->values, 'reference_candidates' => $this->referenceCandidates, 'parser' => ElectricalQueryParser::VERSION];
    }
}
