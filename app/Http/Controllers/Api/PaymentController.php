<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\PakasirService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'payment_status' => ['nullable', 'string', 'max:100'],
            'method' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $query = Payment::query()->with([
            'booking.customer',
            'booking.package.studio',
        ]);

        if (! empty($validated['payment_status'])) {
            $query->where('payment_status', $validated['payment_status']);
        }

        if (! empty($validated['method'])) {
            $query->where('method', $validated['method']);
        }

        if (! empty($validated['date_from'])) {
            $query->whereDate('created_at', '>=', $validated['date_from']);
        }

        if (! empty($validated['date_to'])) {
            $query->whereDate('created_at', '<=', $validated['date_to']);
        }

        if (! empty($validated['search'])) {
            $search = trim($validated['search']);

            $query->where(function ($searchQuery) use ($search) {
                $searchQuery->where('transaction_id', 'like', '%' . $search . '%')
                    ->orWhere('method', 'like', '%' . $search . '%')
                    ->orWhereHas('booking', function ($bookingQuery) use ($search) {
                        $bookingQuery->where('invoice_number', 'like', '%' . $search . '%')
                            ->orWhereHas('customer', function ($customerQuery) use ($search) {
                                $customerQuery->where('name', 'like', '%' . $search . '%')
                                    ->orWhere('phone', 'like', '%' . $search . '%')
                                    ->orWhere('email', 'like', '%' . $search . '%');
                            });
                    });
            });
        }

        $perPage = (int) ($validated['per_page'] ?? 15);
        $payments = $query
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'message' => 'Payment history',
            'data' => $payments->items(),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
                'last_page' => $payments->lastPage(),
            ],
        ]);
    }

    public function pollPaymentStatus(string $invoiceNumber, PakasirService $pakasirService): JsonResponse
    {
        $booking = Booking::with(['payment', 'customer', 'package'])
            ->where('invoice_number', $invoiceNumber)
            ->first();

        if (! $booking) {
            return response()->json([
                'message' => 'Booking not found',
            ], 404);
        }

        $amount = max((int) round((float) $booking->total_price), 0);
        $detail = $pakasirService->transactionDetail($invoiceNumber, $amount);
        $transaction = (array) ($detail['transaction'] ?? []);

        $project = (string) ($transaction['project'] ?? '');
        $orderId = (string) ($transaction['order_id'] ?? '');
        $remoteAmount = (int) round((float) ($transaction['amount'] ?? -1));
        $remoteStatus = strtolower((string) ($transaction['status'] ?? ''));
        $expectedProject = (string) config('services.pakasir.project_slug');

        if ($project !== $expectedProject || $orderId !== $invoiceNumber || $remoteAmount !== $amount) {
            throw ValidationException::withMessages([
                'transaction' => ['Transaction detail mismatch'],
            ]);
        }

        $localPaymentStatus = match ($remoteStatus) {
            'completed' => 'paid',
            'failed' => 'failed',
            'refunded' => 'refunded',
            default => 'unpaid',
        };

        DB::transaction(function () use ($booking, $transaction, $detail, $remoteStatus, $localPaymentStatus, $invoiceNumber, $amount): void {
            $booking->payment_status = $localPaymentStatus;
            $booking->payment_method = (string) ($transaction['payment_method'] ?? $booking->payment_method ?? 'pending');
            $booking->payment_reference = $invoiceNumber;

            if ($remoteStatus === 'completed') {
                $booking->payment_expired_at = null;

                if ($booking->status === 'pending') {
                    $booking->status = 'confirmed';
                }
            }

            $booking->save();

            $paidAt = null;
            if ($remoteStatus === 'completed') {
                $paidAt = ! empty($transaction['completed_at'])
                    ? Carbon::parse((string) $transaction['completed_at'])
                    : now();
            }

            Payment::updateOrCreate(
                ['booking_id' => $booking->id],
                [
                    'method' => (string) ($transaction['payment_method'] ?? $booking->payment_method ?? 'unknown'),
                    'amount' => $amount,
                    'transaction_id' => $invoiceNumber,
                    'payment_status' => $remoteStatus,
                    'paid_at' => $paidAt,
                    'raw_response' => json_encode([
                        'source' => 'polling',
                        'transaction_detail' => $detail,
                    ], JSON_UNESCAPED_UNICODE),
                ]
            );
        });

        $booking->refresh()->load('payment');

        return response()->json([
            'message' => 'Payment status synchronized',
            'data' => [
                'invoice_number' => $booking->invoice_number,
                'booking_status' => $booking->status,
                'payment_status' => $booking->payment_status,
                'payment_method' => $booking->payment_method,
                'payment_reference' => $booking->payment_reference,
                'transaction' => $transaction,
                'payment_record' => $booking->payment,
            ],
        ]);
    }

    public function transactionDetail(Request $request, PakasirService $pakasirService): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'string', 'max:255'],
            'amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $orderId = (string) $validated['order_id'];
        $booking = Booking::with('payment')
            ->where('invoice_number', $orderId)
            ->first();

        $amount = array_key_exists('amount', $validated)
            ? (int) round((float) $validated['amount'])
            : ($booking ? max((int) round((float) $booking->total_price), 0) : null);

        if ($amount === null) {
            throw ValidationException::withMessages([
                'amount' => ['Amount is required when order_id is not found in local booking'],
            ]);
        }

        $detail = $pakasirService->transactionDetail($orderId, $amount);
        $transaction = (array) ($detail['transaction'] ?? []);

        return response()->json([
            'message' => 'Transaction detail',
            'data' => [
                'transaction' => $transaction,
                'local_booking' => $booking ? [
                    'id' => $booking->id,
                    'invoice_number' => $booking->invoice_number,
                    'status' => $booking->status,
                    'payment_status' => $booking->payment_status,
                    'payment_method' => $booking->payment_method,
                    'payment_reference' => $booking->payment_reference,
                    'total_price' => (float) $booking->total_price,
                    'payment_record' => $booking->payment,
                ] : null,
            ],
        ]);
    }

    public function pakasirWebhook(Request $request, PakasirService $pakasirService): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'order_id' => ['required', 'string', 'max:255'],
            'project' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', 'max:100'],
            'payment_method' => ['nullable', 'string', 'max:100'],
            'completed_at' => ['nullable', 'date'],
        ]);

        $expectedProject = (string) config('services.pakasir.project_slug');
        if ($validated['project'] !== $expectedProject) {
            throw ValidationException::withMessages([
                'project' => ['Invalid project'],
            ]);
        }

        $orderId = (string) $validated['order_id'];
        $webhookAmount = (int) round((float) $validated['amount']);
        $status = strtolower((string) $validated['status']);

        $booking = Booking::with('payment')
            ->where('invoice_number', $orderId)
            ->first();

        if (! $booking) {
            return response()->json([
                'message' => 'Booking not found for this order_id',
            ], 404);
        }

        $expectedAmount = max((int) round((float) $booking->total_price), 0);
        if ($webhookAmount !== $expectedAmount) {
            throw ValidationException::withMessages([
                'amount' => ['Amount does not match booking total'],
            ]);
        }

        if ($status !== 'completed') {
            Payment::updateOrCreate(
                ['booking_id' => $booking->id],
                [
                    'method' => (string) ($validated['payment_method'] ?? $booking->payment_method ?? 'unknown'),
                    'amount' => $webhookAmount,
                    'transaction_id' => $orderId,
                    'payment_status' => $status,
                    'paid_at' => null,
                    'raw_response' => json_encode(['webhook' => $validated], JSON_UNESCAPED_UNICODE),
                ]
            );

            return response()->json([
                'message' => 'Webhook received, waiting for completed status',
            ]);
        }

        $detail = $pakasirService->transactionDetail($orderId, $expectedAmount);
        $transaction = (array) ($detail['transaction'] ?? []);
        $detailStatus = strtolower((string) ($transaction['status'] ?? ''));
        $detailProject = (string) ($transaction['project'] ?? '');
        $detailOrderId = (string) ($transaction['order_id'] ?? '');
        $detailAmount = (int) round((float) ($transaction['amount'] ?? -1));

        if (
            $detailStatus !== 'completed' ||
            $detailProject !== $expectedProject ||
            $detailOrderId !== $orderId ||
            $detailAmount !== $expectedAmount
        ) {
            throw ValidationException::withMessages([
                'transaction' => ['Transaction detail verification failed'],
            ]);
        }

        DB::transaction(function () use ($booking, $validated, $transaction, $orderId, $webhookAmount, $status, $detail): void {
            $paidAt = null;
            if (! empty($validated['completed_at'])) {
                $paidAt = Carbon::parse((string) $validated['completed_at']);
            } elseif (! empty($transaction['completed_at'])) {
                $paidAt = Carbon::parse((string) $transaction['completed_at']);
            } else {
                $paidAt = now();
            }

            $booking->payment_status = 'paid';
            $booking->payment_method = (string) ($transaction['payment_method'] ?? $validated['payment_method'] ?? $booking->payment_method);
            $booking->payment_reference = $orderId;
            $booking->payment_expired_at = null;

            if ($booking->status === 'pending') {
                $booking->status = 'confirmed';
            }

            $booking->save();

            Payment::updateOrCreate(
                ['booking_id' => $booking->id],
                [
                    'method' => (string) ($transaction['payment_method'] ?? $validated['payment_method'] ?? 'unknown'),
                    'amount' => $webhookAmount,
                    'transaction_id' => $orderId,
                    'payment_status' => $status,
                    'paid_at' => $paidAt,
                    'raw_response' => json_encode([
                        'webhook' => $validated,
                        'transaction_detail' => $detail,
                    ], JSON_UNESCAPED_UNICODE),
                ]
            );
        });

        return response()->json([
            'message' => 'Payment confirmed and booking updated',
            'data' => [
                'invoice_number' => $booking->invoice_number,
                'booking_status' => $booking->fresh()->status,
                'payment_status' => 'paid',
            ],
        ]);
    }
}
