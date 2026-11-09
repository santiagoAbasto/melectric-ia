<?php

declare(strict_types=1);

namespace App\AI\Providers;

use Anthropic\Client;
use App\AI\Contracts\AIProviderInterface;
use App\AI\DTOs\AIRequest;
use App\AI\DTOs\AIResponse;

/**
 * Adapter de Claude con el SDK oficial (anthropic-ai/sdk). Toma ANTHROPIC_API_KEY del entorno. El system prompt va con cache_control: es estable
 * entre mensajes y abarata cada turno. Con `fallbacks: default`, si el modelo
 * declina por política el servidor reintenta con su respaldo; si aun así declina,
 * la respuesta sale con finishReason=refusal y el orquestador deriva a humano.
 */
final class AnthropicProvider implements AIProviderInterface
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    public function __construct(private readonly Client $client) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function complete(AIRequest $request): AIResponse
    {
        $message = $this->client->beta->messages->create(...$this->params($request));

        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        return new AIResponse(
            content: trim($text),
            model: (string) $message->model,
            inputTokens: $message->usage->inputTokens,
            outputTokens: $message->usage->outputTokens,
            finishReason: $message->stopReason,
            raw: ['id' => $message->id],
            cacheReadTokens: $message->usage->cacheReadInputTokens,
            cacheWriteTokens: $message->usage->cacheCreationInputTokens,
        );
    }

    public function stream(AIRequest $request, callable $onDelta): AIResponse
    {
        $stream = $this->client->beta->messages->createStream(...$this->params($request));

        $text = '';
        $model = $request->model;
        $inputTokens = $outputTokens = $cacheRead = $cacheWrite = null;
        $stopReason = null;

        foreach ($stream as $event) {
            if ($event->type === 'message_start') {
                $model = (string) $event->message->model;
                $inputTokens = $event->message->usage->inputTokens;
                $cacheRead = $event->message->usage->cacheReadInputTokens;
                $cacheWrite = $event->message->usage->cacheCreationInputTokens;
            } elseif ($event->type === 'content_block_delta' && $event->delta->type === 'text_delta') {
                $text .= $event->delta->text;
                $onDelta($event->delta->text);
            } elseif ($event->type === 'message_delta') {
                $outputTokens = $event->usage->outputTokens;
                $stopReason = $event->delta->stopReason;
            }
        }

        return new AIResponse(trim($text), $model, $inputTokens, $outputTokens, $stopReason, ['streamed' => true], $cacheRead, $cacheWrite);
    }

    /** @return array<string, mixed> */
    private function params(AIRequest $request): array
    {
        return [
            'model' => $request->model,
            'maxTokens' => $request->maxTokens,
            'system' => [
                ['type' => 'text', 'text' => $request->system, 'cacheControl' => ['type' => 'ephemeral']],
                ...($request->systemData !== '' ? [['type' => 'text', 'text' => $request->systemData]] : []),
            ],
            'messages' => $request->messages,
            'outputConfig' => array_filter([
                'effort' => $request->effort,
                'format' => $request->responseSchema !== null ? ['type' => 'json_schema', 'schema' => $request->responseSchema] : null,
            ]) ?: null,
            // Haiku 5.5 no tiene fallback del lado del servidor: sólo se pide en Opus/Sonnet.
            ...(str_contains($request->model, 'haiku') ? [] : ['fallbacks' => 'default', 'betas' => [self::FALLBACK_BETA]]),
        ];
    }
}
