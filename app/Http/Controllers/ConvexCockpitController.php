<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Cockpit\ConvexTelemetryData;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

final class ConvexCockpitController extends Controller
{
    public function index(): View
    {
        return view('cockpit.convex');
    }

    public function data(): JsonResponse
    {
        return response()->json((new ConvexTelemetryData((array) config('harness')))->payload())
            ->header('Cache-Control', 'private, no-store');
    }
}
