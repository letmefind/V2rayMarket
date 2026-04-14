<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceShare extends Model
{
    protected $fillable = [
        'user_id',
        'order_id',
        'code',
        'title',
        'payload',
        'last_shared_at',
    ];

    protected $casts = [
        'last_shared_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
