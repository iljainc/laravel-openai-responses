<?php

namespace Idpromogroup\LaravelOpenaiResponses\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LorRequestLog extends Model
{
    use HasFactory;

    protected $table = 'lor_request_logs';

    protected $fillable = [
        'external_key',
        'model',
        'request_text',
        'response_text',
        'pid',
        'process_start_time',
        'conversation_id',
        'status',
        'comments',
        'execution_time',
        'input_tokens',
        'cached_input_tokens',
        'output_tokens',
        'reasoning_tokens',
        'total_cost',
    ];

    protected $casts = [
        'process_start_time' => 'integer',
        'pid' => 'integer',
        'conversation_id' => 'string',
        'execution_time' => 'decimal:2',
        'input_tokens' => 'integer',
        'cached_input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'reasoning_tokens' => 'integer',
        'total_cost' => 'decimal:8',
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

    public function scopeForExternalKey(Builder $query, string $externalKey): Builder
    {
        return $query->where('external_key', $externalKey);
    }

    public function scopeBetweenDates(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    public function scopeWithBilling(Builder $query): Builder
    {
        return $query->whereNotNull('total_cost');
    }
}