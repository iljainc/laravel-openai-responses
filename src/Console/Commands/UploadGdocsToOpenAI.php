<?php

namespace Idpromogroup\LaravelOpenaiResponses\Console\Commands;

use Illuminate\Console\Command;
use Idpromogroup\LaravelOpenaiResponses\Models\LorTemplate;
use Idpromogroup\LaravelOpenaiResponses\Services\LorApiService;
use Idpromogroup\LaravelOpenaiResponses\Services\VectorStoreManagementService;

class UploadGdocsToOpenAI extends Command
{
    protected $signature   = 'openai:upload-gdocs';
    protected $description = 'Sync Google-Docs files of each project with its assistant vector-store';

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
}
