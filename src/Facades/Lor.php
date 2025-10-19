<?php

namespace Idpromogroup\LaravelOpenaiResponses\Facades;

use Illuminate\Support\Facades\Facade;

class Lor extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Idpromogroup\LaravelOpenaiResponses\Services\LorService::class;
    }

    protected static function vectorStoreService()
    {
        return app(\Idpromogroup\LaravelOpenaiResponses\Services\VectorStoreManagementService::class);
    }

    /**
     * Всё статическое проксируем без изменений:
     *   Lor::assistant($assistantId, $question, …)
     *   Lor::syncAssistantFiles($project)
     * и т. д.
     */
    public static function __callStatic($method, $args)
    {
        return (new static())->getFacadeRoot()->$method(...$args);
    }
}