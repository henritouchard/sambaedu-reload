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
        Schema::create('installation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained('applications')->onDelete('cascade');
            $table->string('status')->default('pending');
            $table->string('version')->nullable();
            $table->text('message')->nullable();
            $table->integer('progress')->default(0);
            $table->bigInteger('downloaded_bytes')->default(0);
            $table->bigInteger('total_bytes')->default(0);
            $table->string('sha256_computed')->nullable();
            $table->boolean('sha256_verified')->default(false);
            $table->string('initiated_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('application_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('installation_logs');
    }
};
