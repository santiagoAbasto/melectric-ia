<?php

declare(strict_types=1);

namespace App\Catalog;

use App\Models\SpecificationDefinition;

/** Definiciones de especificaciones (spec §24-25): tipo, dimensión, unidad canónica y si es crítica para seguridad. */
final class SpecCatalog
{
    /** key => [label, data_type, dimension, canonical_unit, safety_critical] */
    public const DEFINITIONS = [
        'rated_current' => ['Corriente asignada (In)', 'QUANTITY', 'current', 'A', true],
        'current_setting_range' => ['Rango de regulación', 'RANGE', 'current', 'A', true],
        'rated_current_ac3' => ['Corriente AC-3 (Ie)', 'QUANTITY', 'current', 'A', true],
        'rated_current_ac1' => ['Corriente AC-1 (Ith)', 'QUANTITY', 'current', 'A', true],
        'motor_power' => ['Potencia de motor', 'QUANTITY', 'power', 'W', true],
        'operating_voltage' => ['Tensión de empleo', 'QUANTITY', 'voltage', 'V', true],
        'rated_voltage' => ['Tensión asignada (Un)', 'QUANTITY', 'voltage', 'V', true],
        'coil_voltage' => ['Tensión de bobina', 'QUANTITY', 'voltage', 'V', true],
        'coil_designation' => ['Designación de bobina', 'STRING', null, null, false],
        'poles' => ['Polos', 'INTEGER', null, null, true],
        'curve' => ['Curva', 'ENUM', null, null, true],
        'breaking_capacity' => ['Poder de corte', 'QUANTITY', 'current', 'A', true],
        'sensitivity' => ['Sensibilidad diferencial (IΔn)', 'QUANTITY', 'current', 'A', true],
        'rcd_variant' => ['Variante diferencial', 'ENUM', null, null, true],
        'utilization_category' => ['Categoría de empleo', 'ENUM', null, null, true],
        'auxiliary_contacts' => ['Contactos auxiliares', 'STRING', null, null, false],
        'reactive_power' => ['Potencia reactiva', 'QUANTITY', 'reactive_power', 'VAR', true],
        'capacitance' => ['Capacidad', 'QUANTITY', 'capacitance', 'F', false],
        'steps' => ['Pasos', 'INTEGER', null, null, false],
        'ip_rating' => ['Grado IP', 'STRING', null, null, true],
        'dimensions' => ['Dimensiones', 'STRING', 'length', 'mm', false],
    ];

    /** @return array<string, int> key => id */
    public static function ensure(): array
    {
        $ids = [];
        foreach (self::DEFINITIONS as $key => [$label, $type, $dimension, $unit, $critical]) {
            $ids[$key] = SpecificationDefinition::query()->updateOrCreate(['key' => $key], [
                'label' => $label, 'data_type' => $type, 'dimension' => $dimension, 'canonical_unit' => $unit, 'safety_critical' => $critical,
            ])->id;
        }

        return $ids;
    }
}
