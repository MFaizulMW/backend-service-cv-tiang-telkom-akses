<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_logs', function (Blueprint $table) {
            $table->id();
            $table->string('job_id')->nullable()->index();
            $table->string('photo_id')->nullable()->index();

            // Audit fields
            $table->string('event');                 // e.g. "job.started", "job.completed"
            $table->string('status');                // "info" | "success" | "error"
            $table->jsonb('context')->nullable();    // Structured additional data

            $table->timestamp('created_at')->useCurrent();

            $table->index(['event', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_logs');
    }
};
