<?php

namespace Idpromogroup\LaravelOpenaiResponses\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

class OpenAIAPIService
{
    private Client $client;
    private string $apiKey;
    private string $baseUrl = 'https://api.openai.com/v1/';

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? config('openai-responses.api_key');
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => config('openai-responses.timeout', 60),
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
        lor_debug("OpenAIAPIService::chatResponses() - Sending request");
        lor_debug("OpenAIAPIService::chatResponses() - Full URL: " . $this->baseUrl . 'responses');
        lor_debug("OpenAIAPIService::chatResponses() - Request data: " . print_r($data, true));
        $response = $this->client->post('responses', [
            'json' => $data
        ]);
        lor_debug("POST request completed, status: " . $response->getStatusCode());

        $body = $response->getBody()->getContents();
        
        $result = json_decode($body, true);

        lor_debug("OpenAIAPIService::chatResponses() - Full AI response: " . print_r($result, true));
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("LOR: JSON decode error - " . json_last_error_msg() . " | Raw body: " . $body);
            return null;
        }

        return $result;
    }

    /**
     * Submit tool outputs to OpenAI Responses API
     */
    public function submitToolOutputs(string $responseId, array $toolOutputs): ?array
    {
        lor_debug("OpenAIAPIService::submitToolOutputs() - Submitting tool outputs for response: {$responseId}");
        lor_debug("OpenAIAPIService::submitToolOutputs() - Full URL: " . $this->baseUrl . "responses/{$responseId}/submit_tool_outputs");
        lor_debug("OpenAIAPIService::submitToolOutputs() - Tool outputs: " . print_r($toolOutputs, true));
        
        $response = $this->client->post("responses/{$responseId}/submit_tool_outputs", [
            'json' => [
                'tool_outputs' => $toolOutputs
            ]
        ]);
        
        lor_debug("Submit tool outputs completed, status: " . $response->getStatusCode());

        $body = $response->getBody()->getContents();
        $result = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::error("LOR: JSON decode error in submitToolOutputs - " . json_last_error_msg() . " | Raw body: " . $body);
            return null;
        }

        lor_debug("OpenAIAPIService::submitToolOutputs() - Full AI response: " . print_r($result, true));
        return $result;
    }

    /**
     * Create new conversation
     */
    public function createConversation(?string $instructions = null): ?string
    {
        try {
            lor_debug("OpenAIAPIService::createConversation() - Creating new conversation");
            
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
            lor_debug("OpenAIAPIService::createConversation() - Success: {$conversationId}");
            return $conversationId;

        } catch (GuzzleException $e) {
            Log::error("LOR: OpenAI Conversation API request failed - " . $e->getMessage());
            return null;
        }
    }

    /**
     * Upload file to OpenAI
     */
    public function uploadFile(string $path, string $purpose = 'assistants'): ?array
    {
        try {
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