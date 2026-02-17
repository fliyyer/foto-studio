<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class PakasirService
{
    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    public function createTransaction(string $paymentMethod, string $orderId, int $amount): array
    {
        $response = Http::baseUrl((string) config('services.pakasir.base_url'))
            ->timeout(15)
            ->acceptJson()
            ->asJson()
            ->post('/api/transactioncreate/' . $paymentMethod, [
                'project' => config('services.pakasir.project_slug'),
                'order_id' => $orderId,
                'amount' => $amount,
                'api_key' => config('services.pakasir.api_key'),
            ])
            ->throw();

        return $response->json();
    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     */
    public function transactionDetail(string $orderId, int $amount): array
    {
        $response = Http::baseUrl((string) config('services.pakasir.base_url'))
            ->timeout(15)
            ->acceptJson()
            ->get('/api/transactiondetail', [
                'project' => config('services.pakasir.project_slug'),
                'amount' => $amount,
                'order_id' => $orderId,
                'api_key' => config('services.pakasir.api_key'),
            ])
            ->throw();

        return $response->json();
    }

    public function buildPaymentUrl(
        string $orderId,
        int $amount,
        ?string $redirectUrl = null,
        bool $qrisOnly = false,
        string $paymentMethod = 'qris'
    ): string
    {
        $baseUrl = rtrim((string) config('services.pakasir.base_url'), '/');
        $slug = (string) config('services.pakasir.project_slug');
        $pathPrefix = $paymentMethod === 'paypal' ? 'paypal' : 'pay';

        $query = array_filter([
            'order_id' => $orderId,
            'redirect' => $redirectUrl,
            'qris_only' => $qrisOnly ? '1' : null,
        ], fn ($value) => $value !== null && $value !== '');

        return $baseUrl . '/' . $pathPrefix . '/' . rawurlencode($slug) . '/' . $amount . '?' . http_build_query($query);
    }
}
