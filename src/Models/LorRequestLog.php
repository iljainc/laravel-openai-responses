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
        'input_cost',
        'cached_input_cost',
        'output_cost',
        'total_cost',
        'billing_source_code',
        'billing_user',
        'api_key_hash',
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
        'input_cost' => 'decimal:8',
        'cached_input_cost' => 'decimal:8',
        'output_cost' => 'decimal:8',
        'total_cost' => 'decimal:8',
        'billing_source_code' => 'integer',
        'billing_user' => 'integer',
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

    public function scopeForBillingUser(Builder $query, int $billingUser): Builder
    {
        return $query->where('billing_user', $billingUser);
    }

    public function scopeForBillingSourceCode(Builder $query, int $code): Builder
    {
        return $query->where('billing_source_code', $code);
    }
}