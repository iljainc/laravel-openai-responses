<?php

namespace Idpromogroup\LaravelOpenAIAssistants\Facades;

use Illuminate\Support\Facades\Facade;

class OpenAIAssistants extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Idpromogroup\LaravelOpenAIAssistants\Services\OpenAIService::class;
    }

    protected static function vectorStoreService()
    {
        return app(\Idpromogroup\LaravelOpenAIAssistants\Services\VectorStoreManagementService::class);
    }

    /**
     * Всё статическое проксируем без изменений:
     *   OpenAIAssistants::assistant($assistantId, $question, …)
     *   OpenAIAssistants::syncAssistantFiles($project)
     * и т. д.
     */
    public static function __callStatic($method, $args)
    {
        return (new static())->getFacadeRoot()->$method(...$args);
    }
}