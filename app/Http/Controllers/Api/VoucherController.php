<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Voucher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VoucherController extends Controller
{
    public function activeList(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $now = now();
        $perPage = (int) ($validated['per_page'] ?? 15);

        $vouchers = Voucher::query()
            ->where('is_active', true)
            ->where(function ($query) use ($now) {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->where(function ($query) {
                $query->whereNull('usage_limit')
                    ->orWhereRaw('used_count < usage_limit');
            })
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        $data = collect($vouchers->items())
            ->map(function (Voucher $voucher) {
                $availableUsage = $voucher->usage_limit === null
                    ? null
                    : max((int) $voucher->usage_limit - (int) $voucher->used_count, 0);

                return [
                    'id' => $voucher->id,
                    'code' => $voucher->code,
                    'name' => $voucher->name,
                    'description' => $voucher->description,
                    'discount_type' => $voucher->discount_type,
                    'discount_value' => (float) $voucher->discount_value,
                    'max_discount' => $voucher->max_discount !== null ? (float) $voucher->max_discount : null,
                    'min_total' => $voucher->min_total !== null ? (float) $voucher->min_total : null,
                    'starts_at' => $voucher->starts_at,
                    'ends_at' => $voucher->ends_at,
                    'usage_limit' => $voucher->usage_limit,
                    'total_usage' => (int) $voucher->used_count,
                    'available_usage' => $availableUsage,
                ];
            })
            ->values();

        return response()->json([
            'message' => 'Active vouchers',
            'data' => $data,
            'meta' => [
                'current_page' => $vouchers->currentPage(),
                'per_page' => $vouchers->perPage(),
                'total' => $vouchers->total(),
                'last_page' => $vouchers->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:vouchers,code'],
            'name' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'discount_type' => ['required', 'in:fixed,percent'],
            'discount_value' => ['required', 'numeric', 'gt:0'],
            'max_discount' => ['nullable', 'numeric', 'gt:0'],
            'min_total' => ['nullable', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($validated['discount_type'] === 'percent' && (float) $validated['discount_value'] > 100) {
            throw ValidationException::withMessages([
                'discount_value' => ['For percent type, discount_value cannot exceed 100'],
            ]);
        }

        $voucher = Voucher::create([
            'code' => strtoupper(trim($validated['code'])),
            'name' => $validated['name'] ?? null,
            'description' => $validated['description'] ?? null,
            'discount_type' => $validated['discount_type'],
            'discount_value' => $validated['discount_value'],
            'max_discount' => $validated['max_discount'] ?? null,
            'min_total' => $validated['min_total'] ?? null,
            'usage_limit' => $validated['usage_limit'] ?? null,
            'used_count' => 0,
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        return response()->json([
            'message' => 'Voucher created',
            'data' => $voucher,
        ], 201);
    }
}
