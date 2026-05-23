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
     * Standard API, USD за 1M токенов (input / cached_input / output).
     * Источник: https://developers.openai.com/api/docs/pricing
     * Ключ — префикс response.model (матчится самый длинный).
     */
    'prices' => [
        'gpt-5.5-pro' => ['input' => 30.00, 'cached_input' => 3.00, 'output' => 180.00],
        'gpt-5.5' => ['input' => 5.00, 'cached_input' => 0.50, 'output' => 30.00],
        'gpt-5.4-pro' => ['input' => 30.00, 'cached_input' => 3.00, 'output' => 180.00],
        'gpt-5.4-nano' => ['input' => 0.20, 'cached_input' => 0.02, 'output' => 1.25],
        'gpt-5.4-mini' => ['input' => 0.75, 'cached_input' => 0.075, 'output' => 4.50],
        'gpt-5.4' => ['input' => 2.50, 'cached_input' => 0.25, 'output' => 15.00],
        'gpt-5.2-pro' => ['input' => 21.00, 'cached_input' => 2.10, 'output' => 168.00],
        'gpt-5.2' => ['input' => 1.75, 'cached_input' => 0.175, 'output' => 14.00],
        'gpt-5.1' => ['input' => 1.25, 'cached_input' => 0.125, 'output' => 10.00],
        'gpt-5-pro' => ['input' => 15.00, 'cached_input' => 1.50, 'output' => 120.00],
        'gpt-5-nano' => ['input' => 0.05, 'cached_input' => 0.005, 'output' => 0.40],
        'gpt-5-mini' => ['input' => 0.25, 'cached_input' => 0.025, 'output' => 2.00],
        'gpt-5' => ['input' => 1.25, 'cached_input' => 0.125, 'output' => 10.00],
        'gpt-4.1-nano' => ['input' => 0.10, 'cached_input' => 0.025, 'output' => 0.40],
        'gpt-4.1-mini' => ['input' => 0.40, 'cached_input' => 0.10, 'output' => 1.60],
        'gpt-4.1' => ['input' => 2.00, 'cached_input' => 0.50, 'output' => 8.00],
        'gpt-4o-2024-05-13' => ['input' => 5.00, 'cached_input' => 5.00, 'output' => 15.00],
        'gpt-4o-mini' => ['input' => 0.15, 'cached_input' => 0.075, 'output' => 0.60],
        'gpt-4o' => ['input' => 2.50, 'cached_input' => 1.25, 'output' => 10.00],
        'o1-pro' => ['input' => 150.00, 'cached_input' => 150.00, 'output' => 600.00],
        'o1' => ['input' => 15.00, 'cached_input' => 7.50, 'output' => 60.00],
        'o3-pro' => ['input' => 20.00, 'cached_input' => 20.00, 'output' => 80.00],
        'o3-mini' => ['input' => 1.10, 'cached_input' => 0.55, 'output' => 4.40],
        'o3' => ['input' => 2.00, 'cached_input' => 0.50, 'output' => 8.00],
        'o1-mini' => ['input' => 1.10, 'cached_input' => 0.55, 'output' => 4.40],
        'o4-mini' => ['input' => 1.10, 'cached_input' => 0.275, 'output' => 4.40],
    ],
];
