<?php

use Illuminate\Support\Facades\Route;

/*
 * Read-only telemetry surface for the edge-bot Mission Control panel
 * (bootstrap §6). Published on host loopback 127.0.0.1:8790 by compose;
 * token-gated; renders state, never mutates it.
 */
Route::get('/telemetry/health', function () {
    $cfg = (array) config('harness');
    $token = (string) ($cfg['telemetry_token'] ?? '');

    abort_if($token === '', 503, 'telemetry token unconfigured');
    abort_unless(hash_equals($token, (string) request()->bearerToken()), 403);

    return response()->json([
        'ok' => true,
        'service' => 'theta',
        'mode' => $cfg['mode'] ?? null,
        // Unknown reads as engaged — fail closed, mirroring KillSwitchGate.
        'kill_switch' => (bool) ($cfg['kill_switch'] ?? true),
        'agentic_account' => $cfg['agentic_account'] ?? null,
        'ts' => now()->toIso8601ZuluString(),
    ]);
});
