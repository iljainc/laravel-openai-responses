<?php

namespace Idpromogroup\LaravelOpenaiResponses\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LorRequestLog extends Model
{
    use HasFactory;

    protected $table = 'openai_request_logs';

    protected $fillable = [
        'external_key',
        'request_text',
        'response_text',
        'pid',
        'process_start_time',
        'conversation_id',
        'status',
        'comments',
        'execution_time'
    ];

    protected $casts = [
        'process_start_time' => 'integer',
        'pid' => 'integer',
        'conversation_id' => 'string',
        'execution_time' => 'decimal:2'
    ];

    // Константы для статусов
    const STATUS_PENDING = 'pending';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';


    /**
     * Связь с диалогом
     */
    public function conversation()
    {
        return $this->belongsTo(LorConversation::class, 'conversation_id', 'conversation_id');
    }

}