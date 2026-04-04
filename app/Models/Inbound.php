<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Inbound extends Model
{

    protected $fillable = [
        'inbound_data',
        'title',
    ];


    protected $casts = [
        'inbound_data' => 'array',
    ];

    protected $appends = ['panel_id', 'is_active', 'remark', 'dropdown_label'];

    /**
     * یافتن اینباند ذخیره‌شده بر اساس id سمت X-UI (مثلاً ۱).
     * مقایسهٔ رشته/عدد و چند مسیر SQL برای سازگاری با MySQL/MariaDB/SQLite.
     */
    public static function findByPanelInboundId(int|string|null $panelInboundId): ?self
    {
        if ($panelInboundId === null || $panelInboundId === '') {
            return null;
        }

        $asString = (string) $panelInboundId;
        $asInt = is_numeric($panelInboundId) ? (int) $panelInboundId : null;

        foreach (array_unique(array_filter([$panelInboundId, $asString, $asInt], fn ($v) => $v !== null && $v !== '')) as $candidate) {
            $row = static::query()->where('inbound_data->id', $candidate)->first();
            if ($row !== null) {
                return $row;
            }
        }

        if ($asInt !== null) {
            $driver = DB::connection()->getDriverName();
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                try {
                    $row = static::query()
                        ->whereRaw('CAST(JSON_UNQUOTE(JSON_EXTRACT(inbound_data, "$.id")) AS UNSIGNED) = ?', [$asInt])
                        ->first();
                    if ($row !== null) {
                        return $row;
                    }
                } catch (\Throwable) {
                }
            }
            if ($driver === 'sqlite') {
                try {
                    $row = static::query()
                        ->whereRaw('CAST(json_extract(inbound_data, "$.id") AS INTEGER) = ?', [$asInt])
                        ->first();
                    if ($row !== null) {
                        return $row;
                    }
                } catch (\Throwable) {
                }
            }
        }

        foreach (static::query()->cursor() as $row) {
            $data = $row->inbound_data;
            if (! is_array($data) || ! array_key_exists('id', $data)) {
                continue;
            }
            $rid = $data['id'];
            if ((string) $rid === $asString || ($asInt !== null && (int) $rid === $asInt)) {
                return $row;
            }
        }

        return null;
    }

    public function getPanelIdAttribute(): ?string
    {
        return isset($this->inbound_data['id']) ? (string) $this->inbound_data['id'] : null;
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->inbound_data['enable'] ?? false;
    }

    public function getRemarkAttribute(): ?string
    {
        return $this->inbound_data['remark'] ?? null;
    }

    public function getDropdownLabelAttribute(): string
    {
        $panelId = $this->panel_id ?? 'N/A';
        $remark = $this->remark ?? 'بدون عنوان';
        $protocol = $this->inbound_data['protocol'] ?? 'unknown';
        $port = $this->inbound_data['port'] ?? '-';

        return "{$remark} (ID: {$panelId}) - {$protocol}:{$port}";
    }
}
