<?php

declare(strict_types=1);

namespace App\AI;

use Anthropic\Client;
use App\AI\Contracts\AIProviderInterface;
use App\AI\Providers\AnthropicProvider;
use App\AI\Providers\FakeAIProvider;
use App\AI\Providers\OllamaProvider;
use App\Search\Embeddings\EmbeddingProviderInterface;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** Resuelve el proveedor por configuración. El dominio nunca instancia un proveedor concreto. */
class ProviderManager
{
    public function resolve(?string $name = null): AIProviderInterface
    {
        $name ??= config('melectric.ai.provider');

        return match ($name) {
            'anthropic' => new AnthropicProvider(new Client(apiKey: (string) config('melectric.ai.anthropic_key'))),
            'ollama' => new OllamaProvider((string) config('melectric.embeddings.ollama_url')),
            'fake' => app()->environment('production')
                ? throw new RuntimeException('FakeAIProvider no está permitido en producción.')
                : new FakeAIProvider,
            default => throw new RuntimeException("Proveedor de IA desconocido: {$name}"),
        };
    }

    /** Hay credenciales para el proveedor: si no, el asistente responde sólo con el redactor determinista. */
    public function isLive(?string $name = null): bool
    {
        $name ??= config('melectric.ai.provider');
        if (Cache::has('ai.provider_down.'.$name)) {
            return false; // corte de circuito: falló la autenticación hace menos de una hora
        }

        return match ($name) {
            'anthropic' => filled(config('melectric.ai.anthropic_key')),
            'ollama' => app(EmbeddingProviderInterface::class)->available(),
            'fake' => true,
            default => false,
        };
    }

    /** Marca al proveedor como caído una hora (credencial inválida o rechazo de autenticación). */
    public static function markDown(string $name, string $reason): void
    {
        Cache::put('ai.provider_down.'.$name, ['reason' => $reason, 'at' => now()->toIso8601String()], now()->addHour());
    }

    public static function downReason(string $name): ?array
    {
        return Cache::get('ai.provider_down.'.$name);
    }

    /** Modelo para una ruta (fast | reasoning | vision) del proveedor dado. */
    public static function model(string $route, ?string $provider = null): ?string
    {
        $provider ??= config('melectric.ai.provider');

        return config("melectric.ai.models.{$provider}.{$route}");
    }

    /** USD de una llamada según config('melectric.ai.pricing'). */
    public static function cost(string $model, int $in, int $out): float
    {
        [$pin, $pout] = config('melectric.ai.pricing.'.$model, [0, 0]);

        return round(($in * $pin + $out * $pout) / 1_000_000, 6);
    }
}
