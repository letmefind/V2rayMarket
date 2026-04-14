<?php

namespace App\Http\Controllers;

use App\Models\ServiceShare;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ServiceShareController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'payload' => ['required', 'string', 'max:12000'],
            'title' => ['nullable', 'string', 'max:255'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
        ]);

        $orderId = isset($data['order_id']) ? (int) $data['order_id'] : null;
        if ($orderId !== null) {
            $owned = \App\Models\Order::whereKey($orderId)->where('user_id', Auth::id())->exists();
            if (! $owned) {
                abort(403);
            }
        }

        $share = null;
        if ($orderId !== null) {
            $share = ServiceShare::query()
                ->where('user_id', Auth::id())
                ->where('order_id', $orderId)
                ->where('payload', $data['payload'])
                ->first();
        }

        if (! $share) {
            $share = ServiceShare::create([
                'user_id' => (int) Auth::id(),
                'order_id' => $orderId,
                'code' => $this->generateUniqueCode(),
                'title' => $data['title'] ?? null,
                'payload' => $data['payload'],
                'last_shared_at' => now(),
            ]);
        } else {
            $share->update([
                'title' => $data['title'] ?? $share->title,
                'last_shared_at' => now(),
            ]);
        }

        return redirect()->route('dashboard')->with([
            'status' => 'کد اشتراک با موفقیت ساخته شد.',
            'share_code' => $share->code,
            'share_url' => route('service-share.lookup', ['code' => $share->code]),
        ]);
    }

    public function lookup(Request $request)
    {
        $code = trim((string) $request->query('code', ''));
        $share = null;

        if (preg_match('/^\d{5}$/', $code)) {
            $share = ServiceShare::query()->where('code', $code)->first();
        }

        return view('service-share.lookup', [
            'share' => $share,
            'code' => $code,
        ]);
    }

    public function resolve(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'digits:5'],
        ]);

        return redirect()->route('service-share.lookup', ['code' => $data['code']]);
    }

    protected function generateUniqueCode(): string
    {
        for ($i = 0; $i < 100; $i++) {
            $candidate = (string) random_int(10000, 99999);
            $exists = ServiceShare::query()->where('code', $candidate)->exists();
            if (! $exists) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Unable to generate unique 5-digit code.');
    }
}
