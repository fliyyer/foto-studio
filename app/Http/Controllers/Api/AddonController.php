<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AddonController extends Controller
{
    public function index(string $studioId, string $packageId): JsonResponse
    {
        $package = $this->findPackage($studioId, $packageId);
        if (! $package) {
            return response()->json(['message' => 'Package not found'], 404);
        }

        $addons = Addon::where('package_id', $package->id)->latest()->get();

        return response()->json([
            'message' => 'List addons',
            'data' => $addons,
        ]);
    }

    public function store(Request $request, string $studioId, string $packageId): JsonResponse
    {
        $package = $this->findPackage($studioId, $packageId);
        if (! $package) {
            return response()->json(['message' => 'Package not found'], 404);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'type' => ['required', 'string', 'max:100'],
            'description' => ['required', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $validated['package_id'] = $package->id;
        $validated['is_active'] = $validated['is_active'] ?? true;

        $addon = Addon::create($validated);

        return response()->json([
            'message' => 'Addon created',
            'data' => $addon,
        ], 201);
    }

    public function show(string $studioId, string $packageId, string $id): JsonResponse
    {
        $addon = $this->findAddon($studioId, $packageId, $id);
        if (! $addon) {
            return response()->json(['message' => 'Addon not found'], 404);
        }

        return response()->json([
            'message' => 'Detail addon',
            'data' => $addon,
        ]);
    }

    public function update(Request $request, string $studioId, string $packageId, string $id): JsonResponse
    {
        $addon = $this->findAddon($studioId, $packageId, $id);
        if (! $addon) {
            return response()->json(['message' => 'Addon not found'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'type' => ['sometimes', 'required', 'string', 'max:100'],
            'description' => ['sometimes', 'required', 'string'],
            'is_active' => ['sometimes', 'nullable', 'boolean'],
        ]);

        if (empty($validated)) {
            return response()->json([
                'message' => 'No update payload received.',
            ], 422);
        }

        $addon->fill($validated);

        if (! $addon->isDirty()) {
            return response()->json([
                'message' => 'No changes detected',
                'data' => $addon,
            ]);
        }

        $addon->save();

        return response()->json([
            'message' => 'Addon updated',
            'data' => $addon->fresh(),
        ]);
    }

    public function destroy(string $studioId, string $packageId, string $id): JsonResponse
    {
        $addon = $this->findAddon($studioId, $packageId, $id);
        if (! $addon) {
            return response()->json(['message' => 'Addon not found'], 404);
        }

        $addon->delete();

        return response()->json([
            'message' => 'Addon deleted',
        ]);
    }

    private function findPackage(string $studioId, string $packageId): ?Package
    {
        return Package::where('studio_id', $studioId)->where('id', $packageId)->first();
    }

    private function findAddon(string $studioId, string $packageId, string $id): ?Addon
    {
        return Addon::where('id', $id)
            ->where('package_id', $packageId)
            ->whereHas('package', function ($query) use ($studioId) {
                $query->where('studio_id', $studioId);
            })
            ->first();
    }
}
