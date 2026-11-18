<?php

namespace App\Http\Controllers;

use App\Models\WebcamSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WebcamController extends Controller
{
    public function __construct()
    {
         $this->middleware('auth');
    }

    /**
     * Page with the live webcam stream and the user's saved snapshots.
     */
    public function index(Request $request)
    {
        $snapshots = $request->user()->webcamSnapshots()->latest()->get();

        return view('webcam', compact('snapshots'));
    }

    /**
     * Save a frame captured from the webcam.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,png', 'max:5120'],
        ]);

        // Private "local" disk: snapshots are served only through show().
        $path = $request->file('image')->store('webcam/'.$request->user()->id, 'local');

        $snapshot = $request->user()->webcamSnapshots()->create(['path' => $path]);

        return response()->json([
            'id' => $snapshot->id,
            'url' => route('webcam.show', $snapshot),
            'created_at' => $snapshot->created_at->format('d.m.Y H:i:s'),
        ], 201);
    }

    /**
     * Return a snapshot file to its owner.
     */
    public function show(Request $request, WebcamSnapshot $snapshot): StreamedResponse
    {
        abort_unless($snapshot->user_id === $request->user()->id, 404);
        abort_unless(Storage::disk('local')->exists($snapshot->path), 404);

        return Storage::disk('local')->response($snapshot->path);
    }

    /**
     * Delete a snapshot and its file.
     */
    public function destroy(Request $request, WebcamSnapshot $snapshot): JsonResponse
    {
        abort_unless($snapshot->user_id === $request->user()->id, 404);

        Storage::disk('local')->delete($snapshot->path);
        $snapshot->delete();

        return response()->json(null, 204);
    }
}
