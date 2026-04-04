<div class="space-y-4">
    <div>
        <h3 class="text-lg font-semibold mb-2">اطلاعات کلی</h3>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <span class="text-sm text-gray-600">عنوان:</span>
                <span class="font-medium">{{ $inbound->title ?? 'بدون عنوان' }}</span>
            </div>
            <div>
                <span class="text-sm text-gray-600">Remark:</span>
                <span class="font-medium">{{ $inboundData['remark'] ?? 'ندارد' }}</span>
            </div>
            <div>
                <span class="text-sm text-gray-600">پروتکل:</span>
                <span class="font-medium">{{ $inboundData['protocol'] ?? 'ندارد' }}</span>
            </div>
            <div>
                <span class="text-sm text-gray-600">پورت:</span>
                <span class="font-medium">{{ $inboundData['port'] ?? 'ندارد' }}</span>
            </div>
            @if(isset($inboundData['listen']))
            <div>
                <span class="text-sm text-gray-600">Listen:</span>
                <span class="font-medium">{{ $inboundData['listen'] }}</span>
            </div>
            @endif
        </div>
    </div>

    @if(isset($inboundData['streamSettings']))
    <div>
        <h3 class="text-lg font-semibold mb-2">تنظیمات Stream</h3>
        @php
            $streamSettings = is_string($inboundData['streamSettings']) 
                ? json_decode($inboundData['streamSettings'], true) 
                : $inboundData['streamSettings'];
        @endphp
        
        <div class="space-y-2">
            <div>
                <span class="text-sm text-gray-600">Network:</span>
                <span class="font-medium">{{ $streamSettings['network'] ?? 'tcp' }}</span>
            </div>
            <div>
                <span class="text-sm text-gray-600">Security:</span>
                <span class="font-medium">{{ $streamSettings['security'] ?? 'none' }}</span>
            </div>

            @if(isset($streamSettings['wsSettings']))
            <div class="mt-2 p-2 bg-gray-50 rounded">
                <strong>WebSocket Settings:</strong>
                <pre class="text-xs mt-1">{{ json_encode($streamSettings['wsSettings'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
            @endif

            @if(isset($streamSettings['httpSettings']))
            <div class="mt-2 p-2 bg-gray-50 rounded">
                <strong>HTTP Settings:</strong>
                <pre class="text-xs mt-1">{{ json_encode($streamSettings['httpSettings'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
            @endif

            @if(isset($streamSettings['xhttpSettings']))
            <div class="mt-2 p-2 bg-gray-50 rounded">
                <strong>XHTTP Settings:</strong>
                <pre class="text-xs mt-1">{{ json_encode($streamSettings['xhttpSettings'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
            @endif

            @if(isset($streamSettings['grpcSettings']))
            <div class="mt-2 p-2 bg-gray-50 rounded">
                <strong>gRPC Settings:</strong>
                <pre class="text-xs mt-1">{{ json_encode($streamSettings['grpcSettings'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
            @endif

            @if(isset($streamSettings['tlsSettings']))
            <div class="mt-2 p-2 bg-gray-50 rounded">
                <strong>TLS Settings:</strong>
                <pre class="text-xs mt-1">{{ json_encode($streamSettings['tlsSettings'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
            @endif
        </div>
    </div>
    @endif

    <div>
        <h3 class="text-lg font-semibold mb-2">اطلاعات کامل JSON</h3>
        <div class="p-4 bg-gray-50 rounded overflow-auto max-h-96">
            <pre class="text-xs">{{ json_encode($inboundData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    </div>
</div>
