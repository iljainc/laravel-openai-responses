# Laravel OpenAI Responses

A Laravel package for standardizing OpenAI API responses in your applications.

## Installation

```bash
composer require idpromogroup/laravel-openai-responses
```

## Usage

### Basic Usage

```php
use Idpromogroup\LaravelOpenaiResponses\Services\OpenAIService;

$service = new OpenAIService($externalKey, 'Your message here');
$result = $service->setModel('gpt-4o-mini')
    ->setInstructions('You are a helpful assistant')
    ->execute();

if ($result->success) {
    echo $result->data;
}
```

### Working with Files

The package supports file attachments for analysis, processing, and discussion:

#### Upload and Attach Local Files

```php
$service = new OpenAIService($externalKey, 'Analyze this document')
    ->setModel('gpt-4o-mini')
    ->attachLocalFile(storage_path('app/documents/report.pdf'));

$result = $service->execute();
```

#### Attach Already Uploaded Files

```php
// Upload file first
$apiService = app(OpenAIAPIService::class);
$uploadResult = $apiService->uploadFile('/path/to/file.pdf', 'assistants');
$fileId = $uploadResult['id'];

// Then attach to conversation
$service = new OpenAIService($externalKey, 'Discuss this file')
    ->attachFile($fileId)
    ->execute();
```

#### Attach Multiple Files

```php
$service = new OpenAIService($externalKey, 'Compare these documents')
    ->attachFileIds(['file_123', 'file_456'])
    ->attachLocalFile(storage_path('app/contract.docx'))
    ->execute();
```

#### Custom Tools for File Processing

```php
$service = new OpenAIService($externalKey, 'Extract data from spreadsheet')
    ->attachFile($fileId, [['type' => 'code_interpreter']])
    ->execute();
```

**Note**: When files are attached, the `file_search` tool is automatically added to enable file reading capabilities.

### Using Templates

```php
$service = new OpenAIService($externalKey, 'Your message')
    ->useTemplate('analysis_template') // by name
    ->execute();

// Or by ID
$service->useTemplate(1);
```

### Conversation Mode

```php
$service = new OpenAIService($externalKey, 'Hello')
    ->setConversation('user123')
    ->execute();

// Subsequent messages will continue the conversation
$service = new OpenAIService($externalKey, 'Follow up question')
    ->setConversation('user123')
    ->execute();
```

### Function Calls

Configure function handler in `config/openai-responses.php`:

```php
'function_handler' => App\Services\MyFunctionHandler::class,
```

Your handler should implement the `execute` method:

```php
class MyFunctionHandler
{
    public function execute(string $functionName, array $args)
    {
        switch ($functionName) {
            case 'get_weather':
                return $this->getWeather($args['location']);
            default:
                return ['error' => 'Unknown function'];
        }
    }
}
```

### Advanced Configuration

```php
$service = new OpenAIService($externalKey, 'Your message')
    ->setModel('gpt-4o-mini')
    ->setInstructions('Custom instructions')
    ->setTemperature(0.7)
    ->setTools([
        ['type' => 'function', 'function' => [...]]
    ])
    ->setJSONSchema([
        'type' => 'object',
        'properties' => [...]
    ])
    ->execute();
```

## File Upload API

Direct file upload to OpenAI:

```php
use Idpromogroup\LaravelOpenaiResponses\Services\OpenAIAPIService;

$apiService = new OpenAIAPIService();
$result = $apiService->uploadFile('/path/to/file.pdf', 'assistants');

if ($result) {
    $fileId = $result['id'];
    // Use $fileId in subsequent requests
}
```

Supported file purposes:
- `assistants` (default) - for Assistant API
- `fine-tune` - for fine-tuning
- `batch` - for batch processing

## License

MIT License
