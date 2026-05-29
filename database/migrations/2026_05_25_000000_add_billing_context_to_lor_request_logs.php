<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lor_request_logs', function (Blueprint $table) {
            $table->unsignedTinyInteger('billing_source_code')->nullable()->after('total_cost');
            $table->unsignedBigInteger('billing_user')->nullable()->after('billing_source_code');
            $table->char('api_key_hash', 64)->nullable()->after('billing_user');

            $table->index(['billing_source_code', 'created_at']);
            $table->index(['billing_user', 'created_at']);
            $table->index('api_key_hash');
        });
    }

    public function down(): void
    {
        Schema::table('lor_request_logs', function (Blueprint $table) {
            $table->dropIndex(['billing_source_code', 'created_at']);
            $table->dropIndex(['billing_user', 'created_at']);
            $table->dropIndex(['api_key_hash']);
            $table->dropColumn(['billing_source_code', 'billing_user', 'api_key_hash']);
        });
    }
};
