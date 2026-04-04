<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            واریز USDT/USDC — سفارش #{{ $order->id }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 space-y-6">
                @if (session('status'))
                    <div class="bg-green-100 border-l-4 border-green-600 text-green-800 p-4 rounded-xl text-right">
                        {{ session('status') }}
                    </div>
                @endif
                @if (session('error'))
                    <div class="bg-red-100 border-l-4 border-red-600 text-red-800 p-4 rounded-xl text-right">
                        {{ session('error') }}
                    </div>
                @endif

                <div class="text-right space-y-3 border-b dark:border-gray-700 pb-6">
                    <p><span class="text-gray-500">شبکه:</span> <strong class="text-gray-900 dark:text-gray-100">{{ $label }}</strong></p>
                    <p><span class="text-gray-500">معادل تومانی سفارش:</span> <strong>{{ number_format($order->amount) }} تومان</strong></p>
                    <p class="text-lg">
                        <span class="text-gray-500">مقدار دقیق واریز:</span>
                        <strong class="text-emerald-600 dark:text-emerald-400 font-mono">{{ rtrim(rtrim(number_format((float) $expected, 8, '.', ''), '0'), '.') ?: '0' }}</strong>
                        <span class="text-gray-700 dark:text-gray-300">{{ str_contains($order->crypto_network ?? '', 'usdc') ? 'USDC' : 'USDT' }}</span>
                    </p>
                    <div class="mt-4 p-4 bg-gray-50 dark:bg-gray-900/50 rounded-lg break-all dir-ltr text-left font-mono text-sm">
                        {{ $addr }}
                    </div>
                    <p class="text-sm text-amber-700 dark:text-amber-300">
                        فقط به همین آدرس و فقط روی همین شبکه واریز کنید. اشتباه شبکه باعث از دست رفتن دارایی می‌شود.
                    </p>
                </div>

                <form method="POST" action="{{ route('payment.manual-crypto.proof', $order) }}" enctype="multipart/form-data" class="space-y-4 text-right">
                    @csrf
                    <div>
                        <label for="tx_hash" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">شناسه تراکنش (TxID / Hash)</label>
                        <input type="text" name="tx_hash" id="tx_hash" value="{{ old('tx_hash', $order->crypto_tx_hash) }}"
                               class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white"
                               placeholder="اختیاری اگر تصویر می‌فرستید">
                    </div>
                    <div>
                        <label for="screenshot" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">تصویر تراکنش</label>
                        <input type="file" name="screenshot" id="screenshot" accept="image/*"
                               class="w-full text-sm text-gray-600 dark:text-gray-300">
                    </div>
                    <p class="text-xs text-gray-500">حداقل یکی از TxID یا تصویر الزامی است.</p>
                    <button type="submit"
                            class="w-full py-3 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg">
                        ثبت و ارسال برای بررسی مدیر
                    </button>
                </form>

                <div class="flex flex-wrap gap-4 justify-end text-sm">
                    <a href="{{ route('payment.manual-crypto', ['order' => $order, 'reset' => 1]) }}" class="text-gray-600 dark:text-gray-400 hover:underline">تغییر شبکه</a>
                    <span class="text-gray-400">|</span>
                    <a href="{{ route('order.show', $order) }}" class="text-gray-600 dark:text-gray-400 hover:underline">بازگشت</a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
