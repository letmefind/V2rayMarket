<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstance;
use Illuminate\Database\Eloquent\Model;

class DiscountCodeUsage extends Model
{
    use BelongsToInstance;

    protected $fillable = [
        'instance_id',
        'discount_code_id', 'user_id', 'order_id', 'discount_amount', 'original_amount',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'original_amount' => 'decimal:2',
    ];
}
