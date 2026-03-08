<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_results', function (Blueprint $table) {
            $table->id();
            $table->string('photo_id')->unique()->index(); // External ID from Telkom/Supabase — not constrained to UUID
            $table->string('job_id')->index();

            // Pole classification
            $table->string('pole_type')->nullable();                 // "2-segmen" | "3-segmen"
            $table->string('measurement_method')->nullable();        // "segmentation" | "detection_bbox_fallback"

            // Pixel measurements (always present)
            $table->float('total_visible_px')->nullable();
            $table->float('underground_depth_px')->nullable();
            $table->float('total_pole_px')->nullable();

            // Centimeter measurements (null if no reference marker)
            $table->float('total_visible_cm')->nullable();
            $table->float('underground_depth_cm')->nullable();
            $table->float('total_pole_cm')->nullable();

            // Compliance
            $table->boolean('is_compliant')->nullable();

            // Full inference payload (detection + segmentation + measurement + compliance)
            $table->jsonb('inference_raw')->nullable();

            // Processing status
            $table->string('status')->default('pending'); // pending | completed | failed

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_results');
    }
};
