<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Таблица lor_templates
        Schema::create('lor_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('instructions')->nullable();
            $table->string('model')->default('gpt-4o');
            $table->json('tools')->nullable();
            $table->decimal('temperature', 3, 2)->default(1.0);
            $table->string('response_format')->default('text');
            $table->json('json_schema')->nullable();
            $table->string('openai_api_key')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });

        // Таблица lor_template_files
        Schema::create('lor_template_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('template_id');
            $table->string('file_url');
            $table->string('file_name')->nullable();
            $table->string('file_type');
            $table->bigInteger('file_size')->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->string('mime_type')->nullable();
            $table->string('vector_store_id')->nullable();
            $table->string('vector_store_file_id')->nullable();
            $table->enum('upload_status', ['pending', 'uploading', 'completed', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();
            
            $table->index('file_hash');
            $table->foreign('template_id')->references('id')->on('lor_templates')->onDelete('cascade');
        });

        // Таблица lor_template_logs
        Schema::create('lor_template_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name');
            $table->text('instructions')->nullable();
            $table->string('model')->default('gpt-4o');
            $table->json('tools')->nullable();
            $table->decimal('temperature', 3, 2)->default(1.0);
            $table->string('response_format')->default('text');
            $table->json('json_schema')->nullable();
            $table->string('openai_api_key')->nullable();
            $table->timestamps();
            
            $table->foreign('project_id')->references('id')->on('lor_templates')->onDelete('cascade');
        });

        // Таблица lor_conversations
        Schema::create('lor_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('conversation_id')->unique();
            $table->string('user');
            $table->enum('status', ['active', 'closed'])->default('active');
            $table->timestamps();
            
            $table->index(['user', 'status']);
        });

        // Таблица lor_request_logs
        Schema::create('lor_request_logs', function (Blueprint $table) {
            $table->id();
            $table->string('external_key');
            $table->text('request_text');
            $table->text('response_text')->nullable();
            $table->integer('pid')->nullable();
            $table->bigInteger('process_start_time')->nullable();
            $table->string('conversation_id')->nullable();
            $table->enum('status', ['pending', 'in_progress', 'completed', 'failed'])->default('pending');
            $table->text('comments')->nullable();
            $table->decimal('execution_time', 8, 2)->nullable()->comment('Execution time in seconds');
            $table->timestamps();
            
            $table->index('external_key');
            $table->index(['pid', 'process_start_time']);
            $table->index('conversation_id');
        });

        // Таблица lor_function_calls
        Schema::create('lor_function_calls', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_log_id')->comment('Связь с openai_request_logs');
            $table->string('external_key');
            $table->string('function_name');
            $table->json('arguments');
            $table->json('output')->nullable();
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->decimal('execution_time', 8, 2)->nullable()->comment('Execution time in seconds');
            $table->timestamps();
            
            $table->index('external_key');
            $table->index('function_name');
            $table->foreign('request_log_id')->references('id')->on('lor_request_logs')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lor_function_calls');
        Schema::dropIfExists('lor_template_logs');
        Schema::dropIfExists('lor_request_logs');
        Schema::dropIfExists('lor_conversations');
        Schema::dropIfExists('lor_template_files');
        Schema::dropIfExists('lor_templates');
    }
};