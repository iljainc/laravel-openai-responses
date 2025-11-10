<?php

namespace Idpromogroup\LaravelOpenaiResponses;

use Idpromogroup\LaravelOpenaiResponses\Services\LorApiService;
use Idpromogroup\LaravelOpenaiResponses\Services\LorService;
use Idpromogroup\LaravelOpenaiResponses\Services\VectorStoreManagementService;
use Illuminate\Support\ServiceProvider;

class LaravelOpenaiResponsesServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Биндинг OpenAI API сервиса
        $this->app->bind(LorApiService::class, function () {
            $apiKey = config('openai-responses.api_key') ?: env('OPENAI_API_KEY');
            if (empty($apiKey)) {
                throw new \RuntimeException(
                    'Missing OpenAI API key: please set OPENAI_API_KEY in your .env file'
                );
            }
            return new LorApiService($apiKey);
        });

        // Управление векторным хранилищем
        $this->app->bind(VectorStoreManagementService::class, fn ($app) =>
            new VectorStoreManagementService($app->make(LorApiService::class))
        );

        $this->mergeConfigFrom(
            __DIR__ . '/../config/openai-responses.php',
            'openai-responses'
        );
    }

    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Публикация миграций
        $this->publishes([
            __DIR__ . '/../database/migrations/2025_01_01_create_openai_responses_tables.php' => database_path('migrations/2025_01_01_create_openai_responses_tables.php'),
        ], 'migrations');

        // Публикация конфига
        $this->publishes([
            __DIR__ . '/../config/openai-responses.php' => config_path('openai-responses.php'),
        ], 'config');

        // Загружаем хелперы если есть
        if (file_exists(__DIR__ . '/helpers.php')) {
            require __DIR__ . '/helpers.php';
        }
        
        // Регистрация команд
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Idpromogroup\LaravelOpenaiResponses\Console\Commands\UploadGdocsToOpenAI::class,
            ]);
        }
    }
}
