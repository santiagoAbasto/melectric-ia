<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Catalog\Units\UnitService;

/** Especificación candidata antes de guardarse, con su texto crudo y su origen (sheet | reference | description | table). */
final readonly class ExtractedSpec
{
    public function __construct(
        public string $key,
        public string $raw,
        public float $confidence,
        public string $origin,
        public ?float $value = null,
        public ?string $unit = null,
        public ?string $text = null,
        public ?float $min = null,
        public ?float $max = null,
        public ?string $qualifier = null,
    ) {}

    /** Clave para comparar dos candidatos del mismo campo. */
    public function comparable(): string
    {
        if ($this->min !== null || $this->max !== null) {
            return round((float) $this->min, 6).'..'.round((float) $this->max, 6).$this->unit;
        }

        return $this->value !== null ? round($this->value, 6).$this->unit : mb_strtoupper((string) $this->text);
    }

    public function display(): string
    {
        if ($this->min !== null && $this->max !== null) {
            return UnitService::formatRange($this->min, $this->max, $this->unit);
        }

        return $this->value !== null ? UnitService::format($this->value, $this->unit) : (string) $this->text;
    }
}
