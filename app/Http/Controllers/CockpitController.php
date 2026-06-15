<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Cockpit\CockpitData;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * Read-only operator cockpit. Renders the decision/health/broker-truth surface
 * the operator watches all session. Strictly observability — it never touches
 * the order path and reads broker truth only from the cached snapshot, so it
 * cannot compete with the live execution path for the broker credit budget.
 *
 * Published on host loopback (127.0.0.1:8790) by compose; reaching it already
 * requires host access.
 */
final class CockpitController extends Controller
{
    public function index(): View
    {
        return view('cockpit.dashboard');
    }

    public function data(): JsonResponse
    {
        return response()->json(CockpitData::build()->payload());
    }
}
