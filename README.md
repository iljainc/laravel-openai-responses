# Laravel OpenAI Responses

A Laravel package for standardizing OpenAI API responses in your applications.

## Installation

```bash
composer require idpromogroup/laravel-openai-responses
```

## Usage

### Basic Response Formatting

```php
use Idpromogroup\LaravelOpenaiResponses\OpenaiResponseManager;

$manager = new OpenaiResponseManager();

// Format successful response
$response = $manager->formatResponse('AI generated content', [
    'model' => 'gpt-4',
    'tokens_used' => 150
]);

// Format error response
$error = $manager->formatError('API rate limit exceeded', 429);
```

### Parse Assistant Responses

```php
$rawResponse = '{"result": "success", "data": "content"}';
$parsed = $manager->parseAssistantResponse($rawResponse);
```

### Validate Response Structure

```php
if ($manager->validateResponse($response)) {
    // Response is valid
}
```

## License

MIT License
