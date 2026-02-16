<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\Booking;
use App\Models\BookingAddon;
use App\Models\Customer;
use App\Models\Package;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function availableSlots(Request $request, string $studioId, string $packageId): JsonResponse
    {
        $validated = $request->validate([
            'booking_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $package = $this->findPackage($studioId, $packageId);
        if (! $package) {
            return response()->json(['message' => 'Package not found'], 404);
        }

        $slots = $this->buildSlots($package, $validated['booking_date']);

        return response()->json([
            'message' => 'Available slots',
            'data' => [
                'booking_date' => $validated['booking_date'],
                'slot_duration' => 30,
                'max_booking_per_slot' => $package->max_booking_per_slot,
                'slots' => $slots,
            ],
        ]);
    }

    public function store(Request $request, string $studioId, string $packageId): JsonResponse
    {
        $package = $this->findPackage($studioId, $packageId);
        if (! $package) {
            return response()->json(['message' => 'Package not found'], 404);
        }

        $validated = $request->validate([
            'booking_date' => ['required', 'date_format:Y-m-d'],
            'customer.name' => ['required', 'string', 'max:255'],
            'customer.phone' => ['required', 'string', 'max:30'],
            'customer.email' => ['required', 'email', 'max:255'],
            'addons' => ['nullable'],
            'preferences.background' => ['nullable', 'string', 'max:100'],
            'preferences.allow_social_media_upload' => ['nullable'],
            'notes' => ['nullable', 'string'],
        ]);

        $startTime = $this->normalizeTime((string) $request->input('start_time', ''));
        if (! $startTime) {
            return response()->json([
                'message' => 'Invalid start_time format. Use HH:mm or HH.mm',
            ], 422);
        }

        $normalizedAddons = $this->normalizeAddons($request->input('addons', []));
        $slots = collect($this->buildSlots($package, $validated['booking_date']));
        $selectedSlot = $slots->firstWhere('start_time', $startTime);

        if (! $selectedSlot) {
            return response()->json([
                'message' => 'Selected time is not available in this package schedule',
            ], 422);
        }

        if (! $selectedSlot['is_available']) {
            return response()->json([
                'message' => 'Selected time slot is full',
            ], 422);
        }

        $pricing = $this->calculateAddonPricing($package, $normalizedAddons);
        $totalPrice = (float) $package->price + $pricing['addons_total'];
        $endTime = Carbon::createFromFormat('H:i', $startTime)
            ->addMinutes((int) $package->duration_minutes)
            ->format('H:i:s');

        $notesPayload = [
            'preferences' => $validated['preferences'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ];

        $notesPayload = array_filter($notesPayload, fn ($value) => $value !== null && $value !== []);
        $encodedNotes = empty($notesPayload) ? null : json_encode($notesPayload, JSON_UNESCAPED_UNICODE);

        $booking = DB::transaction(function () use ($validated, $package, $pricing, $totalPrice, $encodedNotes, $endTime, $startTime) {
            $customer = Customer::updateOrCreate(
                ['phone' => $validated['customer']['phone']],
                [
                    'name' => $validated['customer']['name'],
                    'email' => $validated['customer']['email'],
                ]
            );

            $invoiceNumber = $this->generateInvoiceNumber();

            $booking = Booking::create([
                'invoice_number' => $invoiceNumber,
                'customer_id' => $customer->id,
                'package_id' => $package->id,
                'booking_date' => $validated['booking_date'],
                'start_time' => Carbon::createFromFormat('H:i', $startTime)->format('H:i:s'),
                'end_time' => $endTime,
                'total_price' => $totalPrice,
                'status' => 'pending',
                'payment_status' => 'unpaid',
                'payment_method' => 'pending',
                'payment_expired_at' => now()->addMinutes(30),
                'notes' => $encodedNotes,
            ]);

            foreach ($pricing['addon_rows'] as $row) {
                BookingAddon::create([
                    'booking_id' => $booking->id,
                    'addon_id' => $row['addon_id'],
                    'qty' => $row['qty'],
                    'price' => $row['price'],
                    'subtotal' => $row['subtotal'],
                ]);
            }

            return $booking;
        });

        $booking->load(['customer', 'package', 'bookingAddons.addon']);

        return response()->json([
            'message' => 'Booking created',
            'data' => $booking,
        ], 201);
    }

    public function show(string $invoiceNumber): JsonResponse
    {
        $booking = Booking::with(['customer', 'package', 'bookingAddons.addon'])
            ->where('invoice_number', $invoiceNumber)
            ->first();

        if (! $booking) {
            return response()->json(['message' => 'Booking not found'], 404);
        }

        return response()->json([
            'message' => 'Detail booking',
            'data' => $booking,
        ]);
    }

    private function findPackage(string $studioId, string $packageId): ?Package
    {
        return Package::with('studio')
            ->where('studio_id', $studioId)
            ->where('id', $packageId)
            ->where('is_active', true)
            ->first();
    }

    private function buildSlots(Package $package, string $bookingDate): array
    {
        $studio = $package->studio;
        $slotDuration = 30;
        $durationMinutes = max((int) $package->duration_minutes, 1);
        $maxBookingPerSlot = max((int) $package->max_booking_per_slot, 1);

        $openTime = Carbon::parse((string) $studio->open_time);
        $closeTime = Carbon::parse((string) $studio->close_time);

        $slots = [];
        $cursor = $openTime->copy();

        while ($cursor->copy()->addMinutes($durationMinutes)->lte($closeTime)) {
            $start = $cursor->format('H:i');
            $end = $cursor->copy()->addMinutes($durationMinutes)->format('H:i');

            $bookedCount = Booking::where('package_id', $package->id)
                ->whereDate('booking_date', $bookingDate)
                ->whereTime('start_time', $start)
                ->whereNotIn('status', ['cancelled', 'expired'])
                ->count();

            $remaining = max($maxBookingPerSlot - $bookedCount, 0);

            $slots[] = [
                'start_time' => $start,
                'end_time' => $end,
                'booked_count' => $bookedCount,
                'remaining_quota' => $remaining,
                'is_available' => $remaining > 0,
            ];

            $cursor->addMinutes($slotDuration);
        }

        return $slots;
    }

    private function calculateAddonPricing(Package $package, array $addons): array
    {
        $addonIds = collect($addons)->pluck('addon_id')->unique()->values();

        $addonModels = Addon::where('package_id', $package->id)
            ->where('is_active', true)
            ->whereIn('id', $addonIds)
            ->get()
            ->keyBy('id');

        if ($addonIds->count() !== $addonModels->count()) {
            throw ValidationException::withMessages([
                'addons' => ['One or more addons are invalid for this package'],
            ]);
        }

        $addonRows = [];
        $addonItems = [];
        $addonsTotal = 0.0;

        foreach ($addons as $item) {
            $addon = $addonModels->get($item['addon_id']);
            $qty = (int) $item['qty'];
            $price = (float) $addon->price;
            $subtotal = $price * $qty;
            $addonsTotal += $subtotal;

            $addonRows[] = [
                'addon_id' => $addon->id,
                'qty' => $qty,
                'price' => $price,
                'subtotal' => $subtotal,
            ];

            $addonItems[] = [
                'id' => $addon->id,
                'name' => $addon->name,
                'qty' => $qty,
                'price' => $price,
                'subtotal' => $subtotal,
            ];
        }

        return [
            'addons' => $addonItems,
            'addon_rows' => $addonRows,
            'addons_total' => $addonsTotal,
        ];
    }

    private function normalizeTime(string $value): ?string
    {
        $normalized = str_replace('.', ':', trim($value));

        if (! preg_match('/^\d{2}:\d{2}$/', $normalized)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('H:i', $normalized)->format('H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeAddons(mixed $addons): array
    {
        if (empty($addons)) {
            return [];
        }

        $normalized = [];

        if (is_array($addons) && array_is_list($addons)) {
            foreach ($addons as $item) {
                if (! is_array($item)) {
                    throw ValidationException::withMessages([
                        'addons' => ['Invalid addons payload format'],
                    ]);
                }

                $addonId = $item['addon_id'] ?? $item['id'] ?? null;
                $qty = $item['qty'] ?? $item['quantity'] ?? null;

                if (! is_numeric($addonId) || ! is_numeric($qty) || (int) $qty < 1) {
                    throw ValidationException::withMessages([
                        'addons' => ['Each addon must contain addon_id and qty >= 1'],
                    ]);
                }

                $normalized[] = [
                    'addon_id' => (int) $addonId,
                    'qty' => (int) $qty,
                ];
            }

            return $normalized;
        }

        if (is_array($addons)) {
            foreach ($addons as $addonId => $qty) {
                if (! is_numeric($addonId) || ! is_numeric($qty) || (int) $qty < 1) {
                    throw ValidationException::withMessages([
                        'addons' => ['Addon map format must be {addon_id: qty} with qty >= 1'],
                    ]);
                }

                $normalized[] = [
                    'addon_id' => (int) $addonId,
                    'qty' => (int) $qty,
                ];
            }

            return $normalized;
        }

        throw ValidationException::withMessages([
            'addons' => ['Invalid addons payload format'],
        ]);
    }

    private function generateInvoiceNumber(): string
    {
        do {
            $invoice = 'INV-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6));
        } while (Booking::where('invoice_number', $invoice)->exists());

        return $invoice;
    }
}
