<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalysisResult extends Model
{
    protected $table = 'analysis_results';

    protected $fillable = [
        'photo_id',
        'job_id',
        'pole_type',
        'measurement_method',
        'total_visible_px',
        'underground_depth_px',
        'total_pole_px',
        'total_visible_cm',
        'underground_depth_cm',
        'total_pole_cm',
        'is_compliant',
        'inference_raw',
        'status',
    ];

    protected $casts = [
        'inference_raw'       => 'array',
        'is_compliant'        => 'boolean',
        'total_visible_px'    => 'float',
        'underground_depth_px'=> 'float',
        'total_pole_px'       => 'float',
        'total_visible_cm'    => 'float',
        'underground_depth_cm'=> 'float',
        'total_pole_cm'       => 'float',
    ];
}
