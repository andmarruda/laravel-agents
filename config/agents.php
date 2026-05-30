<?php

return [
    'default_model' => env('AGENTS_DEFAULT_MODEL', 'openai/gpt-4.1-mini'),

    'capabilities' => [
        'text' => [
            'default_model' => env('AGENTS_DEFAULT_MODEL', 'openai/gpt-4.1-mini'),
        ],

        'image' => [
            'default_model' => env('AGENTS_IMAGE_MODEL', 'openai/gpt-image-1'),
            'storage_disk' => env('AGENTS_IMAGE_DISK', 'public'),
            'default_size' => env('AGENTS_IMAGE_SIZE', '1024x1024'),
        ],
    ],

    'models' => [
        'timeout' => (int) env('AGENTS_MODEL_TIMEOUT', 60),
        'retry_times' => (int) env('AGENTS_MODEL_RETRY_TIMES', 2),
        'retry_sleep' => (int) env('AGENTS_MODEL_RETRY_SLEEP', 250),
    ],

    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        ],

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
            'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
        ],

        'fireworks' => [
            'api_key' => env('FIREWORKS_API_KEY'),
            'base_url' => env('FIREWORKS_BASE_URL', 'https://api.fireworks.ai/inference/v1'),
        ],
    ],

    'supervisor' => [
        'max_steps' => (int) env('AGENTS_SUPERVISOR_MAX_STEPS', 12),
    ],
];
