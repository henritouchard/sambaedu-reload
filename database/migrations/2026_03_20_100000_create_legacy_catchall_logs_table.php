<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_catchall_logs', function (Blueprint $table) {
            $table->id();
            $table->string('method', 10);
            $table->string('path', 2048);
            $table->string('ip', 45);
            $table->text('query_string')->nullable();
            $table->text('referer')->nullable();
            $table->timestamp('created_at');

            $table->index('path');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_catchall_logs');
    }
};
