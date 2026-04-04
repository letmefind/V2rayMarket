<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'key',
        'value',
        'test_account_enabled',
        'test_account_volume_gb',
        'test_account_days',
        'test_account_max_per_user',
    ];

    protected $casts = [
        'test_account_enabled' => 'boolean',
        'test_account_volume_gb' => 'integer',
        'test_account_days' => 'integer',
        'test_account_max_per_user' => 'integer',
    ];

    /**
     * Get the value attribute - handle both string and JSON values
     */
    public function getValueAttribute($value)
    {
        if (is_null($value)) {
            return null;
        }
        
        // Try to decode as JSON, if it fails return as string
        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
        
        return $value;
    }

    /**
     * Set the value attribute - encode arrays, keep strings as is
     */
    public function setValueAttribute($value)
    {
        if (is_array($value) || is_object($value)) {
            $this->attributes['value'] = json_encode($value);
        } else {
            $this->attributes['value'] = $value;
        }
    }



    public function inbounds()
    {
        return $this->hasMany(\App\Models\Inbound::class);
    }

    public function getTestAccountEnabledAttribute($value)
    {
        return (bool) $value;
    }
}
