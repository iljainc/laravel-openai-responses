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
];
