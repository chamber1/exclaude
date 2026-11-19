<?php

namespace App\Http\Controllers;

use App\Downloads\DownloadMonitor;
use Illuminate\Http\JsonResponse;

/** GET /up: 200 when the database, cache and storage work, 503 otherwise. No details beyond booleans. */
class HealthController extends Controller
{
    public function __invoke(DownloadMonitor $monitor): JsonResponse
    {
        $checks = $monitor->appChecks();
        $healthy = ! in_array(false, $checks, true);

        return response()->json(['status' => $healthy ? 'ok' : 'fail', 'checks' => $checks], $healthy ? 200 : 503);
    }
}
