<?php

namespace App\Models\Concerns;

use App\Support\InstanceId;
use App\Support\SchemaUtil;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * جداسازی دادهٔ هر ربات/دامنه در یک دیتابیس مشترک (ستون instance_id).
 */
trait BelongsToInstance
{
    public static function bootBelongsToInstance(): void
    {
        static::addGlobalScope('instance', function (Builder $builder): void {
            if (InstanceId::isSharePickupOnly()) {
                return;
            }

            $table = $builder->getModel()->getTable();
            $builder->where($table.'.instance_id', InstanceId::current());
        });

        static::creating(function (Model $model): void {
            if (InstanceId::isSharePickupOnly()) {
                return;
            }

            if (empty($model->getAttribute('instance_id'))) {
                $model->setAttribute('instance_id', InstanceId::current());
            }
        });
    }

    public function scopeForInstance(Builder $query, ?string $instanceId = null): Builder
    {
        $table = $this->getTable();
        $query = $query->withoutGlobalScope('instance');
        if (! SchemaUtil::tableHasColumn($table, 'instance_id')) {
            return $query;
        }

        return $query->where($table.'.instance_id', $instanceId ?? InstanceId::current());
    }
}
