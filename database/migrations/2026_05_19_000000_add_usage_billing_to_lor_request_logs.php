<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lor_request_logs', function (Blueprint $table) {
            $table->string('model')->nullable()->after('external_key');
            $table->unsignedInteger('input_tokens')->nullable()->after('execution_time');
            $table->unsignedInteger('cached_input_tokens')->nullable()->after('input_tokens');
            $table->unsignedInteger('output_tokens')->nullable()->after('cached_input_tokens');
            $table->unsignedInteger('reasoning_tokens')->nullable()->after('output_tokens');
            $table->decimal('total_cost', 14, 8)->nullable()->after('reasoning_tokens');

            $table->index(['model', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('lor_request_logs', function (Blueprint $table) {
            $table->dropIndex(['model', 'created_at']);
            $table->dropColumn([
                'model',
                'input_tokens',
                'cached_input_tokens',
                'output_tokens',
                'reasoning_tokens',
                'total_cost',
            ]);
        });
    }
};
