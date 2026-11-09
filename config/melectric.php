<?php

return [
    'embeddings' => [
        'provider' => env('AI_EMBEDDINGS_PROVIDER', 'ollama'), // ollama | fake (sólo pruebas)
        'ollama_url' => env('OLLAMA_URL', 'http://127.0.0.1:11434'),
        'ollama_model' => env('OLLAMA_EMBED_MODEL', 'nomic-embed-text'),
    ],
    'ai' => [
        'provider' => env('AI_DEFAULT_PROVIDER', 'anthropic'), // anthropic | ollama | fake (sólo pruebas)
        'anthropic_key' => env('ANTHROPIC_API_KEY'),
        // Rutas del ModelRouter por proveedor. Económico para redactar respuestas simples, razonamiento para
        // comparaciones y documentos, visión para fotos.
        'models' => [
            'anthropic' => [
                'fast' => env('AI_MODEL_FAST', 'claude-haiku-5-5'),
                'reasoning' => env('AI_MODEL_REASONING', 'claude-opus-5-5'),
                'vision' => env('AI_MODEL_VISION', 'claude-opus-5-5'),
            ],
            'ollama' => ['fast' => 'qwen2.5-coder:7b', 'reasoning' => 'qwen2.5-coder:7b', 'vision' => null],
            'fake' => ['fast' => 'fake-fast', 'reasoning' => 'fake-reasoning', 'vision' => 'fake-vision'],
        ],
        // USD por millón de tokens [entrada, salida] para el tablero de costos. Revisar contra la lista de precios vigente.
        'pricing' => [
            'claude-haiku-5-5' => [0.10, 0.50],
            'claude-sonnet-5-5' => [2.0, 10.0],
            'claude-opus-5-5' => [4.0, 20.0],
        ],
    ],
    'crawler' => [
        'user_agent' => env('CRAWLER_USER_AGENT', 'MElectricAI-Crawler/0.1'),
        'allowed_hosts' => ['www.melectric.com.ar', 'melectric.com.ar'],
        'delay_ms' => 1000,
    ],
];
