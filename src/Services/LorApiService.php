<?php

namespace Idpromogroup\LaravelOpenaiResponses\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class LorApiService
{
    const DEFAULT_TIMEOUT = 60;

    private Client $client;
    private string $apiKey;
    private string $baseUrl = 'https://api.openai.com/v1/';
    private int $timeout;

    public function __construct(?string $apiKey = null, ?int $timeout = null)
    {
        $this->apiKey = $apiKey ?? config('openai-responses.api_key');
        $this->timeout = $timeout ?? config('openai-responses.timeout', self::DEFAULT_TIMEOUT);
        $this->initClient();
    }
    
    /**
     * Установить таймаут для запросов
     */
    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
        $this->initClient();
    }
    
    /**
     * Инициализировать HTTP клиент
     */
    private function initClient(): void
    {
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => $this->timeout,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * Send chat responses request to OpenAI
     */
    public function chatResponses(array $data): ?array
    {
        lor_debug("LorApiService::chatResponses() - Sending request");
        lor_debug("LorApiService::chatResponses() - Full URL: " . $this->baseUrl . 'responses');
        lor_debug("LorApiService::chatResponses() - Request data: " . print_r($data, true));
        $response = $this->client->post('responses', [
            'json' => $data
        ]);
        lor_debug("POST request completed, status: " . $response->getStatusCode());

        $body = $response->getBody()->getContents();
        
        $result = json_decode($body, true);

        lor_debug("LorApiService::chatResponses() - Full AI response: " . print_r($result, true));
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("LOR: JSON decode error - " . json_last_error_msg() . " | Raw body: " . $body);
            return null;
        }

        return $result;
    }

    /**
     * Create new conversation
     */
    public function createConversation(?string $instructions = null): ?string
    {
        try {
            lor_debug("LorApiService::createConversation() - Creating new conversation");
            
            $items = [];
            
            // Add system instructions if provided
            if ($instructions) {
                $items[] = ['type' => 'message', 'role' => 'system', 'content' => $instructions];
            }
            
            // Add initial user message
            $items[] = ['type' => 'message', 'role' => 'user', 'content' => 'Start conversation'];
            
            $response = $this->client->post('conversations', [
                'json' => [
                    'items' => $items
                ]
            ]);

            $body = $response->getBody()->getContents();
            $result = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("LOR: JSON decode error - " . json_last_error_msg());
                return null;
            }

            if (isset($result['error'])) {
                Log::error("LOR: OpenAI Conversation API error - " . $result['error']['message']);
                return null;
            }

            $conversationId = $result['id'] ?? null;
            lor_debug("LorApiService::createConversation() - Success: {$conversationId}");
            return $conversationId;

        } catch (GuzzleException $e) {
            Log::error("LOR: OpenAI Conversation API request failed - " . $e->getMessage());
            return null;
        }
    }

    /**
     * Upload file to OpenAI
     * 
     * @param string $path Путь к файлу
     * @param string $purpose Цель загрузки ('user_data' для Responses API)
     * @return array|null Ответ API с информацией о загруженном файле
     */
    public function uploadFile(string $path, string $purpose = 'user_data'): ?array
    {
        try {
            // Проверяем существование файла
            if (!file_exists($path)) {
                Log::error("LOR: uploadFile failed - File not found: {$path}");
                return null;
            }
            
            // Проверяем размер файла
            if (filesize($path) === 0) {
                Log::error("LOR: uploadFile failed - Empty file: {$path}");
                return null;
            }
            
            lor_debug("LorApiService::uploadFile() - Uploading file: {$path}, size: " . filesize($path) . " bytes");
            
            $client = new \GuzzleHttp\Client([
                'base_uri' => $this->baseUrl,
                'timeout'  => 300,
                'headers'  => ['Authorization' => 'Bearer ' . $this->apiKey],
            ]);

            $resp = $client->post('files', [
                'multipart' => [
                    ['name' => 'purpose', 'contents' => $purpose],
                    ['name' => 'file', 'contents' => fopen($path, 'r'), 'filename' => basename($path)],
                ],
            ]);

            $json = json_decode($resp->getBody()->getContents(), true);
            
            // Логируем успешную загрузку
            if (isset($json['id'])) {
                lor_debug("LorApiService::uploadFile() - File uploaded successfully, ID: {$json['id']}");
            }
            
            return $json ?? null;
        } catch (\Throwable $e) {
            Log::error("LOR: uploadFile failed - ".$e->getMessage());
            return null;
        }
    }

    /**
     * Send raw request to OpenAI API
     */
    public function sendRequest(string $method, string $endpoint, array $options = []): ?array
    {
        try {
            $response = $this->client->request($method, $endpoint, $options);
            $body = $response->getBody()->getContents();
            $result = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("LOR: JSON decode error - " . json_last_error_msg());
                return null;
            }

            return $result;

        } catch (GuzzleException $e) {
            Log::error("LOR: OpenAI API request failed - " . $e->getMessage());
            return null;
        }
    }

}