<?php

namespace App\Models\Concerns;

use App\Support\InstanceId;
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
        return $query->withoutGlobalScope('instance')
            ->where($this->getTable().'.instance_id', $instanceId ?? InstanceId::current());
    }
}
