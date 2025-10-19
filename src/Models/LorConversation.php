<?php

namespace Idpromogroup\LaravelOpenaiResponses\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpenaiConversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'user',
        'status'
    ];

    protected $casts = [
        'status' => 'string'
    ];

    const STATUS_ACTIVE = 'active';
    const STATUS_CLOSED = 'closed';

    public function requestLogs(): HasMany
    {
        return $this->hasMany(OpenAiRequestLog::class, 'conversation_id', 'conversation_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function close(): void
    {
        $this->update(['status' => self::STATUS_CLOSED]);
    }
}
