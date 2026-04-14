<x-guest-layout>
    <div class="max-w-2xl mx-auto py-10 px-4">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg p-6 sm:p-8">
            <h1 class="text-2xl font-bold text-gray-900 dark:text-gray-100 text-center">دریافت اطلاعات سرویس</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 text-center mt-2">
                کد ۵ رقمی را وارد کنید تا لینک یا کانفیگ سرویس نمایش داده شود.
            </p>
            <p class="text-xs text-gray-500 dark:text-gray-400 text-center mt-2" dir="ltr">
                {{ parse_url(config('app.url'), PHP_URL_HOST) ?: 'این سایت' }}<span class="font-mono">/c</span>
            </p>

            <form method="POST" action="{{ route('service-share.resolve') }}" class="mt-6">
                @csrf
                <label for="code" class="block text-sm font-medium text-gray-700 dark:text-gray-300 text-right mb-2">کد ۵ رقمی</label>
                <div class="flex gap-2">
                    <input
                        id="code"
                        name="code"
                        value="{{ old('code', $code) }}"
                        maxlength="5"
                        pattern="[0-9]{5}"
                        inputmode="numeric"
                        dir="ltr"
                        class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-center tracking-[0.35em]"
                        placeholder="12345"
                        required
                    />
                    <button type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 transition">نمایش</button>
                </div>
                @error('code')
                    <p class="text-xs text-red-600 mt-2 text-right">{{ $message }}</p>
                @enderror
            </form>

            @if ($code !== '' && ! $share)
                <div class="mt-6 rounded-lg border border-red-300 bg-red-50 dark:bg-red-950/20 dark:border-red-800 p-3 text-sm text-red-700 dark:text-red-300 text-right">
                    کد واردشده معتبر نیست یا پیدا نشد.
                </div>
            @endif

            @if ($share)
                <div class="mt-8 border-t border-gray-200 dark:border-gray-700 pt-6">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-gray-100 text-right">
                        {{ $share->title ?: 'اطلاعات سرویس' }}
                    </h2>
                    <p class="text-xs text-gray-500 mt-1 text-right">کد: <span dir="ltr" class="font-mono">{{ $share->code }}</span></p>

                    <div class="mt-4 p-4 rounded-lg bg-gray-100 dark:bg-gray-900 relative">
                        <pre class="text-sm text-gray-900 dark:text-gray-100 whitespace-pre-wrap break-all text-left" dir="ltr">{{ $share->payload }}</pre>
                    </div>

                    <div class="mt-5 text-center">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">QR Code</h3>
                        <div class="inline-block bg-white p-3 rounded-xl">
                            {!! \SimpleSoftwareIO\QrCode\Facades\QrCode::size(210)->margin(1)->generate($share->payload) !!}
                        </div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-guest-layout>
