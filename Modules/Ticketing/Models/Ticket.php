<?php

namespace Modules\Ticketing\Models;

use App\Models\Concerns\BelongsToInstance;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Ticket extends Model
{
    use BelongsToInstance;

    protected $fillable = ['instance_id', 'user_id', 'subject', 'message', 'priority', 'source', 'status'];
    public function user() { return $this->belongsTo(User::class); }
    public function replies() { return $this->hasMany(TicketReply::class); }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
