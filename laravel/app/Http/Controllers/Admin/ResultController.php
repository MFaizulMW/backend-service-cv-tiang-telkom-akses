<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnalysisResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResultController extends Controller
{
    /**
     * GET /admin/results/{photo_id}
     */
    public function show(string $photoId): JsonResponse
    {
        $result = AnalysisResult::where('photo_id', $photoId)->first();

        if (! $result) {
            return response()->json(['error' => 'Result not found for photo_id: ' . $photoId], 404);
        }

        return response()->json($result);
    }

    /**
     * GET /admin/results?date=YYYY-MM-DD
     */
    public function index(Request $request): JsonResponse
    {
        $date = $request->query('date');
        $query = AnalysisResult::orderByDesc('created_at');

        if ($date) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return response()->json(['error' => 'Invalid date format. Use YYYY-MM-DD.'], 422);
            }
            $query->whereDate('created_at', $date);
        }

        $results = $query->paginate(50);

        // Strip inference_raw from list — use GET /results/{photo_id} for full detail
        $results->getCollection()->transform(fn ($r) => $r->makeHidden('inference_raw'));

        return response()->json($results);
    }
}
