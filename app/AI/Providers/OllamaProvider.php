<?php

declare(strict_types=1);

namespace App\AI\Providers;

use App\AI\Contracts\AIProviderInterface;
use App\AI\DTOs\AIRequest;
use App\AI\DTOs\AIResponse;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Modelo local vía Ollama (/api/chat). Nada sale de la máquina; costo 0. */
final class OllamaProvider implements AIProviderInterface
{
    public function __construct(private readonly string $url) {}

    public function name(): string
    {
        return 'ollama';
    }

    public function supportsStreaming(): bool
    {
        return false;
    }

    public function complete(AIRequest $request): AIResponse
    {
        $messages = [['role' => 'system', 'content' => $request->fullSystem()]];
        foreach ($request->messages as $m) {
            $messages[] = ['role' => $m['role'], 'content' => is_string($m['content']) ? $m['content'] : json_encode($m['content'])];
        }
        $res = Http::timeout(120)->post($this->url.'/api/chat', [
            'model' => $request->model, 'messages' => $messages, 'stream' => false,
            'options' => ['num_predict' => $request->maxTokens],
        ]);
        if (! $res->successful()) {
            throw new RuntimeException('Ollama chat falló: '.$res->status());
        }

        return new AIResponse(trim((string) $res->json('message.content')), $request->model,
            $res->json('prompt_eval_count'), $res->json('eval_count'), $res->json('done_reason'));
    }

    public function stream(AIRequest $request, callable $onDelta): AIResponse
    {
        $r = $this->complete($request);
        $onDelta($r->content);

        return $r;
    }
}
