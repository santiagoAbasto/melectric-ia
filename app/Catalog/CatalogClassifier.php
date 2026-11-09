<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * Clasifica cada línea (agrupador B2B) en una categoría con tipo de producto y rubro (filtros del Figma), y deduce la
 * marca sólo cuando hay evidencia: la columna MARCA de la hoja de MCBs, un prefijo de serie Eaton documentado en los
 * catálogos, o la frase «fabricados para Melectric» del catálogo de compensación. Si no hay evidencia, marca = null.
 */
final class CatalogClassifier
{
    /** [regex sobre el nombre del agrupador, categoría, tipo, rubro] — el orden importa. */
    private const RULES = [
        ['/^(Accesorios|Auxiliares|Bobinas|Bloque|Contactos? Aux|Contactos para|Conexion|Enclavamiento|Kit|Peines|Uniones|Mod\. Protec|Juego de Cableado|Ejes|Cajas Guardamotores|Mandos|Z[oó]calos|Base Pilarete|Lent[ií]culas|L[aá]mparas|Tempoizador)/iu', 'Accesorios y auxiliares', 'accessory', 'Industrial'],
        ['/^Termomagn/iu', 'Termomagnéticas', 'circuit_breaker', 'Domiciliario'],
        ['/^Diferenciales/iu', 'Diferenciales', 'rcd', 'Domiciliario'],
        ['/^Telerruptores/iu', 'Telerruptores', 'impulse_relay', 'Domiciliario'],
        ['/^(Contactores|Minicontactor)/iu', 'Contactores', 'contactor', 'Industrial'],
        ['/^Rel[eé]s? T[eé]rmicos?/iu', 'Relés térmicos', 'overload_relay', 'Industrial'],
        ['/^Guardamotores/iu', 'Guardamotores', 'motor_protector', 'Industrial'],
        ['/^Interruptor Compacto Seccionador|^Seccionador/iu', 'Seccionadores', 'disconnector', 'Industrial'],
        ['/^(Interruptor Compacto|Int\. Compactos)/iu', 'Interruptores compactos', 'mccb', 'Industrial'],
        ['/^Capacitores/iu', 'Capacitores', 'capacitor', 'Industrial'],
        ['/^Controlador FP/iu', 'Controladores de factor de potencia', 'pf_controller', 'Industrial'],
        ['/^(Arranques YD|Interruptor Y-D|Inversores de Marcha|Conexion Estrella)/iu', 'Arranque de motores', 'motor_starter', 'Industrial'],
        ['/^(Temporizadores|Rel[eé]s falta|Rel[eé] Diferencial|Multimedidores|Limite)/iu', 'Relés y medición', 'relay_measurement', 'Industrial'],
        ['/Easy E4/iu', 'Automatización', 'automation', 'Industrial'],
        ['/^UPS/iu', 'UPS', 'ups', 'Industrial'],
        ['/^(Pulsador|Selectora|Indicador|Parada|Joystick|Se[nñ]al|Modulos? L|Modulo Ac|Potenci|Conmutador|Caja Para Botoneras|Conjunto Completo|Cabezales|Interruptores de Posici)/iu', 'Mando y señalización', 'control_signaling', 'Industrial'],
    ];

    /** Prefijos de series Eaton/Moeller presentes en los catálogos de MElectric. */
    private const EATON = '/^(DIL|XTC|XTO|PKZ|PKE|NZM|LZM|BZM|PDC|PDG|mMC|mRC|HL-|PLN|PLH|PFDM|M22|EASY|EMR|EMT|ETR|ZB\d|ZE-|Z5-|ZW7|Z-|ZP-|ZV-|ZSCH|FAK|SL7|P1-|P3-|PN\d|T0-|T5|IS-|EVG|H-PKZ|N-PKZ|A-PKZ|AGM|NHI|CI-K|E-PKZ|XNH|HDILE|\d{2}DILE|SDAINLM|EU5|XIOC|XC-|XV|LS-|AT0|KS)/';

    /** @return array{category:string, product_type:string, rubro:string}|null */
    public static function classify(string $familyName): ?array
    {
        foreach (self::RULES as [$re, $category, $type, $rubro]) {
            if (preg_match($re, $familyName)) {
                return ['category' => $category, 'product_type' => $type, 'rubro' => $rubro];
            }
        }

        return null;
    }

    /** @return array{brand:string, evidence:string}|null */
    public static function brand(string $reference, ?string $familyName, ?string $sheetBrand): ?array
    {
        if ($sheetBrand) {
            return ['brand' => mb_strtoupper(trim($sheetBrand)) === 'EATON' ? 'Eaton' : trim($sheetBrand), 'evidence' => 'Columna MARCA (hoja MCBs & RCCBs)'];
        }
        if (preg_match(self::EATON, ProductReferenceNormalizer::base($reference))) {
            return ['brand' => 'Eaton', 'evidence' => 'Prefijo de serie Eaton'];
        }
        if ($familyName && preg_match('/^(Capacitores|Controlador FP)/iu', $familyName)) {
            return ['brand' => 'MElectric', 'evidence' => 'Catálogo de compensación: «fabricados para Melectric»'];
        }

        return null;
    }
}
