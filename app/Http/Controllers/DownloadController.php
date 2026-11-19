<?php

namespace App\Http\Controllers;

use App\Downloads\DownloadOwner;
use App\Downloads\DownloadService;
use App\Http\Requests\InspectDownloadRequest;
use App\Http\Requests\StoreDownloadRequest;
use App\Http\Resources\DownloadTaskResource;
use App\Models\DownloadTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Download API used by the /parser page. Available to guests and users; every task is visible
 * only to its owner (404 for everybody else).
 */
class DownloadController extends Controller
{
    public function __construct(private readonly DownloadService $downloads) {}

    /** Link check: video metadata and the qualities available to this visitor. */
    public function inspect(InspectDownloadRequest $request): JsonResponse
    {
        [$media, $info] = $this->downloads->inspect($request->validated('url'));
        $owner = DownloadOwner::fromRequest($request);
        $maxHeight = $owner->limits()['max_height'];

        $qualities = [];
        foreach (config('downloads.qualities') as $quality) {
            $height = $quality === 'best' ? null : (int) $quality;
            $qualities[] = [
                'value' => $quality,
                // No exact match is fine: the nearest lower quality is taken. Only higher than everything is pointless.
                'available' => $height === null || $info->heights === [] || $height <= max($info->heights),
                'allowed' => $height === null || $height <= $maxHeight,
            ];
        }

        return response()->json([
            'platform' => $media->platform->name(),
            'id' => $media->id,
            'title' => $info->title,
            'duration' => $info->duration,
            'uploader' => $info->uploader,
            'thumbnail' => $info->thumbnail,
            'license' => $info->license,
            'creative_commons' => $info->isCreativeCommons(),
            'max_available_height' => $info->heights[0] ?? null,
            'qualities' => $qualities,
            'limits' => [
                'plan' => $owner->plan(),
                'guest' => $owner->isGuest(),
                'max_height' => $maxHeight,
            ],
        ]);
    }

    /** The visitor's recent tasks (so a reloaded page can resume). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $tasks = DownloadOwner::fromRequest($request)
            ->scopeTasks(DownloadTask::query())
            ->where('created_at', '>=', now()->subDay())
            ->latest('id')
            ->limit(10)
            ->get();

        return DownloadTaskResource::collection($tasks);
    }

    public function store(StoreDownloadRequest $request): JsonResponse
    {
        $task = $this->downloads->create(DownloadOwner::fromRequest($request), $request->validated());

        return (new DownloadTaskResource($task->refresh()))->response()->setStatusCode(202);
    }

    public function show(Request $request, DownloadTask $task): DownloadTaskResource
    {
        $this->authorizeOwner($request, $task);

        return new DownloadTaskResource($task);
    }

    /** Signed, time-limited link (see DownloadService::downloadUrl()), and only for the owner. */
    public function file(Request $request, DownloadTask $task): Response
    {
        $this->authorizeOwner($request, $task);

        return $this->downloads->deliver($task);
    }

    private function authorizeOwner(Request $request, DownloadTask $task): void
    {
        abort_unless(DownloadOwner::fromRequest($request)->owns($task), 404);
    }
}
