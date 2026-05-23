<?php
// config/openai-responses.php

return [
    // выводить debug-логи из helper-функций lor_debug()
    'debug_output'  => env('OPENAI_RESPONSES_DEBUG', true),

    // API-ключ по умолчанию (можно переопределить при вызове)
    'api_key'       => env('OPENAI_RESPONSES_API_KEY'),

    // сервис для выполнения function calls
    'function_handler' => env(
        'OPENAI_RESPONSES_FUNCTION_HANDLER',
        \App\Services\AppLogicService::class
    ),

    // Учёт токенов и стоимости в lor_request_logs (по ответу usage + цены ниже)
    'billing' => [
        'enabled' => env('OPENAI_RESPONSES_BILLING', true),
    ],

    /**
     * Цены за 1M токенов (USD). Ключ — префикс model из API (подходит и к gpt-4o-mini-2024-07-18).
     * Обновляйте при смене тарифов OpenAI.
     */
    'prices' => [
        'gpt-4o-mini' => [
            'input' => 0.15,
            'cached_input' => 0.075,
            'output' => 0.60,
        ],
        'gpt-4o' => [
            'input' => 2.50,
            'cached_input' => 1.25,
            'output' => 10.00,
        ],
        // GPT-5: длинные префиксы важны (gpt-5-nano-* не должен матчиться на gpt-5)
        'gpt-5-nano' => [
            'input' => 0.05,
            'cached_input' => 0.005,
            'output' => 0.40,
        ],
        'gpt-5-mini' => [
            'input' => 0.25,
            'cached_input' => 0.025,
            'output' => 2.00,
        ],
        'gpt-5' => [
            'input' => 1.25,
            'cached_input' => 0.125,
            'output' => 10.00,
        ],
        'o3' => [
            'input' => 2.00,
            'cached_input' => 0.50,
            'output' => 8.00,
        ],
        'o4-mini' => [
            'input' => 1.10,
            'cached_input' => 0.275,
            'output' => 4.40,
        ],
    ],
];
