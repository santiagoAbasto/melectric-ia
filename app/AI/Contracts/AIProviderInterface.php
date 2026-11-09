<?php

declare(strict_types=1);

namespace App\AI\Contracts;

use App\AI\DTOs\AIRequest;
use App\AI\DTOs\AIResponse;

/**
 * Desacople del proveedor de IA (spec §2/§68): el dominio nunca habla con un
 * proveedor concreto. Sin credenciales → FakeAIProvider (marcado). Con
 * credenciales, sólo se cambia el binding por config: nada de reescribir dominio.
 */
interface AIProviderInterface
{
    public function name(): string;

    public function supportsStreaming(): bool;

    public function complete(AIRequest $request): AIResponse;

    /**
     * Streaming token a token para UX fluida. $onDelta recibe cada fragmento de
     * texto. Devuelve la AIResponse final acumulada. Los proveedores sin stream
     * nativo emiten todo el contenido en un solo delta.
     *
     * @param  callable(string):void  $onDelta
     */
    public function stream(AIRequest $request, callable $onDelta): AIResponse;
}
