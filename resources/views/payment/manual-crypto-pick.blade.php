<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            انتخاب شبکه — سفارش #{{ $order->id }}
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

                <div class="text-right space-y-2">
                    <p class="text-gray-700 dark:text-gray-300">
                        مبلغ قابل پرداخت: <strong>{{ number_format($order->amount) }} تومان</strong>
                    </p>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        شبکه مورد نظر را انتخاب کنید. سپس به آدرس نمایش‌داده‌شده واریز کنید و در مرحله بعد TxID یا تصویر تراکنش را ارسال کنید.
                    </p>
                </div>

                <div class="space-y-3">
                    @foreach ($networks as $networkId => $networkLabel)
                        <form method="POST" action="{{ route('payment.manual-crypto.pick', $order) }}" class="block">
                            @csrf
                            <input type="hidden" name="network" value="{{ $networkId }}">
                            <button type="submit"
                                    class="w-full text-right p-4 border-2 rounded-lg border-gray-200 dark:border-gray-600 hover:border-emerald-500 dark:hover:border-emerald-500 transition">
                                <span class="font-semibold text-gray-900 dark:text-gray-100">{{ $networkLabel }}</span>
                            </button>
                        </form>
                    @endforeach
                </div>

                <div class="text-right">
                    <a href="{{ route('order.show', $order) }}" class="text-sm text-gray-600 dark:text-gray-400 hover:underline">بازگشت به روش‌های پرداخت</a>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
