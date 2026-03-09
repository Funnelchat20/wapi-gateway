<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection()
    {
        return config('wapi-gateway.database_connection', 'mysql');
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->create('device_logs', function (Blueprint $table) {
            $table->id();
            $table->string('instance_uid')->index();
            $table->string('provider', 20);
            $table->string('method', 100);
            $table->text('url');
            $table->json('request_payload')->nullable();
            $table->json('response_body')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->boolean('is_error')->default(false)->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['instance_uid', 'method']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('device_logs');
    }
};
