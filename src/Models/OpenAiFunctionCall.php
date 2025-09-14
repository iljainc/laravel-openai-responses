<?php

namespace Idpromogroup\LaravelOpenaiResponses\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpenAiFunctionCall extends Model
{
    use HasFactory;

    protected $table = 'openai_function_calls';

    protected $fillable = [
        'request_log_id',
        'external_key',
        'function_name',
        'arguments',
        'output',
        'status',
        'error_message',
        'execution_time'
    ];

    protected $casts = [
        'arguments' => 'array',
        'output' => 'array',
        'execution_time' => 'decimal:2'
    ];

    // Константы для статусов
    const STATUS_PENDING = 'pending';
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED = 'failed';

    /**
     * Связь с логом запроса
     */
    public function requestLog(): BelongsTo
    {
        return $this->belongsTo(OpenAiRequestLog::class, 'request_log_id');
    }
}