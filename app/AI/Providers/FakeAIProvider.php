<?php

declare(strict_types=1);

namespace App\AI\Providers;

use App\AI\Contracts\AIProviderInterface;
use App\AI\DTOs\AIRequest;
use App\AI\DTOs\AIResponse;

/**
 * Proveedor de pruebas: devuelve el borrador determinista que viene en la petición (o un texto fijo), sin red.
 * Nunca se usa en producción (ProviderManager lo bloquea).
 */
final class FakeAIProvider implements AIProviderInterface
{
    /** @var list<AIRequest> */
    public array $requests = [];

    public function __construct(private readonly ?string $fixed = null) {}

    public function name(): string
    {
        return 'fake';
    }

    public function supportsStreaming(): bool
    {
        return false;
    }

    public function complete(AIRequest $request): AIResponse
    {
        $this->requests[] = $request;
        $draft = $request->metadata['draft'] ?? 'Respuesta de prueba.';

        return new AIResponse($this->fixed ?? $draft, 'fake-model', 100, 50, 'end_turn');
    }

    public function stream(AIRequest $request, callable $onDelta): AIResponse
    {
        $r = $this->complete($request);
        $onDelta($r->content);

        return $r;
    }
}
