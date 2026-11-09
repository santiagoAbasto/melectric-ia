<?php

declare(strict_types=1);

namespace App\Catalog\Units;

/** Valor en unidad canónica con su dimensión y el texto original. */
final readonly class Quantity
{
    public function __construct(
        public float $value,
        public string $unit,
        public string $dimension,
        public string $raw,
    ) {}

    public function sameDimension(self $other): bool
    {
        return $this->dimension === $other->dimension;
    }

    public function display(): string
    {
        return UnitService::format($this->value, $this->unit);
    }
}
