<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstance;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use BelongsToInstance, HasFactory;

    protected $fillable = [
        'instance_id',
        'user_id',
        'type',
        'title',
        'message',
        'link',
        'is_read',
    ];

    protected $casts = [
        'is_read' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
