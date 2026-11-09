<?php

declare(strict_types=1);

namespace App\AI;

use App\Search\SearchResult;
use InvalidArgumentException;

/**
 * Herramientas tipadas que usa el orquestador (spec §69-70). El modelo nunca toca la base: cada herramienta valida su
 * entrada y devuelve datos del catálogo. Cada llamada queda en la traza con entrada, ids devueltos, duración y estado.
 */
final class ToolRegistry
{
    /** @var array<string, array{description:string, params:array<string,string>, handler:callable}> */
    private array $tools = [];

    /** @var list<array<string, mixed>> */
    private array $calls = [];

    /** @var list<string>|null herramientas habilitadas por el perfil */
    private ?array $allowed = null;

    public function register(string $name, string $description, array $params, callable $handler): void
    {
        $this->tools[$name] = ['description' => $description, 'params' => $params, 'handler' => $handler];
    }

    public function allow(?array $names): void
    {
        $this->allowed = $names;
    }

    public function call(string $name, array $input): mixed
    {
        if (! isset($this->tools[$name])) {
            throw new InvalidArgumentException("Herramienta desconocida: {$name}");
        }
        if ($this->allowed !== null && ! in_array($name, $this->allowed, true)) {
            $this->calls[] = ['tool' => $name, 'input' => $input, 'status' => 'BLOCKED_BY_PROFILE', 'ms' => 0, 'result' => null];

            return null;
        }
        foreach ($this->tools[$name]['params'] as $param => $type) {
            $optional = str_ends_with($type, '?');
            $type = rtrim($type, '?');
            if (! array_key_exists($param, $input)) {
                if ($optional) {
                    continue;
                }
                throw new InvalidArgumentException("{$name}: falta {$param}");
            }
            $ok = match ($type) {
                'string' => is_string($input[$param]), 'int' => is_int($input[$param]), 'array' => is_array($input[$param]),
                'float' => is_float($input[$param]) || is_int($input[$param]), default => true,
            };
            if (! $ok && $input[$param] !== null) {
                throw new InvalidArgumentException("{$name}: {$param} debe ser {$type}");
            }
        }
        $t = microtime(true);
        try {
            $result = ($this->tools[$name]['handler'])($input);
            $this->calls[] = ['tool' => $name, 'input' => $input, 'status' => 'OK', 'ms' => (int) round((microtime(true) - $t) * 1000), 'result' => $this->summary($result)];

            return $result;
        } catch (\Throwable $e) {
            $this->calls[] = ['tool' => $name, 'input' => $input, 'status' => 'ERROR', 'ms' => (int) round((microtime(true) - $t) * 1000), 'result' => $e->getMessage()];
            throw $e;
        }
    }

    /** @return list<array<string, mixed>> */
    public function calls(): array
    {
        return $this->calls;
    }

    public function reset(): void
    {
        $this->calls = [];
    }

    /** @return array<string, array{description:string, params:array<string,string>}> */
    public function definitions(): array
    {
        return array_map(fn ($t) => ['description' => $t['description'], 'params' => $t['params']], $this->tools);
    }

    private function summary(mixed $result): mixed
    {
        return match (true) {
            $result instanceof SearchResult => ['strategy' => $result->strategy, 'product_ids' => array_slice(array_column($result->candidates, 'product_id'), 0, 20), 'count' => count($result->candidates)],
            is_array($result) => ['count' => count($result), 'ids' => array_slice(array_values(array_filter(array_map(fn ($r) => is_array($r) ? ($r['id'] ?? $r['chunk_id'] ?? $r['product_id'] ?? null) : null, $result))), 0, 20)],
            is_object($result) && method_exists($result, 'getKey') => ['id' => $result->getKey()],
            default => $result === null ? null : 'ok',
        };
    }
}
