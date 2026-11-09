<?php

declare(strict_types=1);

namespace App\Catalog\Units;

/** Rango cerrado [min, max] en unidad canónica: "Reg. 25-32 A". */
final readonly class Range
{
    public function __construct(
        public float $min,
        public float $max,
        public string $unit,
        public string $dimension,
        public string $raw,
        public bool $minInclusive = true,
        public bool $maxInclusive = true,
    ) {}

    public function contains(Quantity $q): bool
    {
        return $q->dimension === $this->dimension
            && ($this->minInclusive ? $q->value >= $this->min : $q->value > $this->min)
            && ($this->maxInclusive ? $q->value <= $this->max : $q->value < $this->max);
    }
}
