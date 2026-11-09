<?php

namespace App\Providers;

use App\AI\RuleEngine;
use App\AI\ToolRegistry;
use App\Enums\Role;
use App\Models\User;
use App\Search\Embeddings\EmbeddingProviderInterface;
use App\Search\Embeddings\FakeEmbeddingProvider;
use App\Search\Embeddings\OllamaEmbeddingProvider;
use App\Vision\MacVisionOcrProvider;
use App\Vision\OcrProviderInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ToolRegistry::class);
        $this->app->bind(OcrProviderInterface::class, MacVisionOcrProvider::class);
        $this->app->singleton(RuleEngine::class);
        $this->app->singleton(EmbeddingProviderInterface::class, function ($app) {
            $driver = config('melectric.embeddings.provider');
            if ($driver === 'fake') {
                // Nunca en producción: un índice falso daría búsquedas semánticas sin sentido.
                abort_if($app->environment('production'), 500, 'FakeEmbeddingProvider no está permitido en producción.');

                return new FakeEmbeddingProvider;
            }

            return new OllamaEmbeddingProvider(config('melectric.embeddings.ollama_url'), config('melectric.embeddings.ollama_model'));
        });
    }

    public function boot(): void
    {
        foreach (Role::PERMISSIONS as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
    }
}
