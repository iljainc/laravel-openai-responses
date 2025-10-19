<?php

namespace Idpromogroup\LaravelOpenaiResponses\Services;

use Idpromogroup\LaravelOpenaiResponses\Models\LorTemplate;
use Idpromogroup\LaravelOpenaiResponses\Services\LorApiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ЗАКОММЕНТИРОВАНО: VectorStoreManagementService
 * 
 * ТЕКУЩАЯ ПРОБЛЕМА: Этот сервис работает неправильно - он пытается управлять
 * векторными хранилищами OpenAI напрямую через API, что не соответствует
 * архитектуре пакета.
 * 
 * ПРАВИЛЬНАЯ ЛОГИКА должна быть такой:
 * 
 * 1. ХРАНЕНИЕ В БД:
 *    - В таблице lor_template_files должны храниться:
 *      * file_id (ID файла в OpenAI)
 *      * vector_store_id (ID векторного хранилища в OpenAI)
 *      * vector_store_file_id (ID файла внутри векторного хранилища)
 * 
 * 2. ПРИВЯЗКА К ШАБЛОНАМ:
 *    - Шаблон (LorTemplate) должен знать какие файлы у него есть
 *    - Файлы должны знать к какому векторному хранилищу они привязаны
 * 
 * 3. УПРАВЛЕНИЕ:
 *    - Создание векторных хранилищ должно быть отдельной операцией
 *    - Загрузка файлов в хранилища должна быть отдельной операцией
 *    - Привязка хранилищ к ассистентам должна быть отдельной операцией
 * 
 * 4. НЕ НУЖНО:
 *    - Хранить openai_assistant_id в шаблонах
 *    - Управлять ассистентами через этот сервис
 *    - Автоматически создавать векторные хранилища
 * 
 * TODO: Переписать этот сервис с правильной архитектурой
 */
class VectorStoreManagementService
{
    private LorApiService $apiService;

    public function __construct(LorApiService $apiService)
    {
        $this->apiService = $apiService;
    }

    // ЗАКОММЕНТИРОВАНО: Неправильная логика - работает с ассистентами вместо шаблонов
    /*
    public function manageAssistantVectorStore(LorTemplate $project, ?object $command = null): bool
    {
        $assistantId = $project->openai_assistant_id;

        if ($command) $command->info("Init for Assistant {$project->name} ID: {$assistantId}");

        $templateFiles = $project->templateFiles;

        if ($templateFiles->isEmpty()) {
            if ($command) $command->info("No Google Docs files associated with project {$project->name}.");
            return true;
        }

        $assistantData = $this->getAssistantInfo($assistantId);
        $vectorStoreIds = $assistantData["tool_resources"]['file_search']['vector_store_ids'] ?? [];
        $vectorStoreId = $vectorStoreIds[0] ?? null;

        if (!$vectorStoreId) {
            $vectorStore = $this->createVectorStore("Assistant_{$assistantId}_VectorStore");
            if (isset($vectorStore['id'])) {
                $vectorStoreId = $vectorStore['id'];
                $this->attachVectorStoreToAssistant($assistantId, $vectorStoreId);
                if ($command) $command->info("Created vector store with ID: $vectorStoreId");
                lor_debug("Created vector store with ID: $vectorStoreId");
            } else {
                Log::error('VectorStoreManagementService: Failed to create vector store.', $vectorStore ?? []);
                lor_debug('Failed to create vector store.', $vectorStore ?? []);
                return false;
            }
        } else {
            if ($command) $command->info("Using existing vector store ID: $vectorStoreId");
            lor_debug("Using existing vector store ID: $vectorStoreId");
        }

        $uploadedFileIds = [];

        foreach ($templateFiles as $templateFile) {
            $fileContent = $this->downloadFileContent($templateFile->file_url, $templateFile->file_type);
            if ($fileContent === null) {
                Log::warning("VectorStoreManagementService: Could not download content for {$templateFile->file_url}");
                lor_debug("Could not download content for {$templateFile->file_url}");
                continue;
            }
            $currentFileHash = md5($fileContent);

            if ($templateFile->file_hash !== $currentFileHash) {
                // Проверяем, является ли это локальным файлом
                $isLocalFile = !filter_var($templateFile->file_url, FILTER_VALIDATE_URL) && file_exists($templateFile->file_url);
                
                if ($isLocalFile) {
                    // Используем существующий файл напрямую
                    $filePath = $templateFile->file_url;
                } else {
                    // Создаем временный файл для URL
                    $filePath = storage_path('app/template_file_' . $templateFile->id . '.' . $templateFile->file_type);
                    file_put_contents($filePath, $fileContent);
                }

                $uploadedFile = $this->uploadFileToOpenAI($filePath, 'assistants');
                if (isset($uploadedFile['id'])) {
                    $this->addFileToVectorStore($vectorStoreId, $uploadedFile['id']);
                    $templateFile->vector_store_file_id = $uploadedFile['id'];
                    $templateFile->file_hash = $currentFileHash;
                    $templateFile->save();
                    $uploadedFileIds[] = $uploadedFile['id'];
                    if ($command) $command->info("Uploaded and attached {$templateFile->file_url} to vector store.");
                    lor_debug("Uploaded and attached {$templateFile->file_url} to vector store. File ID: {$uploadedFile['id']}");
                } else {
                    Log::error('VectorStoreManagementService: Failed to upload file.', $uploadedFile ?? []);
                    lor_debug('Failed to upload file.', $uploadedFile ?? []);
                }

                // Удаляем временный файл только если он был создан
                if (!$isLocalFile && file_exists($filePath)) {
                    unlink($filePath);
                    if ($command) $command->info("Temporary file deleted.");
                    lor_debug("Temporary file deleted: {$filePath}");
                }
            } else {
                if ($command) $command->info("{$templateFile->file_url} has not changed.");
                lor_debug("{$templateFile->file_url} has not changed.");
                if ($templateFile->vector_store_file_id) {
                    $uploadedFileIds[] = $templateFile->vector_store_file_id;
                    lor_debug("{$templateFile->file_url} already in vector store with ID: {$templateFile->vector_store_file_id}");
                }
            }
        }

        // Удаление устаревших файлов из векторного хранилища
        if ($vectorStoreId) {
            $filesInStore = $this->listFilesInVectorStore($vectorStoreId);
            if (isset($filesInStore['data'])) {
                foreach ($filesInStore['data'] as $storedFile) {
                    if (!in_array($storedFile['id'], $uploadedFileIds)) {
                        $this->deleteFile($storedFile['id']);
                        if ($command) $command->info("Deleted outdated file ID: {$storedFile['id']} from vector store.");
                        lor_debug("Deleted outdated file ID: {$storedFile['id']} from vector store.");
                        // Optionally clear vector_store_file_id in your database for the deleted file
                        $templateFileToDelete = $templateFiles->where('vector_store_file_id', $storedFile['id'])->first();
                        if ($templateFileToDelete) {
                            $templateFileToDelete->vector_store_file_id = null;
                            $templateFileToDelete->save();
                            lor_debug("Cleared vector_store_file_id for deleted file in database.");
                        }
                    }
                }
            }
        }

        return true;
    }
    */

    // ЗАКОММЕНТИРОВАНО: Методы для работы с ассистентами - не нужны в текущей архитектуре
    /*
    private function getAssistantInfo(string $assistantId): ?array
    {
        return $this->apiService->sendRequest('GET', "assistants/{$assistantId}");
    }

    private function createVectorStore(string $name): ?array
    {
        return $this->apiService->sendRequest('POST', "vector_stores", [
            'json' => [
                'name' => $name
            ]
        ]);
    }

    private function attachVectorStoreToAssistant(string $assistantId, string $vectorStoreId): ?array
    {
        return $this->apiService->sendRequest('POST', "assistants/{$assistantId}", [
            'json' => [
                'tools' => [
                    [
                        'type' => 'file_search'
                    ]
                ],
                'tool_resources' => [
                    'file_search' => [
                        'vector_store_ids' => [$vectorStoreId]
                    ]
                ]
            ]
        ]);
    }

    private function uploadFileToOpenAI(string $filePath, string $purpose): ?array
    {
        return $this->apiService->sendRequest('POST', 'files', [
            'multipart' => [
                [
                    'name' => 'file',
                    'contents' => fopen($filePath, 'r'),
                    'filename' => basename($filePath)
                ],
                [
                    'name' => 'purpose',
                    'contents' => $purpose
                ]
            ]
        ]);
    }

    private function addFileToVectorStore(string $vectorStoreId, string $fileId): ?array
    {
        return $this->apiService->sendRequest('POST', "vector_stores/{$vectorStoreId}/files", [
            'json' => [
                'file_id' => $fileId
            ]
        ]);
    }

    private function listFilesInVectorStore(string $vectorStoreId): ?array
    {
        return $this->apiService->sendRequest('GET', "vector_stores/{$vectorStoreId}/files");
    }

    private function deleteFile(string $fileId): ?array
    {
        return $this->apiService->sendRequest('DELETE', "files/{$fileId}");
    }
    */

    protected function downloadFileContent(string $fileIdOrUrl, string $fileType): ?string
    {
        // Проверяем, является ли $fileIdOrUrl URL (например, Google Docs)
        if (filter_var($fileIdOrUrl, FILTER_VALIDATE_URL)) {
            $urlParts = parse_url($fileIdOrUrl);
            if (strpos($urlParts['host'], 'docs.google.com') !== false) {
                // Extract file ID from Google Docs URL
                if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $fileIdOrUrl, $matches)) {
                    $fileId = $matches[1];
                    $url = ($fileType === 'json')
                        ? "https://docs.google.com/spreadsheets/d/{$fileId}/gviz/tq?tqx=out:json"
                        : "https://docs.google.com/feeds/download/documents/export/Export?id={$fileId}&exportFormat=txt";
                    $response = Http::get($url);
                    if ($response->successful()) {
                        if ($fileType === 'txt') return $response->body();
                        elseif ($fileType === 'json') return $this->cleanJsonResponse($response->body());
                    }
                }
            }

            // Любые другие URL
            $response = Http::get($fileIdOrUrl);
            return $response->successful() ? $response->body() : null;
        } else {
            // Проверяем, является ли это путем к локальному файлу
            if (file_exists($fileIdOrUrl)) {
                return file_get_contents($fileIdOrUrl);
            }
            
            // Если это не URL и не локальный файл, возвращаем null
            return null;
        }
    }

    protected function cleanJsonResponse(string $rawResponse): ?string
    {
        if (preg_match('/({.*})/', $rawResponse, $matches)) {
            return $matches[1];
        }
        return null;
    }
}