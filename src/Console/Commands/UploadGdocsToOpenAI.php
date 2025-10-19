<?php

namespace Idpromogroup\LaravelOpenaiResponses\Console\Commands;

use Illuminate\Console\Command;
use Idpromogroup\LaravelOpenaiResponses\Models\LorTemplate;
use Idpromogroup\LaravelOpenaiResponses\Services\LorApiService;
use Idpromogroup\LaravelOpenaiResponses\Services\VectorStoreManagementService;

/**
 * ЗАКОММЕНТИРОВАНО: UploadGdocsToOpenAI Command
 * 
 * ПРИЧИНА: Эта команда зависит от VectorStoreManagementService,
 * который работает неправильно и закомментирован.
 * 
 * ПРОБЛЕМА: Команда пытается найти проекты с openai_assistant_id,
 * но это поле удалено из модели LorTemplate.
 * 
 * TODO: Переписать команду для работы с правильной архитектурой
 */
class UploadGdocsToOpenAI extends Command
{
    protected $signature   = 'openai:upload-gdocs';
    protected $description = 'Sync Google-Docs files of each project with its assistant vector-store';

    // ЗАКОММЕНТИРОВАНО: Неправильная логика - зависит от закомментированного сервиса
    /*
    public function handle(): int
    {
        $projects = LorTemplate::whereNotNull('openai_assistant_id')
            ->get();

        foreach ($projects as $project) {
            $this->info("▼ {$project->project_name}");

            // для каждого проекта — свой ключ
            $key = $project->openai_api_key ?: config('openai-assistants.api_key');
            $apiService = new LorApiService($key);
            $vectorStoreSrv   = new VectorStoreManagementService($apiService);

            $vectorStoreSrv->manageAssistantVectorStore($project, $this);

            $this->info("▲ Done: {$project->name}\n");
        }

        $this->info("Complete");

        return 0;
    }
    */
}
