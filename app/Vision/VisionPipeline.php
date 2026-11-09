<?php

declare(strict_types=1);

namespace App\Vision;

use App\Catalog\Units\UnitService;
use App\Documents\ReferenceDetector;
use App\Search\ProductSearchService;
use RuntimeException;

/**
 * Foto → validación → OCR → referencia → búsqueda exacta → candidatos → confianza (spec §80-84).
 * OCR primero: en equipamiento eléctrico la referencia impresa vale más que el parecido visual. Una foto nunca
 * certifica valores eléctricos: si no se lee una referencia del catálogo, se pide otra foto.
 */
final class VisionPipeline
{
    public const MAX_BYTES = 10 * 1024 * 1024;

    public function __construct(private readonly OcrProviderInterface $ocr, private readonly ReferenceDetector $references, private readonly ProductSearchService $search) {}

    public static function validate(string $path): string
    {
        $size = @filesize($path);
        if (! $size || $size > self::MAX_BYTES) {
            throw new RuntimeException('La imagen está vacía o supera 10 MB.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || ! @getimagesize($path)) {
            throw new RuntimeException('Sólo se aceptan fotos JPG, PNG o WEBP.');
        }

        return $mime;
    }

    /** @return array<string, mixed> resultado interno (spec §84) */
    public function identify(string $path): array
    {
        self::validate($path);
        if (! $this->ocr->available()) {
            return ['available' => false, 'needs_more_information' => true, 'candidates' => [], 'ocr_fields' => [], 'detected_reference' => null, 'confidence' => 0.0];
        }
        $lines = $this->ocr->read($path);
        $readable = array_values(array_filter($lines, fn ($l) => $l['confidence'] >= 0.5));
        $text = implode("\n", array_column($readable, 'text'));

        $brand = preg_match('/\bE\W?A\W?T\W?O\W?N\b|\bF\W?A\W?T\W?O\W?N\b/u', implode(' ', array_column($lines, 'text'))) ? 'Eaton (logo leído aproximado)' : null;
        $fields = [];
        foreach ($readable as $l) {
            if (preg_match('/\b(In|Ie|Un|Ue|I[nΔ]n?|Icn|Ine)\s*=?\s*(.+)$/u', $l['text'], $m) && ($q = UnitService::parse($m[2]))) {
                $fields[] = ['label' => $m[1], 'raw' => $l['text'], 'value' => $q->display(), 'confidence' => $l['confidence']];
            }
        }

        $candidates = [];
        $detected = null;
        foreach ($this->references->detect($text) as $hit) {
            $ocrConf = collect($readable)->first(fn ($l) => str_contains($l['text'], $hit['raw']))['confidence'] ?? 0.5;
            $detected ??= $hit['raw'];
            foreach (array_slice($hit['product_ids'], 0, 8) as $id) {
                $candidates[$id] = ['product_id' => $id, 'method' => $hit['method'], 'score' => round($hit['confidence'] * $ocrConf, 3), 'evidence' => "OCR: «{$hit['raw']}»"];
            }
        }
        // Referencia leída que no está en el catálogo: se informa, no se adivina
        $unknown = [];
        foreach ($readable as $l) {
            if ($l['confidence'] >= 0.9 && preg_match('/^[A-Za-z]{2,}[A-Za-z0-9]*[-\/][A-Za-z0-9\/,.-]+$/', $l['text']) && ! $detected) {
                $unknown[] = $l['text'];
            }
        }
        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);
        $best = $candidates[0]['score'] ?? 0.0;

        return ['available' => true, 'provider' => $this->ocr->name(), 'detected_brand' => $brand, 'detected_reference' => $detected,
            'unknown_references' => array_values(array_unique($unknown)), 'ocr_fields' => $fields, 'ocr_lines' => $lines,
            'candidates' => array_values($candidates), 'confidence' => $best, 'needs_more_information' => $detected === null || $best < 0.6];
    }
}
