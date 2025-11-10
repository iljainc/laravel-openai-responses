<?php

namespace Idpromogroup\LaravelOpenaiResponses\Services;

use Idpromogroup\LaravelOpenaiResponses\Models\LorTemplate;
use Idpromogroup\LaravelOpenaiResponses\Models\LorTemplateFile;
use Idpromogroup\LaravelOpenaiResponses\Services\LorApiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для управления векторными хранилищами OpenAI
 * 
 * Синхронизирует файлы шаблона с vector store в OpenAI:
 * 1. Проверяет существование vector store
 * 2. Сверяет файлы в OpenAI с БД
 * 3. Удаляет лишние, обновляет измененные, добавляет новые
 */
class VectorStoreManagementService
{
    private LorApiService $apiService;

    public function __construct(LorApiService $apiService)
    {
        $this->apiService = $apiService;
    }

    /**
     * Синхронизировать vector store для шаблона
     * 
     * @param LorTemplate $template Шаблон с файлами
     * @return bool Успешность операции
     */
    public function execute(LorTemplate $template): bool
    {
        lor_debug("VectorStoreManagementService::execute() - Starting for template: {$template->name}");

        // Получаем файлы шаблона
        $templateFiles = $template->templateFiles;
        
        if ($templateFiles->isEmpty()) {
            lor_debug("VectorStoreManagementService::execute() - No files for template {$template->name}");
            return true;
        }

        // ШАГ 1: ПРОВЕРКА - получаем или создаем vector store
        $vectorStoreId = $this->getOrCreateVectorStore($template);
        
        if (!$vectorStoreId) {
            Log::error("VectorStoreManagementService: Failed to get/create vector store for template {$template->id}");
            return false;
        }

        lor_debug("VectorStoreManagementService::execute() - Using vector store: {$vectorStoreId}");

        // ШАГ 2: ПОЛУЧАЕМ СПИСОК ФАЙЛОВ в OpenAI
        $filesInStore = $this->listFilesInVectorStore($vectorStoreId);
        $openaiFileIds = [];
        
        if (isset($filesInStore['data'])) {
            foreach ($filesInStore['data'] as $file) {
                $openaiFileIds[] = $file['id'];
            }
            lor_debug("VectorStoreManagementService::execute() - Files in OpenAI: " . count($openaiFileIds));
        }

        // ШАГ 3: СИНХРОНИЗАЦИЯ
        $validFileIds = [];
        
        foreach ($templateFiles as $templateFile) {
            $result = $this->syncTemplateFile($templateFile, $vectorStoreId);
            
            if ($result) {
                $validFileIds[] = $result;
            }
        }

        // ШАГ 4: УДАЛЕНИЕ ЛИШНИХ ФАЙЛОВ из OpenAI
        $filesToDelete = array_diff($openaiFileIds, $validFileIds);
        
        foreach ($filesToDelete as $fileId) {
            lor_debug("VectorStoreManagementService::execute() - Deleting orphaned file: {$fileId}");
            
            $this->removeFileFromVectorStore($vectorStoreId, $fileId);
        }

        lor_debug("VectorStoreManagementService::execute() - Synchronization complete");
        
        return true;
    }

    /**
     * Получить или создать vector store для шаблона
     */
    private function getOrCreateVectorStore(LorTemplate $template): ?string
    {
        // Проверяем есть ли уже vector_store_id у файлов
        $existingVectorStoreId = $template->templateFiles()->whereNotNull('vector_store_id')->first()?->vector_store_id;
        
        if ($existingVectorStoreId) {
            // Проверяем что это хранилище живое
            $storeInfo = $this->getVectorStoreInfo($existingVectorStoreId);
            
            if ($storeInfo && isset($storeInfo['id'])) {
                lor_debug("VectorStoreManagementService: Found existing vector store: {$existingVectorStoreId}");
                return $existingVectorStoreId;
            } else {
                lor_debug("VectorStoreManagementService: Vector store {$existingVectorStoreId} not found in OpenAI, will create new");
            }
        }
        
        // Создаем новое хранилище
        $vectorStore = $this->createVectorStore("Template_{$template->id}_{$template->name}");
        
        if (!isset($vectorStore['id'])) {
            Log::error('VectorStoreManagementService: Failed to create vector store', $vectorStore ?? []);
            return null;
        }
        
        $vectorStoreId = $vectorStore['id'];
        lor_debug("VectorStoreManagementService: Created new vector store: {$vectorStoreId}");
        
        return $vectorStoreId;
    }

    /**
     * Синхронизировать один файл шаблона
     * 
     * @return string|null ID файла в vector store или null при ошибке
     */
    private function syncTemplateFile(LorTemplateFile $templateFile, string $vectorStoreId): ?string
    {
        lor_debug("VectorStoreManagementService::syncTemplateFile() - Processing: {$templateFile->file_url}");
        
        // Скачиваем контент файла
        $fileContent = $this->downloadFileContent($templateFile->file_url, $templateFile->file_type);
        
        if ($fileContent === null) {
            Log::warning("VectorStoreManagementService: Could not download content for {$templateFile->file_url}");
            
            $templateFile->update([
                'upload_status' => LorTemplateFile::STATUS_FAILED,
                'error_message' => 'Failed to download file content'
            ]);
            
            return null;
        }
        
        // Вычисляем hash
        $currentFileHash = md5($fileContent);
        
        // Проверяем нужно ли обновлять
        if ($templateFile->file_hash === $currentFileHash && 
            $templateFile->vector_store_file_id && 
            $templateFile->vector_store_id === $vectorStoreId) {
            
            lor_debug("VectorStoreManagementService::syncTemplateFile() - File unchanged: {$templateFile->file_url}");
            
            return $templateFile->vector_store_file_id;
        }
        
        // Файл изменился или новый - загружаем
        lor_debug("VectorStoreManagementService::syncTemplateFile() - File changed, uploading: {$templateFile->file_url}");
        
        $templateFile->update(['upload_status' => LorTemplateFile::STATUS_UPLOADING]);
        
        // Определяем путь к файлу
        $isLocalFile = !filter_var($templateFile->file_url, FILTER_VALIDATE_URL) && file_exists($templateFile->file_url);
        
        if ($isLocalFile) {
            $filePath = $templateFile->file_url;
            $deleteAfter = false;
        } else {
            // Создаем временный файл
            $filePath = storage_path('app/temp/template_file_' . $templateFile->id . '.' . $templateFile->file_type);
            $tempDir = dirname($filePath);
            
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            
            file_put_contents($filePath, $fileContent);
            $deleteAfter = true;
        }
        
        // Загружаем в OpenAI
        $uploadedFile = $this->uploadFileToOpenAI($filePath, 'assistants');
        
        if (!isset($uploadedFile['id'])) {
            Log::error('VectorStoreManagementService: Failed to upload file to OpenAI', $uploadedFile ?? []);
            
            $templateFile->update([
                'upload_status' => LorTemplateFile::STATUS_FAILED,
                'error_message' => 'Failed to upload file to OpenAI'
            ]);
            
            if ($deleteAfter && file_exists($filePath)) {
                unlink($filePath);
            }
            
            return null;
        }
        
        $fileId = $uploadedFile['id'];
        lor_debug("VectorStoreManagementService::syncTemplateFile() - Uploaded to OpenAI: {$fileId}");
        
        // Добавляем в vector store
        $addResult = $this->addFileToVectorStore($vectorStoreId, $fileId);
        
        if (!$addResult) {
            Log::error('VectorStoreManagementService: Failed to add file to vector store', ['file_id' => $fileId, 'vs_id' => $vectorStoreId]);
            
            $templateFile->update([
                'upload_status' => LorTemplateFile::STATUS_FAILED,
                'error_message' => 'Failed to add file to vector store'
            ]);
            
            if ($deleteAfter && file_exists($filePath)) {
                unlink($filePath);
            }
            
            return null;
        }
        
        // Обновляем запись в БД
        $templateFile->update([
            'vector_store_id' => $vectorStoreId,
            'vector_store_file_id' => $fileId,
            'file_hash' => $currentFileHash,
            'upload_status' => LorTemplateFile::STATUS_COMPLETED,
            'error_message' => null
        ]);
        
        lor_debug("VectorStoreManagementService::syncTemplateFile() - Successfully synced: {$templateFile->file_url}");
        
        // Удаляем временный файл
        if ($deleteAfter && file_exists($filePath)) {
            unlink($filePath);
        }
        
        return $fileId;
    }

    /**
     * Получить информацию о vector store
     */
    private function getVectorStoreInfo(string $vectorStoreId): ?array
    {
        return $this->apiService->sendRequest('GET', "vector_stores/{$vectorStoreId}");
    }

    /**
     * Создать vector store
     */
    private function createVectorStore(string $name): ?array
    {
        return $this->apiService->sendRequest('POST', "vector_stores", [
            'json' => ['name' => $name]
        ]);
    }

    /**
     * Загрузить файл в OpenAI
     */
    private function uploadFileToOpenAI(string $filePath, string $purpose): ?array
    {
        return $this->apiService->uploadFile($filePath, $purpose);
    }

    /**
     * Добавить файл в vector store
     */
    private function addFileToVectorStore(string $vectorStoreId, string $fileId): ?array
    {
        return $this->apiService->sendRequest('POST', "vector_stores/{$vectorStoreId}/files", [
            'json' => ['file_id' => $fileId]
        ]);
    }

    /**
     * Получить список файлов в vector store
     */
    private function listFilesInVectorStore(string $vectorStoreId): ?array
    {
        return $this->apiService->sendRequest('GET', "vector_stores/{$vectorStoreId}/files");
    }

    /**
     * Удалить файл из vector store
     */
    private function removeFileFromVectorStore(string $vectorStoreId, string $fileId): ?array
    {
        return $this->apiService->sendRequest('DELETE', "vector_stores/{$vectorStoreId}/files/{$fileId}");
    }

    /**
     * Скачать содержимое файла
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

    /**
     * Очистить JSON ответ от Google Sheets
     */
    protected function cleanJsonResponse(string $rawResponse): ?string
    {
        if (preg_match('/({.*})/', $rawResponse, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
