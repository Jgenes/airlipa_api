<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    protected $fillable = [
        'ticket_number',
        'user_id',
        'protected_payment_id',
        'dispute_case_id',
        'category',
        'priority',
        'subject',
        'status',
        'first_response_due_at',
        'resolution_due_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'first_response_due_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function protectedPayment(): BelongsTo
    {
        return $this->belongsTo(ProtectedPayment::class);
    }

    public function disputeCase(): BelongsTo
    {
        return $this->belongsTo(DisputeCase::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class, 'support_ticket_id');
    }
}
