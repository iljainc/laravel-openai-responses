<?php

if (! function_exists('lor_debug')) {
    function lor_debug(...$vars)
    {
        if (config('openai-responses.debug_output')) {
            if (\Illuminate\Support\Facades\App::runningInConsole()) {
                foreach ($vars as $var) {
                    echo print_r($var, true) . PHP_EOL;
                }
            } else {
                foreach ($vars as $var) {
                    var_dump($var);
                }
            }
        }
    }
}

if (!function_exists('lor_debug_error')) {
    function lor_debug_error(...$vars)
    {
        if (config('openai-responses.debug_output')) {
            if (\Illuminate\Support\Facades\App::runningInConsole()) {
                foreach ($vars as $var) {
                    // Красный цвет в консоли: \033[31m ... \033[0m
                    echo "\033[31m" . print_r($var, true) . "\033[0m" . PHP_EOL;
                }
            } else {
                echo '<span style="color:red">';
                foreach ($vars as $var) {
                    var_dump($var);
                }
                echo '</span>';
            }
        }
    }
}