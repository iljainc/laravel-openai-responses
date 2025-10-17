<?php

namespace Idpromogroup\LaravelOpenaiResponses\Services;

use Idpromogroup\LaravelOpenaiResponses\Models\OpenAiRequestLog;
use Idpromogroup\LaravelOpenaiResponses\Models\OpenAiFunctionCall;
use Idpromogroup\LaravelOpenaiResponses\Models\Conversation;
use Idpromogroup\LaravelOpenaiResponses\Models\OpenAITemplate;
use Idpromogroup\LaravelOpenaiResponses\Result;
use Illuminate\Support\Facades\Log;

class OpenAIService
{
    private string $externalKey;
    private string $model = 'gpt-4o-mini';
    private string $message;
    private ?ProcessService $processService = null;
    private ?array $messages = null;
    
    // Optional parameters
    private ?string $instructions = null;
    private array $tools = [];
    private ?array $jsonSchema = null;
    private ?float $temperature = null;
    private ?string $conversationUser = null;
    private ?string $conversationId = null;
    private array $attachments = [];

    public function __construct(string $externalKey, string $message)
    {
        $this->externalKey = $externalKey;
        $this->message = $message;
    }

    /* --------------------------------------------------------------------
     |  BUILDER METHODS
     |------------------------------------------------------------------- */

    public function setModel(string $model): self
    {
        $this->model = $model;
        return $this;
    }

    public function setInstructions(string $instructions): self
    {
        $this->instructions = $instructions;
        return $this;
    }

    public function setTools(array $tools): self
    {
        $this->tools = $tools;
        return $this;
    }

    public function setJSONSchema(array $schema): self
    {
        $this->jsonSchema = $schema;
        return $this;
    }

    public function setTemperature(float $temperature): self
    {
        $this->temperature = $temperature;
        return $this;
    }

    public function setConversation(string $user): self
    {
        $this->conversationUser = $user;
        return $this;
    }

    public function attachFile(string $fileId): self
    {
        $this->attachments[] = ['file_id' => $fileId];
        return $this;
    }

    public function attachFileIds(array $fileIds): self
    {
        foreach ($fileIds as $fid) {
            $this->attachments[] = ['file_id' => $fid];
        }
        return $this;
    }

    /** Удобный хелпер: загрузить локальный файл и тут же прикрепить */
    public function attachLocalFile(string $absolutePath): self
    {
        $api = app(OpenAIAPIService::class);
        $resp = $api->uploadFile($absolutePath, 'assistants');
        if (!empty($resp['id'])) {
            $this->attachments[] = ['file_id' => $resp['id']];
        }
        return $this;
    }

    public function useTemplate($template): self
    {
        // Поиск шаблона по ID или имени
        if (is_numeric($template)) {
            $templateModel = OpenAITemplate::find($template);
        } else {
            $templateModel = OpenAITemplate::where('name', $template)->first();
        }

        if (!$templateModel) {
            throw new \InvalidArgumentException("Template not found: {$template}");
        }

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
        
        if ($templateModel->json_schema) {
            $this->setJSONSchema($templateModel->json_schema);
        }

        return $this;
    }


    /* --------------------------------------------------------------------
     |  EXECUTION METHODS
     |------------------------------------------------------------------- */

    public function execute(): Result
    {
        lor_debug("OpenAIService::execute() - INIT model = {$this->model}, externalKey = {$this->externalKey}");
        lor_debug("OpenAIService::execute() - Message: " . substr($this->message, 0, 100) . (strlen($this->message) > 100 ? '...' : ''));
        lor_debug("OpenAIService::execute() - Conversation user: " . ($this->conversationUser ?? 'none'));

        $startTime = microtime(true);
        
        $this->processService = app(ProcessService::class);
        if (!$this->processService->init($this->externalKey, $this->getInputText())) {
            return Result::status('Already in work');
        }
        
        // Handle conversation mode after init
        if ($this->conversationUser) {
            lor_debug("OpenAIService::execute() - Creating/getting conversation for user: {$this->conversationUser}");
            $this->conversationId = $this->getOrCreateConversation();
            if (!$this->conversationId) {
                lor_debug("OpenAIService::execute() - FAILED to create conversation");
                $this->processService->responseLog->update([
                    'status' => ProcessService::STATUS_FAILED,
                ]);
                $this->processService->comment('FAILED: Cannot create conversation');
                return Result::failure('Failed to create conversation');
            }
            
            lor_debug("OpenAIService::execute() - Using conversation ID: {$this->conversationId}");
            // Add conversation_id to existing log
            $this->processService->responseLog->update(['conversation_id' => $this->conversationId]);
        }

        try {
            $apiService = app(OpenAIAPIService::class);
            $response = $apiService->chatResponses($this->buildRequestData());
            
            $this->processService->close(json_encode($response, JSON_UNESCAPED_UNICODE), round(microtime(true) - $startTime, 2));
            
            // Обработка ошибок после close
            if (isset($response['error'])) {
                return $this->handleAPIError($response['error']);
            }
                        
            // Handle function calls if present in Responses API
            if (isset($response['output']) && is_array($response['output'])) {
                $functionCalls = array_filter($response['output'], function($item) {
                    return isset($item['type']) && in_array($item['type'], ['tool_call','function_call'], true);
                });
                
                if (!empty($functionCalls)) {
                    lor_debug("OpenAIService::execute() - Found " . count($functionCalls) . " function calls to handle");
                    $response = $this->handleFunctionCalls($functionCalls);
                }
            }
            
            lor_debug("OpenAIService::execute() - Request completed");
            return Result::success($response);
            
        } catch (\Exception $e) {
            // Проверяем если это ClientException с 400 статусом - это ответ API, а не ошибка
            if ($e instanceof \GuzzleHttp\Exception\ClientException && $e->getCode() === 400) {
                lor_debug("OpenAIService::execute() - Got 400 response, treating as API response");
                
                if ($e->hasResponse()) {
                    $errorBody = $e->getResponse()->getBody()->getContents();
                    $errorJson = json_decode($errorBody, true);
                    
                    if ($errorJson) {
                        lor_debug("OpenAIService::execute() - Parsed 400 response: " . print_r($errorJson, true));
                        
                        // Логируем ответ с ошибкой
                        $this->processService->close(json_encode($errorJson, JSON_UNESCAPED_UNICODE), round(microtime(true) - $startTime, 2), ProcessService::STATUS_FAILED);
                        
                        // Проверяем на ошибку "No tool output found" и обрабатываем
                        if (isset($errorJson['error'])) {
                            return $this->handleAPIError($errorJson['error']);
                        }
                        
                        // Возвращаем как обычный ответ
                        return Result::success($errorJson);
                    }
                }
            }
            
            $errorMessage = $e->getMessage();
            
            // Если это ClientException, извлекаем полное тело ответа
            if ($e instanceof \GuzzleHttp\Exception\ClientException && $e->hasResponse()) {
                $responseBody = $e->getResponse()->getBody()->getContents();
                $errorMessage .= " | Full response: " . $responseBody;
            }
            
            Log::error("LOR: OpenAIService::execute() failed - " . $errorMessage);
            lor_debug("LOR: OpenAIService::execute() failed - " . $errorMessage);
            return Result::failure('API Error: ' . $errorMessage);
        }
    }


    /* --------------------------------------------------------------------
     |  PRIVATE METHODS
     |------------------------------------------------------------------- */

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
        if ($this->jsonSchema) {
            $data['text'] = ['format' => $this->jsonSchema];
        } else {
            $data['text'] = [
                'format' => [
                    'type' => 'text'
                ]
            ];
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

        // Add attachments if present
        if (!empty($this->attachments)) {
            // Convert attachments to proper format for Responses API
            // Files must be in input[].content[] as {type: "input_file", file_id: "..."}
            if (!empty($data['input'])) {
                $lastIndex = count($data['input']) - 1;
                $lastMessage = &$data['input'][$lastIndex];
                
                // Only modify user messages
                if (($lastMessage['role'] ?? '') === 'user') {
                    $content = [];
                    
                    // Add text content
                    if (isset($lastMessage['content'])) {
                        $content[] = [
                            'type' => 'input_text',
                            'text' => $lastMessage['content']
                        ];
                    }
                    
                    // Add file attachments
                    foreach ($this->attachments as $attachment) {
                        $content[] = [
                            'type' => 'input_file',
                            'file_id' => $attachment['file_id']
                        ];
                    }
                    
                    $lastMessage['content'] = $content;
                }
            }
        }

        

        return $data;
    }

    /* --------------------------------------------------------------------
     |  FUNCTION HANDLING
     |------------------------------------------------------------------- */

     private function handleFunctionCalls(array $toolCalls): array
     {
         lor_debug("OpenAIService::handleFunctionCalls() - Starting function calls handling");
         
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
                lor_debug("OpenAIService::handleFunctionCalls() - Skipping invalid function call");
                continue;
            }

            lor_debug("OpenAIService::handleFunctionCalls() - Processing function: {$functionName}");
            $startTime = microtime(true);
            $args = is_string($argumentsRaw)
                ? (json_decode($argumentsRaw, true) ?: [])
                : (is_array($argumentsRaw) ? $argumentsRaw : []);

            $functionLog = OpenAiFunctionCall::create([
                'request_log_id' => $this->processService->responseLog->id,
                'external_key'   => $this->externalKey,
                'function_name'  => $functionName,
                'arguments'      => $args,
                'status'         => OpenAiFunctionCall::STATUS_PENDING,
            ]);

            try {
                $handlerClass = config('openai-responses.function_handler');
                if ($handlerClass && class_exists($handlerClass)) {
                    $handler = app($handlerClass);
                    $result = $handler->execute($functionName, $args);
                    lor_debug("OpenAIService::handleFunctionCalls() - Function {$functionName} returned: " . json_encode($result));
                } else {
                    lor_debug("OpenAIService::handleFunctionCalls() - Function handler not configured: {$handlerClass}");
                    $result = ['error' => 'Function handler not configured or not found'];
                }

                $functionLog->update([
                    'output'         => $result,
                    'status'         => OpenAiFunctionCall::STATUS_SUCCESS,
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
                    'status'         => OpenAiFunctionCall::STATUS_FAILED,
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
        lor_debug("OpenAIService::handleFunctionCalls() - Making new request with function results");
        
        $result = $this->execute();
        return $result->success ? $result->data : [];
     }

     /**
      * Handle API errors and decide on recovery strategy
      */
     private function handleAPIError(array $error): Result
     {
         $message = $error['message'] ?? 'Unknown API error';
         lor_debug("OpenAIService::handleAPIError() - API Error: {$message}");
         
         // Проверяем на ошибку "No tool output found" - диалог завис
         if (strpos($message, 'No tool output found for function call') !== false) {
             lor_debug("OpenAIService::handleAPIError() - Conversation stuck, closing and retrying");
             
             // Закрываем текущий диалог
             if ($this->conversationUser && $this->conversationId) {
                 $conversation = Conversation::where('conversation_id', $this->conversationId)->first();
                 if ($conversation) {
                     $conversation->update(['status' => Conversation::STATUS_CLOSED]);
                     lor_debug("OpenAIService::handleAPIError() - Closed conversation: {$this->conversationId}");
                 }
             }
             
             // Сбрасываем conversation ID и повторяем запрос
             $this->conversationId = null;
             lor_debug("OpenAIService::handleAPIError() - Retrying with new conversation");
             return $this->execute();
         }
         
         // Для других ошибок просто возвращаем failure
         return Result::failure("API Error: {$message}");
     }
     
    

    private function getOrCreateConversation(): ?string
    {
        // Find active conversation for user
        $conversation = Conversation::where('user', $this->conversationUser)
            ->where('status', Conversation::STATUS_ACTIVE)
            ->first();
            
        if ($conversation) {
            return $conversation->conversation_id;
        }
        
        // Create new conversation via API
        try {
            $apiService = app(OpenAIAPIService::class);
            $conversationId = $apiService->createConversation($this->instructions);
            
            if ($conversationId) {
                Conversation::create([
                    'conversation_id' => $conversationId,
                    'user' => $this->conversationUser,
                    'status' => Conversation::STATUS_ACTIVE
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
            
            // Add user message
            $inputMessages[] = [
                'role' => 'user',
                'content' => $this->message
            ];
        }

        return $inputMessages;
    }

}