<?php

namespace App\Http\Controllers;

use App\Services\ServiceShareService;
use Illuminate\Auth\Access\AuthorizationException;
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

        try {
            $share = ServiceShareService::storeForUser(
                (int) Auth::id(),
                $data['payload'],
                $orderId,
                $data['title'] ?? null
            );
        } catch (AuthorizationException) {
            abort(403);
        }

        return redirect()->route('dashboard', ['tab' => 'my_services'])->with([
            'status' => 'کد اشتراک با موفقیت ساخته شد.',
            'share_code' => $share->code,
            'share_url' => ServiceShareService::publicLookupUrl($share->code),
        ]);
    }

    public function lookup(Request $request)
    {
        $code = trim((string) $request->query('code', ''));
        $share = null;

        if (preg_match('/^\d{5}$/', $code)) {
            $share = \App\Models\ServiceShare::query()->where('code', $code)->first();
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
}
