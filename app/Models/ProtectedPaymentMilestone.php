<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProtectedPaymentMilestone extends Model
{
    use HasFactory;

    protected $fillable = [
        'milestone_id',
        'protected_payment_id',
        'sequence_number',
        'title',
        'description',
        'amount_minor',
        'released_amount_minor',
        'refunded_amount_minor',
        'status',
        'due_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence_number' => 'integer',
            'amount_minor' => 'integer',
            'released_amount_minor' => 'integer',
            'refunded_amount_minor' => 'integer',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function protectedPayment(): BelongsTo
    {
        return $this->belongsTo(ProtectedPayment::class, 'protected_payment_id');
    }

    public function remainingAmountMinor(): int
    {
        return max(0, (int) $this->amount_minor - (int) $this->released_amount_minor - (int) $this->refunded_amount_minor);
    }
}
