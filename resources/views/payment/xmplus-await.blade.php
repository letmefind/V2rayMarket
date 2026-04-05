<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            تکمیل پرداخت XMPlus — سفارش #{{ $order->id }}
        </h2>
    </x-slot>

    @php
        $qrcode = $pay['qrcode'] ?? null;
        $data = $pay['data'] ?? null;
        $gateway = $pay['gateway'] ?? null;
    @endphp

    <div class="py-12">
        <div class="max-w-lg mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 space-y-6 text-right">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    دستورالعمل پرداخت از پنل XMPlus دریافت شد. در صورت وجود QR آن را اسکن کنید؛ یا در پنل XMPlus همان فاکتور را باز کرده و پرداخت را انجام دهید (شامل حالت‌هایی که نیاز به تأیید ادمین دارند).
                </p>

                @if (is_string($gateway) && $gateway !== '')
                    <p class="text-sm"><span class="text-gray-500">درگاه:</span> <span dir="ltr" class="font-medium">{{ $gateway }}</span></p>
                @endif

                @if (is_string($qrcode) && str_starts_with($qrcode, 'data:image'))
                    <div class="flex justify-center">
                        <img src="{{ $qrcode }}" alt="QR پرداخت" class="max-w-xs rounded-lg border dark:border-gray-600">
                    </div>
                @endif

                @if (is_string($data) && $data !== '' && ! preg_match('#^https?://#i', $data))
                    <div class="rounded-lg bg-gray-50 dark:bg-gray-900/50 p-3 break-all text-xs" dir="ltr">{{ $data }}</div>
                @endif

                @if ($xmplusPanelUrl !== '')
                    <a href="{{ $xmplusPanelUrl }}" target="_blank" rel="noopener noreferrer"
                       class="inline-flex items-center text-indigo-600 dark:text-indigo-400 hover:underline text-sm">
                        ورود به پنل XMPlus
                    </a>
                @endif

                <form method="POST" action="{{ route('payment.xmplus.finalize', $order) }}">
                    @csrf
                    <button type="submit"
                            class="w-full py-3 px-4 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white font-medium transition">
                        پرداخت انجام شد — فعال‌سازی سرویس
                    </button>
                </form>

                <a href="{{ route('order.show', $order) }}" class="block text-center text-sm text-gray-500 hover:underline">
                    بازگشت به صفحهٔ سفارش
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
