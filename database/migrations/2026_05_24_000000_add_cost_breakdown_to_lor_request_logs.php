<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lor_request_logs', function (Blueprint $table) {
            $table->decimal('input_cost', 14, 8)->nullable()->after('reasoning_tokens');
            $table->decimal('cached_input_cost', 14, 8)->nullable()->after('input_cost');
            $table->decimal('output_cost', 14, 8)->nullable()->after('cached_input_cost');
        });
    }

    public function down(): void
    {
        Schema::table('lor_request_logs', function (Blueprint $table) {
            $table->dropColumn(['input_cost', 'cached_input_cost', 'output_cost']);
        });
    }
};
