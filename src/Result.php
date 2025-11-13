<?php

namespace Idpromogroup\LaravelOpenaiResponses;

class Result
{
    public function __construct(
        public bool $success,
        public mixed $data = null,
        public ?string $error = null,
        public ?string $status = null
    ) {}
    
    /**
     * Проверить успешность запроса (alias для success)
     */
    public function successful(): bool
    {
        return $this->success;
    }
    
    /**
     * Проверить неуспешность запроса
     */
    public function failed(): bool
    {
        return !$this->success;
    }
    
    public static function success(mixed $data, ?string $status = null): self
    {
        return new self(true, $data, null, $status);
    }
    
    public static function failure(string $error, ?string $status = null): self
    {
        return new self(false, null, $error, $status);
    }
    
    public static function status(string $status, mixed $data = null): self
    {
        return new self(false, $data, null, $status);
    }
    
    /**
     * Получить текст ответа ассистента
     */
    public function getMsg(): ?string
    {
        if (!$this->success || !$this->data) {
            return null;
        }
        
        // Find message in output array for Responses API
        if (isset($this->data['output']) && is_array($this->data['output'])) {
            foreach ($this->data['output'] as $item) {
                if ($item['type'] === 'message' && isset($item['content'][0]['text'])) {
                    return $item['content'][0]['text'];
                }
            }
        }
        
        return null;
    }
    
    /**
     * Получить JSON ответа ассистента
     */
    public function getAssistantJSON(): ?array
    {
        if (!$this->success || !$this->data) {
            return null;
        }
        
        // Find message with parsed content in output array for Responses API
        if (isset($this->data['output']) && is_array($this->data['output'])) {
            foreach ($this->data['output'] as $item) {
                if ($item['type'] === 'message') {
                    // Try parsed first (json_schema format)
                    if (isset($item['content'][0]['parsed'])) {
                        return $item['content'][0]['parsed'];
                    }
                    // Fallback to text and decode JSON
                    if (isset($item['content'][0]['text'])) {
                        $decoded = json_decode($item['content'][0]['text'], true);
                        return is_array($decoded) ? $decoded : null;
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Получить все сообщения из ответа
     */
    public function getMessages(): array
    {
        if (!$this->success || !$this->data) {
            return [];
        }
        
        return $this->data['output'] ?? [];
    }
    
    /**
     * Получить информацию об использовании токенов
     */
    public function getUsage(): ?array
    {
        if (!$this->success || !$this->data) {
            return null;
        }
        
        return $this->data['usage'] ?? null;
    }
    
    /**
     * Получить модель из ответа
     */
    public function getModel(): ?string
    {
        if (!$this->success || !$this->data) {
            return null;
        }
        
        return $this->data['model'] ?? null;
    }
    
    /**
     * Проверить есть ли function calls
     */
    public function hasFunctionCalls(): bool
    {
        if (!$this->success || !$this->data) {
            return false;
        }
        
        // Check for function calls in output array for Responses API
        if (isset($this->data['output']) && is_array($this->data['output'])) {
            foreach ($this->data['output'] as $item) {
                if ($item['type'] === 'function_call') {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Получить function calls
     */
    public function getFunctionCalls(): array
    {
        if (!$this->hasFunctionCalls()) {
            return [];
        }
        
        // Get function calls from output array for Responses API
        $functionCalls = [];
        if (isset($this->data['output']) && is_array($this->data['output'])) {
            foreach ($this->data['output'] as $item) {
                if ($item['type'] === 'function_call') {
                    $functionCalls[] = $item;
                }
            }
        }
        
        return $functionCalls;
    }
}
