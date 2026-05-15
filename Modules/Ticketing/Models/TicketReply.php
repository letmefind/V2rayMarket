<?php

namespace Modules\Ticketing\Models;

use App\Models\Concerns\BelongsToInstance;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class TicketReply extends Model
{
    use BelongsToInstance;

    protected $fillable = ['instance_id', 'ticket_id', 'user_id', 'message', 'attachment_path'];
    public function ticket() { return $this->belongsTo(Ticket::class); }
    public function user() { return $this->belongsTo(User::class); }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
