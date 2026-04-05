{{-- نمایش پکیج‌های XMPlus؛ $xmplusCatalog از XmplusCatalog::get — $xmplusCatalogVariant: null|tailwind --}}
@php
    $xc = $xmplusCatalog ?? [];
    $full = $xc['full'] ?? [];
    $traffic = $xc['traffic'] ?? [];
    $err = $xc['error'] ?? null;
    $useTw = ($xmplusCatalogVariant ?? '') === 'tailwind';
@endphp
@if(!empty($full) || !empty($traffic))
    @if($useTw)
        <div class="mb-6 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4 sm:p-6 shadow-sm">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2">پکیج‌های پنل (XMPlus)</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">قیمت خرید از سایت همان پلن‌های زیر است؛ این جدول مشخصات و قیمت‌های اعلام‌شده توسط پنل را نشان می‌دهد.</p>
    @else
        <div class="{{ $xmplusCatalogCardClass ?? 'card border-secondary mb-4' }}">
            <div class="card-body">
                <h3 class="h5 card-title mb-3">پکیج‌های پنل (XMPlus)</h3>
                <p class="text-muted small mb-3">قیمت خرید از سایت همان پلن‌های زیر است؛ این جدول فقط مشخصات و قیمت‌های اعلام‌شده توسط پنل را نشان می‌دهد.</p>
    @endif
            @if(!empty($full))
                <h4 class="{{ $useTw ? 'text-base font-semibold text-gray-800 dark:text-gray-200 mt-4 mb-2' : 'h6 mt-3' }}">پکیج‌های کامل</h4>
                <div class="{{ $useTw ? 'overflow-x-auto' : 'table-responsive' }}">
                    <table class="{{ $useTw ? 'min-w-full text-sm text-right border border-gray-200 dark:border-gray-600 divide-y divide-gray-200 dark:divide-gray-600' : 'table table-sm table-bordered align-middle text-end' }}">
                        <thead class="{{ $useTw ? 'bg-gray-50 dark:bg-gray-900/50' : 'table-light' }}">
                        <tr>
                            <th class="{{ $useTw ? 'p-2 border border-gray-200 dark:border-gray-600' : '' }}">pid</th>
                            <th class="{{ $useTw ? 'p-2 border border-gray-200 dark:border-gray-600' : '' }}">نام</th>
                            <th class="{{ $useTw ? 'p-2 border border-gray-200 dark:border-gray-600' : '' }}">ترافیک</th>
                            <th class="{{ $useTw ? 'p-2 border border-gray-200 dark:border-gray-600' : '' }}">محدودیت‌ها</th>
                            <th class="{{ $useTw ? 'p-2 border border-gray-200 dark:border-gray-600' : '' }}">قیمت‌ها (پنل)</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($full as $p)
                            @if(is_array($p))
                                <tr class="{{ $useTw ? 'hover:bg-gray-50 dark:hover:bg-gray-900/30' : '' }}">
                                    <td class="font-mono {{ $useTw ? 'p-2 border border-gray-200 dark:border-gray-600' : '' }}">{{ $p['id'] ?? '—' }}</td>
                                    <td class="{{ $useTw ? 'p-2 border border-gray-200 dark:border-gray-600' : '' }}">{{ $p['name'] ?? '—' }}</td>
                                    <td class="{{ $useTw ? 'p-2 border border-gray-200 dark:border-gray-600' : '' }}">{{ $p['traffic'] ?? '—' }}</td>
                                    <td class="small {{ $useTw ? 'p-2 border border-gray-200 dark:border-gray-600 text-xs' : '' }}">
                                        @if(!empty($p['speedlimit']))<div>سرعت: {{ $p['speedlimit'] }}</div>@endif
                                        @if(isset($p['iplimit']))<div>IP: {{ $p['iplimit'] }}</div>@endif
                                        @if(!empty($p['server_group']))<div>گروه: {{ $p['server_group'] }}</div>@endif
                                        @if(!empty($p['enable_stock']))<div>موجودی: {{ $p['stock_count'] ?? '—' }}</div>@endif
                                    </td>
                                    <td class="small {{ $useTw ? 'p-2 border border-gray-200 dark:border-gray-600 text-xs' : '' }}">
                                        @if(!empty($p['billing']) && is_array($p['billing']))
                                            @foreach($p['billing'] as $k => $v)
                                                <div><strong>{{ $k }}:</strong> {{ is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE) }}</div>
                                            @endforeach
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if(!empty($traffic))
                <h4 class="{{ $useTw ? 'text-base font-semibold text-gray-800 dark:text-gray-200 mt-6 mb-2' : 'h6 mt-4' }}">پکیج‌های ترافیک</h4>
                <div class="{{ $useTw ? 'overflow-x-auto' : 'table-responsive' }}">
                    <table class="{{ $useTw ? 'min-w-full text-sm text-right border border-gray-200 dark:border-gray-600 divide-y divide-gray-200 dark:divide-gray-600' : 'table table-sm table-bordered align-middle text-end' }}">
                        <thead class="{{ $useTw ? 'bg-gray-50 dark:bg-gray-900/50' : 'table-light' }}">
                        <tr>
                            <th class="{{ $useTw ? 'p-2 border border-gray-200 dark:border-gray-600' : '' }}">pid</th>
                            <th class="{{ $useTw ? 'p-2 border border-gray-200 dark:border-gray-600' : '' }}">نام</th>
                            <th class="{{ $useTw ? 'p-2 border border-gray-200 dark:border-gray-600' : '' }}">ترافیک</th>
                            <th class="{{ $useTw ? 'p-2 border border-gray-200 dark:border-gray-600' : '' }}">قیمت (پنل)</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach($traffic as $p)
                            @if(is_array($p))
                                <tr class="{{ $useTw ? 'hover:bg-gray-50 dark:hover:bg-gray-900/30' : '' }}">
                                    <td class="font-mono {{ $useTw ? 'p-2 border border-gray-200 dark:border-gray-600' : '' }}">{{ $p['id'] ?? '—' }}</td>
                                    <td class="{{ $useTw ? 'p-2 border border-gray-200 dark:border-gray-600' : '' }}">{{ $p['name'] ?? '—' }}</td>
                                    <td class="{{ $useTw ? 'p-2 border border-gray-200 dark:border-gray-600' : '' }}">{{ $p['traffic'] ?? '—' }}</td>
                                    <td class="small {{ $useTw ? 'p-2 border border-gray-200 dark:border-gray-600' : '' }}">{{ $p['billing']['topup_traffic'] ?? '—' }}</td>
                                </tr>
                            @endif
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
    @if($useTw)
        </div>
    @else
            </div>
        </div>
    @endif
@elseif(!empty($err))
    @if($useTw)
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-800 p-4 text-sm text-amber-900 dark:text-amber-100">
            بارگذاری لیست پکیج‌های XMPlus ناموفق بود؛ تنظیمات API و لاگ <code class="text-xs">storage/logs/xmplus-*.log</code> را بررسی کنید.
        </div>
    @else
        <div class="alert alert-warning small mb-4" role="alert">بارگذاری لیست پکیج‌های XMPlus ناموفق بود؛ تنظیمات API و لاگ <code>storage/logs/xmplus-*.log</code> را بررسی کنید.</div>
    @endif
@endif
