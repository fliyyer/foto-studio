<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\Booking;
use App\Models\BookingAddon;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Voucher;
use App\Services\PakasirService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    private const PAKASIR_PAYMENT_METHODS = [
        'cimb_niaga_va',
        'bni_va',
        'qris',
        'sampoerna_va',
        'bnc_va',
        'maybank_va',
        'permata_va',
        'atm_bersama_va',
        'artha_graha_va',
        'bri_va',
        'paypal',
    ];

    public function adminDashboard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $now = now();
        $month = (int) ($validated['month'] ?? $now->month);
        $year = (int) ($validated['year'] ?? $now->year);

        $today = $now->toDateString();
        $startMonth = Carbon::create($year, $month, 1)->startOfMonth();
        $endMonth = $startMonth->copy()->endOfMonth();

        $totalBookingToday = Booking::whereDate('booking_date', $today)->count();
        $totalBookingMonth = Booking::whereBetween('booking_date', [$startMonth->toDateString(), $endMonth->toDateString()])->count();

        $totalRevenueToday = (float) Booking::whereDate('booking_date', $today)
            ->where('payment_status', 'paid')
            ->sum('total_price');

        $totalRevenueMonth = (float) Booking::whereBetween('booking_date', [$startMonth->toDateString(), $endMonth->toDateString()])
            ->where('payment_status', 'paid')
            ->sum('total_price');

        return response()->json([
            'message' => 'Admin dashboard summary',
            'data' => [
                'total_booking_today' => $totalBookingToday,
                'total_booking_month' => $totalBookingMonth,
                'total_revenue_today' => $totalRevenueToday,
                'total_revenue_month' => $totalRevenueMonth,
                'month' => $month,
                'year' => $year,
            ],
        ]);
    }

    public function statuses(): JsonResponse
    {
        $statusCounts = Booking::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $paymentStatusCounts = Booking::query()
            ->selectRaw('payment_status, COUNT(*) as total')
            ->groupBy('payment_status')
            ->pluck('total', 'payment_status');

        $bookingStatuses = collect(Booking::bookingStatuses())
            ->map(fn ($status) => [
                'status' => $status,
                'total' => (int) ($statusCounts[$status] ?? 0),
            ])
            ->values();

        $paymentStatuses = collect(Booking::paymentStatuses())
            ->map(fn ($status) => [
                'status' => $status,
                'total' => (int) ($paymentStatusCounts[$status] ?? 0),
            ])
            ->values();

        return response()->json([
            'message' => 'Booking statuses',
            'data' => [
                'booking_statuses' => $bookingStatuses,
                'payment_statuses' => $paymentStatuses,
            ],
        ]);
    }

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

    public function adminIndex(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string'],
            'payment_status' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:100'],
            'customer_name' => ['nullable', 'string', 'max:100'],
            'booking_date' => ['nullable', 'date_format:Y-m-d'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'studio_id' => ['nullable', 'integer', 'min:1'],
            'package_id' => ['nullable', 'integer', 'min:1'],
            'studio_name' => ['nullable', 'string', 'max:100'],
            'package_name' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort_by' => ['nullable', 'in:created_at,booking_date,total_price,status,payment_status'],
            'sort_dir' => ['nullable', 'in:asc,desc'],
        ]);

        if (! empty($validated['status']) && ! in_array($validated['status'], Booking::bookingStatuses(), true)) {
            throw ValidationException::withMessages([
                'status' => ['Invalid booking status'],
            ]);
        }

        if (! empty($validated['payment_status']) && ! in_array($validated['payment_status'], Booking::paymentStatuses(), true)) {
            throw ValidationException::withMessages([
                'payment_status' => ['Invalid payment status'],
            ]);
        }

        $query = Booking::query()->with([
            'customer',
            'package.studio',
            'bookingAddons.addon',
            'voucher',
            'payment',
        ]);

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['payment_status'])) {
            $query->where('payment_status', $validated['payment_status']);
        }

        if (! empty($validated['booking_date'])) {
            $query->whereDate('booking_date', $validated['booking_date']);
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('booking_date', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('booking_date', '<=', $validated['date_to']);
        }

        if (! empty($validated['studio_id'])) {
            $query->whereHas('package', function ($packageQuery) use ($validated) {
                $packageQuery->where('studio_id', $validated['studio_id']);
            });
        }

        if (! empty($validated['package_id'])) {
            $query->where('package_id', $validated['package_id']);
        }

        if (! empty($validated['studio_name'])) {
            $studioName = trim($validated['studio_name']);

            $query->whereHas('package.studio', function ($studioQuery) use ($studioName) {
                $studioQuery->where('name', 'like', '%' . $studioName . '%');
            });
        }

        if (! empty($validated['package_name'])) {
            $packageName = trim($validated['package_name']);

            $query->whereHas('package', function ($packageQuery) use ($packageName) {
                $packageQuery->where('name', 'like', '%' . $packageName . '%');
            });
        }

        if (! empty($validated['customer_name'])) {
            $customerName = trim($validated['customer_name']);

            $query->whereHas('customer', function ($customerQuery) use ($customerName) {
                $customerQuery->where('name', 'like', '%' . $customerName . '%');
            });
        }

        if (! empty($validated['search'])) {
            $search = trim($validated['search']);

            $query->where(function ($searchQuery) use ($search) {
                $searchQuery->where('invoice_number', 'like', '%' . $search . '%')
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery->where('name', 'like', '%' . $search . '%')
                            ->orWhere('phone', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('package', function ($packageQuery) use ($search) {
                        $packageQuery->where('name', 'like', '%' . $search . '%')
                            ->orWhereHas('studio', function ($studioQuery) use ($search) {
                                $studioQuery->where('name', 'like', '%' . $search . '%');
                            });
                    });
            });
        }

        $sortBy = $validated['sort_by'] ?? 'created_at';
        $sortDir = $validated['sort_dir'] ?? 'desc';
        $perPage = (int) ($validated['per_page'] ?? 15);

        $bookings = $query
            ->orderBy($sortBy, $sortDir)
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'message' => 'Booking list',
            'data' => $bookings->items(),
            'meta' => [
                'current_page' => $bookings->currentPage(),
                'per_page' => $bookings->perPage(),
                'total' => $bookings->total(),
                'last_page' => $bookings->lastPage(),
            ],
        ]);
    }

    public function adminShow(string $id): JsonResponse
    {
        $booking = Booking::with(['customer', 'package.studio', 'bookingAddons.addon', 'voucher', 'payment'])
            ->where('id', $id)
            ->first();

        if (! $booking) {
            return response()->json(['message' => 'Booking not found'], 404);
        }

        return response()->json([
            'message' => 'Booking detail',
            'data' => $booking,
        ]);
    }

    public function adminUpdateStatus(Request $request, string $id): JsonResponse
    {
        $booking = Booking::with(['customer', 'package.studio', 'bookingAddons.addon', 'voucher', 'payment'])
            ->where('id', $id)
            ->first();

        if (! $booking) {
            return response()->json(['message' => 'Booking not found'], 404);
        }

        $validated = $request->validate([
            'status' => ['required', 'string'],
            'payment_status' => ['nullable', 'string'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'payment_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        if (! in_array($validated['status'], Booking::bookingStatuses(), true)) {
            throw ValidationException::withMessages([
                'status' => ['Invalid booking status'],
            ]);
        }

        if (! empty($validated['payment_status']) && ! in_array($validated['payment_status'], Booking::paymentStatuses(), true)) {
            throw ValidationException::withMessages([
                'payment_status' => ['Invalid payment status'],
            ]);
        }

        $booking->status = $validated['status'];

        if (array_key_exists('payment_status', $validated) && $validated['payment_status'] !== null) {
            $booking->payment_status = $validated['payment_status'];
        }

        if (array_key_exists('payment_method', $validated) && $validated['payment_method'] !== null) {
            $booking->payment_method = $validated['payment_method'];
        }

        if (array_key_exists('payment_reference', $validated)) {
            $booking->payment_reference = $validated['payment_reference'];
        }

        if (array_key_exists('notes', $validated) && $validated['notes'] !== null) {
            $booking->notes = $validated['notes'];
        }

        $booking->save();

        return response()->json([
            'message' => 'Booking status updated',
            'data' => $booking->fresh(['customer', 'package.studio', 'bookingAddons.addon', 'voucher', 'payment']),
        ]);
    }

    public function adminReschedule(Request $request, string $id): JsonResponse
    {
        $booking = Booking::with(['package.studio', 'customer', 'bookingAddons.addon', 'voucher', 'payment'])
            ->where('id', $id)
            ->first();

        if (! $booking) {
            return response()->json(['message' => 'Booking not found'], 404);
        }

        if (in_array($booking->status, ['completed', 'cancelled', 'expired'], true)) {
            return response()->json([
                'message' => 'This booking cannot be rescheduled',
            ], 422);
        }

        $validated = $request->validate([
            'booking_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $startTime = $this->normalizeTime($validated['start_time']);
        if (! $startTime) {
            return response()->json([
                'message' => 'Invalid start_time format. Use HH:mm or HH.mm',
            ], 422);
        }

        $slots = collect($this->buildSlots($booking->package, $validated['booking_date'], $booking->id));
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

        $endTime = Carbon::createFromFormat('H:i', $startTime)
            ->addMinutes((int) $booking->package->duration_minutes)
            ->format('H:i:s');

        $booking->booking_date = $validated['booking_date'];
        $booking->start_time = Carbon::createFromFormat('H:i', $startTime)->format('H:i:s');
        $booking->end_time = $endTime;

        if (! empty($validated['notes'])) {
            $booking->notes = $validated['notes'];
        }

        $booking->save();

        return response()->json([
            'message' => 'Booking rescheduled',
            'data' => $booking->fresh(['customer', 'package.studio', 'bookingAddons.addon', 'voucher', 'payment']),
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
            'voucher_code' => ['nullable', 'string', 'max:50'],
            'payment_mode' => ['nullable', 'in:url,api'],
            'payment_method' => ['nullable', 'string'],
            'qris_only' => ['nullable', 'boolean'],
            'redirect_url' => ['nullable', 'url', 'max:500'],
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
        $voucherCode = isset($validated['voucher_code'])
            ? strtoupper(trim((string) $validated['voucher_code']))
            : null;
        $rawPaymentMode = $validated['payment_mode'] ?? null;
        $rawPaymentMethod = isset($validated['payment_method'])
            ? strtolower(trim((string) $validated['payment_method']))
            : null;
        $paymentMode = $rawPaymentMode !== null ? (string) $rawPaymentMode : 'url';

        if ($rawPaymentMode === null && $rawPaymentMethod !== null && in_array($rawPaymentMethod, self::PAKASIR_PAYMENT_METHODS, true)) {
            $paymentMode = 'api';
        }

        $defaultPaymentMethod = $paymentMode === 'api' ? 'qris' : 'url';
        $paymentMethod = $rawPaymentMethod ?? $defaultPaymentMethod;
        $qrisOnly = (bool) ($validated['qris_only'] ?? false);
        $redirectUrl = $validated['redirect_url'] ?? null;

        if ($paymentMode === 'api' && ! in_array($paymentMethod, self::PAKASIR_PAYMENT_METHODS, true)) {
            throw ValidationException::withMessages([
                'payment_method' => ['Invalid payment method'],
            ]);
        }

        if ($paymentMode === 'api' && $qrisOnly) {
            throw ValidationException::withMessages([
                'qris_only' => ['qris_only can only be used with payment_mode=url'],
            ]);
        }

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
        $subtotalPrice = (float) $package->price + $pricing['addons_total'];
        $endTime = Carbon::createFromFormat('H:i', $startTime)
            ->addMinutes((int) $package->duration_minutes)
            ->format('H:i:s');

        $notesPayload = [
            'preferences' => $validated['preferences'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ];

        $notesPayload = array_filter($notesPayload, fn ($value) => $value !== null && $value !== []);
        $encodedNotes = empty($notesPayload) ? null : json_encode($notesPayload, JSON_UNESCAPED_UNICODE);

        $booking = DB::transaction(function () use ($validated, $package, $pricing, $subtotalPrice, $encodedNotes, $endTime, $startTime, $voucherCode) {
            $voucher = null;
            $discountAmount = 0.0;

            if (! empty($voucherCode)) {
                $voucher = Voucher::whereRaw('UPPER(code) = ?', [$voucherCode])
                    ->lockForUpdate()
                    ->first();

                if (! $voucher) {
                    throw ValidationException::withMessages([
                        'voucher_code' => ['Voucher not found'],
                    ]);
                }

                $discountAmount = $this->calculateVoucherDiscount($voucher, $subtotalPrice);

                if ($voucher->usage_limit !== null && $voucher->used_count >= $voucher->usage_limit) {
                    throw ValidationException::withMessages([
                        'voucher_code' => ['Voucher quota has been reached'],
                    ]);
                }

                $voucher->increment('used_count');
            }

            $totalPrice = max($subtotalPrice - $discountAmount, 0);

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
                'voucher_id' => $voucher?->id,
                'booking_date' => $validated['booking_date'],
                'start_time' => Carbon::createFromFormat('H:i', $startTime)->format('H:i:s'),
                'end_time' => $endTime,
                'subtotal_price' => $subtotalPrice,
                'discount_amount' => $discountAmount,
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

        $paymentData = $this->initializePakasirPayment($booking, $paymentMode, $paymentMethod, $redirectUrl, $qrisOnly);
        $booking->load(['customer', 'package', 'bookingAddons.addon', 'voucher', 'payment']);

        return response()->json([
            'message' => 'Booking created',
            'data' => $booking,
            'payment' => $paymentData,
        ], 201);
    }

    public function show(string $invoiceNumber): JsonResponse
    {
        $booking = Booking::with(['customer', 'package', 'bookingAddons.addon', 'voucher', 'payment'])
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

    private function buildSlots(Package $package, string $bookingDate, ?int $excludeBookingId = null): array
    {
        $studio = $package->studio;
        $slotDuration = 30;
        $durationMinutes = max((int) $package->duration_minutes, 1);
        $maxBookingPerSlot = max((int) $package->max_booking_per_slot, 1);
        $isBookingDateToday = Carbon::parse($bookingDate)->isToday();
        $currentTime = now();

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
                ->when($excludeBookingId, function ($query) use ($excludeBookingId) {
                    $query->where('id', '!=', $excludeBookingId);
                })
                ->count();

            $remaining = max($maxBookingPerSlot - $bookedCount, 0);
            $slotDateTime = Carbon::createFromFormat('Y-m-d H:i', $bookingDate . ' ' . $start);
            $isPastSlot = $isBookingDateToday && $slotDateTime->lte($currentTime);
            $isAvailable = $remaining > 0 && ! $isPastSlot;

            $slots[] = [
                'start_time' => $start,
                'end_time' => $end,
                'booked_count' => $bookedCount,
                'remaining_quota' => $isAvailable ? $remaining : 0,
                'is_available' => $isAvailable,
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

    private function initializePakasirPayment(
        Booking $booking,
        string $paymentMode,
        string $paymentMethod,
        ?string $redirectUrl,
        bool $qrisOnly
    ): array {
        $this->ensurePakasirConfigured();

        $amount = max((int) round((float) $booking->total_price), 0);
        $service = app(PakasirService::class);

        if ($amount === 0) {
            $booking->update([
                'payment_status' => 'paid',
                'payment_method' => 'free',
                'payment_reference' => $booking->invoice_number,
                'payment_expired_at' => null,
            ]);

            Payment::updateOrCreate(
                ['booking_id' => $booking->id],
                [
                    'method' => 'free',
                    'amount' => 0,
                    'transaction_id' => $booking->invoice_number,
                    'payment_status' => 'completed',
                    'paid_at' => now(),
                    'raw_response' => json_encode(['message' => 'No payment required'], JSON_UNESCAPED_UNICODE),
                ]
            );

            return [
                'provider' => 'pakasir',
                'mode' => 'free',
                'order_id' => $booking->invoice_number,
                'amount' => 0,
                'payment_method' => 'free',
                'payment_url' => null,
                'expired_at' => null,
            ];
        }

        $fallbackPaymentMethod = $paymentMethod === 'paypal' ? 'paypal' : 'url';
        $fallbackUrl = $service->buildPaymentUrl($booking->invoice_number, $amount, $redirectUrl, $qrisOnly, $fallbackPaymentMethod);

        if ($paymentMode === 'url') {
            $booking->update([
                'payment_method' => 'url',
                'payment_reference' => $booking->invoice_number,
            ]);

            Payment::updateOrCreate(
                ['booking_id' => $booking->id],
                [
                    'method' => 'url',
                    'amount' => $amount,
                    'transaction_id' => $booking->invoice_number,
                    'payment_status' => 'pending',
                    'paid_at' => null,
                    'raw_response' => json_encode(['payment_url' => $fallbackUrl], JSON_UNESCAPED_UNICODE),
                ]
            );

            return [
                'provider' => 'pakasir',
                'mode' => 'url',
                'order_id' => $booking->invoice_number,
                'amount' => $amount,
                'payment_method' => 'url',
                'payment_url' => $fallbackUrl,
                'expired_at' => optional($booking->payment_expired_at)->toISOString(),
            ];
        }

        try {
            $response = $service->createTransaction($paymentMethod, $booking->invoice_number, $amount);
            $payment = (array) ($response['payment'] ?? []);
            $expiredAt = ! empty($payment['expired_at'])
                ? Carbon::parse((string) $payment['expired_at'])->setTimezone(config('app.timezone'))
                : now()->addMinutes(30);

            $booking->update([
                'payment_method' => (string) ($payment['payment_method'] ?? $paymentMethod),
                'payment_reference' => (string) ($payment['order_id'] ?? $booking->invoice_number),
                'payment_expired_at' => $expiredAt,
            ]);

            Payment::updateOrCreate(
                ['booking_id' => $booking->id],
                [
                    'method' => (string) ($payment['payment_method'] ?? $paymentMethod),
                    'amount' => (float) ($payment['total_payment'] ?? $payment['amount'] ?? $amount),
                    'transaction_id' => (string) ($payment['order_id'] ?? $booking->invoice_number),
                    'payment_status' => 'pending',
                    'paid_at' => null,
                    'raw_response' => json_encode($response, JSON_UNESCAPED_UNICODE),
                ]
            );

            return [
                'provider' => 'pakasir',
                'mode' => 'api',
                'order_id' => (string) ($payment['order_id'] ?? $booking->invoice_number),
                'amount' => (float) ($payment['amount'] ?? $amount),
                'fee' => isset($payment['fee']) ? (float) $payment['fee'] : null,
                'total_payment' => isset($payment['total_payment']) ? (float) $payment['total_payment'] : null,
                'payment_method' => (string) ($payment['payment_method'] ?? $paymentMethod),
                'payment_number' => $payment['payment_number'] ?? null,
                'payment_url' => $fallbackUrl,
                'expired_at' => $expiredAt->toISOString(),
            ];
        } catch (\Throwable $exception) {
            Log::warning('Failed to create Pakasir API transaction, fallback to payment URL', [
                'booking_id' => $booking->id,
                'invoice_number' => $booking->invoice_number,
                'payment_method' => $paymentMethod,
                'error' => $exception->getMessage(),
            ]);

            $booking->update([
                'payment_method' => $paymentMethod,
                'payment_reference' => $booking->invoice_number,
            ]);

            Payment::updateOrCreate(
                ['booking_id' => $booking->id],
                [
                    'method' => $paymentMethod,
                    'amount' => $amount,
                    'transaction_id' => $booking->invoice_number,
                    'payment_status' => 'pending',
                    'paid_at' => null,
                    'raw_response' => json_encode([
                        'fallback' => true,
                        'error' => $exception->getMessage(),
                        'payment_url' => $fallbackUrl,
                    ], JSON_UNESCAPED_UNICODE),
                ]
            );

            return [
                'provider' => 'pakasir',
                'mode' => 'url_fallback',
                'order_id' => $booking->invoice_number,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'payment_url' => $fallbackUrl,
                'expired_at' => optional($booking->payment_expired_at)->toISOString(),
                'warning' => 'Pakasir API unavailable, using payment URL fallback',
            ];
        }
    }

    private function ensurePakasirConfigured(): void
    {
        if (empty(config('services.pakasir.project_slug')) || empty(config('services.pakasir.api_key'))) {
            throw ValidationException::withMessages([
                'payment' => ['Pakasir configuration is incomplete'],
            ]);
        }
    }

    private function calculateVoucherDiscount(Voucher $voucher, float $subtotalPrice): float
    {
        if (! $voucher->is_active) {
            throw ValidationException::withMessages([
                'voucher_code' => ['Voucher is inactive'],
            ]);
        }

        if ($voucher->starts_at !== null && now()->lt($voucher->starts_at)) {
            throw ValidationException::withMessages([
                'voucher_code' => ['Voucher is not active yet'],
            ]);
        }

        if ($voucher->ends_at !== null && now()->gt($voucher->ends_at)) {
            throw ValidationException::withMessages([
                'voucher_code' => ['Voucher has expired'],
            ]);
        }

        if ($voucher->min_total !== null && $subtotalPrice < (float) $voucher->min_total) {
            throw ValidationException::withMessages([
                'voucher_code' => ['Minimum booking total for this voucher is not reached'],
            ]);
        }

        $discount = $voucher->discount_type === Voucher::TYPE_PERCENT
            ? ($subtotalPrice * ((float) $voucher->discount_value / 100))
            : (float) $voucher->discount_value;

        if ($voucher->max_discount !== null) {
            $discount = min($discount, (float) $voucher->max_discount);
        }

        return min($discount, $subtotalPrice);
    }
}
