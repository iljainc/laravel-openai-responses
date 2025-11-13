<?php

namespace Idpromogroup\LaravelOpenaiResponses\Services;

use Idpromogroup\LaravelOpenaiResponses\Models\LorRequestLog;
use Idpromogroup\LaravelOpenaiResponses\Models\LorFunctionCall;
use Idpromogroup\LaravelOpenaiResponses\Models\LorConversation;
use Idpromogroup\LaravelOpenaiResponses\Models\LorTemplate;
use Idpromogroup\LaravelOpenaiResponses\Result;
use Illuminate\Support\Facades\Log;

/**
 * Основной сервис для работы с OpenAI API через Responses API
 * 
 * Предоставляет удобный интерфейс для:
 * - Отправки сообщений в OpenAI
 * - Работы с файлами (изображения и PDF)
 * - Использования шаблонов
 * - Обработки function calls
 * - Управления диалогами
 */
class LorService
{
    /** Внешний ключ для идентификации запроса */
    private string $externalKey;
    
    /** Модель OpenAI (по умолчанию gpt-4o-mini) */
    private string $model = 'gpt-4o-mini';
    
    /** Основное сообщение пользователя */
    private ?string $message = null;
    
    /** Сервис для обработки процессов и логирования */
    private ?ProcessService $processService = null;
    
    /** Предустановленные сообщения для диалога */
    private ?array $messages = null;
    
    // Optional parameters
    /** Системные инструкции для AI */
    private ?string $instructions = null;
    
    /** Массив инструментов (function calls) */
    private array $tools = [];
    
    /** Формат ответа (text, json_object, json_schema) */
    private ?string $responseFormat = null;
    
    /** JSON схема для структурированного ответа */
    private ?array $jsonSchema = null;
    
    /** Температура для генерации (0.0 - 2.0) */
    private ?float $temperature = null;
    
    /** Пользователь для режима диалога */
    private ?string $conversationUser = null;
    
    /** ID диалога в OpenAI */
    private ?string $conversationId = null;
    
    /** Массив прикрепленных файлов */
    private array $attachments = [];
    
    /** Используемый шаблон */
    private ?LorTemplate $template = null;

    /**
     * Конструктор сервиса
     * 
     * @param string $externalKey Внешний ключ для идентификации запроса
     * @param string|null $message Сообщение пользователя (опционально)
     */
    public function __construct(string $externalKey, ?string $message = null)
    {
        $this->externalKey = $externalKey;
        if ($message !== null) {
            $this->message = $message;
        }
    }

    /* --------------------------------------------------------------------
     |  BUILDER METHODS
     |------------------------------------------------------------------- */

    /**
     * Установить сообщение пользователя
     * 
     * @param string $message Сообщение пользователя
     * @return self
     */
    public function setMessage(string $message): self
    {
        $this->message = $message;
        return $this;
    }

    /**
     * Установить модель OpenAI
     * 
     * @param string $model Название модели (например, 'gpt-4o-mini', 'gpt-4o')
     * @return self
     */
    public function setModel(string $model): self
    {
        $this->model = $model;
        return $this;
    }

    /**
     * Установить системные инструкции для AI
     * 
     * @param string $instructions Инструкции для модели
     * @return self
     */
    public function setInstructions(string $instructions): self
    {
        $this->instructions = $instructions;
        return $this;
    }

    /**
     * Установить инструменты (function calls)
     * 
     * @param array $tools Массив инструментов
     * @return self
     */
    public function setTools(array $tools): self
    {
        $this->tools = $tools;
        return $this;
    }

    /**
     * Установить формат ответа
     * 
     * @param string $format Формат: 'text', 'json_object', 'json_schema'
     * @return self
     */
    public function setResponseFormat(string $format): self
    {
        $this->responseFormat = $format;
        return $this;
    }

    /**
     * Установить JSON схему для структурированного ответа
     * 
     * @param array $schema JSON схема
     * @return self
     */
    public function setJSONSchema(array $schema): self
    {
        $this->jsonSchema = $schema;
        return $this;
    }

    /**
     * Установить температуру генерации
     * 
     * @param float $temperature Температура от 0.0 до 2.0
     * @return self
     */
    public function setTemperature(float $temperature): self
    {
        $this->temperature = $temperature;
        return $this;
    }

    /**
     * Включить режим диалога для пользователя
     * 
     * @param string $user Идентификатор пользователя
     * @return self
     */
    public function setConversation(string $user): self
    {
        $this->conversationUser = $user;
        return $this;
    }

    /**
     * Загрузить локальный файл в OpenAI и прикрепить его
     * 
     * Поддерживаемые форматы:
     * - Изображения: JPG, PNG, WEBP
     * - Документы: PDF
     * 
     * @param string $absolutePath Абсолютный путь к файлу
     * @return self
     * @throws \InvalidArgumentException Если формат файла не поддерживается
     */
    public function attachLocalFile(string $absolutePath): self
    {
        // Проверяем существование файла
        if (!file_exists($absolutePath)) {
            lor_debug("LorService::attachLocalFile() - File not found: {$absolutePath}");
            throw new \InvalidArgumentException("File not found: {$absolutePath}");
        }
        
        // Проверяем размер файла
        if (filesize($absolutePath) === 0) {
            lor_debug("LorService::attachLocalFile() - Empty file: {$absolutePath}");
            throw new \InvalidArgumentException("Empty file: {$absolutePath}");
        }
        
        // Определяем MIME тип файла
        $mimeType = mime_content_type($absolutePath);
        if (!$mimeType) {
            lor_debug("LorService::attachLocalFile() - Cannot determine MIME type for: {$absolutePath}");
            return $this;
        }

        // Определяем тип файла по MIME и проверяем поддержку
        $fileType = $this->getFileTypeFromMime($mimeType);
        if (!$fileType) {
            lor_debug("LorService::attachLocalFile() - Unsupported file type: {$mimeType} for: {$absolutePath}");
            throw new \InvalidArgumentException(__("Unsupported format. Upload PDF or image (JPG/PNG/WEBP)."));
        }

        lor_debug("LorService::attachLocalFile() - File: {$absolutePath}, MIME: {$mimeType}, Type: {$fileType}");

        // Загружаем файл в OpenAI с purpose 'user_data'
        $api = app(LorApiService::class);
        $resp = $api->uploadFile($absolutePath, 'user_data');
        
        // Добавляем файл в список вложений
        if (!empty($resp['id'])) {
            $this->attachments[] = [
                'file_id' => $resp['id'],
                'type' => $fileType
            ];
            lor_debug("LorService::attachLocalFile() - Uploaded file ID: {$resp['id']}, Type: {$fileType}");
        }
        
        return $this;
    }

    /**
     * Определить тип файла по MIME типу
     * 
     * @param string $mimeType MIME тип файла
     * @return string|null 'image', 'pdf' или null для неподдерживаемых типов
     */
    private function getFileTypeFromMime(string $mimeType): ?string
    {
        if (str_starts_with($mimeType, 'image/')) {
            // Разрешенные типы изображений (JPG, PNG, WEBP)
            // image/jpg иногда встречается вместо image/jpeg
            $allowedImageTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
            if (in_array($mimeType, $allowedImageTypes)) {
                return 'image';
            }
            // Все остальные image/* (tiff, heic, gif, etc.) - отлуп
        } elseif ($mimeType === 'application/pdf') {
            return 'pdf';
        }
        
        return null; // Неподдерживаемый тип
    }

    /**
     * Использовать шаблон для настройки параметров
     * 
     * @param int|string $template ID шаблона или его название
     * @return self
     * @throws \InvalidArgumentException Если шаблон не найден
     */
    public function useTemplate($template): self
    {
        // Поиск шаблона по ID или имени
        if (is_numeric($template)) {
            $templateModel = LorTemplate::find($template);
        } else {
            $templateModel = LorTemplate::where('name', $template)->first();
        }

        if (!$templateModel) {
            throw new \InvalidArgumentException("Template not found: {$template}");
        }
        
        // Сохраняем шаблон для доступа к vector stores
        $this->template = $templateModel;

        // Заполняем все свойства из шаблона
        if ($templateModel->instructions) {
            $this->instructions = $templateModel->instructions;
        }
        
        if ($templateModel->model) {
            $this->model = $templateModel->model;
        }
        
        if ($templateModel->tools) {
            $this->tools = $templateModel->tools;
        }
        
        if ($templateModel->temperature !== null) {
            $this->temperature = $templateModel->temperature;
        }
        
        if ($templateModel->response_format) {
            $this->setResponseFormat($templateModel->response_format);
        }
        
        if ($templateModel->json_schema) {
            $this->setJSONSchema($templateModel->json_schema);
        }

        return $this;
    }


    /* --------------------------------------------------------------------
     |  EXECUTION METHODS
     |------------------------------------------------------------------- */

    /**
     * Выполнить запрос к OpenAI API
     * 
     * Основной метод для отправки запроса и получения ответа.
     * Обрабатывает function calls, ошибки API и логирование.
     * 
     * @return Result Результат выполнения запроса
     */
    public function execute(): Result
    {
        // Проверка что установлено либо message, либо messages
        if (empty($this->message) && empty($this->messages)) {
            throw new \InvalidArgumentException('Either message or messages must be set before execution');
        }
        
        lor_debug("LorService::execute() - INIT model = {$this->model}, externalKey = {$this->externalKey}");
        lor_debug("LorService::execute() - Message: " . (!empty($this->message) ? substr($this->message, 0, 100) . (strlen($this->message) > 100 ? '...' : '') : 'using predefined messages'));
        lor_debug("LorService::execute() - Conversation user: " . ($this->conversationUser ?? 'none'));

        $startTime = microtime(true);
        
        $this->processService = app(ProcessService::class);
        if (!$this->processService->init($this->externalKey, $this->getInputText())) {
            return Result::status('Already in work');
        }
        
        // Handle conversation mode after init
        if ($this->conversationUser) {
            lor_debug("LorService::execute() - Creating/getting conversation for user: {$this->conversationUser}");
            $this->conversationId = $this->getOrCreateConversation();
            if (!$this->conversationId) {
                lor_debug("LorService::execute() - FAILED to create conversation");
                $this->processService->responseLog->update([
                    'status' => ProcessService::STATUS_FAILED,
                ]);
                $this->processService->comment('FAILED: Cannot create conversation');
                return Result::failure('Failed to create conversation');
            }
            
            lor_debug("LorService::execute() - Using conversation ID: {$this->conversationId}");
            // Add conversation_id to existing log
            $this->processService->responseLog->update(['conversation_id' => $this->conversationId]);
        }

        try {
            $apiService = app(LorApiService::class);
            $response = $apiService->chatResponses($this->buildRequestData());
            
            $this->processService->comment('SUCCESS: responce received');
                        
            // Handle function calls if present in Responses API
            if (isset($response['output']) && is_array($response['output'])) {
                $functionCalls = array_filter($response['output'], function($item) {
                    return isset($item['type']) && in_array($item['type'], ['tool_call','function_call'], true);
                });
                
                if (!empty($functionCalls)) {
                    lor_debug("LorService::execute() - Found " . count($functionCalls) . " function calls to handle");                    
                    $this->processService->comment('Run functions');
                    $response = $this->handleFunctionCalls($functionCalls);
                }
            }
            
            $this->processService->close(json_encode($response, JSON_UNESCAPED_UNICODE), round(microtime(true) - $startTime, 2));
            
            lor_debug("LorService::execute() - Request completed");
            return Result::success($response);
            
        } catch (\Exception $e) {
            $executionTime = round(microtime(true) - $startTime, 2);
            $errorMessage = $e->getMessage();
            $errorBody = null;
            $errorJson = null;

            if ($e instanceof \GuzzleHttp\Exception\ClientException && $e->hasResponse()) {
                $errorBody = $e->getResponse()->getBody()->getContents();
                $errorJson = json_decode($errorBody, true);

                if (json_last_error() === JSON_ERROR_NONE && isset($errorJson['error'])) {
                    $errorData = $errorJson['error'];
                    $errorMessage = $errorData['message'] ?? $errorMessage;
                    $errorCode = $errorData['code'] ?? null;

                    lor_debug("LorService::execute() - API Error ({$errorCode}): {$errorMessage}");

                    if ($errorCode === 'unsupported_file_format') {
                        $this->processService->close($errorBody ?? $errorMessage, $executionTime, ProcessService::STATUS_FAILED);
                        return Result::failure($errorMessage);
                    }

                    if ($errorCode === 'response_expired') {
                        lor_debug("LorService::execute() - Conversation TTL expired, nuking dialog");
                        $this->processService->close($errorBody ?? $errorMessage, $executionTime, ProcessService::STATUS_FAILED);

                        if ($this->conversationUser && $this->conversationId) {
                            $conversation = LorConversation::where('conversation_id', $this->conversationId)->first();
                            if ($conversation) {
                                $conversation->update(['status' => LorConversation::STATUS_CLOSED]);
                                lor_debug("LorService::execute() - Closed expired conversation: {$this->conversationId}");
                            }
                        }

                        $this->conversationId = null;
                        lor_debug("LorService::execute() - Retrying after conversation reset");
                        return $this->execute();
                    };
                }
            }

            Log::error("LOR: LorService::execute() failed - " . $errorMessage);
            lor_debug("LOR: LorService::execute() failed - " . $errorMessage);

            $this->processService->close($errorBody ?? $errorMessage, $executionTime, ProcessService::STATUS_FAILED);

            return Result::failure('API Error: ' . $errorMessage);
        }
    }


    /* --------------------------------------------------------------------
     |  PRIVATE METHODS
     |------------------------------------------------------------------- */

    /**
     * Построить данные запроса для OpenAI API
     * 
     * Формирует массив параметров для отправки в Responses API,
     * включая обработку файлов, инструментов и настроек.
     * 
     * @return array Данные для запроса к API
     */
    private function buildRequestData(): array
    {
        $data = [
            'model' => $this->model,
            'input' => $this->getInputText(),
        ];
        
        // Add temperature only if set
        if ($this->temperature !== null) {
            $data['temperature'] = $this->temperature;
        }
        
        // Add conversation if provided
        if ($this->conversationId) {
            $data['conversation'] = $this->conversationId;
        }

        // Set text format for Responses API
        if ($this->responseFormat === 'json_schema' && $this->jsonSchema) {
            $data['text'] = [
                'format' => [
                    'type' => 'json_schema',
                    'json_schema' => $this->jsonSchema
                ]
            ];
        } elseif ($this->responseFormat === 'json_object') {
            $data['text'] = [
                'format' => [
                    'type' => 'json_object'
                ]
            ];
        } else {
            $data['text'] = [
                'format' => [
                    'type' => 'text'
                ]
            ];
        }

        // Добавляем file_search tool если есть vector store в шаблоне
        $vectorStoreIds = $this->getVectorStoreIds();
        if (!empty($vectorStoreIds)) {
            $fileSearchTool = [
                'type' => 'file_search',
                'file_search' => [
                    'vector_store_ids' => $vectorStoreIds
                ]
            ];
            
            // Добавляем file_search к существующим tools
            if (empty($this->tools)) {
                $this->tools = [$fileSearchTool];
            } else {
                // Проверяем нет ли уже file_search
                $hasFileSearch = false;
                foreach ($this->tools as $tool) {
                    if (isset($tool['type']) && $tool['type'] === 'file_search') {
                        $hasFileSearch = true;
                        break;
                    }
                }
                if (!$hasFileSearch) {
                    $this->tools[] = $fileSearchTool;
                }
            }
        }

        if (!empty($this->tools)) {
            // Ensure tools is an array of objects, not a single object
            if (is_array($this->tools) && isset($this->tools['name'])) {
                // Single tool object - wrap in array
                $data['tools'] = [$this->tools];
            } else {
                // Already array of tools or empty
                $data['tools'] = $this->tools;
            }
        }

        // Обрабатываем вложения файлов если есть
        if (!empty($this->attachments)) {
            // Валидируем что все файлы имеют определенные типы
            foreach ($this->attachments as $attachment) {
                if ($attachment['type'] === null) {
                    lor_debug("LorService::buildRequestData() - File without type: {$attachment['file_id']}");
                    throw new \InvalidArgumentException(__("Unsupported format. Upload PDF or image (JPG/PNG/WEBP)."));
                }
            }
            
            // Конвертируем вложения в правильный формат для Responses API
            if (!empty($data['input'])) {
                $lastIndex = count($data['input']) - 1;
                $lastMessage = &$data['input'][$lastIndex];
                
                // Модифицируем только пользовательские сообщения
                if (($lastMessage['role'] ?? '') === 'user') {
                    $content = [];
                    
                    // Добавляем текстовое содержимое первым (только если это строка)
                    if (isset($lastMessage['content'])) {
                        if (is_string($lastMessage['content'])) {
                            // Обычный текстовый контент
                            $content[] = [
                                'type' => 'input_text',
                                'text' => $lastMessage['content']
                            ];
                        } elseif (is_array($lastMessage['content'])) {
                            // Уже структурированный контент - используем как есть
                            $content = $lastMessage['content'];
                        }
                    }
                    
                    // Добавляем файловые вложения с правильным типом
                    foreach ($this->attachments as $attachment) {
                        $fileId = $attachment['file_id'];
                        $fileType = $attachment['type'];
                        
                        if ($fileType === 'image') {
                            // Изображения отправляем как input_image (плоский формат)
                            $content[] = [
                                'type' => 'input_image',
                                'file_id' => $fileId
                            ];
                            lor_debug("LorService::buildRequestData() - Added image attachment: {$fileId}");
                        } elseif ($fileType === 'pdf') {
                            // PDF файлы отправляем как input_file (плоский формат)
                            $content[] = [
                                'type' => 'input_file',
                                'file_id' => $fileId
                            ];
                            lor_debug("LorService::buildRequestData() - Added PDF attachment: {$fileId}");
                        } else {
                            lor_debug("LorService::buildRequestData() - Unknown file type: {$fileType} for file: {$fileId}");
                        }
                    }
                    
                    // Обновляем контент только если есть файлы для добавления
                    if (!empty($this->attachments)) {
                        $lastMessage['content'] = $content;
                    }
                }
            }
        }

        

        return $data;
    }

    /* --------------------------------------------------------------------
     |  FUNCTION HANDLING
     |------------------------------------------------------------------- */

     /**
      * Обработать вызовы функций (function calls)
      * 
      * Выполняет все function calls, полученные от AI,
      * сохраняет результаты в базу данных и отправляет новый запрос.
      * 
      * @param array $toolCalls Массив вызовов функций
      * @return array Результат нового запроса с результатами функций
      */
     private function handleFunctionCalls(array $toolCalls): array
     {
         lor_debug("LorService::handleFunctionCalls() - Starting function calls handling");
         
         // Формируем messages с результатами функций
         $this->messages = [];
     
         foreach ($toolCalls as $toolCall) {
            $functionName = $toolCall['name']
                ?? ($toolCall['function']['name'] ?? ($toolCall['tool_name'] ?? null));

            $argumentsRaw = $toolCall['arguments']
                ?? ($toolCall['function']['arguments'] ?? '{}');

            $toolCallId = $toolCall['call_id']
                ?? ($toolCall['id'] ?? ($toolCall['tool_call_id'] ?? null));

            if (!$functionName || !$toolCallId) {
                lor_debug("LorService::handleFunctionCalls() - Skipping invalid function call");
                continue;
            }

            lor_debug("LorService::handleFunctionCalls() - Processing function: {$functionName}");
            $startTime = microtime(true);
            $args = is_string($argumentsRaw)
                ? (json_decode($argumentsRaw, true) ?: [])
                : (is_array($argumentsRaw) ? $argumentsRaw : []);

            $functionLog = LorFunctionCall::create([
                'request_log_id' => $this->processService->responseLog->id,
                'external_key'   => $this->externalKey,
                'function_name'  => $functionName,
                'arguments'      => $args,
                'status'         => LorFunctionCall::STATUS_PENDING,
            ]);

            try {
                $handlerClass = config('openai-responses.function_handler');
                if ($handlerClass && class_exists($handlerClass)) {
                    $handler = app($handlerClass);
                    $result = $handler->execute($functionName, $args);
                    lor_debug("LorService::handleFunctionCalls() - Function {$functionName} returned: " . json_encode($result));
                } else {
                    lor_debug("LorService::handleFunctionCalls() - Function handler not configured: {$handlerClass}");
                    $result = ['error' => 'Function handler not configured or not found'];
                }

                $functionLog->update([
                    'output'         => $result,
                    'status'         => LorFunctionCall::STATUS_SUCCESS,
                    'execution_time' => round(microtime(true) - $startTime, 2),
                ]);

                // Добавляем результат функции в messages
                $this->messages[] = [
                    'type' => 'function_call_output',
                    'call_id' => $toolCallId,
                    'output' => is_string($result)
                        ? $result
                        : json_encode($result, JSON_UNESCAPED_UNICODE),
                ];
            } catch (\Exception $e) {
                $functionLog->update([
                    'status'         => LorFunctionCall::STATUS_FAILED,
                    'error_message'  => $e->getMessage(),
                    'execution_time' => round(microtime(true) - $startTime, 2),
                ]);

                // Добавляем ошибку в messages
                $this->messages[] = [
                    'type' => 'function_call_output',
                    'call_id' => $toolCallId,
                    'output' => json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE),
                ];

                Log::error("LOR: Function call failed - {$functionName}: " . $e->getMessage());
            }
        }
        
        // Обнуляем processService и делаем новый запрос
        $this->processService = null;
        lor_debug("LorService::handleFunctionCalls() - Making new request with function results");
        
        $result = $this->execute();
        return $result->success ? $result->data : [];
    }
    
    /**
     * Получить vector_store_id из связанных файлов шаблона
     * 
     * @return array Массив vector store IDs
     */
    private function getVectorStoreIds(): array
    {
        if (!$this->template) {
            return [];
        }
        
        // Получаем уникальные vector_store_id из файлов шаблона
        $vectorStoreIds = $this->template->templateFiles()
            ->whereNotNull('vector_store_id')
            ->pluck('vector_store_id')
            ->unique()
            ->values()
            ->toArray();
        
        if (!empty($vectorStoreIds)) {
            lor_debug("LorService::getVectorStoreIds() - Found vector stores: " . implode(', ', $vectorStoreIds));
        }
        
        return $vectorStoreIds;
    }

    /**
     * Получить существующий или создать новый диалог для пользователя
     * 
     * @return string|null ID диалога в OpenAI или null при ошибке
     */
    private function getOrCreateConversation(): ?string
    {
        // Find active conversation for user
        $conversation = LorConversation::where('user', $this->conversationUser)
            ->where('status', LorConversation::STATUS_ACTIVE)
            ->first();
            
        if ($conversation) {
            return $conversation->conversation_id;
        }
        
        // Create new conversation via API
        try {
            $apiService = app(LorApiService::class);
            $conversationId = $apiService->createConversation($this->instructions);
            
            if ($conversationId) {
                LorConversation::create([
                    'conversation_id' => $conversationId,
                    'user' => $this->conversationUser,
                    'status' => LorConversation::STATUS_ACTIVE
                ]);
                
                return $conversationId;
            }
        } catch (\Exception $e) {
            Log::error("LOR: Failed to create conversation - " . $e->getMessage());
        }
        
        return null;
    }

    /**
     * Получить финальный input текст который отправляется в OpenAI
     * 
     * Формирует массив сообщений для отправки в API.
     * В режиме диалога не добавляет системные инструкции
     * (они уже есть в диалоге).
     * 
     * @return array Массив сообщений для API
     */
    public function getInputText()
    {
        $inputMessages = [];

        // Use predefined messages if available, otherwise build from message
        if ($this->messages) {
            $inputMessages = $this->messages;
        } else {
            $inputMessages = [];
            
            // For conversation mode, don't add system message (already in conversation)
            if (!$this->conversationId && $this->instructions) {
                $inputMessages[] = [
                    'role' => 'system',
                    'content' => $this->instructions
                ];
            }
            
            // Add user message if set
            if ($this->message) {
                $inputMessages[] = [
                    'role' => 'user',
                    'content' => $this->message
                ];
            }
        }

        return $inputMessages;
    }

}