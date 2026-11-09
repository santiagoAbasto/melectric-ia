<?php

declare(strict_types=1);

namespace App\AI\DTOs;

final readonly class AIResponse
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public string $content,
        public string $model,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?string $finishReason = null,
        public array $raw = [],
        public ?int $cacheReadTokens = null,
        public ?int $cacheWriteTokens = null,
    ) {}

    /** El modelo declinó por política: nunca se muestra texto vacío, se deriva a una persona. */
    public function refused(): bool
    {
        return $this->finishReason === 'refusal';
    }
}
