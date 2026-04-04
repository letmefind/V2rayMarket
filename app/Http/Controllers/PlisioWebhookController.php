<?php

namespace App\Http\Controllers;

use App\Actions\FulfillOrderAfterPaymentAction;
use App\Models\Order;
use App\Services\PlisioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PlisioWebhookController extends Controller
{
    public function handle(Request $request, FulfillOrderAfterPaymentAction $fulfill)
    {
        $payload = $request->all();
        Log::info('Plisio webhook', ['keys' => array_keys($payload)]);

        $plisio = PlisioService::fromDatabase();
        $secret = $plisio->getApiKey();
        if ($secret === '' || ! $plisio->verifyCallbackPayload($payload, $secret)) {
            Log::warning('Plisio webhook verify failed');

            return response('Invalid signature', 422);
        }

        $status = $payload['status'] ?? '';
        $orderNumber = $payload['order_number'] ?? null;
        if ($orderNumber === null || $orderNumber === '') {
            return response('Missing order', 400);
        }

        $order = Order::find((int) $orderNumber);
        if (! $order) {
            Log::warning('Plisio webhook unknown order', ['order_number' => $orderNumber]);

            return response('OK', 200);
        }

        if ($status === 'completed') {
            $txnId = (string) ($payload['txn_id'] ?? '');
            if ($txnId !== '') {
                $order->update(['plisio_txn_id' => $txnId]);
                $order->refresh();
            }

            try {
                $fulfill->execute($order, 'plisio');
            } catch (\Throwable $e) {
                Log::error('Plisio fulfill failed: '.$e->getMessage(), ['trace' => $e->getTraceAsString(), 'order_id' => $order->id]);

                return response('Fulfillment error', 500);
            }
        }

        return response('OK', 200);
    }
}
