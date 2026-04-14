@php
    $typingPath = \App\Services\ServiceShareService::publicDisplayTypingPath();
    $payloadJs = ($share ?? null)
        ? json_encode($share->payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)
        : 'null';
@endphp

<x-service-share-layout>
    <div class="relative min-h-screen overflow-hidden bg-slate-950">
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_80%_50%_at_50%_-20%,rgba(99,102,241,0.35),transparent)]"></div>
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_60%_40%_at_100%_50%,rgba(14,165,233,0.12),transparent)]"></div>
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_50%_40%_at_0%_80%,rgba(168,85,247,0.1),transparent)]"></div>

        <main class="relative z-10 mx-auto max-w-lg px-4 py-8 sm:py-12 sm:px-6 lg:max-w-2xl">
            {{-- هدر --}}
            <header class="mb-8 text-center sm:mb-10">
                <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 shadow-lg shadow-indigo-500/25 ring-1 ring-white/10 sm:h-16 sm:w-16">
                    <svg class="h-8 w-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold tracking-tight text-white sm:text-3xl">دریافت اشتراک</h1>
                <p class="mt-2 text-sm text-slate-400 sm:text-base">کد ۵ رقمی که برایتان فرستاده‌اند را وارد کنید تا لینک یا QR نمایش داده شود.</p>
            </header>

            {{-- راهنمای گام‌ها --}}
            <ol class="mb-8 flex flex-col gap-3 lg:mb-10 lg:flex-row lg:gap-4">
                <li class="flex flex-1 items-start gap-3 rounded-xl border border-white/10 bg-white/5 p-4 backdrop-blur-sm">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-indigo-500/80 text-sm font-bold text-white">۱</span>
                    <span class="text-right text-sm leading-relaxed text-slate-300">در مرورگر همین صفحه را باز کنید (آدرس: <span class="font-mono text-indigo-300" dir="ltr">{{ $typingPath }}</span>).</span>
                </li>
                <li class="flex flex-1 items-start gap-3 rounded-xl border border-white/10 bg-white/5 p-4 backdrop-blur-sm">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-violet-500/80 text-sm font-bold text-white">۲</span>
                    <span class="text-right text-sm leading-relaxed text-slate-300">کد را در کادر پایین بزنید و «نمایش» را بزنید.</span>
                </li>
                <li class="flex flex-1 items-start gap-3 rounded-xl border border-white/10 bg-white/5 p-4 backdrop-blur-sm">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-cyan-500/70 text-sm font-bold text-white">۳</span>
                    <span class="text-right text-sm leading-relaxed text-slate-300">روی لینک بزنید تا کپی شود؛ یا QR را در اپ VPN اسکن کنید.</span>
                </li>
            </ol>

            {{-- فرم کد --}}
            <div class="rounded-2xl border border-white/10 bg-white/[0.07] p-5 shadow-2xl shadow-black/20 backdrop-blur-md sm:p-8">
                <form method="POST" action="{{ route('service-share.resolve') }}" class="space-y-4">
                    @csrf
                    <label for="code" class="block text-sm font-medium text-slate-300">کد ۵ رقمی</label>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-stretch">
                        <input
                            id="code"
                            name="code"
                            value="{{ old('code', $code) }}"
                            maxlength="5"
                            pattern="[0-9]{5}"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            dir="ltr"
                            class="min-h-[52px] flex-1 rounded-xl border border-white/15 bg-slate-900/80 px-4 text-center text-xl font-mono tracking-[0.4em] text-white placeholder:text-slate-600 shadow-inner focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
                            placeholder="• • • • •"
                            required
                        />
                        <button type="submit" class="min-h-[52px] shrink-0 rounded-xl bg-gradient-to-l from-indigo-600 to-violet-600 px-8 text-sm font-semibold text-white shadow-lg shadow-indigo-900/40 transition hover:from-indigo-500 hover:to-violet-500 focus:outline-none focus:ring-2 focus:ring-indigo-400/50 active:scale-[0.98] sm:min-w-[120px]">
                            نمایش
                        </button>
                    </div>
                    @error('code')
                        <p class="text-sm text-rose-400">{{ $message }}</p>
                    @enderror
                </form>

                @if ($code !== '' && ! $share)
                    <div class="mt-6 flex items-start gap-3 rounded-xl border border-rose-500/30 bg-rose-500/10 p-4 text-sm text-rose-200">
                        <svg class="mt-0.5 h-5 w-5 shrink-0 text-rose-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                        <span>این کد معتبر نیست یا منقضی شده. دوباره از فرد مقابل کد را بگیرید.</span>
                    </div>
                @endif

                @if ($share)
                    <div class="mt-8 border-t border-white/10 pt-8">
                        <div class="mb-4 flex flex-col gap-1 text-right sm:flex-row sm:items-center sm:justify-between">
                            <h2 class="text-lg font-bold text-white">{{ $share->title ?: 'اطلاعات اشتراک' }}</h2>
                            <span class="text-xs text-slate-500">کد استفاده‌شده: <span dir="ltr" class="font-mono text-slate-400">{{ $share->code }}</span></span>
                        </div>

                        <p class="mb-3 text-xs text-slate-400">روی باکس زیر بزنید تا کل متن کپی شود؛ یا اگر لینک است در برنامه VPN باز می‌شود.</p>

                        <button
                            type="button"
                            id="payload-copy-btn"
                            class="group relative w-full cursor-pointer rounded-xl border border-emerald-500/30 bg-slate-900/90 p-4 text-left transition hover:border-emerald-400/50 hover:bg-slate-900 focus:outline-none focus:ring-2 focus:ring-emerald-500/40"
                            dir="ltr"
                        >
                            <span class="absolute left-3 top-3 flex items-center gap-1 rounded-md bg-emerald-500/20 px-2 py-1 text-[10px] font-medium text-emerald-300 opacity-90 group-hover:bg-emerald-500/30 sm:text-xs">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                یک‌بار ضربه = کپی
                            </span>
                            <pre id="payload-text" class="mt-8 max-h-48 overflow-auto whitespace-pre-wrap break-all pr-2 text-sm leading-relaxed text-emerald-100/95 sm:max-h-64 sm:text-[13px]">{{ $share->payload }}</pre>
                        </button>

                        <p id="copy-toast" class="pointer-events-none mt-3 hidden text-center text-sm font-medium text-emerald-400" role="status">✓ در کلیپ‌بورد کپی شد</p>

                        <div class="mt-8">
                            <h3 class="mb-4 text-center text-sm font-semibold text-slate-300">اسکن QR در اپ VPN</h3>
                            <div class="flex justify-center">
                                <div class="rounded-2xl bg-white p-3 shadow-xl ring-1 ring-black/5 sm:p-4">
                                    {!! \SimpleSoftwareIO\QrCode\Facades\QrCode::size(220)->margin(1)->generate($share->payload) !!}
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <p class="mt-8 text-center text-xs text-slate-600">اگر مشکلی بود، از همان شخصی که کد را گرفتید دوباره بخواهید.</p>
        </main>
    </div>

    @if ($share)
        @push('scripts')
        <script>
            (function () {
                var raw = {!! $payloadJs !!};
                if (raw === null) return;
                var btn = document.getElementById('payload-copy-btn');
                var toast = document.getElementById('copy-toast');
                function showToast() {
                    if (!toast) return;
                    toast.classList.remove('hidden');
                    clearTimeout(window.__copyToastT);
                    window.__copyToastT = setTimeout(function () { toast.classList.add('hidden'); }, 2200);
                }
                function copyText() {
                    var t = String(raw);
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        return navigator.clipboard.writeText(t).then(showToast).catch(fallbackCopy);
                    }
                    fallbackCopy();
                }
                function fallbackCopy() {
                    var ta = document.createElement('textarea');
                    ta.value = String(raw);
                    ta.style.position = 'fixed';
                    ta.style.left = '-9999px';
                    document.body.appendChild(ta);
                    ta.select();
                    try {
                        document.execCommand('copy');
                        showToast();
                    } catch (e) {}
                    document.body.removeChild(ta);
                }
                if (btn) {
                    btn.addEventListener('click', function (e) {
                        e.preventDefault();
                        copyText();
                    });
                }
            })();
        </script>
        @endpush
    @endif
</x-service-share-layout>
