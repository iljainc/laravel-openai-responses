<?php

namespace Idpromogroup\LaravelOpenaiResponses\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpenAITemplateLog extends Model
{
    use HasFactory;

    protected $table = 'openai_template_logs';

    protected $fillable = [
        'project_id',
        'user_id',
        'name',
        'instructions',
        'model',
        'tools',
        'temperature',
        'response_format',
        'json_schema',
        'openai_api_key',
    ];

    protected $casts = [
        'tools' => 'array',
        'json_schema' => 'array',
        'temperature' => 'float',
        'project_id' => 'integer',
        'user_id' => 'integer'
    ];

    /**
     * Get the project that owns this log.
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(OpenAITemplate::class, 'project_id');
    }
}
