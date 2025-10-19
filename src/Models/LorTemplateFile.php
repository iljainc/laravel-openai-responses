<?php

namespace Idpromogroup\LaravelOpenaiResponses\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpenAITemplateFile extends Model
{
    use HasFactory;

    protected $table = 'openai_template_files';

    protected $fillable = [
        'template_id',
        'file_url',
        'file_name',
        'file_type',
        'file_size',
        'file_hash',
        'mime_type',
        'vector_store_id',
        'vector_store_file_id',
        'upload_status',
        'error_message'
    ];

    protected $casts = [
        'file_size' => 'integer'
    ];

    // Константы для статусов загрузки
    const STATUS_PENDING = 'pending';
    const STATUS_UPLOADING = 'uploading';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';

    // Типы файлов для векторных хранилищ
    const TYPE_PDF = 'pdf';
    const TYPE_TXT = 'txt';
    const TYPE_DOCX = 'docx';
    const TYPE_MD = 'md';
    const TYPE_JSON = 'json';

    /**
     * Get the template that owns this file.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(OpenAITemplate::class, 'template_id');
    }

    /**
     * Check if file is successfully uploaded to vector store
     */
    public function isUploaded(): bool
    {
        return $this->upload_status === self::STATUS_COMPLETED && !empty($this->vector_store_file_id);
    }

    /**
     * Get file extension from URL or name
     */
    public function getFileExtension(): ?string
    {
        if ($this->file_name) {
            return pathinfo($this->file_name, PATHINFO_EXTENSION);
        }
        
        if ($this->file_url) {
            return pathinfo(parse_url($this->file_url, PHP_URL_PATH), PATHINFO_EXTENSION);
        }
        
        return null;
    }
}
