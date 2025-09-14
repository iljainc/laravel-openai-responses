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

    /**
     * Получить время запуска процесса по PID
     */
    private function getProcessStartTime(int $pid)
    {
        try {
            // Получаем время запуска через /proc/PID/stat
            $statFile = "/proc/$pid/stat";
            if (file_exists($statFile)) {
                $stat = file_get_contents($statFile);
                $statArray = explode(' ', $stat);
                return (int)$statArray[21]; // starttime in clock ticks
            }
            
            lor_debug_error("Process stat file not found - PID: {$pid}");
            return false;
        } catch (\Exception $e) {
            lor_debug_error("Failed to get process start time - PID: {$pid}, Error: {$e->getMessage()}");
            Log::error("LOR: ProcessService: Failed to get process start time", ['pid' => $pid, 'error' => $e->getMessage()]);
            return false;
        }
    }


    /**
     * Проверить активен ли процесс в системе
     */
    private function isProcessActive(int $pid, int $startTime): bool
    {
        try {
            // Проверяем существование процесса
            if (!file_exists("/proc/$pid")) {
                return false;
            }

            // Проверяем время запуска
            $currentStartTime = $this->getProcessStartTime($pid);
            return $currentStartTime === $startTime;
        } catch (\Exception $e) {
            lor_debug_error("Failed to check process status - PID: {$pid}, Error: {$e->getMessage()}");
            Log::error("LOR: ProcessService: Failed to check process status", ['pid' => $pid, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Инициализация процесса - сначала создаем запись, потом разбираемся с хуйней
     */
    public function init(string $externalKey, $requestText): bool
    {
        // Получаем PID сразу нахуй
        $pid = getmypid();
        $startTime = $this->getProcessStartTime($pid);
        
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
            'process_start_time' => $startTime,
        ]);

        if ($startTime === false) {
            $this->responseLog->update(['status' => self::STATUS_FAILED]);
            $this->comment('FAILED: Cannot get process start time - system error');
            lor_debug_error("ProcessService::init() - Failed to get process start time");
            Log::error("LOR: ProcessService: Failed to get process start time", ['pid' => $pid, 'external_key' => $externalKey]);
            return false;
        }

        // Ищем активные процессы для данного внешнего ключа (кроме текущего)
        $activeProcesses = OpenAiRequestLog::where('external_key', $externalKey)
            ->where('id', '!=', $this->responseLog->id)
            ->whereNotIn('status', [self::STATUS_COMPLETED, self::STATUS_FAILED])
            ->get();

        // Проверяем каждый процесс на активность в системе
        foreach ($activeProcesses as $process) {
            if (!$process->pid || !$process->process_start_time) continue;
            
            $checkPid = $process->pid;
            $checkStartTime = $process->process_start_time;
            
            if ($this->isProcessActive((int)$checkPid, (int)$checkStartTime)) {
                lor_debug_error("ProcessService::init() - Found active process ID: {$process->id}");
                $this->responseLog->update(['status' => self::STATUS_FAILED]);
                $this->comment("REJECTED: Another process is active (ID: {$process->id})");
                return false;
        } else {
                // Процесс мертв, помечаем как failed
                $process->update([
                    'status' => self::STATUS_FAILED,
                    'comments' => ($process->comments ?? '') . "\n[" . now()->format('Y-m-d H:i:s.v') . "] Process marked as failed - not active in system (killed by process ID: {$this->responseLog->id})"
                ]);
                lor_debug("ProcessService::init() - Marked dead process as failed ID: {$process->id}");
                $this->comment("Marked dead process as failed: {$process->id}");
            }
        }

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
    }


}