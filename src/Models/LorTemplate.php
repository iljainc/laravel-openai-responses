<?php

namespace Idpromogroup\LaravelOpenaiResponses\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpenAITemplate extends Model
{
    use HasFactory;

    protected $table = 'openai_templates';

    protected static function boot()
    {
        parent::boot();
        
        // Логируем при создании
        static::created(function ($template) {
            $template->logState();
        });
        
        // Логируем при изменении
        static::updated(function ($template) {
            $template->logState();
        });
    }

    protected $fillable = [
        'name',
        'instructions',
        'model',
        'tools',
        'temperature',
        'response_format',
        'json_schema',
        'openai_api_key',
        'user_id'
    ];

    protected $casts = [
        'tools' => 'array',
        'json_schema' => 'array',
        'temperature' => 'float',
        'user_id' => 'integer'
    ];

    /**
     * Get the project logs for this project.
     */
    public function projectLogs(): HasMany
    {
        return $this->hasMany(OpenAITemplateLog::class, 'project_id');
    }

    /**
     * Get API key - from project or config
     */
    public function getApiKey(): string
    {
        return $this->openai_api_key ?: config('openai-responses.api_key');
    }

    /**
     * Log the current state of the project
     */
    public function logState(): void
    {
        OpenAITemplateLog::create([
            'project_id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'instructions' => $this->instructions,
            'model' => $this->model,
            'tools' => $this->tools,
            'temperature' => $this->temperature,
            'response_format' => $this->response_format,
            'json_schema' => $this->json_schema,
            'openai_api_key' => $this->openai_api_key,
        ]);
    }
}
