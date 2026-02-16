<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Studio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StudioController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $studios = Studio::latest()->get();

        return response()->json([
            'message' => 'List studio',
            'data' => $studios,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string'],
            'city' => ['required', 'string', 'max:255'],
            'open_time' => ['required', 'date_format:H:i'],
            'close_time' => ['required', 'date_format:H:i'],
            'thumbnail' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        if ($request->hasFile('thumbnail')) {
            $validated['thumbnail'] = $request->file('thumbnail')->store('studios', 'public');
        }

        $studio = Studio::create($validated);

        return response()->json([
            'message' => 'Studio created',
            'data' => $studio,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        $studio = Studio::find($id);

        if (! $studio) {
            return response()->json([
                'message' => 'Studio not found',
            ], 404);
        }

        return response()->json([
            'message' => 'Detail studio',
            'data' => $studio,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $studio = Studio::find($id);

        if (! $studio) {
            return response()->json([
                'message' => 'Studio not found',
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'address' => ['sometimes', 'required', 'string'],
            'city' => ['sometimes', 'required', 'string', 'max:255'],
            'open_time' => ['sometimes', 'required', 'date_format:H:i'],
            'close_time' => ['sometimes', 'required', 'date_format:H:i'],
            'thumbnail' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        if ($request->hasFile('thumbnail')) {
            if ($studio->thumbnail) {
                Storage::disk('public')->delete($studio->thumbnail);
            }

            $validated['thumbnail'] = $request->file('thumbnail')->store('studios', 'public');
        }

        $studio->update($validated);

        return response()->json([
            'message' => 'Studio updated',
            'data' => $studio->fresh(),
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        $studio = Studio::find($id);

        if (! $studio) {
            return response()->json([
                'message' => 'Studio not found',
            ], 404);
        }

        if ($studio->thumbnail) {
            Storage::disk('public')->delete($studio->thumbnail);
        }

        $studio->delete();

        return response()->json([
            'message' => 'Studio deleted',
        ]);
    }
}
