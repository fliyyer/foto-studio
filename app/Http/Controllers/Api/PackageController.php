<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Studio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PackageController extends Controller
{
    public function index(string $studioId): JsonResponse
    {
        $studio = Studio::find($studioId);

        if (! $studio) {
            return response()->json([
                'message' => 'Studio not found',
            ], 404);
        }

        $packages = Package::where('studio_id', $studioId)->latest()->get();

        return response()->json([
            'message' => 'List packages',
            'data' => $packages,
        ]);
    }

    public function store(Request $request, string $studioId): JsonResponse
    {
        $studio = Studio::find($studioId);

        if (! $studio) {
            return response()->json([
                'message' => 'Studio not found',
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:255'],
            'thumbnail' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'price' => ['required', 'numeric', 'min:0'],
            'duration_minutes' => ['required', 'integer', 'min:1'],
            'slot_duration' => ['required', 'integer', 'min:1'],
            'max_booking_per_slot' => ['required', 'integer', 'min:1'],
            'description' => ['required', 'string'],
            'max_person' => ['required', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($request->hasFile('thumbnail')) {
            $validated['thumbnail'] = $request->file('thumbnail')->store('packages', 'public');
        }

        $validated['studio_id'] = (int) $studioId;
        $validated['is_active'] = $validated['is_active'] ?? true;

        $package = Package::create($validated);

        return response()->json([
            'message' => 'Package created',
            'data' => $package,
        ], 201);
    }

    public function show(string $studioId, string $id): JsonResponse
    {
        $package = Package::with('addons')
            ->where('studio_id', $studioId)
            ->where('id', $id)
            ->first();

        if (! $package) {
            return response()->json([
                'message' => 'Package not found',
            ], 404);
        }

        return response()->json([
            'message' => 'Detail package',
            'data' => $package,
        ]);
    }

    public function update(Request $request, string $studioId, string $id): JsonResponse
    {
        $package = Package::where('studio_id', $studioId)->where('id', $id)->first();

        if (! $package) {
            return response()->json([
                'message' => 'Package not found',
            ], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'required', 'string', 'max:255'],
            'thumbnail' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'duration_minutes' => ['sometimes', 'required', 'integer', 'min:1'],
            'slot_duration' => ['sometimes', 'required', 'integer', 'min:1'],
            'max_booking_per_slot' => ['sometimes', 'required', 'integer', 'min:1'],
            'description' => ['sometimes', 'required', 'string'],
            'max_person' => ['sometimes', 'required', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'nullable', 'boolean'],
        ]);

        if ($request->hasFile('thumbnail')) {
            if ($package->thumbnail) {
                Storage::disk('public')->delete($package->thumbnail);
            }

            $validated['thumbnail'] = $request->file('thumbnail')->store('packages', 'public');
        }

        if (empty($validated)) {
            return response()->json([
                'message' => 'No update payload received.',
                'hint' => 'Send at least one field to update using POST form-data or JSON.',
            ], 422);
        }

        $package->fill($validated);

        if (! $package->isDirty()) {
            return response()->json([
                'message' => 'No changes detected',
                'data' => $package,
            ]);
        }

        $package->save();

        return response()->json([
            'message' => 'Package updated',
            'data' => $package->fresh(),
        ]);
    }

    public function destroy(string $studioId, string $id): JsonResponse
    {
        $package = Package::where('studio_id', $studioId)->where('id', $id)->first();

        if (! $package) {
            return response()->json([
                'message' => 'Package not found',
            ], 404);
        }

        if ($package->thumbnail) {
            Storage::disk('public')->delete($package->thumbnail);
        }

        $package->delete();

        return response()->json([
            'message' => 'Package deleted',
        ]);
    }
}
