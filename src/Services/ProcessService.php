<?php

namespace Idpromogroup\LaravelOpenaiResponses\Services;

use Idpromogroup\LaravelOpenaiResponses\Models\OpenAiRequestLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Сервис для отслеживания процессов обработки ответов OpenAI.
 * Уникальность процесса определяется PID + время запуска.
 * Внешний ключ объединяет channel + user_id + msg_id.
 */
class ProcessService
{
    const STATUS_PENDING = 'pending';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    public $responseLog;
    private $lockFp = null;

    /**
     * Инициализация процесса - сначала создаем запись, потом разбираемся с хуйней
     */
    public function init(string $externalKey, $requestText): bool
    {
        // Получаем PID сразу нахуй
        $pid = getmypid();
        
        // Если массив - упаковываем в JSON
        if (is_array($requestText)) {
            $requestText = json_encode($requestText, JSON_UNESCAPED_UNICODE);
        }
        
        // СНАЧАЛА НАХУЙ создаем запись в любом случае со всеми данными
        $this->responseLog = OpenAiRequestLog::create([
            'external_key' => $externalKey,
            'request_text' => $requestText,
            'status' => self::STATUS_PENDING,
            'pid' => $pid,
            'process_start_time' => null,
        ]);

        // File lock для проверки реальной активности скрипта (не воркера)
        // Один lock-файл на один external_key (channel+user_id+msg_id)
        $lockFile = storage_path('app/temp/openai_lock_' . md5($externalKey));
        $lockDir = dirname($lockFile);
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0755, true);
        }

        // ШАГ 1: Открываем файл (создаст если нет, не трогает если есть)
        // Режим 'c' = create/open without truncate
        // НЕ БЛОКИРУЕТ - просто открывает файл
        $this->lockFp = fopen($lockFile, 'c');
        if (!$this->lockFp) {
            $this->responseLog->update(['status' => self::STATUS_FAILED]);
            $this->comment('FAILED: Cannot create lock file');
            return false;
        }

        // ШАГ 2: ЗДЕСЬ ПРОИСХОДИТ БЛОКИРОВКА - пытаемся захватить эксклюзивный lock
        // LOCK_EX = эксклюзивная блокировка (только один процесс)
        // LOCK_NB = non-blocking (не ждать, сразу вернуть false если занято)
        if (!flock($this->lockFp, LOCK_EX | LOCK_NB)) {
            // Lock занят другим скриптом = он РЕАЛЬНО работает прямо сейчас
            // flock НЕ СМОТРИТ на PID воркера - блокировка на уровне ядра ОС
            fclose($this->lockFp);
            $this->lockFp = null;
            
            $this->responseLog->update(['status' => self::STATUS_FAILED]);
            $this->comment('REJECTED: Another process is actively working (file lock held)');
            return false;
        }

        // Lock успешно захвачен текущим скриптом, можем работать
        // Блокировка автоматически снимется при fclose() или завершении PHP скрипта
        touch($lockFile); // Обновляем mtime для отладки

        // Можем выполнять - меняем статус на in_progress
        $this->responseLog->update(['status' => self::STATUS_IN_PROGRESS]);
        $this->comment('SUCCESS: Process started, ready to work');
        lor_debug("ProcessService::init() - Process started successfully, log ID: {$this->responseLog->id}");
        return true;
    }

    /**
     * Добавить комментарий к процессу
     */
    public function comment(string $text): void
    {
        if (!$this->responseLog) {
            lor_debug_error("ProcessService::comment() - No active response log for comment: {$text}");
            return;
        }

            try {
                DB::transaction(function () use ($text) {
                $currentLog = OpenAiRequestLog::find($this->responseLog->id);

                    if ($currentLog) {
                        $timestamp = Carbon::now()->format('m-d H:i:s.v');
                        $newComment = "[{$timestamp}] {$text}\n";

                        $updatedComments = ($currentLog->comments ?? '') . $newComment;

                    $currentLog->update(['comments' => $updatedComments]);
                    $this->responseLog = $currentLog;
                    }
                });
            } catch (\Exception $e) {
            lor_debug_error("Failed to add comment - Log ID: {$this->responseLog->id}, Error: {$e->getMessage()}");
            Log::error("LOR: ProcessService: Failed to add comment", ['response_log_id' => $this->responseLog->id, 'error' => $e->getMessage()]);
        }
    }




    /**
     * Завершить процесс
     */
    public function close(?string $responseText = null, ?float $executionTime = null, string $status = self::STATUS_COMPLETED): void
    {
        if ($this->responseLog) {
            $updateData = ['status' => $status];
            
            if ($responseText !== null) {
                $updateData['response_text'] = $responseText;
            }
            
            if ($executionTime !== null) {
                $updateData['execution_time'] = $executionTime;
            }
            
            $this->responseLog->update($updateData);
            $this->comment($status === self::STATUS_FAILED ? 'Process failed' : 'Process completed');
        }

        // Освобождаем file lock
        if ($this->lockFp) {
            fclose($this->lockFp);
            $this->lockFp = null;
        }
    }


}