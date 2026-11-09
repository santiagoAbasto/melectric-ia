<?php

declare(strict_types=1);

namespace App\Documents;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\ProductSpecification;

/**
 * Versiones y reemplazos (spec §43-45). Un documento nuevo que reemplaza a otro deja al anterior SUPERSEDED con fecha
 * de fin de vigencia; las especificaciones que salían del anterior dejan de usarse (status SUPERSEDED en la búsqueda).
 * La fecha de subida no decide nada: lo decide el administrador o la relación declarada.
 */
final class DocumentPrecedenceService
{
    public function supersede(Document $new, Document $old): void
    {
        if ($new->is($old)) {
            return;
        }
        $before = $old->only(['status', 'effective_to']);
        $old->update(['status' => 'SUPERSEDED', 'effective_to' => $new->effective_from ?? now()->toDateString()]);
        $new->update(['supersedes_id' => $old->id, 'family_key' => $old->family_key, 'status' => 'CURRENT']);
        ProductSpecification::query()->where('document_id', $old->id)->whereNotIn('status', ['VERIFIED'])->update(['status' => 'REJECTED']);
        AuditLog::record('document.superseded', $old, $before, ['status' => 'SUPERSEDED', 'by_document_id' => $new->id]);
    }

    /** Documentos vigentes (los únicos que el asistente cita). */
    public static function currentIds(): array
    {
        return Document::query()->whereIn('status', ['CURRENT', 'UNKNOWN'])->pluck('id')->all();
    }
}
