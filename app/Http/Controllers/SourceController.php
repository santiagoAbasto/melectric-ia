<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SourceRecord;
use Inertia\Response;

class SourceController extends Controller
{
    /** Fila exacta del Excel (o registro de otra fuente) que respalda un dato. */
    public function record(SourceRecord $record): Response
    {
        $record->load('source');

        return inertia('Sources/Record', ['record' => ['id' => $record->id, 'locator' => $record->locator, 'payload' => $record->payload, 'hash' => substr($record->hash, 0, 16),
            'updated_at' => $record->updated_at?->toIso8601String(),
            'source' => $record->source->only(['name', 'kind', 'access_level', 'authority']) + ['retrieved_at' => $record->source->retrieved_at?->toIso8601String()]]]);
    }
}
