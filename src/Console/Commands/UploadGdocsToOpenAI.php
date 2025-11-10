<?php

namespace Idpromogroup\LaravelOpenaiResponses\Console\Commands;

use Illuminate\Console\Command;
use Idpromogroup\LaravelOpenaiResponses\Models\LorTemplate;
use Idpromogroup\LaravelOpenaiResponses\Services\LorApiService;
use Idpromogroup\LaravelOpenaiResponses\Services\VectorStoreManagementService;

/**
 * Команда для синхронизации файлов шаблонов с vector stores OpenAI
 * 
 * Использование:
 * - php artisan lor:sync-vector-stores - синхронизировать все шаблоны с файлами
 * - php artisan lor:sync-vector-stores --template=1 - синхронизировать конкретный шаблон
 */
class UploadGdocsToOpenAI extends Command
{
    protected $signature   = 'lor:sync-vector-stores {--template= : ID шаблона для синхронизации}';
    protected $description = 'Синхронизация файлов шаблонов с vector stores OpenAI';

    public function handle(): int
    {
        $templateId = $this->option('template');
        
        if ($templateId) {
            // Синхронизируем конкретный шаблон
            $template = LorTemplate::find($templateId);
            
            if (!$template) {
                $this->error("Шаблон с ID {$templateId} не найден");
                return 1;
            }
            
            return $this->syncTemplate($template);
        }
        
        // Синхронизируем все шаблоны с файлами
        $templates = LorTemplate::has('templateFiles')->get();
        
        if ($templates->isEmpty()) {
            $this->info("Нет шаблонов с файлами для синхронизации");
            return 0;
        }
        
        $this->info("Найдено шаблонов для синхронизации: " . $templates->count());
        
        foreach ($templates as $template) {
            $this->info("\n▼ Шаблон: {$template->name} (ID: {$template->id})");
            
            $result = $this->syncTemplate($template);
            
            if ($result === 0) {
                $this->info("▲ Готово: {$template->name}\n");
            } else {
                $this->error("▲ Ошибка: {$template->name}\n");
            }
        }
        
        $this->info("\nСинхронизация завершена");
        return 0;
    }
    
    private function syncTemplate(LorTemplate $template): int
    {
        try {
            // Используем API ключ шаблона или дефолтный
            $apiKey = $template->getApiKey();
            $apiService = new LorApiService($apiKey);
            $vectorStoreService = new VectorStoreManagementService($apiService);
            
            $success = $vectorStoreService->execute($template);
            
            return $success ? 0 : 1;
            
        } catch (\Exception $e) {
            $this->error("Ошибка при синхронизации: " . $e->getMessage());
            return 1;
        }
    }
}
